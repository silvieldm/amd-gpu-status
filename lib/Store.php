<?php
declare(strict_types=1);

/**
 * Tiny append-only history store: one JSON object per line, per GPU.
 *
 * No database, no extension required beyond the standard JSON support. The
 * file is kept at `limit` lines (plus a slack window before it is rewritten).
 */
final class Store
{
    public function __construct(
        private string $dir,
        private int $limit = 1000,
    ) {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    public function append(string $key, array $point): void
    {
        $file = $this->file($key);
        $line = json_encode($point, JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

        clearstatcache(true, $file);
        if (@filesize($file) > $this->limit * 220) {
            $this->compact($file);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(string $key, int $count = 120): array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return [];
        }

        $lines = array_slice($lines, -$count);
        $out = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function file(string $key): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $key) ?: 'x';

        return $this->dir . '/history-' . $safe . '.jsonl';
    }

    private function compact(string $file): void
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        $keep = array_slice($lines, -$this->limit);
        @file_put_contents($file, implode("\n", $keep) . "\n", LOCK_EX);
    }
}
