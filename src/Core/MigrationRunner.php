<?php

declare(strict_types=1);

namespace NightCore\Core;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    private SchemaInspector $schema;

    public function __construct(private PDO $db, private TableNames $tables)
    {
        $this->schema = new SchemaInspector($db, $tables);
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

            $this->applyDirectives($sql);
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

    private function applyDirectives(string $sql): void
    {
        preg_match_all(
            '/^\s*--\s*@ensure-column\s+([A-Za-z0-9_]+)\s+([A-Za-z0-9_]+)\s+(.+?)\s*$/m',
            $sql,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $table = $match[1];
            $column = $match[2];
            $definition = trim($match[3]);

            if (
                $definition === '' ||
                !$this->schema->tableExists($table) ||
                !$this->isAllowedColumnDefinition($definition) ||
                $this->schema->columnExists($table, $column)
            ) {
                continue;
            }

            $this->db->exec(
                'ALTER TABLE ' . $this->tables->get($table) . ' ADD COLUMN `' . $column . '` ' . $definition
            );
        }
    }

    private function isAllowedColumnDefinition(string $definition): bool
    {
        return (bool) preg_match(
            '/^(?:TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|FLOAT|DOUBLE|REAL|BIT|BOOLEAN|BOOL|CHAR|VARCHAR|TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|BINARY|VARBINARY|TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB|DATE|DATETIME|TIMESTAMP|TIME|YEAR|JSON|ENUM|SET)(?:\b|\()/i',
            $definition
        );
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
