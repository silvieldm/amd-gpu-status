'use strict';

/**
 * AMD GPU Status dashboard.
 *
 * One request to api.php per refresh, everything is drawn from the JSON
 * response. No external libraries, no CDN.
 */

const MOCK = new URLSearchParams(location.search).has('mock');

/** Selectable auto refresh intervals in seconds; 0 means off. */
const INTERVALS = [0, 1, 5, 10, 30, 60];
const INTERVAL_STORAGE = 'gpu-status-interval';

const els = {
    grid: document.getElementById('grid'),
    status: document.getElementById('status'),
    timing: document.getElementById('timing'),
    refresh: document.getElementById('refresh'),
    interval: document.getElementById('interval'),
    devicesToggle: document.getElementById('devices-toggle'),
    devices: document.getElementById('devices'),
    devicesList: document.getElementById('devices-list'),
    devicesHint: document.getElementById('devices-hint'),
    devicesApply: document.getElementById('devices-apply'),
    devicesCheck: document.getElementById('devices-check'),
    devicesClose: document.getElementById('devices-close'),
};

const state = {
    busy: false,
    pending: false,
    timer: null,
    last: null,
    devices: null,
};

const PIPELINE_LABELS = {
    ee: 'EE',
    vgt: 'VGT',
    ta: 'TA',
    tc: 'TC',
    sx: 'SX',
    sh: 'SH',
    spi: 'SPI',
    smx: 'SMX',
    cr: 'CR',
    sc: 'SC',
    pa: 'PA',
    db: 'DB',
    cb: 'CB',
};

/* ------------------------------------------------------------------ helpers */

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[c]));
}

function num(value, digits = 1) {
    return typeof value === 'number' && Number.isFinite(value)
        ? value.toFixed(digits)
        : null;
}

function pct(value) {
    const v = num(value);
    return v === null ? '—' : `${v}%`;
}

function mhz(value) {
    return typeof value === 'number' && value > 0 ? `${Math.round(value)}` : null;
}

function watts(value) {
    const v = num(value);
    return v === null ? null : v;
}

function bytesLabel(mb) {
    if (typeof mb !== 'number' || !Number.isFinite(mb)) {
        return null;
    }
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`;
}

/** Compact capacity for the model line: 16384 MB -> "16 GB". */
function memorySize(mb) {
    if (typeof mb !== 'number' || !Number.isFinite(mb) || mb <= 0) {
        return null;
    }
    const gb = mb / 1024;
    return `${Number.isInteger(gb) ? gb : gb.toFixed(1)} GB`;
}

/** "Radeon RX 9070 XT · 16 GB", or '' when neither is known. */
function gpuTitle(gpu) {
    const name = typeof gpu.name === 'string' && gpu.name.trim() !== '' ? gpu.name.trim() : null;
    const mem = memorySize(gpu.vram_total_mb);
    return [name, mem].filter(Boolean).join(' · ');
}

function clockTime(ms) {
    if (!ms) {
        return '—';
    }
    return new Date(ms).toLocaleTimeString([], { hour12: false });
}

/* --------------------------------------------------------------- sparkline */

function sparkline(points, id) {
    if (!Array.isArray(points) || points.length < 2) {
        return '<div class="spark"><div class="spark-fallback">history builds up with every refresh</div></div>';
    }

    const W = 100;
    const H = 30;
    const n = points.length;
    const y = (v) => {
        const clamped = Math.max(0, Math.min(100, v));
        return (H - 1) - (clamped / 100) * (H - 2);
    };
    const x = (i) => (i / (n - 1)) * W;

    const gpu = [];
    const vram = [];
    points.forEach((p, i) => {
        if (typeof p.gpu === 'number') gpu.push([x(i), y(p.gpu)]);
        if (typeof p.vram === 'number') vram.push([x(i), y(p.vram)]);
    });

    if (gpu.length < 2 && vram.length < 2) {
        return '<div class="spark"><div class="spark-fallback">no numeric history yet</div></div>';
    }

    const path = (list) => list
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${p[0].toFixed(2)} ${p[1].toFixed(2)}`)
        .join(' ');

    const area = gpu.length >= 2
        ? `${path(gpu)} L${W} ${H} L0 ${H} Z`
        : '';

    return `
    <div class="spark">
      <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="utilisation history">
        <defs>
          <linearGradient id="gpu-fill-${id}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#f5a623" stop-opacity="0.34"/>
            <stop offset="100%" stop-color="#f5a623" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <line x1="0" y1="${y(50).toFixed(2)}" x2="${W}" y2="${y(50).toFixed(2)}"
              stroke="#2b3542" stroke-width="0.3" stroke-dasharray="1.6 1.6"
              vector-effect="non-scaling-stroke"/>
        ${area ? `<path d="${area}" fill="url(#gpu-fill-${id})"/>` : ''}
        ${gpu.length >= 2 ? `<path d="${path(gpu)}" fill="none" stroke="#f5a623" stroke-width="1.4"
              stroke-linejoin="round" vector-effect="non-scaling-stroke"/>` : ''}
        ${vram.length >= 2 ? `<path d="${path(vram)}" fill="none" stroke="#4fc3f7" stroke-width="1.1"
              stroke-linejoin="round" stroke-dasharray="2.2 1.6" vector-effect="non-scaling-stroke"/>` : ''}
      </svg>
      <div class="legend">
        <span><i style="background:#f5a623"></i>GPU</span>
        <span><i style="background:#4fc3f7"></i>VRAM</span>
        <span>${clockTime(points[0].t)} – ${clockTime(points[points.length - 1].t)}</span>
      </div>
    </div>`;
}

