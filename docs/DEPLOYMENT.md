# Full Night Core deployment on a VPS

[Русская версия](ru/DEPLOYMENT.md)

This guide describes the recommended production installation of Night Core on a dedicated Debian or Ubuntu VPS using Nginx, PHP-FPM and MariaDB.

The request path is:

```text
Internet -> Cloudflare or direct access -> Nginx -> PHP-FPM -> Night Core -> MariaDB
```

The web-server document root must point to `public/` only.

Do not expose the repository root. `.env`, `config2.php`, `config/media.php`, source files, migrations, tests and runtime storage must not be downloadable over HTTP.

## 1. Requirements

Prepare:

- a GDPS domain or subdomain;
- a VPS with root or sudo access;
- Debian 12+, Ubuntu 22.04+ or a compatible distribution;
- PHP 8.1 or newer;
- Nginx;
- MariaDB or MySQL;
- Git;
- a TLS certificate;
- a dedicated database and database user;
- backup storage outside the application directory.

Recommended paths:

```text
/var/www/nightcore
/var/lib/nightcore/levels
/var/lib/nightcore/songs
/var/lib/nightcore/sfx
```

## 2. DNS

Create an A record for the GDPS hostname and point it to the VPS IP address. Add an AAAA record when IPv6 is used.

Complete and verify the origin HTTPS deployment before enabling a Cloudflare proxy.

## 3. Install system packages

Debian or Ubuntu example:

```bash
sudo apt update
sudo apt install -y \
  git nginx mariadb-server \
  php-fpm php-cli php-mysql php-curl php-mbstring php-xml \
  ca-certificates curl unzip
```

Verify PHP:

```bash
php -v
php -m | grep -Ei 'PDO|pdo_mysql|curl'
```

`PDO` and `pdo_mysql` are required. PHP cURL is strongly recommended for Newgrounds and outbound HTTPS.

Find the PHP-FPM service and socket:

```bash
systemctl list-units --type=service 'php*-fpm.service'
ls -la /run/php/
```

Replace `<php-fpm-service>` and `<php-fpm-socket>` in this guide with the actual values.

## 4. Download Night Core

```bash
sudo git clone https://github.com/CosmicGDPSOwner/Night-CoreV1.git /var/www/nightcore
sudo chown -R "$USER":www-data /var/www/nightcore
cd /var/www/nightcore
git switch main
git log -1 --oneline
```

Use an SSH URL or deploy key when the repository is private.

## 5. Create the database

```bash
sudo mariadb
```

```sql
CREATE DATABASE nightcore
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'nightcore'@'localhost'
  IDENTIFIED BY 'CHANGE_ME_LONG_RANDOM_DATABASE_PASSWORD';

GRANT ALL PRIVILEGES
  ON nightcore.*
  TO 'nightcore'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

Verify the account:

```bash
mariadb -u nightcore -p -h 127.0.0.1 nightcore
```

## 6. Configure `.env`

```bash
cd /var/www/nightcore
cp .env.production.example .env
openssl rand -hex 32
nano .env
```

Important settings:

```env
APP_ENV=production
APP_DEBUG=0

NIGHTCORE_SERVER_NAME=My GDPS
SERVER_ID=my-gdps
CORE_PROFILE=cvolton
BASE_PATH=/

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=nightcore
DB_USER=nightcore
DB_PASS=CHANGE_ME_LONG_RANDOM_DATABASE_PASSWORD
DB_CHARSET=utf8mb4

REGISTRATION_IP_HASH_KEY=CHANGE_ME_RANDOM_KEY_1
PANEL_SECURITY_HASH_KEY=CHANGE_ME_RANDOM_KEY_2

CORE_ADMIN_ACCOUNT_IDS=
TRUST_PROXY_HEADERS=0
```

Use different long random values for registration and panel hashing. Never commit `.env`.

```bash
sudo chown "$USER":www-data .env
sudo chmod 640 .env
```

## 7. Configure `config2.php`

```bash
cp config2.example.php config2.php
nano config2.php
```

Example:

```php
<?php

declare(strict_types=1);

return [
    'account_deletion_enabled' => false,
    'session_idle_timeout_seconds' => 1800,
    'session_absolute_timeout_seconds' => 28800,
];
```

```bash
php -l config2.php
sudo chown "$USER":www-data config2.php
sudo chmod 640 config2.php
```

## 8. Configure authenticated media uploads

```bash
cp config/media.php.example config/media.php
nano config/media.php
```

Example:

```php
<?php

declare(strict_types=1);

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

`public_uploads=true` enables upload forms for authenticated active GDPS accounts. It does not allow anonymous uploads.

```bash
php -l config/media.php
sudo chown "$USER":www-data config/media.php
sudo chmod 640 config/media.php
```

## 9. Create runtime storage

```bash
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore/levels
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore/songs
sudo install -d -o www-data -g www-data -m 2770 /var/lib/nightcore/sfx
```

Set these paths in `.env`:

