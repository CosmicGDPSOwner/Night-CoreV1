<?php

declare(strict_types=1);

namespace NightCore\Core;

use RuntimeException;

final class MediaPolicy
{
    private function __construct(
        private bool $publicUploads,
        private int $songMaxBytes,
        private int $sfxMaxBytes,
        private int $uploadCooldownSeconds,
        private int $uploadsPerHourPerIp,
        private int $globalUploadsPerHour,
        private int $minimumFreeBytes
    ) {
    }

    public static function load(string $root): self
    {
        $songMaxBytes = max(1024, Config::getInt('CUSTOM_SONG_MAX_BYTES', 26214400));
        $sfxMaxBytes = max(1024, Config::getInt('CUSTOM_SFX_MAX_BYTES', 10485760));
        $publicUploads = false;
        $uploadCooldownSeconds = 30;
        $uploadsPerHourPerIp = 10;
        $globalUploadsPerHour = 200;
        $minimumFreeBytes = 512 * 1048576;

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
            if (array_key_exists('upload_cooldown_seconds', $settings)) {
                $uploadCooldownSeconds = self::boundedInt($settings['upload_cooldown_seconds'], 'upload_cooldown_seconds', 0, 86400);
            }
            if (array_key_exists('uploads_per_hour_per_ip', $settings)) {
                $uploadsPerHourPerIp = self::boundedInt($settings['uploads_per_hour_per_ip'], 'uploads_per_hour_per_ip', 0, 10000);
            }
            if (array_key_exists('global_uploads_per_hour', $settings)) {
                $globalUploadsPerHour = self::boundedInt($settings['global_uploads_per_hour'], 'global_uploads_per_hour', 0, 100000);
            }
            if (array_key_exists('minimum_free_space_mib', $settings)) {
                $minimumFreeBytes = self::mibToBytes($settings['minimum_free_space_mib'], 'minimum_free_space_mib');
            }
        }

        return new self(
            $publicUploads,
            $songMaxBytes,
            $sfxMaxBytes,
            $uploadCooldownSeconds,
            $uploadsPerHourPerIp,
            $globalUploadsPerHour,
            $minimumFreeBytes
        );
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

    public function uploadCooldownSeconds(): int
    {
        return $this->uploadCooldownSeconds;
    }

    public function uploadsPerHourPerIp(): int
    {
        return $this->uploadsPerHourPerIp;
    }

    public function globalUploadsPerHour(): int
    {
        return $this->globalUploadsPerHour;
    }

    public function minimumFreeBytes(): int
    {
        return $this->minimumFreeBytes;
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
        $mib = self::boundedInt($value, $key, 1, 1024 * 1024);
        return $mib * 1048576;
    }

    private static function boundedInt(mixed $value, string $key, int $min, int $max): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\\d+$/', $value) === 1)) {
            throw new RuntimeException('Media setting ' . $key . ' must be an integer value.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new RuntimeException('Media setting ' . $key . ' must be between ' . $min . ' and ' . $max . '.');
        }
        return $number;
    }
}