/* --------------------------------------------------------------- rendering */

function pipelineRows(pipeline) {
    if (!pipeline || typeof pipeline !== 'object') {
        return '';
    }

    const keys = Object.keys(pipeline);
    if (keys.length === 0) {
        return '<p class="muted" style="font-size:.76rem;margin:8px 0 0">no block data from radeontop</p>';
    }

    keys.sort((a, b) => {
        const ia = PIPELINE_LABELS[a] ? Object.keys(PIPELINE_LABELS).indexOf(a) : 99;
        const ib = PIPELINE_LABELS[b] ? Object.keys(PIPELINE_LABELS).indexOf(b) : 99;
        return ia - ib || a.localeCompare(b);
    });

    return `<div class="pipe">${keys.map((key) => {
        const value = pipeline[key];
        const label = PIPELINE_LABELS[key] || esc(key.toUpperCase());
        const width = Math.max(0, Math.min(100, value));
        return `<div class="pipe-row">
            <span>${label}</span>
            <span class="pipe-bar"><span style="width:${width.toFixed(1)}%"></span></span>
            <span class="num">${pct(value)}</span>
        </div>`;
    }).join('')}</div>`;
}

function statTile(caption, value, unit, title) {
    if (value === null) {
        return '';
    }
    return `<div class="stat"${title ? ` title="${esc(title)}"` : ''}>
        <span class="caption">${caption}</span>
        <span class="value">${value}${unit ? `<small>${unit}</small>` : ''}</span>
    </div>`;
}

function warnings(list) {
    if (!Array.isArray(list) || list.length === 0) {
        return '';
    }
    return list.map((w) => `<p class="warn"><span>!</span><span>${esc(w)}</span></p>`).join('');
}

function sourceLabel(source) {
    return {
        radeontop: 'radeontop',
        sysfs: 'sysfs fallback',
        none: 'no data',
        mock: 'mock data',
    }[source] || esc(source);
}

function nameSource(gpu) {
    return {
        override: 'name from lib/cards.php',
        lspci: 'name from lspci',
        'pci.ids': 'name from pci.ids',
        mock: 'mock name',
        none: 'no name found, showing the PCI id',
    }[gpu.name_src] || null;
}

