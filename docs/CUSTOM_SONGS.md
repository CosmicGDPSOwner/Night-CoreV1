# Server-hosted custom songs

Night Core can host custom Geometry Dash songs directly on the GDPS server. This is independent from Newgrounds/Boomlings lookup and is useful when the GDPS origin cannot reach those services.

## How it works

1. An administrator uploads an MP3 through `/songAdmin.php`.
2. Night Core allocates a Song ID in the `90000000..99999999` range.
3. The MP3 is stored outside the public document root.
4. Song metadata is written to `core_songs`, so the normal `getGJSongInfo.php` and level-search responses already know about the song.
5. Geometry Dash downloads the MP3 through `/downloadCustomSong.php?songID=<ID>`.

The download endpoint supports normal GET/HEAD requests and single HTTP byte ranges, which makes it suitable for normal media clients without exposing the storage directory itself.

## Configuration

Production example:

```env
CUSTOM_SONG_STORAGE_PATH=/var/lib/nightcore/songs
CUSTOM_SONG_MAX_BYTES=26214400
CUSTOM_SONG_PUBLIC_BASE_URL=https://gdps.example.com
CUSTOM_SONG_ADMIN_TOKEN=CHANGE_ME_LONG_RANDOM_SECRET
```

`CUSTOM_SONG_STORAGE_PATH` should be outside `public/` and writable by the PHP/web-server user.

`CUSTOM_SONG_MAX_BYTES` is the Night Core file-size limit. The default is 25 MiB. PHP `upload_max_filesize`, `post_max_size`, Nginx `client_max_body_size`, Apache limits, or hosting-provider limits may be lower and must also permit the intended upload size.

`CUSTOM_SONG_PUBLIC_BASE_URL` is the canonical public Night Core origin. It may be left empty: the uploader then derives the scheme/host from the request used to open `/songAdmin.php`. Set it explicitly when the server is behind a reverse proxy, uses several hostnames, or the upload page is accessed through an internal address.

`CUSTOM_SONG_ADMIN_TOKEN` enables the browser uploader. When it is empty, `/songAdmin.php` returns HTTP 404. Use a long random secret and do not commit it to Git.

## Storage permissions

For a normal Linux production deployment:

```bash
mkdir -p /var/lib/nightcore/songs
chown www-data:www-data /var/lib/nightcore/songs
chmod 0755 /var/lib/nightcore/songs
```

The exact PHP/web-server user may differ from `www-data` on another distribution or hosting provider.

After changing configuration, run:

```bash
php bin/nightcore migrate
php bin/nightcore doctor
```

Migration `0009_local_song_library.sql` creates `core_local_songs`. The doctor treats custom-song storage as critical when the uploader is enabled or local songs already exist.

## Uploading a track

Open:

```text
https://your-gdps.example/songAdmin.php
```

Enter:

- the admin token;
- song title;
- artist/author;
- an MP3 file.

Night Core validates the `.mp3` extension, MP3/ID3 header and configured size limit, writes the file atomically, stores a SHA-256 checksum and returns the generated Song ID.

Use that Song ID in Geometry Dash as the custom-song ID. No client patch, launcher change, EXE or APK modification is required.

## Deleting a track

After authenticating on `/songAdmin.php`, the local library is shown below the upload form. Deleting a local song removes both its MP3 file and its Night Core song metadata. Levels that still reference that Song ID will no longer be able to resolve/download the song.

## Security and abuse boundary

The built-in uploader is deliberately administrator-only. Do not turn it into an unauthenticated public file upload endpoint. Public community submissions should sit behind a separate authenticated/moderated workflow with quotas, content policy and storage-abuse controls.

Only upload audio that you are permitted to host and distribute.

## Relationship to Newgrounds

The local song library and Newgrounds resolver can coexist. Night Core checks `core_songs` first, so a server-hosted Song ID is served locally without an upstream request. External lookup remains a best-effort fallback for unknown IDs.
