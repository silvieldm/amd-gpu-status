# Architecture

How AMD GPU Status is put together, and why. For installation and usage see
the [README](../README.md), for the JSON contract see [API.md](API.md).

## Goals

* Show the AMD GPUs the user selects, one card each, with live utilisation.
* List every radeontop-compatible card on the host and show its model name.
* One PHP request per refresh, no background daemon.
* No build step, no Composer, no database, no CDN, no JavaScript framework.
* Degrade quietly: a sensor that the driver does not expose is left out,
  not shown as `0`.

Non-goals: authentication, multi-host monitoring, historical long-term storage,
fan control, overclocking.

## Request flow

```
browser                     php                    system
───────                     ───                    ──────
GET /                  ──▶  index.php
                            └─ renders shell (no data)
GET api.php?action=devices ──▶ api.php
                            └─ Devices::catalog()
                               ├─ scan /sys/class/drm/card*/device/uevent
                               ├─ resolveName()   cards.php → lspci -nn → pci.ids
                               └─ selection.json + probe.json
POST action=select     ──▶  validate buses → data/selection.json
POST action=probe      ──▶  one radeontop window per bus → data/probe.json
GET api.php?action=sample ──▶ api.php
                            ├─ flock data/.sample.lock  (busy? → last.json, cached:true)
                            └─ Sampler::collect()
                               ├─ Devices::selection()
                               ├─ for each bus:
                               │   ├─ Radeontop::sample()  radeontop -b N -d - -l 1
                               │   ├─ Sysfs::read()        pp_dpm_*, hwmon, mem_info_*
                               │   ├─ Radeontop::summarize()
                               │   └─ Store::append()      data/history-N.jsonl
                               ├─ Store::recent()          last 120 points per bus
                               └─ json_encode → data/last.json + response
browser renders cards, draws the sparkline as inline SVG
```

The page itself contains no values. Everything comes from `api.php`, which
keeps the HTML cacheable and the sampling in exactly one place.

## Modules

| File | Responsibility |
|---|---|
| `index.php` | HTML shell, GPUs panel, refresh button, interval select |
| `api.php` | HTTP layer: sample, devices, selection, probe, locking, cache |
| `lib/config.php` | the only file an operator has to touch |
| `lib/Sampler.php` | orchestrates one measurement per selected GPU, merges sources, writes history, probes cards |
| `lib/Devices.php` | card discovery, compatibility, selection storage, probe cache, model names |
| `lib/cards.php` | optional model-name overrides |
| `lib/Radeontop.php` | runs the binary, parses the dump, splits headline values from pipeline blocks |
| `lib/Sysfs.php` | card discovery, clocks, power, VRAM sizes, fallback utilisation |
| `lib/Store.php` | append-only JSONL history with trimming |
| `lib/Mock.php` | time-based fake values for `?mock=1` |
| `assets/app.js` | fetch, render, sparkline, GPUs panel, interval timer |
| `assets/style.css` | dark theme, grid, cards, panel |
| `debug.php` | read-only diagnostics page with the card table |

There is no autoloader. `api.php` requires `Sampler.php`, which requires the
classes it uses; `debug.php` requires `Sysfs.php`, `Radeontop.php` and
`Devices.php` directly. Adding a class means adding a `require_once` in the file
that uses it.

## Sampling a GPU

`Radeontop::sample()` builds

```
radeontop -b <bus> -d - -l 1
```

and reads stdout. `-d -` dumps one line to stdout, `-l 1` limits it to a single
measurement window (~1 s), which is why a sample is not instant.

* The bus token is validated and normalised (`Sysfs::normBus()`), then passed
  through `escapeshellarg()`; there is no interpolation of user input anywhere.
* The binary is resolved once per request via `command -v` (or used as an
  absolute path) and cached in a static.
* If the direct call yields no parsable values and `allow_sudo` is on, the same
  command is retried as `sudo -n …` – that covers FPM setups where the worker
  is not root.
* `timeout 10` is prefixed when coreutils is available, so a wedged radeontop
  cannot hang the PHP worker forever.

### Parsing

The dump is plain text, version dependent, roughly:

```
0000:03:00.0 gpu 4.05%, ee 0.00%, vgt 5.26%, …, vram 3.30% 268.00mb,
gtt 0.19% 15.00mb, vis 0.00% 0.00mb
```

One regex extracts every `label <pct>% [<size> MB|GB]` pair:

```php
'/([A-Za-z_][A-Za-z0-9_]{0,7})\s*[:=]?\s*([0-9]+(?:\.[0-9]+)?)\s*%'
.'(?:\s*\(?\s*([0-9]+(?:\.[0-9]+)?)\s*(mb|mib|gb|gib)\)?)?/i'
```

Design choices:

* The label is capped at 8 characters without spaces, so `0000:03:00.0` and
  textual prologue lines cannot be mistaken for a block name.
* `:` and `=` are optional separators, so both `gpu 4.05%` and `GPU: 4.05%`
  parse.
* The size suffix is optional and accepts `(268 MB)` as well as `268.00mb`.
  GB/GiB is converted to MB.