function cardHead(gpu, chip) {
    const title = gpuTitle(gpu);
    const hint = nameSource(gpu);
    return `
      <div class="card-head">
        <span class="slot">GPU ${esc(gpu.index)}</span>
        ${title ? `<span class="model" title="${esc(hint ? `${title} — ${hint}` : title)}">${esc(title)}</span>` : ''}
        ${chip}
        <span class="bdf">${esc(gpu.bdf || `bus ${gpu.bus}`)}</span>
      </div>`;
}

function card(gpu, history) {
    if (gpu.source === 'none') {
        return `
    <section class="card">
      ${cardHead(gpu, '<span class="chip" data-source="none">no data</span>')}
      ${warnings(gpu.warnings)}
      <div class="empty">
        No measurement for bus ${esc(gpu.bus)}.<br>
        <span class="muted">Pick a working card in the <strong>GPUs</strong> panel, and check that
        <code>radeontop</code> can run as root.</span>
      </div>
    </section>`;
    }

    const total = bytesLabel(gpu.vram_total_mb);
    const used = bytesLabel(gpu.vram_used_mb);
    const gpuPct = typeof gpu.util_gpu === 'number' ? gpu.util_gpu : 0;
    const vramPct = typeof gpu.util_vram === 'number' ? gpu.util_vram : 0;

    const stats = [
        statTile('GPU clock', mhz(gpu.clock_gpu), 'MHz', null),
        statTile('VRAM clock', mhz(gpu.clock_mem), 'MHz', null),
        statTile('Power', watts(gpu.power_w), 'W',
            gpu.power_src ? `Board power from ${gpu.power_src}` : null),
    ].join('');

    const vramDetail = used && total ? `${used} / ${total}` : (used ? used : '');

    return `
    <section class="card">
      ${cardHead(gpu, `<span class="chip" data-source="${esc(gpu.source)}">${sourceLabel(gpu.source)}</span>`)}

      <div class="headline">
        <div class="metric">
          <span class="caption">GPU load</span>
          <span class="big">${num(gpu.util_gpu) ?? '—'}<small>%</small></span>
          <span class="bar"><span style="width:${gpuPct.toFixed(1)}%"></span></span>
        </div>
        <div class="metric">
          <span class="caption">VRAM load</span>
          <span class="big">${num(gpu.util_vram) ?? '—'}<small>%</small></span>
          <span class="bar vram"><span style="width:${vramPct.toFixed(1)}%"></span></span>
        </div>
      </div>
      ${vramDetail
          ? `<p class="sub-line">${esc(vramDetail)} used</p>`
          : '<div style="height:14px"></div>'}

      ${stats ? `<div class="stats">${stats}</div>` : ''}

      ${sparkline(history, esc(gpu.bus))}

      ${warnings(gpu.warnings)}

      <details class="details">
        <summary>Pipeline &amp; blocks</summary>
        ${pipelineRows(gpu.pipeline)}
        ${gpu.util_gtt !== null && gpu.util_gtt !== undefined
            ? `<p class="muted" style="margin:10px 0 0;font-size:.72rem;font-family:var(--mono)">GTT ${pct(gpu.util_gtt)}</p>`
            : ''}
      </details>
    </section>`;
}

function render(data) {
    state.last = data;

    if (!Array.isArray(data.gpus) || data.gpus.length === 0) {
        els.grid.innerHTML = `<section class="card"><div class="empty">
            No GPU found.<br><span class="muted">Open the <strong>GPUs</strong> panel to pick a card, and check that
            <code>radeontop</code> is installed.</span>
        </div></section>`;
        return;
    }

    els.grid.innerHTML = data.gpus
        .map((gpu) => card(gpu, data.history?.[gpu.bus] ?? []))
        .join('');

    const updated = clockTime(data.ts);
    els.status.textContent = `${data.gpus.length} GPU${data.gpus.length === 1 ? '' : 's'} · updated ${updated}${data.cached ? ' (cached)' : ''}`;

    const ms = data.duration_ms;
    const bits = [
        typeof ms === 'number' ? `${(ms / 1000).toFixed(1)} s` : null,
    ];

    const problems = data.gpus.reduce((sum, g) => sum + (Array.isArray(g.warnings) ? g.warnings.length : 0), 0);
    if (problems) {
        bits.push(`${problems} warning${problems === 1 ? '' : 's'}`);
    }

    els.timing.textContent = bits.filter(Boolean).join(' · ');
}

