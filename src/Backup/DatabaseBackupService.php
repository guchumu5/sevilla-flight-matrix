<?php
declare(strict_types=1);

namespace SevillaMatrix\Backup;

use PDO;
use RuntimeException;

final class DatabaseBackupService
{
    private string $directory;

    public function __construct(private readonly PDO $pdo, ?string $directory = null)
    {
        $this->directory = $directory ?? PROJECT_ROOT . '/storage/backups';
    }

    /** @return array<string,mixed> */
    public function create(string $kind = 'manual'): array
    {
        $kind = $kind === 'auto' ? 'auto' : 'manual';
        $this->ensureDirectory();
        $lock = fopen($this->directory . '/.backup.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Ya hay una copia de seguridad en curso.');
        }

        $name = sprintf('matrix-%s-%s-%s.jsonl.gz', $kind, date('Ymd-His'), bin2hex(random_bytes(3)));
        $path = $this->directory . '/' . $name;
        $stream = gzopen($path, 'wb6');
        if (!$stream) throw new RuntimeException('No se pudo crear el fichero de copia.');

        $counts = [];
        try {
            $tables = $this->tables();
            gzwrite($stream, json_encode([
                'type' => 'manifest',
                'format' => 1,
                'created_at' => date(DATE_ATOM),
                'database' => (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn(),
                'tables' => $tables,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

            foreach ($tables as $table) {
                $count = 0;
                $query = $this->pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
                while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                    gzwrite($stream, json_encode([
                        'type' => 'row',
                        'table' => $table,
                        'data' => $row,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $count++;
                }
                $counts[$table] = $count;
            }
            gzwrite($stream, json_encode(['type' => 'complete', 'counts' => $counts]) . "\n");
        } catch (\Throwable $error) {
            gzclose($stream);
            @unlink($path);
            throw $error;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        gzclose($stream);
        $checksum = hash_file('sha256', $path);
        file_put_contents($path . '.sha256', $checksum . '  ' . $name . "\n", LOCK_EX);
        $this->prune();
        return $this->describe($path, true) + ['counts' => $counts];
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $this->ensureDirectory();
        $paths = glob($this->directory . '/matrix-*.jsonl.gz') ?: [];
        usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        return array_map(fn(string $path): array => $this->describe($path, true), $paths);
    }

    /** @return array<string,mixed> */
    public function verify(string $name): array
    {
        $path = $this->path($name);
        $description = $this->describe($path, true);
        $stream = gzopen($path, 'rb');
        if (!$stream) throw new RuntimeException('No se pudo abrir la copia.');
        $line = gzgets($stream);
        $manifest = is_string($line) ? json_decode($line, true) : null;
        $complete = null;
        $rows = 0;
        while (!gzeof($stream)) {
            $line = gzgets($stream);
            if (!is_string($line) || trim($line) === '') continue;
            $record = json_decode($line, true);
            if (!is_array($record)) {
                gzclose($stream);
                throw new RuntimeException('La copia contiene una línea JSON dañada.');
            }
            if (($record['type'] ?? '') === 'row') $rows++;
            if (($record['type'] ?? '') === 'complete') $complete = $record;
        }
        gzclose($stream);
        if (($manifest['type'] ?? '') !== 'manifest' || $complete === null) {
            throw new RuntimeException('La copia está incompleta o no pertenece a esta aplicación.');
        }
        return $description + ['rows' => $rows, 'tables' => count($manifest['tables'] ?? []), 'complete' => true];
    }

    public function path(string $name): string
    {
        if (!preg_match('/^matrix-(?:auto|manual)-\d{8}-\d{6}-[a-f0-9]{6}\.jsonl\.gz$/', $name)) {
            throw new RuntimeException('Nombre de copia no válido.');
        }
        $path = $this->directory . '/' . $name;
        if (!is_file($path)) throw new RuntimeException('La copia solicitada no existe.');
        return $path;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $stmt = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name"
        );
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array<string,mixed> */
    private function describe(string $path, bool $verify): array
    {
        $name = basename($path);
        $expected = trim((string)@file_get_contents($path . '.sha256'));
        $expected = preg_split('/\s+/', $expected)[0] ?? '';
        $actual = $verify ? hash_file('sha256', $path) : '';
        return [
            'name' => $name,
            'kind' => str_contains($name, '-auto-') ? 'automática' : 'manual',
            'created_at' => date('Y-m-d H:i:s', (int)filemtime($path)),
            'size_bytes' => (int)filesize($path),
            'checksum' => $expected,
            'verified' => $verify ? ($expected !== '' && hash_equals($expected, $actual)) : null,
        ];
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear storage/backups.');
        }
        if (!is_writable($this->directory)) throw new RuntimeException('storage/backups no es escribible.');
    }

    private function prune(): void
    {
        $paths = glob($this->directory . '/matrix-auto-*.jsonl.gz') ?: [];
        usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $daily = $weekly = $monthly = [];
        $keep = [];
        foreach ($paths as $path) {
            $time = (int)filemtime($path);
            $day = date('Y-m-d', $time);
            $week = date('o-W', $time);
            $month = date('Y-m', $time);
            if (count($daily) < 7 && !isset($daily[$day])) { $daily[$day] = true; $keep[$path] = true; }
            if (count($weekly) < 5 && !isset($weekly[$week])) { $weekly[$week] = true; $keep[$path] = true; }
            if (count($monthly) < 12 && !isset($monthly[$month])) { $monthly[$month] = true; $keep[$path] = true; }
        }
        foreach ($paths as $path) {
            if (isset($keep[$path])) continue;
            @unlink($path);
            @unlink($path . '.sha256');
        }
    }
}
