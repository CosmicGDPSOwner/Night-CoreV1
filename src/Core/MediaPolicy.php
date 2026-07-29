<?php

declare(strict_types=1);

namespace NightCore\Core;

use RuntimeException;

final class MediaPolicy
{
    private function __construct(
        private bool $publicUploads,
        private int $songMaxBytes,
        private int $sfxMaxBytes
    ) {
    }

    public static function load(string $root): self
    {
        $songMaxBytes = max(1024, Config::getInt('CUSTOM_SONG_MAX_BYTES', 26214400));
        $sfxMaxBytes = max(1024, Config::getInt('CUSTOM_SFX_MAX_BYTES', 10485760));
        $publicUploads = false;

        $path = rtrim($root, '/\\') . '/config/media.php';
        if (is_file($path)) {
            $settings = require $path;
            if (!is_array($settings)) {
                throw new RuntimeException('config/media.php must return an array.');
            }

            if (array_key_exists('public_uploads', $settings)) {
                $publicUploads = self::boolValue($settings['public_uploads'], 'public_uploads');
            }
            if (array_key_exists('song_max_mib', $settings)) {
                $songMaxBytes = self::mibToBytes($settings['song_max_mib'], 'song_max_mib');
            }
            if (array_key_exists('sfx_max_mib', $settings)) {
                $sfxMaxBytes = self::mibToBytes($settings['sfx_max_mib'], 'sfx_max_mib');
            }
        }

        return new self($publicUploads, $songMaxBytes, $sfxMaxBytes);
    }

    public function publicUploadsEnabled(): bool
    {
        return $this->publicUploads;
    }

    public function songMaxBytes(): int
    {
        return $this->songMaxBytes;
    }

    public function sfxMaxBytes(): int
    {
        return $this->sfxMaxBytes;
    }

    private static function boolValue(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        throw new RuntimeException('Invalid boolean value for media setting ' . $key . '.');
    }

    private static function mibToBytes(mixed $value, string $key): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Media setting ' . $key . ' must be an integer MiB value.');
        }
        $mib = (int) $value;
        if ($mib < 1 || $mib > 1024) {
            throw new RuntimeException('Media setting ' . $key . ' must be between 1 and 1024 MiB.');
        }
        return $mib * 1048576;
    }
}