function renderError(message) {
    els.grid.innerHTML = `<section class="card"><div class="empty">
        Could not load data.<br><span class="muted">${esc(message)}</span>
    </div></section>`;
    els.status.textContent = 'error';
    els.timing.textContent = '';
}

/* --------------------------------------------------------------- gpu panel */

function apiUrl(action) {
    const params = new URLSearchParams({ action });
    if (MOCK) {
        params.set('mock', '1');
    }
    return `api.php?${params.toString()}`;
}

async function jsonRequest(url, options) {
    const res = await fetch(url, { cache: 'no-store', ...options });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
        throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
}

function openDevices() {
    els.devices.hidden = false;
    els.devicesToggle.setAttribute('aria-expanded', 'true');
    loadDevices();
}

function closeDevices() {
    els.devices.hidden = true;
    els.devicesToggle.setAttribute('aria-expanded', 'false');
}

async function loadDevices() {
    els.devicesList.innerHTML = '<p class="devices-empty">Loading cards…</p>';
    els.devicesCheck.disabled = true;

    try {
        const data = await jsonRequest(apiUrl('devices'));
        state.devices = data;
        renderDevices(data);
    } catch (err) {
        els.devicesList.innerHTML = `<p class="devices-empty">${esc(err.message)}</p>`;
    } finally {
        els.devicesCheck.disabled = false;
    }
}

function deviceRow(device) {
    const compatible = device.compatible === true;
    const probe = device.probe || null;

    let stateText = 'untested';
    let tone = 'unknown';
    if (!compatible) {
        stateText = 'unsupported';
        tone = 'off';
    } else if (probe && probe.ok === true) {
        stateText = 'responding';
        tone = 'ok';
    } else if (probe && probe.ok === false) {
        stateText = 'no answer';
        tone = 'bad';
    }

    const title = gpuTitle(device) || `bus ${device.bus}`;
    const meta = [device.bdf, device.driver].filter(Boolean).join(' · ');
    const src = ['override', 'pci.ids', 'mock', 'none'].includes(device.name_src)
        ? `<span class="device-src">${esc(device.name_src)}</span>`
        : '';
    const reason = device.reason ? ` title="${esc(device.reason)}"` : '';

    return `<label class="device${compatible ? '' : ' disabled'}"${reason}>
        <input type="checkbox" value="${esc(device.bus)}"${device.selected ? ' checked' : ''}${compatible ? '' : ' disabled'}>
        <span class="device-body">
            <span class="device-name">${esc(title)}${src}</span>
            <span class="device-meta">${esc(meta)}</span>
        </span>
        <span class="device-state" data-tone="${tone}"${probe && probe.error ? ` title="${esc(probe.error)}"` : ''}>${stateText}</span>
    </label>`;
}

function renderDevices(data) {
    const list = Array.isArray(data.devices) ? data.devices : [];

    if (list.length === 0) {
        els.devicesList.innerHTML = '<p class="devices-empty">No DRM cards found below <code>/sys/class/drm</code>.</p>';
        els.devicesHint.textContent = 'Nothing to select.';
        return;
    }

    els.devicesList.innerHTML = list.map(deviceRow).join('');

    const supported = list.filter((d) => d.compatible === true).length;
    const checked = list.filter((d) => d.selected === true && d.compatible === true).length;
    const tools = data.tools || {};

    const bits = [
        `${list.length} card${list.length === 1 ? '' : 's'}`,
        `${supported} radeontop-compatible`,
        `${checked} selected`,
    ];
    if (data.mock) {
        bits.push('mock cards');
    } else {
        bits.push(tools.lspci || tools.pci_ids ? 'names from lspci/pci.ids' : 'no name database found');
    }

    els.devicesHint.textContent = bits.join(' · ');
}

