<?php
declare(strict_types=1);

/**
 * Reads clocks, power and VRAM information from the amdgpu sysfs interface.
 *
 * radeontop only reports utilisation percentages. Clocks and wattage come from
 * the driver:
 *
 *   /sys/class/drm/cardN/device/uevent              -> PCI_SLOT_NAME
 *   /sys/class/drm/cardN/device/pp_dpm_sclk         -> clock levels, active one marked with *
 *   /sys/class/drm/cardN/device/pp_dpm_mclk
 *   /sys/class/drm/cardN/device/hwmon/hwmonX/power1_average  (microwatt)
 *   /sys/class/drm/cardN/device/mem_info_vram_total / mem_info_vram_used
 *   /sys/class/drm/cardN/device/gpu_busy_percent / mem_busy_percent
 *
 * Every value is optional: a missing file simply yields null and the UI hides it.
 */
final class Sysfs
{
    private const DRM = '/sys/class/drm';

    /** @var array<string,string> hex bus => card device directory */
    private array $cards = [];

    public function __construct()
    {
        $this->cards = self::discover();
    }

    /**
     * @return array<string,string> hex bus number (lowercase) => device path
     */
    public static function discover(): array
    {
        $cards = [];
        foreach (glob(self::DRM . '/card[0-9]*/device/uevent') ?: [] as $uevent) {
            $slot = self::pciSlot($uevent);
            if ($slot === null) {
                continue;
            }
            // PCI_SLOT_NAME=0000:03:00.0 -> bus 03
            $parts = explode(':', $slot);
            if (count($parts) < 2) {
                continue;
            }
            $bus = strtolower(ltrim($parts[1], '0')) ?: '0';
            $device = dirname($uevent);
            // Prefer a card that exposes the amdgpu clock interface.
            if (!isset($cards[$bus]) || is_file($device . '/pp_dpm_sclk')) {
                $cards[$bus] = $device;
            }
        }

        return $cards;
    }

    private static function pciSlot(string $uevent): ?string
    {
        $content = @file_get_contents($uevent);
        if ($content === false) {
            return null;
        }
        if (preg_match('/^PCI_SLOT_NAME=(\S+)$/m', $content, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** @return array<string,string> bus => bdf, for the UI */
    public function bdfs(): array
    {
        $out = [];
        foreach ($this->cards as $bus => $dir) {
            $out[$bus] = self::pciSlot($dir . '/uevent') ?? ('bus ' . $bus);
        }

        return $out;
    }

    public function has(string $bus): bool
    {
        return isset($this->cards[self::normBus($bus)]);
    }

    /**
     * @return array{
     *   clock_gpu:?int, clock_mem:?int, power_w:?float, power_src:?string,
     *   vram_total_mb:?int, vram_used_mb:?int,
     *   busy_gpu:?float, busy_mem:?float
     * }
     */
    public function read(string $bus): array
    {
        $bus = self::normBus($bus);
        $dir = $this->cards[$bus] ?? null;

        if ($dir === null) {
            return self::empty();
        }

        $vramTotal = self::number($dir . '/mem_info_vram_total');
        $vramUsed = self::number($dir . '/mem_info_vram_used');
        [$power, $powerSrc] = self::power($dir);

        return [
            'clock_gpu' => self::activeClock($dir . '/pp_dpm_sclk'),
            'clock_mem' => self::activeClock($dir . '/pp_dpm_mclk'),
            'power_w' => $power,
            'power_src' => $powerSrc,
            'vram_total_mb' => $vramTotal === null ? null : (int) round($vramTotal / 1048576),
            'vram_used_mb' => $vramUsed === null ? null : (int) round($vramUsed / 1048576),
            'busy_gpu' => self::number($dir . '/gpu_busy_percent'),
            'busy_mem' => self::number($dir . '/mem_busy_percent'),
        ];
    }

    /** @return array<string,mixed> */
    private static function empty(): array
    {
        return [
            'clock_gpu' => null,
            'clock_mem' => null,
            'power_w' => null,
            'power_src' => null,
            'vram_total_mb' => null,
            'vram_used_mb' => null,
            'busy_gpu' => null,
            'busy_mem' => null,
        ];
    }

    public static function normBus(string $bus): string
    {
        $bus = strtolower(trim($bus));
        if (str_starts_with($bus, '0x')) {
            $bus = substr($bus, 2);
        }

        return ltrim($bus, '0') ?: '0';
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

    /**
     * Parses a DPM table such as:
     *   0: 500Mhz
     *   1: 1600Mhz *
     * and returns the active level in MHz.
     */
    private static function activeClock(string $file): ?int
    {
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $fallback = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*Mhz/i', $line, $m) !== 1) {
                continue;
            }
            $mhz = (int) round((float) $m[1]);
            $fallback = $fallback ?? $mhz;
            if (str_contains($line, '*')) {
                return $mhz;
            }
        }

        return $fallback;
    }

    /**
     * Returns the board power in watt plus the sensor that produced it.
     *
     * amdgpu reports both files in microwatt. `power1_input` is the current
     * value, `power1_average` a smoothed one that can still contain a load
     * spike from the last seconds, so input wins when both exist.
     *
     * @return array{0:?float,1:?string}
     */
    private static function power(string $deviceDir): array
    {
        $candidates = [];
        foreach (glob($deviceDir . '/hwmon/hwmon[0-9]*') ?: [] as $hwmon) {
            $candidates[] = [$hwmon . '/power1_input', 'power1_input'];
            $candidates[] = [$hwmon . '/power1_average', 'power1_average'];
        }

        foreach ($candidates as [$file, $label]) {
            $micro = self::number($file);
            if ($micro === null || $micro <= 0) {
                continue;
            }

            return [round($micro / 1_000_000, 1), $label];
        }

        return [null, null];
    }

    /**
     * Raw sensor values for the diagnostics page, keyed by file name.
     *
     * @return array<string,mixed>
     */
    public function raw(string $bus): array
    {
        $bus = self::normBus($bus);
        $dir = $this->cards[$bus] ?? null;
        if ($dir === null) {
            return ['device' => null];
        }

        $files = [
            'power1_input',
            'power1_average',
            'power1_cap',
            'gpu_busy_percent',
            'mem_busy_percent',
            'mem_info_vram_total',
            'mem_info_vram_used',
        ];

        $hwmon = [];
        foreach (glob($dir . '/hwmon/hwmon[0-9]*') ?: [] as $hwmonDir) {
            $values = [];
            foreach ($files as $name) {
                $raw = @file_get_contents($hwmonDir . '/' . $name);
                if ($raw === false) {
                    continue;
                }
                $values[$name] = trim($raw);
            }
            $hwmon[] = ['path' => $hwmonDir, 'values' => $values];
        }

        $device = [];
        foreach ($files as $name) {
            $raw = @file_get_contents($dir . '/' . $name);
            if ($raw !== false) {
                $device[$name] = trim($raw);
            }
        }

        return [
            'device' => $dir,
            'bdf' => self::pciSlot($dir . '/uevent'),
            'driver' => trim((string) @readlink($dir . '/driver')),
            'device_values' => $device,
            'hwmon' => $hwmon,
            'sclk' => trim((string) @file_get_contents($dir . '/pp_dpm_sclk')),
            'mclk' => trim((string) @file_get_contents($dir . '/pp_dpm_mclk')),
        ];
    }
}
