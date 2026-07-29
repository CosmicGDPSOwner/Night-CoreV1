<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use RuntimeException;

final class LevelStorage
{
    public function __construct(private string $directory, private int $maxBytes)
    {
    }

    public function write(int $levelID, string $levelString): void
    {
        if ($levelID <= 0 || $levelString === '' || strlen($levelString) > $this->maxBytes) {
            throw new RuntimeException('Invalid level payload.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create level storage directory.');
        }

        $target = $this->path($levelID);
        $temp = $target . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, $levelString, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write level payload.');
        }
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException('Unable to finalize level payload.');
        }
    }

    public function read(int $levelID): ?string
    {
        $path = $this->path($levelID);
        if (!is_file($path)) {
            return null;
        }
        $value = file_get_contents($path);
        return $value === false ? null : $value;
    }

    public function delete(int $levelID): void
    {
        if ($levelID <= 0) {
            return;
        }
        $path = $this->path($levelID);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to delete level payload.');
        }
    }

    private function path(int $levelID): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $levelID;
    }
}
