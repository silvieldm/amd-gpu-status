<?php
declare(strict_types=1);

require_once __DIR__ . '/Sysfs.php';
require_once __DIR__ . '/Radeontop.php';
require_once __DIR__ . '/Devices.php';
require_once __DIR__ . '/Mock.php';
require_once __DIR__ . '/Store.php';

/**
 * Collects one measurement for every configured GPU.
 */
final class Sampler
{
    private Radeontop $radeontop;

    private Store $store;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
        $this->radeontop = new Radeontop(
            (string) $config['radeontop'],
            (bool) $config['allow_sudo'],
            (int) $config['timeout'],
        );
        $this->store = new Store((string) $config['data_dir'], (int) $config['history_limit']);
    }

    /**
     * @param list<string>|null $buses explicit selection; null uses the stored one
     * @return array<string,mixed>
     */
    public function collect(bool $mock = false, bool $persist = true, ?array $buses = null): array
    {
        $started = microtime(true);
        $sysfs = new Sysfs();
        $devices = new Devices($this->config);

        $buses = $buses === null
            ? $devices->selection($mock)
            : $devices->validate($buses, $mock);

        $catalog = [];
        foreach ($devices->all($mock) as $device) {
            $catalog[$device['bus']] = $device;
        }

        $bdfs = $sysfs->bdfs();

        $gpus = [];
        $history = [];

        foreach ($buses as $index => $bus) {
            $bus = Sysfs::normBus($bus);
            $info = $catalog[$bus] ?? null;
            $warnings = [];

            $sysValues = $mock ? Mock::sysfsValues($bus) : $sysfs->read($bus);
            $parsed = $mock
                ? Radeontop::parse(Mock::radeontopDump($bus))
                : [];
            $rt = null;

            if (!$mock) {
                $rt = $this->radeontop->sample($bus);
                $parsed = $rt['values'];
                if (!$rt['ok']) {
                    $warnings[] = (string) $rt['error'];
                }
            }

            $summary = Radeontop::summarize($parsed);

            $utilGpu = $summary['gpu'] ?? $sysValues['busy_gpu'];
            $utilVram = $summary['vram'] ?? $sysValues['busy_mem'];

            // Prefer radeontop's megabyte figures, fall back to sysfs.
            $vramUsed = $summary['vram_mb'] ?? $sysValues['vram_used_mb'];
            $vramTotal = $sysValues['vram_total_mb'];

            $gpus[] = [
                'index' => $index,
                'bus' => $bus,
                'bdf' => $info['bdf'] ?? $bdfs[$bus] ?? ($mock ? Mock::bdf($bus) : null),
                'name' => $info['name'] ?? null,
                'name_src' => $info['name_src'] ?? null,
                'vendor_id' => $info['vendor_id'] ?? null,
                'device_id' => $info['device_id'] ?? null,
                'driver' => $info['driver'] ?? null,
                'compatible' => $info['compatible'] ?? null,
                'util_gpu' => $utilGpu === null ? null : round((float) $utilGpu, 1),
                'util_vram' => $utilVram === null ? null : round((float) $utilVram, 1),
                'util_gtt' => $summary['gtt'],
                'vram_used_mb' => $vramUsed === null ? null : (int) round((float) $vramUsed),
                'vram_total_mb' => $vramTotal,
                'clock_gpu' => $sysValues['clock_gpu'],
                'clock_mem' => $sysValues['clock_mem'],
                'power_w' => $sysValues['power_w'],
                'power_src' => $sysValues['power_src'] ?? null,
                'pipeline' => $summary['pipeline'],
                'source' => $mock ? 'mock' : ($summary['gpu'] !== null ? 'radeontop' : ($utilGpu !== null ? 'sysfs' : 'none')),
                'warnings' => $warnings,
            ];

            $point = self::point($gpus[$index]);
            if ($persist && $point !== null) {
                $this->store->append($bus, $point);
            }

            if ($mock) {
                $history[$bus] = $this->fakeHistory($bus, $point, (int) $this->config['history_points']);
            } else {
                $history[$bus] = $this->store->recent($bus, (int) $this->config['history_points']);
                if ($point !== null && ($history[$bus] === [] || end($history[$bus])['t'] !== $point['t'])) {
                    $history[$bus][] = $point;
                }
            }
        }

        return [
            'ok' => $gpus !== [] && array_filter($gpus, static fn (array $g): bool => $g['source'] !== 'none') !== [],
            'mock' => $mock,
            'host' => gethostname() ?: null,
            'ts' => (int) round(microtime(true) * 1000),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'gpus' => $gpus,
            'history' => $history,
        ];
    }

    /**
     * Runs one radeontop window per bus and remembers the result in
     * data/probe.json, so the GPU panel can show which cards really answer.
     *
     * @param list<string> $buses
     * @return array<string,array{ok:bool,error:?string,ts:int}>
     */
    public function probe(array $buses, bool $mock = false): array
    {
        $devices = new Devices($this->config);
        $buses = $devices->validate($buses, $mock);

        $out = [];
        foreach ($buses as $bus) {
            $now = (int) round(microtime(true) * 1000);

            if ($mock) {
                $out[$bus] = ['ok' => true, 'error' => null, 'ts' => $now];
                continue;
            }

            $rt = $this->radeontop->sample($bus);
            $result = [
                'ok' => (bool) $rt['ok'],
                'error' => $rt['ok'] ? null : ($rt['error'] ?? 'no parsable output'),
                'ts' => $now,
            ];
            $devices->saveProbe($bus, $result['ok'], $result['error']);
            $out[$bus] = $result;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $gpu
     * @return array<string,mixed>|null
     */
    private static function point(array $gpu): ?array
    {
        if ($gpu['util_gpu'] === null && $gpu['util_vram'] === null) {
            return null;
        }

        return [
            't' => (int) round(microtime(true) * 1000),
            'gpu' => $gpu['util_gpu'],
            'vram' => $gpu['util_vram'],
            'sclk' => $gpu['clock_gpu'],
            'mclk' => $gpu['clock_mem'],
            'w' => $gpu['power_w'],
        ];
    }

    /**
     * Synthesises a plausible looking history so the mock view has a chart.
     *
     * @param array<string,mixed>|null $last
     * @return list<array<string,mixed>>
     */
    private function fakeHistory(string $bus, ?array $last, int $count): array
    {
        if ($last === null) {
            return [];
        }

        $out = [];
        $step = 2000;
        $now = $last['t'];

        for ($i = $count - 1; $i >= 0; $i--) {
            $t = $now - $i * $step;
            $load = Mock::load($t / 1000, $bus);
            $out[] = [
                't' => $t,
                'gpu' => round(15 + $load * 60, 1),
                'vram' => round(35 + $load * 30, 1),
            ];
        }

        $out[count($out) - 1] = $last;

        return $out;
    }
}
