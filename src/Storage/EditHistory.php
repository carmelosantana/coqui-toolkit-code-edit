<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\CodeEdit\Storage;

/**
 * SQLite-backed edit history with file backups for undo support.
 *
 * Each mutating edit operation records the original file content as a backup
 * and logs the operation in SQLite. Supports undo by edit ID, undo last N
 * edits on a file, listing recent edits, and pruning old backups.
 *
 * The database and backup directory are created lazily on first use.
 */
final class EditHistory
{
    private ?\PDO $db = null;
    private readonly string $dbPath;
    private readonly string $backupDir;

    public function __construct(
        string $storagePath,
    ) {
        $this->dbPath = rtrim($storagePath, DIRECTORY_SEPARATOR) . '/history.db';
        $this->backupDir = rtrim($storagePath, DIRECTORY_SEPARATOR) . '/backups';
    }

    /**
     * Record an edit operation with a backup of the original content.
     *
     * @param array<string, mixed> $metadata Additional context about the operation.
     * @return int The edit ID.
     */
    public function record(
        string $filePath,
        string $operation,
        string $originalContent,
        array $metadata = [],
    ): int {
        $db = $this->connect();

        $timestamp = (new \DateTimeImmutable())->format('c');
        $backupName = sprintf('%s_%s', $timestamp, basename($filePath));
        $backupName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $backupName) ?? $backupName;
        $backupPath = $this->backupDir . DIRECTORY_SEPARATOR . $backupName;

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        if (@file_put_contents($backupPath, $originalContent) === false) {
            throw new \RuntimeException('Failed to write backup: ' . $backupPath);
        }

        $stmt = $db->prepare(
            'INSERT INTO edits (file_path, operation, timestamp, backup_path, metadata)
             VALUES (:file_path, :operation, :timestamp, :backup_path, :metadata)',
        );
        $stmt->execute([
            ':file_path' => $filePath,
            ':operation' => $operation,
            ':timestamp' => $timestamp,
            ':backup_path' => $backupPath,
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Retrieve the backup content for a specific edit.
     *
     * @return array{id: int, file_path: string, operation: string, timestamp: string, content: string}
     */
    public function getBackup(int $editId): array
    {
        $db = $this->connect();

        $stmt = $db->prepare('SELECT * FROM edits WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        /** @var array{id: int, file_path: string, operation: string, timestamp: string, backup_path: string, metadata: string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException(sprintf('Edit #%d not found', $editId));
        }

        $content = @file_get_contents($row['backup_path']);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Backup file missing for edit #%d', $editId));
        }

        return [
            'id' => (int) $row['id'],
            'file_path' => $row['file_path'],
            'operation' => $row['operation'],
            'timestamp' => $row['timestamp'],
            'content' => $content,
        ];
    }

    /**
     * Get the last N edits for a specific file.
     *
     * @return list<array{id: int, file_path: string, operation: string, timestamp: string, content: string}>
     */
    public function getLastEdits(string $filePath, int $count = 1): array
    {
        $db = $this->connect();

        $stmt = $db->prepare(
            'SELECT * FROM edits WHERE file_path = :file_path ORDER BY id DESC LIMIT :count',
        );
        $stmt->bindValue(':file_path', $filePath);
        $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            /** @var array{id: int, file_path: string, operation: string, timestamp: string, backup_path: string, metadata: string} $row */
            $content = @file_get_contents($row['backup_path']);
            if ($content === false) {
                continue; // Skip edits with missing backup files
            }

            $results[] = [
                'id' => (int) $row['id'],
                'file_path' => $row['file_path'],
                'operation' => $row['operation'],
                'timestamp' => $row['timestamp'],
                'content' => $content,
            ];
        }

        return $results;
    }

    /**
     * Remove an edit record and its backup file.
     */
    public function removeEdit(int $editId): void
    {
        $db = $this->connect();

        $stmt = $db->prepare('SELECT backup_path FROM edits WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        /** @var array{backup_path: string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false && is_file($row['backup_path'])) {
            @unlink($row['backup_path']);
        }

        $stmt = $db->prepare('DELETE FROM edits WHERE id = :id');
        $stmt->execute([':id' => $editId]);
    }

    /**
     * List recent edits, optionally filtered by file path.
     *
     * @return list<array{id: int, file_path: string, operation: string, timestamp: string, metadata: string}>
     */
    public function list(?string $filePath = null, int $limit = 20): array
    {
        $db = $this->connect();

        if ($filePath !== null) {
            $stmt = $db->prepare(
                'SELECT id, file_path, operation, timestamp, metadata FROM edits
                 WHERE file_path = :file_path ORDER BY id DESC LIMIT :limit',
            );
            $stmt->bindValue(':file_path', $filePath);
        } else {
            $stmt = $db->prepare(
                'SELECT id, file_path, operation, timestamp, metadata FROM edits
                 ORDER BY id DESC LIMIT :limit',
            );
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            /** @var array{id: int|string, file_path: string, operation: string, timestamp: string, metadata: string} $row */
            $results[] = [
                'id' => (int) $row['id'],
                'file_path' => $row['file_path'],
                'operation' => $row['operation'],
                'timestamp' => $row['timestamp'],
                'metadata' => $row['metadata'],
            ];
        }

        return $results;
    }

    /**
     * Prune edit records and backup files older than the given number of days.
     *
     * @return int Number of edits pruned.
     */
    public function prune(int $keepDays = 7): int
    {
        $db = $this->connect();

        $cutoff = (new \DateTimeImmutable())->modify("-{$keepDays} days")->format('c');

        // Fetch backup paths before deleting rows
        $stmt = $db->prepare('SELECT backup_path FROM edits WHERE timestamp <= :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        $count = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            /** @var array{backup_path: string} $row */
            if (is_file($row['backup_path'])) {
                @unlink($row['backup_path']);
            }
            $count++;
        }

        $stmt = $db->prepare('DELETE FROM edits WHERE timestamp <= :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $count;
    }

    private function connect(): \PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->db = new \PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS edits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path TEXT NOT NULL,
                operation TEXT NOT NULL,
                timestamp TEXT NOT NULL,
                backup_path TEXT NOT NULL,
                metadata TEXT DEFAULT "{}"
            )',
        );

        return $this->db;
    }
}
