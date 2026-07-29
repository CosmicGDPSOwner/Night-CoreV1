<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use RuntimeException;
use Throwable;

final class CustomSongService
{
    public function __construct(
        private ContentRepository $content,
        private CustomSongStorage $storage,
        private CustomSongIdAllocator $ids
    ) {
    }

    public function storage(): CustomSongStorage
    {
        return $this->storage;
    }

    public function minSongID(): int
    {
        return $this->ids->minID();
    }

    public function maxSongID(): int
    {
        return $this->ids->maxID();
    }

    /** @return array{songID:int,name:string,authorName:string,size:string,download:string,bytes:int,sha256:string} */
    public function import(string $sourcePath, string $originalName, string $name, string $authorName, string $publicBaseUrl): array
    {
        $name = $this->field($name, 255);
        $authorName = $this->field($authorName, 255);
        $originalName = $this->field(basename($originalName), 255);
        if ($name === '' || $authorName === '') {
            throw new RuntimeException('Song name and author are required.');
        }
        if ($originalName === '') {
            $originalName = 'song.mp3';
        }
        $publicBaseUrl = $this->publicBaseUrl($publicBaseUrl);

        $songID = $this->ids->reserve($originalName, time());
        $stored = null;
        try {
            $stored = $this->storage->store($songID, $sourcePath, $originalName);
            $size = number_format($stored['bytes'] / 1048576, 2, '.', '');
            $download = $publicBaseUrl . '/downloadCustomSong.php?songID=' . $songID;

            $this->content->finalizeLocalSong($songID, $stored['sha256'], $stored['bytes']);
            $this->content->upsertSong([
                'songID' => $songID,
                'name' => $name,
                'authorID' => 0,
                'authorName' => $authorName,
                'size' => $size,
                'download' => $download,
                'isDisabled' => 0,
                'createdAt' => time(),
            ]);
            $this->content->clearSongFetchFailure($songID);

            return [
                'songID' => $songID,
                'name' => $name,
                'authorName' => $authorName,
                'size' => $size,
                'download' => $download,
                'bytes' => $stored['bytes'],
                'sha256' => $stored['sha256'],
            ];
        } catch (Throwable $e) {
            if ($stored !== null) {
                try {
                    $this->storage->delete($songID);
                } catch (Throwable) {
                }
            }
            try {
                $this->content->deleteLocalSongRows($songID);
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function downloadRecord(int $songID): ?array
    {
        if ($songID <= 0) {
            return null;
        }
        $row = $this->content->findLocalSong($songID);
        if ($row === null || (int) ($row['isDisabled'] ?? 1) === 1 || !$this->storage->exists($songID)) {
            return null;
        }
        $row['path'] = $this->storage->path($songID);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 100): array
    {
        return $this->content->listLocalSongs($limit);
    }

    public function delete(int $songID): bool
    {
        $row = $this->content->findLocalSong($songID);
        if ($row === null) {
            return false;
        }
        $this->storage->delete($songID);
        $this->content->deleteLocalSongRows($songID);
        return true;
    }

    private function publicBaseUrl(string $value): string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new RuntimeException('Custom song public base URL must use http or https.');
        }
        if (trim((string) ($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Custom song public base URL must contain a normal public host.');
        }
        return $value;
    }

    private function field(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim($value)) ?? '';
        $value = str_replace(['~|~', '~:~', '#'], '', $value);
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