```env
LEVEL_STORAGE_PATH=/var/lib/nightcore/levels
CUSTOM_SONG_STORAGE_PATH=/var/lib/nightcore/songs
CUSTOM_SFX_STORAGE_PATH=/var/lib/nightcore/sfx
```

Do not use `chmod 777`.

## 10. Configure PHP upload limits

Typical PHP-FPM `php.ini` values:

```ini
upload_max_filesize = 25M
post_max_size = 32M
max_file_uploads = 10
max_execution_time = 60
```

Restart PHP-FPM after changes:

```bash
sudo systemctl restart <php-fpm-service>
```

## 11. Install the schema

```bash
cd /var/www/nightcore
sudo -u www-data php bin/nightcore install
```

Expected result:

```text
Installation checks: OK
```

Useful commands:

```bash
sudo -u www-data php bin/nightcore migrate
sudo -u www-data php bin/nightcore doctor
```

## 12. Configure Nginx

Copy the provided template:

```bash
sudo cp deploy/nginx/nightcore.conf.example \
  /etc/nginx/sites-available/nightcore
sudo nano /etc/nginx/sites-available/nightcore
```

Replace:

```text
CHANGE_ME_GDPS_DOMAIN
CHANGE_ME_PHP_FPM_SOCKET
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/nightcore \
  /etc/nginx/sites-enabled/nightcore
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

The critical setting is:

```nginx
root /var/www/nightcore/public;
```

The public URL must not contain `/public`.

## 13. Enable HTTPS

Debian or Ubuntu Certbot example:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d gdps.example.com
sudo nginx -t
sudo systemctl reload nginx
```

## 14. Firewall and database exposure

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Do not expose MariaDB port 3306 to the public Internet when the database is local.

## 15. Cloudflare

Keep:

```env
TRUST_PROXY_HEADERS=0
```

until the origin is restricted to trusted proxy traffic. Only then set it to `1`.

Night Core reads `CF-Connecting-IP` and then the first `X-Forwarded-For` address when proxy headers are trusted.

## 16. Create the bootstrap owner

Register a normal GDPS account and find its account ID:

```sql
SELECT accountID, userName, isActive
FROM accounts
ORDER BY accountID;
```

Then update `.env`:

```env
CORE_ADMIN_ACCOUNT_IDS=2
```

Use the real account ID. Multiple IDs are comma-separated.

Restart PHP-FPM:

```bash
sudo systemctl restart <php-fpm-service>
```

## 17. Install cron

```bash
sudo cp deploy/cron/nightcore.cron.example /etc/cron.d/nightcore
sudo chmod 644 /etc/cron.d/nightcore
```

Manual check:

```bash
sudo -u www-data php /var/www/nightcore/bin/nightcore accounts:purge-due
```

## 18. Final validation

```bash
cd /var/www/nightcore
sudo -u www-data php bin/nightcore doctor

curl -fsS https://gdps.example.com/health.php
curl -fsS https://gdps.example.com/ready.php
curl -fsS https://gdps.example.com/info.php
```

Check panels:

```bash
for page in dashboard.php staffAdmin.php eventAdmin.php; do
  printf '%-20s ' "$page"
  curl -sS -o /dev/null -w '%{http_code}\n' \
    "https://gdps.example.com/$page"
done
```

Verify private files return 404:

```bash
curl -I https://gdps.example.com/.env
curl -I https://gdps.example.com/bootstrap.php
curl -I https://gdps.example.com/src/Core/Application.php
```

## 19. Newgrounds

Required `.env` values:

```env
NEWGROUNDS_FETCH_ENABLED=1
NEWGROUNDS_USE_BOOMLINGS_METADATA=1
NEWGROUNDS_DIRECT_FALLBACK=1
NEWGROUNDS_TIMEOUT_SECONDS=5
NEWGROUNDS_NEGATIVE_TTL_SECONDS=3600
```

Test:

```bash
curl -I https://www.newgrounds.com/
curl -sS -X POST \
  https://gdps.example.com/getGJSongInfo.php \
  -d 'songID=631860'
```

A `-1` response may mean the song is unavailable, the upstream returned 403, outbound HTTPS is blocked, PHP cURL is missing, or the ID is in negative cache.

## 20. Geometry Dash client

Configure the client or launcher with:

```text
https://gdps.example.com/
```

Do not append `/public`.

Validate registration, login, profiles, levels, comments, songs, Daily, Weekly and Event with a real client before migrating production users.

## 21. Logs

```bash
sudo tail -n 200 /var/log/nginx/error.log
sudo journalctl -u <php-fpm-service> -n 200 --no-pager
sudo journalctl -u mariadb -n 200 --no-pager
```

## 22. Updates and recovery

Read:

- `docs/UPDATING.md`;
- `docs/BACKUP_AND_RECOVERY.md`;
- `docs/DEPLOYMENT_CHECKLIST.md`.

Do not update while `git status --short` contains unknown tracked changes.

For shared hosting, use `docs/SHARED_HOSTING.md`.
