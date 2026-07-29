<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use RuntimeException;

final class CustomSongStorage
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
            throw new RuntimeException('Unable to create custom song storage directory.');
        }
        if (!is_writable($this->directory)) {
            throw new RuntimeException('Custom song storage directory is not writable.');
        }
    }

    /** @return array{path:string,bytes:int,sha256:string} */
    public function store(int $songID, string $sourcePath, string $originalName): array
    {
        if ($songID <= 0 || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('Invalid custom song upload source.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'mp3') {
            throw new RuntimeException('Only .mp3 files are accepted.');
        }

        $bytes = filesize($sourcePath);
        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('The uploaded MP3 is empty.');
        }
        if ($bytes > $this->maxBytes) {
            throw new RuntimeException('The uploaded MP3 exceeds the configured size limit.');
        }
        if (!$this->looksLikeMp3($sourcePath)) {
            throw new RuntimeException('The uploaded file does not look like an MP3 stream.');
        }

        $this->ensure();
        $target = $this->path($songID);
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
            throw new RuntimeException('Unable to open custom song storage stream.');
        }

        $copied = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        if ($copied === false || $copied !== $bytes) {
            @unlink($temp);
            throw new RuntimeException('Unable to persist the complete MP3 file.');
        }
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException('Unable to finalize the custom song file.');
        }
        @chmod($target, 0644);

        $hash = hash_file('sha256', $target);
        if (!is_string($hash) || strlen($hash) !== 64) {
            @unlink($target);
            throw new RuntimeException('Unable to hash the custom song file.');
        }

        return ['path' => $target, 'bytes' => $bytes, 'sha256' => $hash];
    }

    public function path(int $songID): string
    {
        if ($songID <= 0) {
            throw new RuntimeException('Invalid custom song ID.');
        }
        return $this->directory . DIRECTORY_SEPARATOR . $songID . '.mp3';
    }

    public function exists(int $songID): bool
    {
        return $songID > 0 && is_file($this->path($songID)) && is_readable($this->path($songID));
    }

    public function delete(int $songID): void
    {
        if ($songID <= 0) {
            return;
        }
        $path = $this->path($songID);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to delete the custom song file.');
        }
    }

    private function looksLikeMp3(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $header = fread($handle, 3);
        fclose($handle);
        if (!is_string($header) || strlen($header) < 2) {
            return false;
        }
        if (strlen($header) >= 3 && $header === 'ID3') {
            return true;
        }

        return ord($header[0]) === 0xFF && (ord($header[1]) & 0xE0) === 0xE0;
    }
}
