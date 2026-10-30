<?php
declare(strict_types=1);

$config = require __DIR__ . '/lib/config.php';
$title = (string) $config['title'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="identity">
        <h1><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
        <p class="sub" id="host">amdgpu · radeontop</p>
    </div>
    <div class="actions">
        <button type="button" id="devices-toggle" class="button ghost" aria-expanded="false" aria-controls="devices">GPUs</button>
        <label class="refresh-select" title="Refresh automatically">
            <span>auto</span>
            <select id="interval" aria-label="Auto refresh interval">
                <option value="0">off</option>
                <option value="1">1 s</option>
                <option value="5">5 s</option>
                <option value="10">10 s</option>
                <option value="30">30 s</option>
                <option value="60">60 s</option>
            </select>
        </label>
        <button type="button" id="refresh" class="button">Refresh</button>
    </div>
</header>

<section id="devices" class="devices" hidden aria-label="GPU selection">
    <div class="devices-top">
        <div class="devices-title">
            <h2>GPUs on this host</h2>
            <p id="devices-hint" class="devices-hint">Selection is stored on the server and shared by every browser.</p>
        </div>
        <button type="button" id="devices-close" class="icon-button" aria-label="Close GPU panel">×</button>
    </div>
    <div id="devices-list" class="devices-list" aria-live="polite">
        <p class="devices-empty">Loading cards…</p>
    </div>
    <div class="devices-actions">
        <button type="button" id="devices-check" class="button ghost">Check with radeontop</button>
        <span class="spacer"></span>
        <button type="button" id="devices-apply" class="button">Apply</button>
    </div>
</section>

<main id="grid" class="grid" aria-live="polite">
    <section class="card skeleton" aria-hidden="true"></section>
    <section class="card skeleton" aria-hidden="true"></section>
</main>

<noscript>
    <p class="notice">This dashboard needs JavaScript: values are fetched from <code>api.php</code> and drawn in the browser.</p>
</noscript>

<footer class="statusbar">
    <span id="status">waiting for first sample…</span>
    <span class="spacer"></span>
    <span id="timing" class="muted"></span>
</footer>

<script src="assets/app.js"></script>
</body>
</html>