async function applySelection() {
    const buses = [...els.devicesList.querySelectorAll('input[type="checkbox"]')]
        .filter((input) => input.checked && !input.disabled)
        .map((input) => input.value);

    if (buses.length === 0) {
        els.devicesHint.textContent = 'Select at least one GPU.';
        return;
    }

    els.devicesApply.disabled = true;

    try {
        await jsonRequest(apiUrl('select'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ buses }),
        });
        closeDevices();
        load(true);
    } catch (err) {
        els.devicesHint.textContent = err.message;
    } finally {
        els.devicesApply.disabled = false;
    }
}

async function checkDevices() {
    const list = Array.isArray(state.devices?.devices) ? state.devices.devices : [];
    const buses = list.filter((d) => d.compatible === true).map((d) => d.bus);

    if (buses.length === 0) {
        return;
    }

    els.devicesCheck.disabled = true;
    els.devicesHint.textContent = `Checking ${buses.length} card${buses.length === 1 ? '' : 's'} with radeontop, about one second each…`;

    try {
        await jsonRequest(apiUrl('probe'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ buses }),
        });
        await loadDevices();
    } catch (err) {
        els.devicesHint.textContent = err.message;
        els.devicesCheck.disabled = false;
    }
}

/* ------------------------------------------------------------------ loading */

async function load(queue = false) {
    if (state.busy) {
        if (queue) {
            state.pending = true;
        }
        return;
    }

    state.busy = true;
    document.body.classList.add('loading');
    els.refresh.disabled = true;

    try {
        const data = await jsonRequest(apiUrl('sample'));
        render(data);
    } catch (err) {
        renderError(err && err.message ? err.message : String(err));
    } finally {
        state.busy = false;
        document.body.classList.remove('loading');
        els.refresh.disabled = false;

        if (state.pending) {
            state.pending = false;
            load();
        }
    }
}

els.refresh.addEventListener('click', () => load(true));

function applyRefreshInterval(seconds) {
    if (state.timer) {
        window.clearInterval(state.timer);
        state.timer = null;
    }
    if (seconds > 0) {
        // A sample takes ~2 s; ticks that land while one is running are skipped.
        state.timer = window.setInterval(() => load(), seconds * 1000);
    }
}

function storedInterval() {
    const seconds = Number.parseInt(window.localStorage.getItem(INTERVAL_STORAGE) ?? '0', 10);
    return INTERVALS.includes(seconds) ? seconds : 0;
}

els.interval.addEventListener('change', () => {
    const seconds = Number.parseInt(els.interval.value, 10) || 0;

    try {
        window.localStorage.setItem(INTERVAL_STORAGE, String(seconds));
    } catch (err) {
        // private mode, storage full: the choice just does not survive a reload
    }

    applyRefreshInterval(seconds);

    if (seconds > 0) {
        load();
    }
});

const restored = storedInterval();
els.interval.value = String(restored);
applyRefreshInterval(restored);

els.devicesToggle.addEventListener('click', () => {
    if (els.devices.hidden) {
        openDevices();
    } else {
        closeDevices();
    }
});
els.devicesClose.addEventListener('click', closeDevices);
els.devicesApply.addEventListener('click', applySelection);
els.devicesCheck.addEventListener('click', checkDevices);

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !els.devices.hidden) {
        closeDevices();
        return;
    }

    if (event.key === 'r' && !event.metaKey && !event.ctrlKey && !event.altKey) {
        const tag = document.activeElement?.tagName;
        if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
            load(true);
        }
    }
});

load();
