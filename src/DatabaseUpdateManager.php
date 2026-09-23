<?php
declare(strict_types=1);

namespace SevillaMatrix;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseUpdateManager
{
    private const LOCK_NAME = 'sevilla_matrix_database_updates';
    private readonly string $updatesPath;

    public function __construct(
        private readonly PDO $pdo,
        ?string $updatesPath = null
    ) {
        $this->updatesPath = $updatesPath ?? PROJECT_ROOT . '/database/updates';
        $this->ensureRegistry();
    }

    public function status(): array
    {
        $applied = $this->appliedUpdates();
        $updates = [];

        foreach ($this->definitions() as $definition) {
            $record = $applied[$definition['id']] ?? null;
            $state = 'pending';
            if ($record) {
                $state = hash_equals((string)$record['checksum'], $definition['checksum'])
                    ? 'applied'
                    : 'modified';
            }

            $updates[] = [
                'id' => $definition['id'],
                'name' => $definition['name'],
                'description' => $definition['description'],
                'category' => $definition['category'],
                'requires_backup' => $definition['requires_backup'],
                'transactional' => $definition['transactional'],
                'statement_count' => count($definition['statements']),
                'checksum' => substr($definition['checksum'], 0, 12),
                'state' => $state,
                'applied_at' => $record['applied_at'] ?? null,
                'execution_ms' => isset($record['execution_ms']) ? (int)$record['execution_ms'] : null,
                'statements_executed' => isset($record['statements_executed']) ? (int)$record['statements_executed'] : null,
            ];
        }

        return [
            'updates' => $updates,
            'pending_count' => count(array_filter($updates, static fn(array $item): bool => $item['state'] === 'pending')),
            'applied_count' => count(array_filter($updates, static fn(array $item): bool => $item['state'] === 'applied')),
            'modified_count' => count(array_filter($updates, static fn(array $item): bool => $item['state'] === 'modified')),
        ];
    }

    public function apply(string $id): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $id)) {
            throw new RuntimeException('Identificador de actualización no válido.');
        }

        $definition = null;
        foreach ($this->definitions() as $candidate) {
            if ($candidate['id'] === $id) {
                $definition = $candidate;
                break;
            }
        }
        if (!$definition) {
            throw new RuntimeException('La actualización solicitada no existe en esta versión de la aplicación.');
        }

        if (!$this->acquireLock()) {
            throw new RuntimeException('Ya hay otra actualización de la base de datos en ejecución.');
        }

        $started = hrtime(true);
        $executed = 0;

        try {
            $applied = $this->appliedUpdates()[$id] ?? null;
            if ($applied && hash_equals((string)$applied['checksum'], $definition['checksum'])) {
                return [
                    'id' => $id,
                    'state' => 'already_applied',
                    'message' => 'La actualización ya estaba aplicada.',
                ];
            }
            if ($applied) {
                throw new RuntimeException('Esta actualización cambió después de aplicarse. Crea una nueva actualización en lugar de sobrescribirla.');
            }

            if ($definition['transactional']) {
                $this->pdo->beginTransaction();
            }

            foreach ($definition['statements'] as $statement) {
                $this->pdo->exec($statement);
                $executed++;
            }

            $elapsedMs = (int)round((hrtime(true) - $started) / 1_000_000);
            $stmt = $this->pdo->prepare(
                'INSERT INTO schema_migrations
                 (migration_id, name, checksum, applied_at, execution_ms, statements_executed)
                 VALUES (:id, :name, :checksum, NOW(), :execution_ms, :statements_executed)'
            );
            $stmt->execute([
                'id' => $definition['id'],
                'name' => $definition['name'],
                'checksum' => $definition['checksum'],
                'execution_ms' => $elapsedMs,
                'statements_executed' => $executed,
            ]);

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return [
                'id' => $id,
                'state' => 'applied',
                'message' => 'Actualización aplicada correctamente.',
                'execution_ms' => $elapsedMs,
                'statements_executed' => $executed,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException(
                "La actualización se detuvo en la sentencia " . ($executed + 1) . ': ' . $error->getMessage(),
                0,
                $error
            );
        } finally {
            $this->releaseLock();
        }
    }

    private function ensureRegistry(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration_id VARCHAR(120) NOT NULL,
                name VARCHAR(180) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
                statements_executed INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uq_schema_migration (migration_id)
            ) ENGINE=InnoDB'
        );
    }

    private function appliedUpdates(): array
    {
        $records = $this->pdo->query(
            'SELECT migration_id, name, checksum, applied_at, execution_ms, statements_executed
             FROM schema_migrations ORDER BY applied_at, id'
        )->fetchAll();

        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record['migration_id']] = $record;
        }
        return $indexed;
    }

    private function definitions(): array
    {
        if (!is_dir($this->updatesPath)) {
            return [];
        }

        $files = glob($this->updatesPath . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $definitions = [];
        $knownIds = [];

        foreach ($files as $manifestPath) {
            $manifest = require $manifestPath;
            if (!is_array($manifest)) {
                throw new RuntimeException('Manifiesto de actualización no válido: ' . basename($manifestPath));
            }

            $id = (string)($manifest['id'] ?? '');
            $name = trim((string)($manifest['name'] ?? ''));
            $sqlFile = (string)($manifest['sql_file'] ?? '');
            $realSqlFile = realpath($sqlFile);
            $databaseRoot = realpath(PROJECT_ROOT . '/database');

            if (!preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $id) || $name === '' || !$realSqlFile || !$databaseRoot
                || !str_starts_with($realSqlFile, $databaseRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Actualización incompleta o fuera del directorio permitido: ' . basename($manifestPath));
            }
            if (isset($knownIds[$id])) {
                throw new RuntimeException('Identificador de actualización duplicado: ' . $id);
            }
            $knownIds[$id] = true;

            $contents = file_get_contents($realSqlFile);
            if ($contents === false) {
                throw new RuntimeException('No se pudo leer el SQL de la actualización ' . $id . '.');
            }

            $requiresBackup = (bool)($manifest['requires_backup'] ?? true);
            $transactional = (bool)($manifest['transactional'] ?? true);
            $checksumSource = json_encode([
                'id' => $id,
                'name' => $name,
                'transactional' => $transactional,
                'requires_backup' => $requiresBackup,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . $contents;

            $definitions[] = [
                'id' => $id,
                'name' => $name,
                'description' => trim((string)($manifest['description'] ?? '')),
                'category' => trim((string)($manifest['category'] ?? 'Base de datos')),
                'requires_backup' => $requiresBackup,
                'transactional' => $transactional,
                'checksum' => hash('sha256', $checksumSource),
                'statements' => $this->splitSql($contents),
            ];
        }

        return $definitions;
    }

    private function splitSql(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if (($char === '-' && $next === '-') || $char === '#') {
                $lineComment = true;
                if ($char === '-') $i++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                $buffer = '';
                if ($statement !== '' && !preg_match('/^(START\s+TRANSACTION|COMMIT|ROLLBACK)$/i', $statement)) {
                    $statements[] = $statement;
                }
                continue;
            }
            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }
        if (!$statements) {
            throw new RuntimeException('La actualización no contiene sentencias SQL ejecutables.');
        }
        return $statements;
    }

    private function acquireLock(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $stmt->execute(['lock_name' => self::LOCK_NAME]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute(['lock_name' => self::LOCK_NAME]);
        } catch (Throwable) {
            // Do not hide the result or the original exception if MySQL drops the session.
        }
    }
}
