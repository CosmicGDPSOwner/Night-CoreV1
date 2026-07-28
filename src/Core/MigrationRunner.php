<?php

declare(strict_types=1);

namespace NightCore\Core;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    /** @return list<string> */
    public function migrate(string $directory): array
    {
        $this->ensureTrackingTable();

        $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);
            if ($this->isApplied($name)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration: ' . $name);
            }

            $sql = str_replace('{{prefix}}', $this->tables->prefix(), $sql);
            foreach ($this->splitStatements($sql) as $statement) {
                $this->db->exec($statement);
            }

            $query = $this->db->prepare(
                'INSERT INTO ' . $this->tables->get('core_migrations') . ' (migration, appliedAt) VALUES (:migration, :appliedAt)'
            );
            $query->execute([':migration' => $name, ':appliedAt' => time()]);
            $applied[] = $name;
        }

        return $applied;
    }

    private function ensureTrackingTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->tables->get('core_migrations') . ' (' .
            'migration VARCHAR(190) NOT NULL PRIMARY KEY, appliedAt BIGINT NOT NULL' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function isApplied(string $name): bool
    {
        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->tables->get('core_migrations') . ' WHERE migration = :migration'
        );
        $query->execute([':migration' => $name]);
        return (int) $query->fetchColumn() > 0;
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $clean = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $clean) ?: [];
        $statements = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }
}
