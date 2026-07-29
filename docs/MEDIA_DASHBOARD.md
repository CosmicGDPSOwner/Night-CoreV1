# Public media dashboard

Night Core exposes `/mediaAdmin.php` as a public media library/uploader when the server owner enables public uploads in a private server-local PHP policy file.

## Public surface

The page provides:

- MP3 song upload using the existing local-song library;
- Ogg (`.ogg`) SFX upload using the separate local SFX library;
- read-only song/SFX lists with IDs, sizes and download URLs;
- read-only display of per-file limits and anti-spam policy;
- CSRF protection for browser upload forms.

There is no admin-token prompt on this page. It also does not expose file deletion or limit-changing actions to visitors.

The legacy `/songAdmin.php` endpoint remains token-protected for compatibility and owner-only song management.

## Owner-only PHP policy

Copy the tracked example once:

```bash
cp config/media.php.example config/media.php
```

`config/media.php` is ignored by Git, so normal updates do not overwrite the server owner's local policy. A production example:

```php
<?php
return [
    'public_uploads' => true,
    'song_max_mib' => 25,
    'sfx_max_mib' => 10,
    'upload_cooldown_seconds' => 30,
    'uploads_per_hour_per_ip' => 10,
    'global_uploads_per_hour' => 200,
    'minimum_free_space_mib' => 512,
];
```

Only the server owner should be able to edit this file. On a typical Linux/Nginx/PHP-FPM host:

```bash
chown root:www-data config/media.php
chmod 0640 config/media.php
```

PHP needs read access; web users do not need write access.

`public_uploads=false` keeps the library page readable but hides/rejects upload forms. The default is disabled when `config/media.php` does not exist.

## Anti-spam and disk protection

Before persisting an anonymous upload, Night Core applies:

- `upload_cooldown_seconds`: minimum delay between reservations from the same IP;
- `uploads_per_hour_per_ip`: hourly upload cap per IP;
- `global_uploads_per_hour`: hourly cap across the public uploader;
- `minimum_free_space_mib`: disk-space reserve below which new uploads are automatically paused.

Rate-limit state lives in MariaDB table `core_media_upload_rate_limits`. Client IP addresses are not stored in plaintext; the limiter uses a SHA-256 hash as its key.

A numeric cooldown/hourly limit can be set to `0` to disable only that limit. With `TRUST_PROXY_HEADERS=0`, the client address comes from `REMOTE_ADDR`. `X-Forwarded-For` is trusted only when `TRUST_PROXY_HEADERS=1`; the origin must then be protected against direct proxy bypass.

## File-size limits

`song_max_mib` and `sfx_max_mib` are integer MiB values. Night Core applies them when storing files. Environment values remain fallback defaults when the private PHP policy omits a limit:

```env
CUSTOM_SONG_MAX_BYTES=26214400
CUSTOM_SFX_MAX_BYTES=10485760
```

The public page can only display these limits; it cannot change them.

The Night Core limit is still bounded by infrastructure. `upload_max_filesize`, `post_max_size`, Nginx `client_max_body_size`, Apache/provider limits and reverse proxies must also allow the request. Lowering the PHP-policy limit works immediately without restarting PHP-FPM.

## Storage

Recommended production paths:

```env
CUSTOM_SONG_STORAGE_PATH=/var/lib/nightcore/songs
CUSTOM_SFX_STORAGE_PATH=/var/lib/nightcore/sfx
```

Both directories must be writable by the PHP/web-server user and should remain outside `public/`.

```bash
mkdir -p /var/lib/nightcore/songs /var/lib/nightcore/sfx
chown -R www-data:www-data /var/lib/nightcore/songs /var/lib/nightcore/sfx
chmod 0755 /var/lib/nightcore/songs /var/lib/nightcore/sfx
```

`php bin/nightcore doctor` requires writable song/SFX storage while public uploads are enabled.

## SFX library

SFX uploads are stored in `core_local_sfx` and served by `/downloadCustomSfx.php?sfxID=<ID>`. The uploader accepts Ogg streams and validates the `OggS` container header before storage.

Default local SFX ID range:

```env
CUSTOM_SFX_ID_MIN=2000000
CUSTOM_SFX_ID_MAX=8999999
```

The SFX namespace is separate from song IDs, so the same numeric value may exist once as a song ID and once as an SFX ID without a database collision.

### Geometry Dash 2.2 compatibility boundary

The server-side SFX library, upload, storage, range download and level `sfxIDs` persistence are implemented. Stock Geometry Dash handles SFX differently from custom songs: songs receive an explicit download URL through song metadata, while SFX belong to the game's audio-library/CDN system. The exact stock-client SFX discovery/request path still requires separate compatibility work.

Do not treat successful browser download of an SFX as proof that an unmodified Geometry Dash client will request that same URL.

## Database migrations

`0010_media_dashboard.sql` creates the local SFX/media tables. `0011_public_media_rate_limits.sql` adds persistent anonymous-upload rate-limit state.

Only host audio that you are permitted to distribute.
