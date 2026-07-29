<?php

declare(strict_types=1);

namespace NightCore\Domain\Levels;

use NightCore\Core\TableNames;
use NightCore\Domain\Content\NewgroundsSongProvider;
use PDO;

final class LevelSongProvider
{
    public function __construct(
        private PDO $db,
        private TableNames $tables,
        private ?NewgroundsSongProvider $externalSongs = null
    ) {
    }

    public function decorate(string $response): string
    {
        if ($response === '-1') {
            return $response;
        }

        $sections = explode('#', $response);
        // GD 2.0+ level search framing is levels#users#songs#pagination#hash.
        if (count($sections) < 5) {
            return $response;
        }

        $songIDs = $this->extractSongIDs($sections[0]);
        if ($songIDs === []) {
            return $response;
        }

        $placeholders = implode(',', array_fill(0, count($songIDs), '?'));
        $query = $this->db->prepare(
            'SELECT songID, name, authorID, authorName, size, download, isDisabled FROM ' .
            $this->tables->get('core_songs') . ' WHERE songID IN (' . $placeholders . ')'
        );
        $query->execute($songIDs);

        $songRows = [];
        foreach ($query->fetchAll() as $song) {
            $songRows[(int) $song['songID']] = $song;
        }

        if ($this->externalSongs !== null) {
            foreach ($songIDs as $songID) {
                if (!isset($songRows[$songID])) {
                    $fetched = $this->externalSongs->findOrFetch($songID);
                    if ($fetched !== null) {
                        $songRows[$songID] = $fetched;
                    }
                }
            }
        }

        $ordered = [];
        foreach ($songIDs as $songID) {
            if (!isset($songRows[$songID]) || (int) $songRows[$songID]['isDisabled'] === 1) {
                continue;
            }
            $ordered[] = $this->formatSong($songRows[$songID]);
        }

        $sections[2] = implode('~:~', $ordered);
        return implode('#', $sections);
    }

    /** @param array<string,mixed> $song */
    private function formatSong(array $song): string
    {
        $download = (string) $song['download'];
        if (str_contains($download, ':')) {
            $download = rawurlencode($download);
        }
        return implode('~|~', [
            '1', (string) (int) $song['songID'],
            '2', $this->field((string) $song['name'], 255),
            '3', (string) (int) $song['authorID'],
            '4', $this->field((string) $song['authorName'], 255),
            '5', (string) $song['size'],
            '6', '',
            '10', $download,
            '7', '',
            '8', '1',
        ]);
    }

    /** @return array<int,int> */
    private function extractSongIDs(string $levelSection): array
    {
        $ids = [];
        foreach (explode('|', $levelSection) as $level) {
            $parts = explode(':', $level);
            for ($i = 0, $count = count($parts) - 1; $i < $count; $i += 2) {
                if ($parts[$i] === '35' && ctype_digit($parts[$i + 1])) {
                    $songID = (int) $parts[$i + 1];
                    if ($songID > 0) {
                        $ids[] = $songID;
                    }
                    break;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function field(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = str_replace(['#', '~|~', '~:~'], '', $value);
        return substr($value, 0, $maxLength);
    }
}
