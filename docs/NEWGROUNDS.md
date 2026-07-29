# Newgrounds custom songs

Night Core V1 can resolve unknown Geometry Dash custom-song IDs automatically and cache the result locally.

## Request flow

For a `songID` that is not already present in `core_songs`:

1. Night Core first asks the official Geometry Dash/Boomlings song-info endpoint for metadata.
2. If that does not return usable metadata, Night Core can fall back to the public Newgrounds audio page for that ID.
3. The returned download URL is accepted only when it is HTTPS and belongs to Newgrounds/ngfiles.
4. Successful metadata is written to `core_songs`.
5. Later requests are served from the local database without another upstream lookup.

The same resolver is used by `getGJSongInfo.php` and when level-search responses need song metadata.

## Migration

The integration adds:

```text
0008_newgrounds_song_cache.sql
```

Run:

```bash
php bin/nightcore migrate
php bin/nightcore doctor
```

The migration creates `core_song_fetch_failures`, which is only a negative lookup cache for unavailable/invalid song IDs.

## Configuration

```env
NEWGROUNDS_FETCH_ENABLED=1
NEWGROUNDS_USE_BOOMLINGS_METADATA=1
NEWGROUNDS_DIRECT_FALLBACK=1
NEWGROUNDS_TIMEOUT_SECONDS=5
NEWGROUNDS_NEGATIVE_TTL_SECONDS=3600
```

### `NEWGROUNDS_FETCH_ENABLED`

Master switch for automatic external song lookup. Set to `0` to keep the old local-database-only behavior.

### `NEWGROUNDS_USE_BOOMLINGS_METADATA`

When enabled, Night Core checks the official Geometry Dash song-info endpoint before scraping the Newgrounds audio page.

### `NEWGROUNDS_DIRECT_FALLBACK`

Allows a direct Newgrounds page lookup when the Geometry Dash metadata endpoint does not provide a usable result.

### `NEWGROUNDS_TIMEOUT_SECONDS`

Timeout for each external request. Night Core clamps this value to a safe range.

### `NEWGROUNDS_NEGATIVE_TTL_SECONDS`

How long a failed song ID is cached before Night Core may try it again. This prevents repeated invalid IDs from generating outbound traffic on every client request.

## Requirements

The application host needs outbound HTTPS access.

Night Core prefers the PHP cURL extension when it is available. If cURL is unavailable, the fallback HTTP client requires `allow_url_fopen=1`.

## Security behavior

Night Core does not accept arbitrary download hosts from upstream metadata. A song is cached only when its download URL:

- uses HTTPS; and
- belongs to `newgrounds.com`, a Newgrounds subdomain, `ngfiles.com`, or an ngfiles subdomain.

External responses are size-limited and requests do not follow redirects.

## Testing from the server

After migrating, request a Newgrounds song ID that is not already in `core_songs`:

```bash
curl -sS -X POST http://127.0.0.1/getGJSongInfo.php \
  -d 'songID=REPLACE_WITH_REAL_ID'
```

A successful response has the normal Geometry Dash song wire format beginning with:

```text
1~|~<songID>~|~2~|~...
```

Then verify the local cache:

```bash
mariadb -u nightcore -p"$(cat /root/nightcore-db-pass)" nightcore \
  -e "SELECT songID,name,authorName,size,isDisabled FROM core_songs WHERE songID=REPLACE_WITH_REAL_ID;"
```

Do not paste database passwords into chat, logs, issues or screenshots.

## Failure behavior

When the song cannot be resolved, Night Core returns the normal GD failure value `-1` and stores only a temporary failed-lookup record. It does not create a fake song row.

A song already present in `core_songs` remains authoritative. A row with `isDisabled=1` returns `-2` and is not replaced automatically by an upstream fetch.
