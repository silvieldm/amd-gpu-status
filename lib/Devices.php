<?php
declare(strict_types=1);

require_once __DIR__ . '/Sysfs.php';

/**
 * Discovers every DRM card on the machine and resolves its model name.
 *
 * radeontop itself has no "list devices" mode (only `-b <bus>`), so the list
 * comes from /sys/class/drm. A card counts as radeontop compatible when the
 * vendor is AMD (1002) and the kernel driver is amdgpu or radeon (R600 and up).
 *
 * The marketing name is not exposed by sysfs. Resolution order:
 *
 *   1. lib/cards.php override (BDF, then vendor:device, then device id)
 *   2. `lspci -nn -s <bdf>`, which uses the system pci.ids
 *   3. parsing /usr/share/hwdata/pci.ids directly
 *   4. "AMD 1002:7550" as a last resort
 */
final class Devices
{
    private const DRM = '/sys/class/drm';

    /** @var array<string,list<array<string,mixed>>> */
    private array $cache = [];

    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
    }

    /**
     * Every card, ascending bus, with its probe cache entry (or null).
     *
     * @return list<array<string,mixed>>
     */
    public function all(bool $mock = false): array
    {
        $key = $mock ? 'mock' : 'real';
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if ($mock) {
            return $this->cache[$key] = self::mockDevices();
        }

        $devices = [];
        foreach (glob(self::DRM . '/card[0-9]*/device/uevent') ?: [] as $uevent) {
            $device = $this->readDevice(dirname($uevent));
            if ($device === null) {
                continue;
            }
            $bus = $device['bus'];
            // A bus can expose more than one DRM card; keep the one with the
            // amdgpu clock interface, otherwise the first one seen.
            if (!isset($devices[$bus]) || is_file(dirname($uevent) . '/pp_dpm_sclk')) {
                $devices[$bus] = $device;
            }
        }

        uksort($devices, static fn (string $a, string $b): int => (int) hexdec($a) <=> (int) hexdec($b));

        $probes = $this->probes();
        $out = [];
        foreach ($devices as $bus => $device) {
            $device['probe'] = $probes[$bus] ?? null;
            $device['selected'] = false;
            $out[] = $device;
        }

        return $this->cache[$key] = $out;
    }

    /**
     * The catalogue the GPU panel shows: cards plus the current selection.
     *
     * @return list<array<string,mixed>>
     */
    public function catalog(bool $mock = false): array
    {
        $selected = $this->selection($mock);
        $devices = $this->all($mock);

        foreach ($devices as &$device) {
            $device['selected'] = in_array($device['bus'], $selected, true);
        }
        unset($device);

        return $devices;
    }

    /**
     * The buses that should be sampled.
     *
     * A stored selection wins, but only for cards that are still present and
     * compatible. Without a stored selection the configured default is used
     * verbatim, so a typo in config still renders a visible "no data" card.
     *
     * @return list<string>
     */
    public function selection(bool $mock = false): array
    {
        $saved = $this->savedSelection();
        if ($saved !== null) {
            $available = $this->compatibleBuses($mock);
            $valid = array_values(array_filter(
                $saved,
                static fn (string $bus): bool => in_array($bus, $available, true),
            ));

            return $valid !== [] ? $valid : $available;
        }

        $configured = $this->config['buses'] ?? [];
        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(
                static fn (mixed $bus): string => Sysfs::normBus((string) $bus),
                $configured,
            ));
        }

        return $this->compatibleBuses($mock);
    }

    /**
     * Validates and normalises a list of bus tokens against the detected cards.
     *
     * @param list<mixed> $buses
     * @return list<string>
     * @throws InvalidArgumentException when the list is empty or a token is not
     *                                  a present, compatible card
     */
    public function validate(array $buses, bool $mock = false): array
    {
        $available = $this->compatibleBuses($mock);
        $out = [];

        foreach ($buses as $bus) {
            if (!is_string($bus) && !is_int($bus)) {
                throw new InvalidArgumentException('invalid bus token');
            }
            $token = strtolower(trim((string) $bus));
            if (preg_match('/^(?:0x)?[0-9a-f]{1,2}$/', $token) !== 1) {
                throw new InvalidArgumentException('invalid bus token: ' . $token);
            }
            $token = Sysfs::normBus($token);
            if (!in_array($token, $available, true)) {
                throw new InvalidArgumentException('no radeontop-compatible GPU on bus ' . $token);
            }
            if (!in_array($token, $out, true)) {
                $out[] = $token;
            }
        }

        if ($out === []) {
            throw new InvalidArgumentException('select at least one GPU');
        }

        uasort($out, static fn (string $a, string $b): int => (int) hexdec($a) <=> (int) hexdec($b));

        return array_values($out);
    }

    /**
     * Stores the selection in data/selection.json, shared by all browsers.
     *
     * @param list<mixed> $buses
     * @return list<string> the normalised selection
     */
    public function saveSelection(array $buses, bool $mock = false): array
    {
        $selection = $this->validate($buses, $mock);
        $this->writeJson($this->dataDir() . '/selection.json', [
            'buses' => $selection,
            'ts' => (int) round(microtime(true) * 1000),
        ]);

        return $selection;
    }

    /** @return array<string,array{ok:bool,error:?string,ts:int}> */
    public function probes(): array
    {
        $file = $this->dataDir() . '/probe.json';
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $bus => $entry) {
            if (!is_array($entry) || !array_key_exists('ok', $entry)) {
                continue;
            }
            $out[(string) $bus] = [
                'ok' => (bool) $entry['ok'],
                'error' => isset($entry['error']) && is_string($entry['error']) ? $entry['error'] : null,
                'ts' => isset($entry['ts']) ? (int) $entry['ts'] : 0,
            ];
        }

        return $out;
    }

    public function saveProbe(string $bus, bool $ok, ?string $error): void
    {
        $bus = Sysfs::normBus($bus);
        $probes = $this->probes();
        $probes[$bus] = [
            'ok' => $ok,
            'error' => $error,
            'ts' => (int) round(microtime(true) * 1000),
        ];
        ksort($probes);

        $this->writeJson($this->dataDir() . '/probe.json', $probes);
    }

    /** Absolute path of lspci, or null when it is not installed. */
    public function lspciPath(): ?string
    {
        $binary = trim((string) ($this->config['lspci'] ?? ''));
        if ($binary === '' || !function_exists('shell_exec')) {
            return null;
        }
        if (str_contains($binary, '/')) {
            return is_executable($binary) ? $binary : null;
        }
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

        return $found === '' ? null : $found;
    }

    /** First readable pci.ids file from the configuration, or null. */
    public function pciIdsPath(): ?string
    {
        foreach ($this->pciIdsFiles() as $file) {
            if (is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------- discovery */

    /** @return array<string,mixed>|null */
    private function readDevice(string $dir): ?array
    {
        $uevent = @file_get_contents($dir . '/uevent');
        if ($uevent === false) {
            return null;
        }

        $values = [];
        foreach (preg_split('/\R/', $uevent) ?: [] as $line) {
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $values[substr($line, 0, $pos)] = substr($line, $pos + 1);
            }
        }

        $bdf = strtolower(trim($values['PCI_SLOT_NAME'] ?? ''));
        if (preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', $bdf) !== 1) {
            return null;
        }

        [$vendor, $device] = self::pciId($dir, $values['PCI_ID'] ?? '');
        $parts = explode(':', $bdf);
        $bus = Sysfs::normBus($parts[1] ?? '');

        $driver = strtolower(trim($values['DRIVER'] ?? ''));
        if ($driver === '') {
            $driver = strtolower(basename(trim((string) @readlink($dir . '/driver'))));
        }

        $vramBytes = self::number($dir . '/mem_info_vram_total');
        $vramMb = $vramBytes === null ? null : (int) round($vramBytes / 1048576);

        [$name, $nameSrc] = $this->resolveName($bdf, $vendor, $device);

        $compatible = $vendor === '1002' && in_array($driver, ['amdgpu', 'radeon'], true);
        $reason = null;
        if (!$compatible) {
            $reason = $vendor !== '1002'
                ? 'not an AMD card' . ($vendor !== '' && $device !== '' ? ' (' . strtoupper($vendor . ':' . $device) . ')' : '')
                : 'driver ' . ($driver !== '' ? $driver : 'unknown') . ' is not amdgpu/radeon';
        }

        return [
            'bus' => $bus,
            'bdf' => $bdf,
            'vendor_id' => $vendor !== '' ? $vendor : null,
            'device_id' => $device !== '' ? $device : null,
            'driver' => $driver !== '' ? $driver : null,
            'name' => $name,
            'name_src' => $nameSrc,
            'vram_total_mb' => $vramMb,
            'compatible' => $compatible,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{0:string,1:string} vendor and device id, lowercase
     */
    private static function pciId(string $dir, string $pciId): array
    {
        $pciId = strtolower(trim($pciId));
        if (preg_match('/^([0-9a-f]{4}):([0-9a-f]{4})$/', $pciId, $m) === 1) {
            return [$m[1], $m[2]];
        }

        $vendor = strtolower(trim((string) @file_get_contents($dir . '/vendor')));
        $device = strtolower(trim((string) @file_get_contents($dir . '/device')));
        $vendor = preg_replace('/^0x/', '', $vendor) ?? '';
        $device = preg_replace('/^0x/', '', $device) ?? '';

        return [$vendor, $device];
    }

    /** @return list<string> */
    private function compatibleBuses(bool $mock = false): array
    {
        $out = [];
        foreach ($this->all($mock) as $device) {
            if ($device['compatible'] === true) {
                $out[] = $device['bus'];
            }
        }

        return $out;
    }

    /* ----------------------------------------------------------- model names */

    /**
     * @return array{0:string,1:string} name and source
     */
    private function resolveName(string $bdf, string $vendor, string $device): array
    {
        $fallback = $vendor !== '' && $device !== ''
            ? sprintf('AMD %s:%s', strtoupper($vendor), strtoupper($device))
            : 'AMD GPU';

        if ($vendor === '' || $device === '') {
            return [$fallback, 'none'];
        }

        foreach ($this->overrides() as $key => $value) {
            if ($key === $bdf || $key === $vendor . ':' . $device || $key === $device) {
                return [$value, 'override'];
            }
        }

        $lspci = $this->lspciName($bdf);
        if ($lspci !== null && !self::isGeneric($lspci)) {
            return [$lspci, 'lspci'];
        }

        $ids = $this->pciIdsName($vendor, $device);
        if ($ids !== null && !self::isGeneric($ids)) {
            return [$ids, 'pci.ids'];
        }

        return [$fallback, 'none'];
    }

    /** @return array<string,string> lowercase key => marketing name */
    private function overrides(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $file = __DIR__ . '/cards.php';
        if (!is_file($file)) {
            return $cache = [];
        }

        $raw = require $file;
        if (!is_array($raw)) {
            return $cache = [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[strtolower(trim((string) $key))] = trim($value);
            }
        }

        return $cache = $out;
    }

    private function lspciName(string $bdf): ?string
    {
        $binary = $this->lspciPath();
        if ($binary === null) {
            return null;
        }

        static $names = [];
        $key = $binary . '|' . $bdf;
        if (array_key_exists($key, $names)) {
            return $names[$key];
        }

        $out = (string) shell_exec(escapeshellarg($binary) . ' -nn -s ' . escapeshellarg($bdf) . ' 2>/dev/null');

        return $names[$key] = self::shortName($out);
    }

    private function pciIdsName(string $vendor, string $device): ?string
    {
        static $names = [];
        $key = $vendor . ':' . $device;
        if (array_key_exists($key, $names)) {
            return $names[$key];
        }

        foreach ($this->pciIdsFiles() as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $raw = self::lookupPciIds($file, $vendor, $device);
            if ($raw !== null) {
                return $names[$key] = self::shortName($raw);
            }
        }

        return $names[$key] = null;
    }

    /** @return list<string> */
    private function pciIdsFiles(): array
    {
        $files = $this->config['pci_ids'] ?? [];
        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $files), static fn (string $f): bool => $f !== ''));
    }

    private static function lookupPciIds(string $file, string $vendor, string $device): ?string
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return null;
        }

        $inVendor = false;
        $result = null;

        while (($line = fgets($handle)) !== false) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $trimmed = rtrim($line, "\r\n");

            if ($line[0] !== "\t" && $line[0] !== ' ') {
                if ($inVendor) {
                    break;
                }
                if (preg_match('/^([0-9a-f]{4})\s{2,}(.+)$/i', $trimmed, $m) === 1) {
                    $inVendor = strtolower($m[1]) === strtolower($vendor);
                }
                continue;
            }

            if (!$inVendor) {
                continue;
            }

            // Device and subsystem lines are indented; only one tab is a device.
            if (preg_match('/^\t([0-9a-f]{4})\s{2,}(.+)$/i', $trimmed, $m) === 1
                && strtolower($m[1]) === strtolower($device)
            ) {
                $result = trim($m[2]);
                break;
            }
        }

        fclose($handle);

        return $result === null || $result === '' ? null : $result;
    }

    /**
     * Turns an lspci line or a pci.ids name into a short marketing name:
     *
     *   0000:03:00.0 VGA …: Advanced Micro Devices, Inc. [AMD/ATI] Navi 48 [Radeon RX 9070 XT] [1002:7550] (rev c0)
     *   Navi 48 [Radeon RX 9070 XT]
     *
     * both become "Radeon RX 9070 XT".
     */
    private static function shortName(string $raw): ?string
    {
        $line = trim((string) strtok($raw, "\n"));
        if ($line === '') {
            return null;
        }

        // Drop "<bdf> <class>: " from an lspci line.
        $name = preg_replace('/^\S+\s+[^:]*:\s*/', '', $line, 1) ?? $line;
        // Drop "(rev c0)" and the trailing [1002:7550].
        $name = preg_replace('/\s*\(rev [^)]*\)\s*$/i', '', $name) ?? $name;
        $name = preg_replace('/\s*\[[0-9a-f]{4}:[0-9a-f]{4}\]\s*$/i', '', $name) ?? $name;
        // Drop vendor tags.
        $name = str_replace(['[AMD/ATI]', '[AMD]', '[ATI]'], ' ', $name);
        $name = preg_replace('/^(?:Advanced Micro Devices, Inc\.?|Advanced Micro Devices|AMD\/ATI|AMD)\s*/i', '', $name) ?? $name;

        // Prefer a bracket that actually names the card: "Navi 48 [Radeon RX 9070 XT]".
        if (preg_match('/\[([^\]]*(?:Radeon|RX|PRO|FirePro|Instinct|Vega|Navi)[^\]]*)\]/i', $name, $m) === 1) {
            $name = $m[1];
        }

        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return $name === '' ? null : $name;
    }

    private static function isGeneric(string $name): bool
    {
        return preg_match('/^(?:AMD\s+)?Device\s+[0-9a-f]{4}$/i', $name) === 1;
    }

    /* ------------------------------------------------------------------ misc */

    /** @return list<string>|null */
    private function savedSelection(): ?array
    {
        $raw = @file_get_contents($this->dataDir() . '/selection.json');
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['buses'] ?? null)) {
            return null;
        }

        $buses = [];
        foreach ($data['buses'] as $bus) {
            if (is_string($bus) || is_int($bus)) {
                $buses[] = Sysfs::normBus((string) $bus);
            }
        }

        return $buses === [] ? null : array_values(array_unique($buses));
    }

    private function dataDir(): string
    {
        $dir = (string) ($this->config['data_dir'] ?? __DIR__ . '/../data');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('could not encode ' . basename($file));
        }

        // Write then rename so a concurrent reader never sees half a file.
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('could not write ' . basename($file));
        }
    }

    private static function number(string $file): ?float
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /** @return list<array<string,mixed>> */
    private static function mockDevices(): array
    {
        $now = (int) round(microtime(true) * 1000);

        return [
            [
                'bus' => '3',
                'bdf' => '0000:03:00.0',
                'vendor_id' => '1002',
                'device_id' => '7550',
                'driver' => 'amdgpu',
                'name' => 'Radeon RX 9070 XT',
                'name_src' => 'mock',
                'vram_total_mb' => 16384,
                'compatible' => true,
                'reason' => null,
                'probe' => ['ok' => true, 'error' => null, 'ts' => $now],
                'selected' => false,
            ],
            [
                'bus' => '9',
                'bdf' => '0000:09:00.0',
                'vendor_id' => '1002',
                'device_id' => '7590',
                'driver' => 'amdgpu',
                'name' => 'Radeon RX 9060 XT',
                'name_src' => 'mock',
                'vram_total_mb' => 12288,
                'compatible' => true,
                'reason' => null,
                'probe' => ['ok' => true, 'error' => null, 'ts' => $now],
                'selected' => false,
            ],
        ];
    }
}
