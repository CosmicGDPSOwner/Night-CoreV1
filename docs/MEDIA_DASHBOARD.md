# Public media dashboard

Night Core exposes `/mediaAdmin.php` as a public media library/uploader when the server owner enables public uploads in a private server-local PHP policy file.

## Public surface

The page provides:

- MP3 song upload using the existing local-song library;
- Ogg (`.ogg`) SFX upload using the separate local SFX library;
- read-only song/SFX lists with IDs, sizes and download URLs;
- read-only display of the current per-file limits;
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
];
```

Only the server owner should be able to edit this file. On a typical Linux/Nginx/PHP-FPM host:

```bash
chown root:www-data config/media.php
chmod 0640 config/media.php
```

PHP needs read access; web users do not need write access.

`public_uploads=false` keeps the library page readable but hides/rejects upload forms. The default is disabled when `config/media.php` does not exist.

## Upload limits

`song_max_mib` and `sfx_max_mib` are integer MiB values from `1` to `1024`. Night Core applies them when storing files. Environment values remain fallback defaults when the private PHP policy omits a limit:

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

## Database migration

Migration `0010_media_dashboard.sql` creates `core_local_sfx` and the earlier `core_media_settings` table. The public-dashboard design no longer reads or writes upload limits through `core_media_settings`; limits now come from the private server-local PHP policy.

## Public-upload security

Public means unauthenticated: anyone who can reach `/mediaAdmin.php` can submit a valid file while `public_uploads=true`. Per-file limits do not prevent somebody from repeatedly uploading files and consuming disk space. Internet-facing installations should additionally apply an appropriate rate/quota/moderation layer at the reverse proxy or application boundary.

Only host audio that you are permitted to distribute.
