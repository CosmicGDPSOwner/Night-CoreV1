<?php

declare(strict_types=1);

namespace NightCore\Core;

use RuntimeException;

final class DeploymentInspector
{
    /** @return list<array{name:string,label:string,ok:bool,detail:string,critical:bool}> */
    public static function inspect(Application $app, string $root): array
    {
        $root = rtrim($root, '/\\');
        $checks = [];

        $checks[] = self::check(
            'php_version',
            'PHP >= 8.1',
            PHP_VERSION_ID >= 80100,
            PHP_VERSION,
            true
        );
        $checks[] = self::check('pdo', 'PDO extension', extension_loaded('pdo'), extension_loaded('pdo') ? 'loaded' : 'missing', true);
        $checks[] = self::check(
            'pdo_mysql',
            'pdo_mysql extension',
            extension_loaded('pdo_mysql'),
            extension_loaded('pdo_mysql') ? 'loaded' : 'missing',
            true
        );

        try {
            $app->db()->query('SELECT 1')->fetchColumn();
            $checks[] = self::check('database', 'Database connection', true, 'reachable', true);
        } catch (\Throwable) {
            $checks[] = self::check('database', 'Database connection', false, 'unavailable', true);
        }

        foreach (['accounts', 'users', 'core_migrations'] as $table) {
            $exists = $app->schema()->tableExists($table);
            $checks[] = self::check(
                'table_' . $table,
                'Table ' . $app->tables()->raw($table),
                $exists,
                $exists ? 'present' : 'missing',
                true
            );
        }

        $pending = self::pendingMigrations($app, $root . '/migrations');
        $checks[] = self::check(
            'migrations',
            'Database migrations',
            $pending === [],
            $pending === [] ? 'current' : 'pending: ' . implode(', ', $pending),
            true
        );

        $storage = self::levelStoragePath($root);
        $storageOk = is_dir($storage) && is_writable($storage);
        $checks[] = self::check(
            'level_storage',
            'Level storage',
            $storageOk,
            $storageOk ? $storage . ' (writable)' : $storage . ' (missing or not writable)',
            true
        );

        $publicMediaUploads = MediaPolicy::load($root)->publicUploadsEnabled();
        $legacySongAdminEnabled = trim(Config::get('CUSTOM_SONG_ADMIN_TOKEN', '') ?? '') !== '';
        $songWriteRequired = $publicMediaUploads || $legacySongAdminEnabled;
        $sfxWriteRequired = $publicMediaUploads;

        $songStorage = self::customSongStoragePath($root);
        $localSongCount = 0;
        if ($app->schema()->tableExists('core_local_songs')) {
            try {
                $localSongCount = (int) $app->db()->query('SELECT COUNT(*) FROM ' . $app->tables()->get('core_local_songs'))->fetchColumn();
            } catch (\Throwable) {
                $localSongCount = 0;
            }
        }
        $songStorageRequired = $songWriteRequired || $localSongCount > 0;
        $songStorageOk = !$songStorageRequired || (is_dir($songStorage) && is_readable($songStorage) && (!$songWriteRequired || is_writable($songStorage)));
        if (!$songStorageRequired) {
            $songStorageDetail = 'disabled; ' . $songStorage;
        } elseif ($songStorageOk) {
            $songStorageDetail = $songStorage . ($songWriteRequired ? ' (writable)' : ' (readable)');
        } else {
            $songStorageDetail = $songStorage . ' (missing or insufficient permissions)';
        }
        $checks[] = self::check('custom_song_storage', 'Custom song storage', $songStorageOk, $songStorageDetail, $songStorageRequired);

        $sfxStorage = self::customSfxStoragePath($root);
        $localSfxCount = 0;
        if ($app->schema()->tableExists('core_local_sfx')) {
            try {
                $localSfxCount = (int) $app->db()->query('SELECT COUNT(*) FROM ' . $app->tables()->get('core_local_sfx') . ' WHERE bytes > 0')->fetchColumn();
            } catch (\Throwable) {
                $localSfxCount = 0;
            }
        }
        $sfxStorageRequired = $sfxWriteRequired || $localSfxCount > 0;
        $sfxStorageOk = !$sfxStorageRequired || (is_dir($sfxStorage) && is_readable($sfxStorage) && (!$sfxWriteRequired || is_writable($sfxStorage)));
        if (!$sfxStorageRequired) {
            $sfxStorageDetail = 'disabled; ' . $sfxStorage;
        } elseif ($sfxStorageOk) {
            $sfxStorageDetail = $sfxStorage . ($sfxWriteRequired ? ' (writable)' : ' (readable)');
        } else {
            $sfxStorageDetail = $sfxStorage . ' (missing or insufficient permissions)';
        }
        $checks[] = self::check('custom_sfx_storage', 'Custom SFX storage', $sfxStorageOk, $sfxStorageDetail, $sfxStorageRequired);

        $appEnv = strtolower(trim(Config::get('APP_ENV', 'development') ?? 'development'));
        $debugEnabled = Config::getBool('APP_DEBUG', false);
        $checks[] = self::check(
            'debug',
            'Debug mode',
            !$debugEnabled,
            $debugEnabled ? 'enabled' : 'disabled',
            $appEnv === 'production'
        );

        $dbUser = strtolower(trim(Config::get('DB_USER', '') ?? ''));
        $checks[] = self::check(
            'db_user',
            'Dedicated database user',
            $dbUser !== '' && $dbUser !== 'root',
            $dbUser === '' ? 'not configured' : ($dbUser === 'root' ? 'root is not recommended' : 'configured'),
            false
        );

        return $checks;
    }

