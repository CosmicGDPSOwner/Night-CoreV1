<?php

declare(strict_types=1);

namespace NightCore\Core;

use InvalidArgumentException;

final class TableNames
{
    public function __construct(private string $prefix = '')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new InvalidArgumentException('CORE_TABLE_PREFIX may contain only letters, numbers and underscores.');
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function raw(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid table name.');
        }

        return $this->prefix . $table;
    }

    public function get(string $table): string
    {
        return '`' . $this->raw($table) . '`';
    }
}
