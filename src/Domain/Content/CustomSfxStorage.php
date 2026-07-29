<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use RuntimeException;

final class CustomSfxStorage
{
    public function __construct(private string $directory, private int $maxBytes)
    {
        $this->directory = rtrim($this->directory, '/\\');
        $this->maxBytes = max(1024, $this->maxBytes);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function ensure(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create custom SFX storage directory.');
        }
        if (!is_writable($this->directory)) {
            throw new RuntimeException('Custom SFX storage directory is not writable.');
        }
    }

    /** @return array{path:string,bytes:int,sha256:string,extension:string} */
    public function store(int $sfxID, string $sourcePath, string $originalName): array
    {
        if ($sfxID <= 0 || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('Invalid custom SFX upload source.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'ogg') {
            throw new RuntimeException('Only .ogg SFX files are accepted.');
        }

        $bytes = filesize($sourcePath);
        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('The uploaded SFX is empty.');
        }
        if ($bytes > $this->maxBytes) {
            throw new RuntimeException('The uploaded SFX exceeds the configured size limit.');
        }
        if (!$this->looksLikeOgg($sourcePath)) {
            throw new RuntimeException('The uploaded file does not look like an Ogg stream.');
        }

        $this->ensure();
        $target = $this->path($sfxID, $extension);
        $temp = $target . '.tmp-' . bin2hex(random_bytes(6));

        $input = fopen($sourcePath, 'rb');
        $output = fopen($temp, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temp);
            throw new RuntimeException('Unable to open custom SFX storage stream.');
        }

        $copied = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        if ($copied === false || $copied !== $bytes) {
            @unlink($temp);
            throw new RuntimeException('Unable to persist the complete SFX file.');
        }
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException('Unable to finalize the custom SFX file.');
        }
        @chmod($target, 0644);

        $hash = hash_file('sha256', $target);
        if (!is_string($hash) || strlen($hash) !== 64) {
            @unlink($target);
            throw new RuntimeException('Unable to hash the custom SFX file.');
        }

        return ['path' => $target, 'bytes' => $bytes, 'sha256' => $hash, 'extension' => $extension];
    }

    public function path(int $sfxID, string $extension = 'ogg'): string
    {
        if ($sfxID <= 0 || $extension !== 'ogg') {
            throw new RuntimeException('Invalid custom SFX ID or extension.');
        }
        return $this->directory . DIRECTORY_SEPARATOR . $sfxID . '.' . $extension;
    }

    public function exists(int $sfxID, string $extension = 'ogg'): bool
    {
        return $sfxID > 0 && is_file($this->path($sfxID, $extension)) && is_readable($this->path($sfxID, $extension));
    }

    public function delete(int $sfxID, string $extension = 'ogg'): void
    {
        if ($sfxID <= 0) {
            return;
        }
        $path = $this->path($sfxID, $extension);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to delete the custom SFX file.');
        }
    }

    private function looksLikeOgg(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);
        return $header === 'OggS';
    }
}
