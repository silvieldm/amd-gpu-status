<?php
declare(strict_types=1);

/**
 * Fake data for `?mock=1`: lets you look at the dashboard without radeontop
 * or an AMD GPU. Values move slowly so the sparkline shows something.
 */
final class Mock
{
    /**
     * Share of load at a point in time, 0..1. Two overlapping sines keep the
     * curve from looking like a pure sawtooth.
     */
    public static function load(float $t, string $bus): float
    {
        $seed = (float) (hexdec(Sysfs::normBus($bus)) ?: 1);
        $value = 0.5
            + 0.27 * sin($t * 0.018 * 2 * M_PI + $seed * 1.7)
            + 0.17 * sin($t * 0.0055 * 2 * M_PI + $seed * 2.4);

        return max(0.0, min(1.0, $value));
    }

    public static function radeontopDump(string $bus): string
    {
        $t = microtime(true);
        $load = self::load($t, $bus);

        $gpu = 15 + $load * 60;
        $vram = 35 + $load * 30;
        $totalMb = self::vramTotal($bus);

        $blocks = [
            'ee' => 0.00, 'vgt' => $gpu * 0.31, 'ta' => $gpu * 0.12, 'tc' => $gpu * 0.08,
            'sx' => $gpu * 0.07, 'sh' => $gpu * 0.42, 'spi' => $gpu * 0.19, 'smx' => $gpu * 0.28,
            'cr' => 0.00, 'sc' => 0.00, 'pa' => $gpu * 0.11, 'db' => $gpu * 0.06, 'cb' => $gpu * 0.14,
        ];

        $parts = [sprintf('gpu %.2f%%', $gpu)];
        foreach ($blocks as $name => $value) {
            $parts[] = sprintf('%s %.2f%%', $name, $value);
        }
        $parts[] = sprintf('vram %.2f%% %.2fmb', $vram, $totalMb * $vram / 100);
        $parts[] = sprintf('gtt %.2f%% %.2fmb', 0.2, 15.0);
        $parts[] = 'vis 0.00% 0.00mb';

        return sprintf('%s %s', self::bdf($bus), implode(', ', $parts));
    }

    /**
     * @return array<string,mixed>
     */
    public static function sysfsValues(string $bus): array
    {
        $t = microtime(true);
        $load = self::load($t, $bus);
        $totalMb = self::vramTotal($bus);

        return [
            'clock_gpu' => (int) round(800 + $load * 1700),
            'clock_mem' => (int) round(400 + $load * 650),
            'power_w' => round(16 + $load * 230, 1),
            'power_src' => 'mock',
            'vram_total_mb' => $totalMb,
            'vram_used_mb' => (int) round($totalMb * (0.35 + $load * 0.30)),
            'busy_gpu' => round($load * 100, 1),
            'busy_mem' => round($load * 60, 1),
            'mock' => true,
        ];
    }

    public static function vramTotal(string $bus): int
    {
        return match (Sysfs::normBus($bus)) {
            '3' => 16384,
            '9' => 12288,
            default => 2048,
        };
    }

    public static function bdf(string $bus): string
    {
        return sprintf('0000:%02x:00.0', (int) hexdec(Sysfs::normBus($bus)));
    }
}
