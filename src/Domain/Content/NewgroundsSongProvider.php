<?php

declare(strict_types=1);

namespace NightCore\Domain\Content;

use Closure;
use Throwable;

final class NewgroundsSongProvider
{
    private const BOOMLINGS_URL = 'https://www.boomlings.com/database/getGJSongInfo.php';
    private const NEWGROUNDS_URL = 'https://www.newgrounds.com/audio/listen/';

    public function __construct(
        private ContentRepository $content,
        private bool $enabled = true,
        private bool $useBoomlingsMetadata = true,
        private bool $directFallback = true,
        private int $timeoutSeconds = 5,
        private int $negativeTtlSeconds = 3600,
        private ?Closure $requester = null,
        private ?NewgroundsSongParser $parser = null
    ) {
        $this->timeoutSeconds = max(1, min(15, $this->timeoutSeconds));
        $this->negativeTtlSeconds = max(60, min(86400, $this->negativeTtlSeconds));
        $this->parser ??= new NewgroundsSongParser();
    }

    /** @return array<string,mixed>|null */
    public function findOrFetch(int $songID): ?array
    {
        if (!$this->enabled || $songID <= 0 || $songID > 100000000) {
            return null;
        }

        $cached = $this->content->findSong($songID);
        if ($cached !== null) {
            return $cached;
        }

        $now = time();
        if (!$this->content->canAttemptSongFetch($songID, $now)) {
            return null;
        }

        $song = null;
        try {
            if ($this->useBoomlingsMetadata) {
                $payload = $this->request('POST', self::BOOMLINGS_URL, [
                    'songID' => (string) $songID,
                    'secret' => 'Wmfd2893gb7',
                ]);
                if ($payload !== null) {
                    $song = $this->parser->parseBoomlings($payload, $songID);
                }
            }

            if ($song === null && $this->directFallback) {
                $html = $this->request('GET', self::NEWGROUNDS_URL . $songID);
                if ($html !== null) {
                    $song = $this->parser->parseNewgroundsPage($html, $songID);
                }
            }
        } catch (Throwable $e) {
            error_log('Night Core Newgrounds lookup failed for song ' . $songID . ': ' . $e->getMessage());
        }

        if ($song === null) {
            $this->content->recordSongFetchFailure($songID, $now + $this->negativeTtlSeconds, $now);
            return null;
        }

        $this->content->upsertSong($song);
        $this->content->clearSongFetchFailure($songID);
        return $this->content->findSong($songID);
    }

    /** @param array<string,string> $data */
    private function request(string $method, string $url, array $data = []): ?string
    {
        if ($this->requester !== null) {
            $result = ($this->requester)($method, $url, $data, $this->timeoutSeconds);
            return is_string($result) && $result !== '' ? $result : null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['www.boomlings.com', 'www.newgrounds.com'], true)) {
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                    CURLOPT_TIMEOUT => $this->timeoutSeconds,
                    CURLOPT_USERAGENT => 'Night-CoreV1/1.0 Geometry-Dash-private-server',
                    CURLOPT_HTTPHEADER => ['Accept: text/html,application/json,text/plain;q=0.9,*/*;q=0.8'],
                    CURLOPT_ENCODING => '',
                ];
                if ($method === 'POST') {
                    $options[CURLOPT_POST] = true;
                    $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
                    $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
                }
                curl_setopt_array($ch, $options);
                $result = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                if (is_string($result) && $result !== '' && $status >= 200 && $status < 300) {
                    return strlen($result) > 2097152 ? substr($result, 0, 2097152) : $result;
                }
            }
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $headers = [
            'User-Agent: Night-CoreV1/1.0 Geometry-Dash-private-server',
            'Accept: text/html,application/json,text/plain;q=0.9,*/*;q=0.8',
            'Connection: close',
        ];
        $http = [
            'method' => $method,
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $http['content'] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        }
        $http['header'] = implode("\r\n", $headers) . "\r\n";
        $context = stream_context_create(['http' => $http]);
        $result = @file_get_contents($url, false, $context, 0, 2097152);
        if (!is_string($result) || $result === '') {
            return null;
        }
        $status = $this->streamStatusCode($http_response_header ?? []);
        return $status >= 200 && $status < 300 ? $result : null;
    }

    /** @param array<int,string> $headers */
    private function streamStatusCode(array $headers): int
    {
        if ($headers === [] || !preg_match('/\s(\d{3})(?:\s|$)/', (string) $headers[0], $match)) {
            return 0;
        }
        return (int) $match[1];
    }
}