* Unknown labels are kept. A new radeontop release that adds a block shows up
  in the details section instead of being dropped.
* No value ever throws: an empty or garbage dump produces an empty array, which
  the caller turns into a warning.

`Radeontop::summarize()` then splits the result into `gpu`, `vram`, `gtt`,
`vram_mb`, `gtt_mb` and the `pipeline` map, ordered by the `PIPELINE` constant.

## Reading sysfs

`Sysfs::discover()` walks `/sys/class/drm/card[0-9]*/device/uevent`, reads
`PCI_SLOT_NAME=0000:03:00.0` and keys the device directory by bus number.
Leading zeros are stripped, so `03` becomes `3`; because radeontop expects hex,
`-b 3` and `0000:03:00.0` line up. For bus ≥ 10 the same rule applies in hex –
`-b a` belongs to `0000:0a:00.0`.

| Value | File | Conversion |
|---|---|---|
| GPU clock | `device/pp_dpm_sclk` | line containing `*`, `1234Mhz` → 1234 |
| VRAM clock | `device/pp_dpm_mclk` | same |
| Power | `device/hwmon/hwmon*/power1_input` | µW ÷ 1 000 000 |
| Power fallback | `…/power1_average` | same, only if `input` is missing or 0 |
| VRAM total | `device/mem_info_vram_total` | bytes ÷ 1 048 576 |
| VRAM used | `device/mem_info_vram_used` | bytes ÷ 1 048 576 |
| GPU busy | `device/gpu_busy_percent` | as-is |
| Mem busy | `device/mem_busy_percent` | as-is |

Notes:

* `power1_input` is the current board power; `power1_average` is smoothed and
  can still contain a load spike, so it is only a fallback.
* Power is **board** power: core, VRAM, memory controller and display engines.
  20–30 W idle is normal for RDNA2/RDNA3 with a display attached.
* Every getter returns `null` on a missing, empty or non-numeric file.
  `Sysfs::empty()` provides the same shape for an unknown bus.
* The DPM tables list all levels; the active one is marked with `*`. If no
  level is marked, the first parsable level is used as a best effort.

## Card discovery and selection

`Devices` scans `/sys/class/drm/card[0-9]*/device/uevent` on its own; it parses
`PCI_SLOT_NAME`, `PCI_ID` and `DRIVER`, falls back to `readlink(device/driver)`
and the raw `vendor`/`device` files, and keys cards by the same normalised bus
token as `Sysfs`. If several DRM cards share a bus, the one exposing
`pp_dpm_sclk` wins.

A card is radeontop-compatible when the vendor is `1002` and the driver is
`amdgpu` or `radeon` (radeontop supports R600 and up). Other DRM cards stay in
the list but disabled, with the reason in `reason` — an empty list would hide a
misconfiguration instead of explaining it.

Selection rules:

* `data/selection.json` stores `{"buses":["3","9"],"ts":…}` and is shared by
  every browser.
* `Devices::validate()` accepts only `^(0x)?[0-9a-f]{1,2}$` tokens that belong
  to a present, compatible card. Empty lists and unknown buses are rejected
  with HTTP 400.
* `Devices::selection()` prefers the stored list, filters out cards that have
  disappeared and otherwise falls back to `buses` in `lib/config.php`. The
  config default is deliberately not validated, so a typo still renders a
  visible "no data" card instead of silently vanishing.
* `api.php` deletes `last.json` when the selection changes, so a cached
  response from the old set cannot win the next request.
* Writes go to a temporary file and are renamed into place; a concurrent reader
  never sees half a JSON document.

## Model names

sysfs exposes no marketing name. `Devices::resolveName()` tries, in order:

1. `lib/cards.php` — keyed by full BDF, `vendor:device`, or device id
2. `lspci -nn -s <bdf>` — for example
   `Advanced Micro Devices, Inc. [AMD/ATI] Navi 48 [Radeon RX 9070 XT] [1002:7550] (rev c0)`
3. the same lookup against the `pci.ids` files from the configuration
4. `AMD 1002:7550`

The shortener drops the device-class prefix, `(rev …)` and the trailing
`[vendor:device]`, removes vendor tags, then prefers a bracket that looks like a
product name (`[Radeon RX 9070 XT]`). Generic results (`Device 7550`) are
rejected so the next source gets a chance. The winning source is reported as
`name_src` and shown in `debug.php`; the UI appends the VRAM size from
`mem_info_vram_total`, because the same model exists with several capacities.

pci.ids is parsed without loading the whole file: scan to the vendor line, stop
at the next top-level line, match a single-tab device line. Subsystem lines
(two tabs) are ignored.

## Probing cards

The compatibility rule above is a heuristic. **Check with radeontop** in the
panel calls `Sampler::probe()`, which runs one real radeontop window per card
under the sample lock (about one second each) and stores `ok`, `error` and `ts`
in `data/probe.json`. The panel then shows *responding* or *no answer* until the
next probe; the heuristic itself never blocks a card.

## Merging and fallbacks