    public static function allCriticalOk(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['critical'] ?? false) && !($check['ok'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    public static function levelStoragePath(string $root): string
    {
        $configured = trim(Config::get('LEVEL_STORAGE_PATH', '') ?? '');
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }
        return rtrim($root, '/\\') . '/data/levels';
    }

    public static function customSongStoragePath(string $root): string
    {
        $configured = trim(Config::get('CUSTOM_SONG_STORAGE_PATH', '') ?? '');
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }
        return rtrim($root, '/\\') . '/data/songs';
    }

    public static function customSfxStoragePath(string $root): string
    {
        $configured = trim(Config::get('CUSTOM_SFX_STORAGE_PATH', '') ?? '');
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }
        return rtrim($root, '/\\') . '/data/sfx';
    }

    public static function ensureLevelStorage(string $root): string
    {
        $path = self::levelStoragePath($root);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create level storage directory.');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('Level storage directory is not writable.');
        }
        return $path;
    }

    public static function ensureCustomSongStorage(string $root): string
    {
        $path = self::customSongStoragePath($root);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create custom song storage directory.');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('Custom song storage directory is not writable.');
        }
        return $path;
    }

    public static function ensureCustomSfxStorage(string $root): string
    {
        $path = self::customSfxStoragePath($root);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create custom SFX storage directory.');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('Custom SFX storage directory is not writable.');
        }
        return $path;
    }

    /** @return list<string> */
    public static function pendingMigrations(Application $app, string $directory): array
    {
        $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $expected = array_map('basename', $files);
        if ($expected === []) {
            return [];
        }

        if (!$app->schema()->tableExists('core_migrations')) {
            return $expected;
        }

        $rows = $app->db()->query(
            'SELECT migration FROM ' . $app->tables()->get('core_migrations') . ' ORDER BY migration ASC'
        )->fetchAll();
        $applied = [];
        foreach ($rows as $row) {
            if (isset($row['migration']) && is_string($row['migration'])) {
                $applied[] = $row['migration'];
            }
        }

        return array_values(array_diff($expected, $applied));
    }

    /** @return array{name:string,label:string,ok:bool,detail:string,critical:bool} */
    private static function check(string $name, string $label, bool $ok, string $detail, bool $critical): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'ok' => $ok,
            'detail' => $detail,
            'critical' => $critical,
        ];
    }
}
