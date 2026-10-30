# HTTP interface

All responses are generated fresh; there is no cache header and no session.
The dashboard needs no API key, it is a LAN tool (see the security note in the
[README](../README.md)).

| Endpoint | Method | Purpose |
|---|---|---|
| `/` | GET | Dashboard shell (HTML, no values inside) |
| `/api.php?action=sample` | GET | One measurement as JSON (default action) |
| `/api.php?action=devices` | GET | Every DRM card with model name, compatibility and selection |
| `/api.php?action=select` | POST | Store the selection in `data/selection.json` |
| `/api.php?action=probe` | POST | Run radeontop once per card and cache the result |
| `/debug.php` | GET | Diagnostics page (HTML), raw sysfs, radeontop and card table |

Every endpoint accepts `mock=1` (`mock` value other than `0`/`false`) and then
answers with fake data without writing anything to disk.

Response headers: `Content-Type: application/json; charset=utf-8`,
`Cache-Control: no-store, max-age=0`.

## `GET /api.php?action=sample`

Query parameters:

| Name | Values | Effect |
|---|---|---|
| `action` | `sample` (default) | one measurement |
| `mock` | `1`, `true` | fake data, history synthesised in memory, `last.json` untouched |
| `buses` | `3,9` or `3&buses[]=9` | optional explicit selection for this request; validated like `action=select` |

Without `buses` the stored selection is used (see `action=select`), falling back
to `buses` in `lib/config.php`.

### Response

```json
{
  "ok": true,
  "mock": false,
  "host": "tower",
  "ts": 1793373872699,
  "duration_ms": 2087,
  "gpus": [
    {
      "index": 0,
      "bus": "3",
      "bdf": "0000:03:00.0",
      "name": "Radeon RX 9070 XT",
      "name_src": "lspci",
      "vendor_id": "1002",
      "device_id": "7550",
      "driver": "amdgpu",
      "compatible": true,
      "util_gpu": 46.4,
      "util_vram": 50.7,
      "util_gtt": 0.2,
      "vram_used_mb": 8303,
      "vram_total_mb": 16384,
      "clock_gpu": 1688,
      "clock_mem": 740,
      "power_w": 136.2,
      "power_src": "power1_input",
      "pipeline": { "ee": 0, "vgt": 14.37, "ta": 5.56 },
      "source": "radeontop",
      "warnings": []
    }
  ],
  "history": {
    "3": [
      { "t": 1793373634699, "gpu": 68.9, "vram": 61.9, "sclk": 1900, "mclk": 1000, "w": 120.5 }
    ]
  }
}
```

#### Top level

| Field | Type | Meaning |
|---|---|---|
| `ok` | bool | `true` when at least one GPU produced a value (`source` ≠ `none`) |
| `mock` | bool | response came from `?mock=1` |
| `host` | string\|null | `gethostname()` of the PHP machine |
| `ts` | int | end of the measurement, epoch milliseconds |
| `duration_ms` | int | wall clock time the request spent sampling |
| `gpus` | array | one entry per selected bus, in selection order |
| `history` | object | bus token → array of points, up to `history_points` (the current sample is included) |
| `cached` | bool | only present (and `true`) when the response came from `last.json` because another sample held the lock |

#### `gpus[]`

| Field | Type | Unit | Notes |
|---|---|---|---|
| `index` | int | – | position in the response, used for the `GPU 0` badge |
| `bus` | string | hex | normalised token, e.g. `3`, `a` |
| `bdf` | string\|null | – | `0000:03:00.0`, `null` when sysfs has no card for that bus |
| `name` | string\|null | – | marketing name, e.g. `Radeon RX 9070 XT`; `AMD 1002:7550` when nothing was found |
| `name_src` | string\|null | – | `override`, `lspci`, `pci.ids`, `mock` or `none` |
| `vendor_id` | string\|null | hex | PCI vendor id, normally `1002` |
| `device_id` | string\|null | hex | PCI device id, e.g. `7550` |
| `driver` | string\|null | – | kernel driver reported by sysfs (`amdgpu`, `radeon`, …) |
| `compatible` | bool\|null | – | radeontop heuristic result; `null` when sysfs has no card |
| `util_gpu` | float\|null | % | radeontop, else `gpu_busy_percent` |
| `util_vram` | float\|null | % | radeontop, else `mem_busy_percent` |
| `util_gtt` | float\|null | % | radeontop only |
| `vram_used_mb` | int\|null | MiB | radeontop's megabyte figure, else `mem_info_vram_used` |
| `vram_total_mb` | int\|null | MiB | `mem_info_vram_total` |
| `clock_gpu` | int\|null | MHz | active `pp_dpm_sclk` level |
| `clock_mem` | int\|null | MHz | active `pp_dpm_mclk` level |
| `power_w` | float\|null | W | board power, one decimal |
| `power_src` | string\|null | – | `power1_input`, `power1_average`, `mock` or `null` |
| `pipeline` | object | % | radeontop block → percentage, unknown blocks included |
| `source` | string | – | `radeontop`, `sysfs`, `none` or `mock` |
| `warnings` | string[] | – | short human readable reasons, already deduplicated per GPU |

Any `null` means "not available on this machine". The frontend hides the
corresponding tile instead of printing `0`; consumers of the API should treat
`null` and a missing key the same way.

The model name is returned without the memory size. The UI appends the VRAM
capacity (`Radeon RX 9070 XT · 16 GB`) because the same model is sold in several
sizes and `vram_total_mb` distinguishes them reliably.

#### `history[bus][]`

| Field | Type | Unit | Notes |
|---|---|---|---|
| `t` | int | ms | epoch milliseconds |
| `gpu` | float\|null | % | GPU load at that time |
| `vram` | float\|null | % | VRAM load |
| `sclk` | int\|null | MHz | |
| `mclk` | int\|null | MHz | |
| `w` | float\|null | W | |