`Sampler::collect()` decides per value:

| Value | First choice | Fallback |
|---|---|---|
| GPU load | radeontop `gpu` | `gpu_busy_percent` |
| VRAM load | radeontop `vram` | `mem_busy_percent` |
| VRAM used | radeontop `vram` size | `mem_info_vram_used` |
| `source` | `radeontop` | `sysfs`, else `none` |

`source` is what the small chip in the card header shows. `none` means no
usable value at all; the UI then renders a short hint instead of empty tiles.

## History

`Store` writes one JSON object per line to `data/history-<bus>.jsonl`:

```json
{"t":1793373634699,"gpu":68.9,"vram":61.9,"sclk":1900,"mclk":1000,"w":120.5}
```

* Append with `FILE_APPEND | LOCK_EX`.
* A point is only written when GPU or VRAM load is non-null.
* When the file exceeds `history_limit * 220` bytes it is rewritten with the
  last `history_limit` lines (220 ≈ longest realistic line, so the check is
  cheap and the file never grows unbounded).
* Reads take the last `history_points` lines; unparsable lines are skipped.
* Mock samples are never persisted.

`data/last.json` holds the last successful response. It exists for two reasons:
a request that cannot get the sample lock is answered from it, and it makes
"what did the dashboard show a second ago" inspectable.

## Locking

`api.php` takes `flock(data/.sample.lock, LOCK_EX | LOCK_NB)`:

* Acquired: sample normally, overwrite `last.json` on success.
* Not acquired and `last.json` exists: answer immediately with that payload plus
  `"cached": true`.
* Not acquired and no cache yet: block until the running sample finishes.

The probe action takes the same lock in blocking mode, so a probe never overlaps
with a sample. This keeps a 1 s auto-refresh, two open browser tabs and a double
click from starting four radeontop processes.

## Frontend

* No build step; `app.js` is loaded as-is.
* Each refresh replaces the card markup – at 0.2–1 Hz that is cheaper than
  diffing, and it keeps the render code linear.
* All interpolated strings pass through `esc()`; numbers are formatted, not
  concatenated raw.
* The sparkline is an inline SVG with `viewBox="0 0 100 30"` and
  `preserveAspectRatio="none"`, so it scales with the card width. Y is fixed at
  0–100 % to keep GPU and VRAM comparable; the dashed line marks 50 %.
* The interval select stores its value in `localStorage`
  (`gpu-status-interval`). Unknown or unreadable values fall back to `off`.
* The GPUs panel fetches `action=devices`, renders one checkbox per card with
  name, BDF, driver and probe state, then POSTs the ticked buses to
  `action=select`. It closes and samples again on success.
* `load(queue)` is guarded by `state.busy`. Interval ticks that arrive mid-sample
  are dropped; an explicit action (refresh, apply, `r`) sets `pending` so one
  follow-up runs as soon as the current request finishes.
* `?mock=1` switches the whole page to fake data, including a synthesised
  history and two fake cards, so the layout can be reviewed without a GPU.

## Failure handling

| Situation | Behaviour |
|---|---|
| radeontop missing | warning on the card, `sysfs` fallback if available |
| radeontop without permission | one `sudo -n` retry, then a warning with the reason |
| wrong bus token | `no GPU on this bus` warning, card shows `none` |
| no sysfs (non-Linux, no amdgpu) | clocks and power tiles disappear |
| malformed radeontop output | empty parse → warning, no exception |
| unwritable `data/` | history silently not written; sampling still works |
| no `lspci` and no readable `pci.ids` | card shows `AMD 1002:7550` until `lib/cards.php` has an entry |
| stored selection points at a removed card | the card is filtered out; if none remains, the compatible list is used |
| probe fails | panel shows the reason; sampling and the heuristic still work |

`debug.php` exposes all raw inputs for these cases, plus the card table with
name source and probe result.

## Extending

* **More GPUs:** use the GPUs panel; no config edit is needed. The number of
  cards on the grid follows the selection.
* **Different defaults:** set `buses` in `lib/config.php`; an empty array starts
  with every compatible card.
* **Unknown model name:** add a line to `lib/cards.php`; no code change needed.
* **New metric:** read it in `Sysfs::read()`, add it to `Sampler`'s GPU array,
  add a tile in `assets/app.js` (`statTile()` is enough for a single number).
* **Parallel sampling:** replace the sequential loop in `Sampler::collect()`
  with two `proc_open()` calls started together; this halves the refresh time
  but drops the plain `shell_exec()` call the project was specified with.
* **Long-term graphs:** the JSONL files are plain data; `history_limit` can be
  raised, or a second reader can tail them.

## Security notes

* No shell injection: bus tokens are validated against the detected cards,
  normalised, then escaped; the binary paths come from configuration, not from
  the request.
* No file writes based on request input: the only paths are `data/` plus the
  sanitised bus key. The selection file is written atomically.
* Output is HTML-escaped in PHP and JS.
* There is no authentication. Bind to the LAN, or put the dashboard behind the
  Unraid webgui login / a reverse proxy.
