# Owner media dashboard

Night Core includes a small owner-only dashboard at `/mediaAdmin.php` for managing server-hosted audio without editing the database by hand.

## Features

The dashboard currently provides:

- MP3 song upload using the existing local-song library;
- Ogg (`.ogg`) SFX upload using a separate local SFX library;
- song/SFX listing and deletion;
- separate per-file upload limits for songs and SFX;
- runtime limit changes stored in MariaDB, so lowering a limit does not require editing `.env` or restarting PHP-FPM;
- session-based authentication and CSRF protection after the owner token is accepted.

The existing `/songAdmin.php` endpoint remains available for compatibility. `/mediaAdmin.php` is the preferred owner interface going forward.

## Authentication

Configure a dedicated dashboard token:

```env
MEDIA_ADMIN_TOKEN=CHANGE_ME_LONG_RANDOM_SECRET
```

When `MEDIA_ADMIN_TOKEN` is empty, Night Core falls back to `CUSTOM_SONG_ADMIN_TOKEN`. This lets existing installations adopt the dashboard without immediately rotating or duplicating their current song-admin secret.

Keep the dashboard private. On an HTTP-only test origin, access it through an SSH tunnel rather than sending the token over the public network. Production deployments should use HTTPS.

## Storage

Recommended production paths:

```env
CUSTOM_SONG_STORAGE_PATH=/var/lib/nightcore/songs
CUSTOM_SFX_STORAGE_PATH=/var/lib/nightcore/sfx
```

Both directories must be writable by the PHP/web-server user and should stay outside `public/`.

Example Linux setup:

```bash
mkdir -p /var/lib/nightcore/songs /var/lib/nightcore/sfx
chown -R www-data:www-data /var/lib/nightcore/songs /var/lib/nightcore/sfx
chmod 0755 /var/lib/nightcore/songs /var/lib/nightcore/sfx
```

`php bin/nightcore doctor` checks both stores when the media dashboard is enabled or when local media rows already exist.

## Upload limits

Environment defaults:

```env
CUSTOM_SONG_MAX_BYTES=26214400
CUSTOM_SFX_MAX_BYTES=10485760
```

The dashboard exposes these as MiB values. For example, changing the song limit from `25` to `10` stores a `10 MiB` runtime override in `core_media_settings`. New uploads immediately use that lower limit.

The dashboard limit is an application limit, not a replacement for PHP or the web server. `upload_max_filesize`, `post_max_size`, Nginx `client_max_body_size`, Apache/provider limits, and reverse proxies must also allow the request. Lowering the Night Core limit works immediately; raising it above an infrastructure limit still requires changing that infrastructure limit.

## SFX library

SFX uploads are stored in `core_local_sfx` and served by `/downloadCustomSfx.php?sfxID=<ID>`. The current implementation accepts Ogg streams and validates the `OggS` container header before storage.

Default local SFX ID range:

```env
CUSTOM_SFX_ID_MIN=2000000
CUSTOM_SFX_ID_MAX=8999999
```

The SFX namespace is separate from song IDs, so the same numeric value may exist once as a song ID and once as an SFX ID without a database collision.

### Geometry Dash 2.2 compatibility boundary

The server-side SFX library, upload, storage, range download and level `sfxIDs` persistence are implemented. Stock Geometry Dash handles SFX differently from custom songs: songs receive an explicit download URL through song metadata, while SFX belong to the game's audio-library/CDN system. For that reason, the local SFX file path must still be validated against the exact GDPS Switcher/original-client request flow before Night Core claims transparent stock-client SFX replacement.

Do not treat successful browser download of an SFX as proof that an unmodified Geometry Dash client will request that same URL.

## Database migration

Migration `0010_media_dashboard.sql` creates:

- `core_media_settings` for dashboard-controlled runtime values;
- `core_local_sfx` for owner-managed SFX metadata.

Apply it with:

```bash
php bin/nightcore migrate
php bin/nightcore doctor
```

## Security boundary

The dashboard is an owner-management surface, not a public community uploader. Do not expose anonymous uploads. A future community submission flow should have account authentication, moderation, quotas, abuse controls and content-policy enforcement separate from this owner dashboard.

Only host audio that you are permitted to distribute.
