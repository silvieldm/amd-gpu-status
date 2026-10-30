<?php
declare(strict_types=1);

/**
 * Central configuration. No dependencies, safe to edit on the target machine.
 */
return [
    // PCI bus tokens as passed to `radeontop -b <token>`. Tokens are hex.
    // This is the default selection; the GPUs panel stores its own choice in
    // data/selection.json. Leave empty to start with every detected card.
    'buses' => ['3', '9'],

    // Path or name of the radeontop binary.
    'radeontop' => 'radeontop',

    // Path or name of lspci, used for model names. Empty disables it.
    'lspci' => 'lspci',

    // pci.ids files, tried in order when lspci is missing or returns no name.
    'pci_ids' => [
        '/usr/share/hwdata/pci.ids',
        '/usr/share/misc/pci.ids',
        '/usr/share/pci.ids',
        '/var/lib/pciutils/pci.ids',
    ],

    // Retry with `sudo -n` when the direct call has no permission.
    'allow_sudo' => true,

    // Kill a hanging radeontop call after this many seconds (needs coreutils).
    'timeout' => 10,

    // History: one point per refresh, ring buffer per GPU.
    'history_limit' => 1000,
    'history_points' => 120,

    'data_dir' => __DIR__ . '/../data',
    'title' => 'AMD GPU Status',
];
