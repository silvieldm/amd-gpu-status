<?php
declare(strict_types=1);

/**
 * Runs `radeontop -b <bus> -d - -l 1` and parses the dump.
 *
 * The dump looks roughly like this (version dependent):
 *
 *   0000:03:00.0 gpu 4.05%, ee 0.00%, vgt 5.26%, ta 2.70%, tc 1.59%,
 *   sx 1.53%, sh 4.83%, spi 3.52%, smx 4.86%, cr 0.00%, sc 0.00%,
 *   pa 2.06%, db 0.00%, cb 0.21%, vram 3.30% 268.00mb,
 *   gtt 0.19% 15.00mb, vis 0.00% 0.00mb
 *
 * Parsing is deliberately tolerant: unknown labels are kept, missing ones are
 * skipped and nothing throws.
 */
final class Radeontop
{
    /** Order used by the UI for the collapsed detail section. */
    public const PIPELINE = ['ee', 'vgt', 'ta', 'tc', 'sx', 'sh', 'spi', 'smx', 'cr', 'sc', 'pa', 'db', 'cb'];

    /** Labels that look like a percentage but are not a block. */
    private const IGNORE = ['dumping', 'limit', 'bus', 'card', 'bdf'];

    public function __construct(
        private string $binary = 'radeontop',
        private bool $allowSudo = true,
        private int $timeout = 10,
    ) {
    }

    public function available(): bool
    {
        static $cache = [];
        $key = $this->binary;

        if (!array_key_exists($key, $cache)) {
            $cache[$key] = $this->resolveBinary() !== null;
        }

        return $cache[$key];
    }

    private function resolveBinary(): ?string
    {
        if (str_contains($this->binary, '/')) {
            return is_executable($this->binary) ? $this->binary : null;
        }

        $path = trim((string) shell_exec('command -v ' . escapeshellarg($this->binary) . ' 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    /**
     * @return array{ok:bool, values:array<string,array{pct:float,mb:?float}>, raw:string, error:?string, seconds:float}
     */
    public function sample(string $bus): array
    {
        $started = microtime(true);
        $bus = Sysfs::normBus($bus);

        $binary = $this->resolveBinary();
        if ($binary === null) {
            return [
                'ok' => false,
                'values' => [],
                'raw' => '',
                'error' => 'radeontop not found in PATH',
                'seconds' => 0.0,
            ];
        }

        $base = escapeshellarg($binary) . ' -b ' . escapeshellarg($bus) . ' -d - -l 1';
        $attempts = [['cmd' => $base, 'error' => null]];

        if ($this->allowSudo && function_exists('shell_exec')) {
            $attempts[] = [
                'cmd' => 'sudo -n ' . $base,
                'error' => 'radeontop needs root (try a sudoers entry for the radeontop binary)',
            ];
        }

        $lastError = null;
        $lastRaw = '';

        foreach ($attempts as $attempt) {
            $cmd = $attempt['cmd'];
            if ($this->timeout > 0 && trim((string) shell_exec('command -v timeout 2>/dev/null')) !== '') {
                $cmd = 'timeout ' . $this->timeout . ' ' . $cmd;
            }

            $raw = (string) shell_exec($cmd . ' 2>&1');
            $lastRaw = $raw;
            $values = self::parse($raw);

            if ($values !== []) {
                return [
                    'ok' => true,
                    'values' => $values,
                    'raw' => $raw,
                    'error' => null,
                    'seconds' => round(microtime(true) - $started, 3),
                ];
            }

            $lastError = self::diagnose($raw) ?? $attempt['error'];
        }

        return [
            'ok' => false,
            'values' => [],
            'raw' => $lastRaw,
            'error' => $lastError ?? 'no parsable radeontop output',
            'seconds' => round(microtime(true) - $started, 3),
        ];
    }

    /**
     * Turns stderr/stdout noise into a short, human readable reason.
     */
    private static function diagnose(string $raw): ?string
    {
        $text = trim($raw);
        if ($text === '') {
            return 'radeontop returned no output';
        }

        if (preg_match('/(permission denied|failed to open|are you root|operation not permitted)/i', $text, $m) === 1) {
            return 'radeontop needs root: ' . strtolower($m[1]);
        }
        if (preg_match('/(no such file|not found|command not found)/i', $text, $m) === 1) {
            return 'radeontop not found';
        }
        if (preg_match('/(no device|invalid bus|failed to (?:find|open) device|cannot open)/i', $text, $m) === 1) {
            return 'no GPU on this bus';
        }

        $line = strtok($text, "\n") ?: $text;

        return mb_substr(trim($line), 0, 160);
    }

    /**
     * @return array<string,array{pct:float,mb:?float}>
     */
    public static function parse(string $raw): array
    {
        $pattern = '/([A-Za-z_][A-Za-z0-9_]{0,7})\s*[:=]?\s*([0-9]+(?:\.[0-9]+)?)\s*%'
            . '(?:\s*\(?\s*([0-9]+(?:\.[0-9]+)?)\s*(mb|mib|gb|gib)\)?)?/i';

        if (preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $out = [];
        foreach ($matches as $row) {
            $label = strtolower(trim(preg_replace('/\s+/', ' ', $row[1]) ?? ''));
            if ($label === '' || in_array($label, self::IGNORE, true)) {
                continue;
            }

            $mb = null;
            if (($row[3] ?? '') !== '') {
                $mb = (float) $row[3];
                $unit = strtolower($row[4] ?? 'mb');
                if ($unit === 'gb' || $unit === 'gib') {
                    $mb *= 1024;
                }
            }

            $out[$label] = ['pct' => (float) $row[2], 'mb' => $mb];
        }

        return $out;
    }

    /**
     * Splits parsed values into the headline numbers and the pipeline details.
     *
     * @param array<string,array{pct:float,mb:?float}> $values
     * @return array{gpu:?float, vram:?float, gtt:?float, vram_mb:?float, gtt_mb:?float, pipeline:array<string,float>}
     */
    public static function summarize(array $values): array
    {
        $pipeline = [];
        foreach (self::PIPELINE as $key) {
            if (isset($values[$key])) {
                $pipeline[$key] = $values[$key]['pct'];
            }
        }
        // Keep anything unknown so a new radeontop release does not hide data.
        foreach ($values as $key => $value) {
            if (isset($pipeline[$key]) || in_array($key, ['gpu', 'vram', 'gtt', 'vis'], true)) {
                continue;
            }
            $pipeline[$key] = $value['pct'];
        }

        return [
            'gpu' => $values['gpu']['pct'] ?? null,
            'vram' => $values['vram']['pct'] ?? null,
            'gtt' => $values['gtt']['pct'] ?? null,
            'vram_mb' => $values['vram']['mb'] ?? null,
            'gtt_mb' => $values['gtt']['mb'] ?? null,
            'pipeline' => $pipeline,
        ];
    }
}
