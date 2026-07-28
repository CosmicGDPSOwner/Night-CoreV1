<?php

declare(strict_types=1);

namespace NightCore\Core;

use PDO;
use Throwable;

final class SchemaInspector
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->db->query('SELECT 1 FROM ' . $this->tables->get($table) . ' LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $query = $this->db->query('SHOW COLUMNS FROM ' . $this->tables->get($table) . " LIKE " . $this->db->quote($column));
            return $query !== false && $query->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
