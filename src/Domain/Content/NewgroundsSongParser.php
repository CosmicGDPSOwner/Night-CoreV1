<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

final class NewgroundsSongParser
{
    /** @return array<string,mixed>|null */
    public function parseBoomlings(string $payload, int $expectedSongID): ?array
    {
        $payload = trim($payload);
        if ($payload === '' || $payload === '-1' || $payload === '-2' || !str_contains($payload, '~|~')) {
            return null;
        }

        $parts = explode('~|~', $payload);
        $fields = [];
        for ($i = 0, $count = count($parts) - 1; $i < $count; $i += 2) {
            $fields[(string) $parts[$i]] = (string) $parts[$i + 1];
        }

        $songID = isset($fields['1']) && ctype_digit($fields['1']) ? (int) $fields['1'] : 0;
        $download = rawurldecode((string) ($fields['10'] ?? ''));
        if ($songID !== $expectedSongID || !$this->validDownloadUrl($download)) {
            return null;
        }

        return $this->songRow(
            $songID,
            (string) ($fields['2'] ?? ''),
            isset($fields['3']) && is_numeric($fields['3']) ? (int) $fields['3'] : 0,
            (string) ($fields['4'] ?? ''),
            isset($fields['5']) && is_numeric($fields['5']) ? (float) $fields['5'] : 0.0,
            $download
        );
    }

    /** @return array<string,mixed>|null */
    public function parseNewgroundsPage(string $html, int $songID): ?array
    {
        if ($html === '') {
            return null;
        }

        $download = '';
        if (preg_match_all('/"url"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $html, $matches)) {
            foreach ($matches[1] as $encoded) {
                $candidate = $this->decodeJsonString((string) $encoded);
                if ($this->validDownloadUrl($candidate)) {
                    $download = $candidate;
                    break;
                }
            }
        }
        if ($download === '') {
            return null;
        }

        $artist = '';
        if (preg_match('/"artist"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $html, $match)) {
            $artist = $this->decodeJsonString((string) $match[1]);
        }

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
            $title = html_entity_decode(strip_tags((string) $match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = preg_replace('/\s*(?:[-|]\s*)?Newgrounds(?:\.com)?\s*$/i', '', $title) ?? $title;
        }

        if ($title === '' || $artist === '') {
            return null;
        }

        $size = 0.0;
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*MB\b/i', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $match)) {
            $size = (float) $match[1];
        }

        return $this->songRow($songID, $title, 0, $artist, $size, $download);
    }

    /** @return array<string,mixed> */
    private function songRow(int $songID, string $name, int $authorID, string $authorName, float $size, string $download): array
    {
        return [
            'songID' => $songID,
            'name' => $this->field($name, 255),
            'authorID' => max(0, $authorID),
            'authorName' => $this->field($authorName, 255),
            'size' => number_format(max(0.0, $size), 2, '.', ''),
            'download' => $download,
            'isDisabled' => 0,
            'createdAt' => time(),
        ];
    }

    private function decodeJsonString(string $value): string
    {
        $decoded = json_decode('"' . $value . '"', true);
        return is_string($decoded) ? html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    private function validDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return $host === 'ngfiles.com'
            || str_ends_with($host, '.ngfiles.com')
            || $host === 'newgrounds.com'
            || str_ends_with($host, '.newgrounds.com');
    }

    private function field(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim($value)) ?? '';
        $value = str_replace(['~|~', '~:~', '#'], '', $value);
        return substr($value, 0, $maxLength);
    }
}
