<?php
declare(strict_types=1);

/**
 * JSON endpoint.
 *
 *   api.php?action=sample      one measurement plus the recent history
 *   api.php?action=devices     every DRM card, its model name and the selection
 *   api.php?action=select      POST {"buses":["3","9"]} stores the selection
 *   api.php?action=probe       POST {"buses":["3","9"]} tests radeontop per card
 *
 * Add `&mock=1` for fake data; nothing is written to disk in mock mode.
 */

$config = require __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/Sampler.php';
require_once __DIR__ . '/lib/Devices.php';

set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$mock = isset($_GET['mock']) && $_GET['mock'] !== '0' && $_GET['mock'] !== 'false';
$action = (string) ($_GET['action'] ?? 'sample');

$dataDir = (string) $config['data_dir'];
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}

/** @param array<string,mixed> $payload */
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(int $status, string $message): void
{
    respond([
        'ok' => false,
        'error' => $message,
        'gpus' => [],
        'history' => [],
    ], $status);
}

/**
 * Reads buses from a JSON body, a form field or the query string.
 *
 * @return list<mixed>|null
 */
function requested_buses(): ?array
{
    $raw = null;

    $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains(strtolower($type), 'application/json')) {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($body) && array_key_exists('buses', $body)) {
            $raw = $body['buses'];
        }
    }

    if ($raw === null && isset($_POST['buses'])) {
        $raw = $_POST['buses'];
    }
    if ($raw === null && isset($_GET['buses'])) {
        $raw = $_GET['buses'];
    }
    if ($raw === null) {
        return null;
    }
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        throw new InvalidArgumentException('buses must be a list of bus tokens');
    }

    return array_values($raw);
}

/** Waits for a running measurement so two radeontop calls never overlap. */
function sample_lock(string $dataDir, bool $nonBlocking = false): mixed
{
    $lock = @fopen($dataDir . '/.sample.lock', 'c');
    if ($lock === false) {
        return null;
    }

    $flags = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
    if (!flock($lock, $flags)) {
        fclose($lock);

        return false;
    }

    return $lock;
}

/** @param resource|null|false $lock */
function release_lock(mixed $lock): void
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    $devices = new Devices($config);

    if ($action === 'devices') {
        respond([
            'ok' => true,
            'mock' => $mock,
            'host' => gethostname() ?: null,
            'ts' => (int) round(microtime(true) * 1000),
            'devices' => $devices->catalog($mock),
            'selection' => $devices->selection($mock),
            'tools' => [
                'lspci' => $devices->lspciPath(),
                'pci_ids' => $devices->pciIdsPath(),
            ],
        ]);
    }

    if ($action === 'select') {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            fail(405, 'use POST to change the selection');
        }
        $buses = requested_buses();
        if ($buses === null) {
            fail(400, 'missing buses');
        }
        $selection = $devices->saveSelection($buses, $mock);
        // The cached sample belongs to the old selection.
        @unlink($dataDir . '/last.json');
        respond(['ok' => true, 'selection' => $selection]);
    }

    if ($action === 'probe') {
        $buses = requested_buses() ?? $devices->selection($mock);
        $lock = sample_lock($dataDir);
        try {
            $sampler = new Sampler($config);
            $probe = $sampler->probe($buses, $mock);
        } finally {
            release_lock($lock);
        }
        respond(['ok' => true, 'mock' => $mock, 'probe' => $probe]);
    }

    if ($action !== 'sample') {
        fail(400, 'unknown action: ' . $action);
    }

    $explicit = requested_buses();

    $lock = null;
    if (!$mock) {
        // Two clicks in a row should not start four radeontop processes.
        $lock = sample_lock($dataDir, true);
        if ($lock === false) {
            $cached = @file_get_contents($dataDir . '/last.json');
            $decoded = $cached === false ? null : json_decode($cached, true);
            if (is_array($decoded)) {
                $decoded['cached'] = true;
                respond($decoded);
            }
            // No cached answer yet: wait for the running sample instead.
            $lock = sample_lock($dataDir);
        }
    }

    try {
        $sampler = new Sampler($config);
        $data = $sampler->collect($mock, !$mock, $explicit);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new RuntimeException('could not encode response: ' . json_last_error_msg());
        }

        if (!$mock) {
            @file_put_contents($dataDir . '/last.json', $json, LOCK_EX);
        }

        echo $json;
    } finally {
        release_lock($lock);
    }
} catch (InvalidArgumentException $e) {
    fail(400, $e->getMessage());
} catch (Throwable $e) {
    fail(500, $e->getMessage());
}
