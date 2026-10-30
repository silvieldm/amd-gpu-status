<?php
declare(strict_types=1);

/**
 * Manual model-name overrides.
 *
 * The name normally comes from `lspci -nn` (or from parsing pci.ids). If the
 * pci.ids database on the machine is older than the card, lspci prints
 * "Device 7550" instead of the marketing name. Add an entry here to fix that
 * without touching the rest of the dashboard.
 *
 * Keys may be, in order of preference:
 *   '0000:03:00.0'  full PCI slot (what `lspci -nn` and sysfs show)
 *   '1002:7550'     vendor:device id
 *   '7550'          device id only (use only when no two cards share it)
 *
 * The value is displayed verbatim, without the memory size; the dashboard
 * appends the VRAM size it reads from mem_info_vram_total.
 */
return [
    // '0000:03:00.0' => 'Radeon RX 9070 XT',
    // '1002:7550' => 'Radeon RX 9070 XT',
];
