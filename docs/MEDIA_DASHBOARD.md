# Dashboard media library

`/dashboard.php?tab=media` is the canonical public song/SFX page. `/mediaAdmin.php` is only a compatibility redirect.

## Public and authenticated surfaces

Everyone can read:

- local MP3 song IDs, names, authors, sizes and download URLs;
- local Ogg SFX IDs, names, sizes and download URLs;
- the configured per-file size limits.

Uploading requires a signed-in GDPS account that is active, not account-banned and not due for deletion. The same account/password database is used by Geometry Dash. The page never stores a plaintext password in the PHP session.

The dashboard intentionally does not publish upload cooldowns, per-network quotas, global quotas, registration thresholds or network-derived identifiers. Rejected limiter requests use a generic temporary-unavailability message.

## Private media policy

Copy the example once:

```bash
cp config/media.php.example config/media.php
```

`config/media.php` is ignored by Git. Example:

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

Only the server owner should edit this file. `public_uploads=false` keeps both libraries readable and rejects/hides upload forms.

## Server-side protection

Before storage, Night Core verifies authentication, CSRF, file upload state, media format, configured size, upload reservations and free disk space. Rate-limit state is persisted in MariaDB and network addresses are not stored as plaintext.

These controls reduce abuse but do not replace Cloudflare, origin firewall rules, Nginx/PHP request limits or DDoS protection.

## Storage

Recommended production paths:

```env
CUSTOM_SONG_STORAGE_PATH=/var/lib/nightcore/songs
CUSTOM_SFX_STORAGE_PATH=/var/lib/nightcore/sfx
```

The PHP-FPM user needs read/write access. Keep storage outside `public/`.

## SFX compatibility boundary

The server-side Ogg SFX library, range downloads and level `sfxIDs` persistence are implemented. An unmodified Geometry Dash client uses its own SFX library/CDN discovery flow, so a successful browser download alone does not prove stock-client discovery.

## Related migrations

- `0010_media_dashboard.sql` — local media/SFX tables;
- `0011_public_media_rate_limits.sql` — upload limiter state;
- `0019_event_claims_and_media_login.sql` — authenticated media login/audit.