Points are appended only when GPU or VRAM load is known. The newest point is
the current sample; older ones come from the JSONL file, so history survives a
reload but not a deleted `data/` directory. History of a card is kept when it is
deselected and selected again.

## `GET /api.php?action=devices`

### Response

```json
{
  "ok": true,
  "mock": false,
  "host": "tower",
  "ts": 1793381936126,
  "devices": [
    {
      "bus": "3",
      "bdf": "0000:03:00.0",
      "vendor_id": "1002",
      "device_id": "7550",
      "driver": "amdgpu",
      "name": "Radeon RX 9070 XT",
      "name_src": "lspci",
      "vram_total_mb": 16384,
      "compatible": true,
      "reason": null,
      "probe": { "ok": true, "error": null, "ts": 1793381936266 },
      "selected": true
    }
  ],
  "selection": ["3", "9"],
  "tools": {
    "lspci": "/usr/bin/lspci",
    "pci_ids": "/usr/share/hwdata/pci.ids"
  }
}
```

#### `devices[]`

| Field | Type | Notes |
|---|---|---|
| `bus` | string | normalised hex token used for `radeontop -b` |
| `bdf` | string | `0000:03:00.0` |
| `vendor_id` | string\|null | PCI vendor id, lowercase hex |
| `device_id` | string\|null | PCI device id, lowercase hex |
| `driver` | string\|null | kernel driver (`amdgpu`, `radeon`, `i915`, …) |
| `name` | string | model name, or `AMD 1002:7550` as a fallback |
| `name_src` | string | `override`, `lspci`, `pci.ids`, `mock` or `none` |
| `vram_total_mb` | int\|null | `mem_info_vram_total` in MiB |
| `compatible` | bool | vendor `1002` and driver `amdgpu`/`radeon` |
| `reason` | string\|null | why a card is not compatible |
| `probe` | object\|null | last `action=probe` result |
| `selected` | bool | currently sampled |

`probe` has `ok` (bool), `error` (string\|null) and `ts` (epoch milliseconds).
It is `null` until the card was probed once.

`tools` reports where model names come from: `lspci` and `pci_ids` are absolute
paths or `null`. Both `null` means the UI falls back to `AMD <vendor>:<device>`
until `lib/cards.php` has an entry.

The list contains **all** DRM cards, including non-AMD ones, so a missing card
is visible as a disabled row with a reason instead of an empty panel.

## `POST /api.php?action=select`

Body as JSON (`Content-Type: application/json`) or form encoded:

```json
{ "buses": ["3", "9"] }
```

The list must contain at least one bus token (`^(0x)?[0-9a-f]{1,2}$`) that
belongs to a present, compatible card. Duplicates are ignored and the stored
list is sorted by bus. The previous cached sample is deleted.

Response:

```json
{ "ok": true, "selection": ["3", "9"] }
```

| Status | Meaning |
|---|---|
| 200 | stored |
| 400 | empty list, invalid token, or a bus with no compatible card |
| 405 | called with GET instead of POST |

## `POST /api.php?action=probe`

```json
{ "buses": ["3", "9"] }
```

Runs one real radeontop window per bus (about one second each, serialised with
the sample lock) and stores the result. Without `buses` the current selection is
probed.

Response:

```json
{
  "ok": true,
  "mock": false,
  "probe": {
    "3": { "ok": true, "error": null, "ts": 1793381936266 },
    "9": { "ok": false, "error": "radeontop needs root: permission denied", "ts": 1793381937300 }
  }
}
```

A failing card is not an HTTP error: the entry has `ok: false` and a short
`error`. Only invalid bus tokens (400) or a GET request (405) are errors.

## Errors

Sampling and probing problems are **not** HTTP errors. A GPU that cannot be read
still appears in `gpus`/`devices` with `source: "none"` or `probe.ok: false` and
a reason. HTTP errors use the same envelope:

```json
{ "ok": false, "error": "no radeontop-compatible GPU on bus a", "gpus": [], "history": [] }
```

| Status | Cause |
|---|---|
| 400 | invalid action, invalid/empty bus list, bus not present |
| 405 | `action=select` or `action=probe` without POST |
| 500 | unexpected failure (for example a response that cannot be encoded) |

## `GET /debug.php`

HTML, no JSON. Besides the environment it prints a card table (bus, BDF,
vendor:device, driver, model name and its source, VRAM, compatibility, probe
result) and per selected bus:

* detected device directory, `PCI_SLOT_NAME` and driver symlink
* every `hwmon` directory with `power1_input`, `power1_average`, `power1_cap`
  as raw value and watt
* `gpu_busy_percent`, `mem_busy_percent`, VRAM bytes
* the raw `pp_dpm_sclk` / `pp_dpm_mclk` tables
* whether radeontop is found, its parsed values and its raw output

It performs one radeontop call per selected bus, so opening it takes about the
same time as one refresh.

## Files touched at runtime

| Path | Written by | Purpose |
|---|---|---|
| `data/history-<bus>.jsonl` | `Store::append()` | one JSON point per line |
| `data/selection.json` | `Devices::saveSelection()` | selected buses, shared by all browsers |
| `data/probe.json` | `Devices::saveProbe()` | last radeontop probe per bus |
| `data/last.json` | `api.php` | last successful sample, used as the concurrency cache |
| `data/.sample.lock` | `api.php` | `flock` target, empty |

All of them are safe to delete while the dashboard is running (lock and caches
are recreated on the next request). Deleting `selection.json` restores the
`buses` default from `lib/config.php`; deleting the JSONL files loses history.
