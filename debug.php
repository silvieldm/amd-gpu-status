<?php
declare(strict_types=1);

/**
 * Diagnostics: what the driver and radeontop really report.
 *
 * Open /debug.php on the target machine and compare the raw values with the
 * tiles on the dashboard. Read-only, no configuration, no writes.
 */

$config = require __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/Sysfs.php';
require_once __DIR__ . '/lib/Radeontop.php';
require_once __DIR__ . '/lib/Devices.php';

$sys = new Sysfs();
$bdfs = $sys->bdfs();
$devices = new Devices($config);
$catalog = $devices->all();
$buses = $devices->selection();

$radeontop = new Radeontop(
    (string) $config['radeontop'],
    (bool) $config['allow_sudo'],
    (int) $config['timeout'],
);

$binaryPath = trim((string) shell_exec('command -v ' . escapeshellarg((string) $config['radeontop']) . ' 2>/dev/null'));
$who = function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid())
    : get_current_user();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}

function watt(string $micro): string
{
    if (!is_numeric($micro)) {
        return $micro;
    }

    return sprintf('%s µW = %.1f W', number_format((float) $micro, 0, '.', ''), (float) $micro / 1_000_000);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AMD GPU Status · diagnostics</title>
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0; padding: 24px clamp(14px, 3vw, 32px) 48px;
            background: #101317; color: #e8edf3;
            font: 14px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        h2 { font-size: 1rem; margin: 28px 0 8px; color: #f5a623; }
        p.lead { margin: 0 0 18px; color: #93a1b1; }
        table { border-collapse: collapse; width: 100%; max-width: 900px; }
        th, td { text-align: left; padding: 5px 10px 5px 0; border-bottom: 1px solid #2b3542; vertical-align: top; }
        th { color: #93a1b1; font-weight: 500; width: 260px; }
        table.cards th { width: auto; white-space: nowrap; }
        code, pre { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.82rem; }
        pre { background: #161b21; border: 1px solid #2b3542; border-radius: 8px; padding: 10px 12px; overflow-x: auto; margin: 6px 0 0; }
        .ok { color: #4caf7d; }
        .bad { color: #e2605a; }
        .muted { color: #93a1b1; }
        .warning { border-left: 3px solid #e2605a; padding-left: 10px; margin: 10px 0; }
    </style>
</head>
<body>
<h1>AMD GPU Status · diagnostics</h1>
<p class="lead">Raw values as read by PHP. Nothing here is cached or transformed except the watt conversion.</p>

<h2>Environment</h2>
<table>
    <tr><th>PHP</th><td><code><?= e(PHP_VERSION) ?></code> (<?= e(PHP_SAPI) ?>)</td></tr>
    <tr><th>PHP runs as</th><td><code><?= e((string) $who) ?></code></td></tr>
    <tr><th>radeontop</th><td>
        <?php if ($binaryPath !== ''): ?>
            <span class="ok">found</span> <code><?= e($binaryPath) ?></code>
        <?php else: ?>
            <span class="bad">not found in PATH</span> <span class="muted">(configured: <code><?= e((string) $config['radeontop']) ?></code>)</span>
        <?php endif; ?>
    </td></tr>
    <tr><th>Configured buses</th><td><code><?= e(implode(', ', array_map('strval', (array) ($config['buses'] ?? []))) ?: 'auto') ?></code> <span class="muted">default when no selection is stored</span></td></tr>
    <tr><th>Selected buses</th><td><code><?= e(implode(', ', $buses) ?: 'none') ?></code> <span class="muted">from <code>data/selection.json</code></span></td></tr>
    <tr><th>lspci</th><td>
        <?php $lspciPath = $devices->lspciPath(); ?>
        <?php if ($lspciPath !== null): ?>
            <span class="ok">found</span> <code><?= e($lspciPath) ?></code>
        <?php else: ?>
            <span class="bad">not found</span> <span class="muted">model names fall back to pci.ids / overrides</span>
        <?php endif; ?>
    </td></tr>
    <tr><th>pci.ids</th><td>
        <?php $idsPath = $devices->pciIdsPath(); ?>
        <?php if ($idsPath !== null): ?>
            <span class="ok">found</span> <code><?= e($idsPath) ?></code>
        <?php else: ?>
            <span class="bad">not found</span> <span class="muted">add names in <code>lib/cards.php</code></span>
        <?php endif; ?>
    </td></tr>
    <tr><th>Detected cards</th><td>
        <?php if ($bdfs === []): ?>
            <span class="bad">none below /sys/class/drm</span>
        <?php else: ?>
            <code><?= e(implode(', ', array_map(
                static fn (string $bus, string $bdf): string => 'bus ' . $bus . ' = ' . $bdf,
                array_keys($bdfs),
                array_values($bdfs),
            ))) ?></code>
        <?php endif; ?>
    </td></tr>
</table>

<h2>Cards · model names and compatibility</h2>
<?php if ($catalog === []): ?>
    <p class="warning">No DRM card below <code>/sys/class/drm</code>. Is the amdgpu/radeon module loaded?</p>
<?php else: ?>
    <table class="cards">
        <tr>
            <th>bus / slot</th>
            <th>id</th>
            <th>driver</th>
            <th>model name</th>
            <th>VRAM</th>
            <th>radeontop</th>
            <th>probe</th>
        </tr>
        <?php foreach ($catalog as $card): ?>
            <tr>
                <td><code>bus <?= e((string) $card['bus']) ?></code><br><span class="muted"><code><?= e((string) $card['bdf']) ?></code></span></td>
                <td><code><?= e(strtoupper((string) ($card['vendor_id'] ?? '?') . ':' . (string) ($card['device_id'] ?? '?'))) ?></code></td>
                <td><code><?= e((string) ($card['driver'] ?? '?')) ?></code></td>
                <td>
                    <?= e((string) ($card['name'] ?? '?')) ?>
                    <span class="muted">(<?= e((string) ($card['name_src'] ?? '?')) ?>)</span>
                </td>
                <td><?= $card['vram_total_mb'] === null ? '<span class="muted">n/a</span>' : e(number_format((float) $card['vram_total_mb'] / 1024, 0) . ' GB') ?></td>
                <td><?= $card['compatible'] ? '<span class="ok">yes</span>' : '<span class="bad">no</span> <span class="muted">' . e((string) ($card['reason'] ?? '')) . '</span>' ?></td>
                <td>
                    <?php $probe = $card['probe'] ?? null; ?>
                    <?php if ($probe === null): ?>
                        <span class="muted">not tested</span>
                    <?php elseif ($probe['ok']): ?>
                        <span class="ok">ok</span> <span class="muted"><?= e(date('Y-m-d H:i:s', (int) ($probe['ts'] / 1000))) ?></span>
                    <?php else: ?>
                        <span class="bad">failed</span> <span class="muted"><?= e((string) ($probe['error'] ?? '')) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php foreach ($buses as $bus): ?>
    <?php $raw = $sys->raw($bus); ?>
    <h2>bus <?= e($bus) ?></h2>

    <?php if (($raw['device'] ?? null) === null): ?>
        <p class="warning">No device in <code>/sys/class/drm</code> for this bus. Check the bus token in <code>lib/config.php</code>.</p>
    <?php else: ?>
        <table>
            <tr><th>device</th><td><code><?= e((string) $raw['device']) ?></code></td></tr>
            <tr><th>PCI slot</th><td><code><?= e((string) ($raw['bdf'] ?? '?')) ?></code></td></tr>
            <tr><th>driver</th><td><code><?= e((string) ($raw['driver'] ?? '?')) ?></code></td></tr>
            <tr><th>gpu_busy_percent</th><td><code><?= e((string) ($raw['device_values']['gpu_busy_percent'] ?? 'n/a')) ?></code></td></tr>
            <tr><th>mem_busy_percent</th><td><code><?= e((string) ($raw['device_values']['mem_busy_percent'] ?? 'n/a')) ?></code></td></tr>
            <tr><th>VRAM total / used</th><td><code><?= e((string) ($raw['device_values']['mem_info_vram_total'] ?? 'n/a')) ?> / <?= e((string) ($raw['device_values']['mem_info_vram_used'] ?? 'n/a')) ?></code> <span class="muted">bytes</span></td></tr>
        </table>
    <?php endif; ?>

    <?php foreach (($raw['hwmon'] ?? []) as $hwmon): ?>
        <p class="muted" style="margin:12px 0 0;font-size:.78rem">hwmon: <code><?= e((string) $hwmon['path']) ?></code></p>
        <table>
            <?php foreach ($hwmon['values'] as $name => $value): ?>
                <tr>
                    <th><code><?= e((string) $name) ?></code></th>
                    <td><code><?= str_starts_with((string) $name, 'power') ? watt((string) $value) : e((string) $value) ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($hwmon['values'] === []): ?>
                <tr><td class="muted">no power files in this hwmon device</td></tr>
            <?php endif; ?>
        </table>
    <?php endforeach; ?>

    <table>
        <tr><th>pp_dpm_sclk</th><td><pre><?= e((string) ($raw['sclk'] ?? 'n/a')) ?></pre></td></tr>
        <tr><th>pp_dpm_mclk</th><td><pre><?= e((string) ($raw['mclk'] ?? 'n/a')) ?></pre></td></tr>
    </table>

    <table>
        <tr><th>radeontop available</th><td><?= $radeontop->available() ? '<span class="ok">yes</span>' : '<span class="bad">no</span>' ?></td></tr>
        <?php $sample = $radeontop->available() ? $radeontop->sample($bus) : null; ?>
        <tr><th>radeontop ok</th><td><?= $sample && $sample['ok'] ? '<span class="ok">yes</span>' : '<span class="bad">no</span>' ?> <?= $sample && $sample['error'] ? '<span class="muted">' . e((string) $sample['error']) . '</span>' : '' ?></td></tr>
        <?php if ($sample !== null): ?>
            <tr><th>parsed values</th><td><pre><?= e((string) json_encode($sample['values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></td></tr>
            <tr><th>raw output</th><td><pre><?= e(trim($sample['raw']) ?: '(empty)') ?></pre></td></tr>
        <?php endif; ?>
    </table>
<?php endforeach; ?>

<p class="muted" style="margin-top:28px;max-width:900px">
    Both <code>power1_input</code> and <code>power1_average</code> are board power in microwatt and
    include VRAM, memory controller and display output. On RDNA2/RDNA3 cards 20–30 W idle is
    normal, especially with a connected display or high refresh rate.
</p>
</body>
</html>
