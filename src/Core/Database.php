<?php

declare(strict_types=1);

namespace NightCore\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /** @param array{dsn:?string,host:string,port:int,name:string,user:string,password:string,charset:string} $config */
    public static function connect(array $config): PDO
    {
        $dsn = $config['dsn'];
        if ($dsn === null || $dsn === '') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['name'],
                $config['charset']
            );
        }

        try {
            return new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }
}
