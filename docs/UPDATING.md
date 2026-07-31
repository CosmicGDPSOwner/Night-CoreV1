# Updating Night Core

[Русская версия](ru/UPDATING.md)

This guide assumes a production installation at `/var/www/nightcore`.

## Before updating

1. Read the patch notes and migration list.
2. Back up the database and runtime storage.
3. Record the current commit.
4. Check the working tree.

```bash
cd /var/www/nightcore
git rev-parse HEAD
git status --short
```

The working tree should be clean. Stop when unknown tracked changes are present and determine where they came from.

## Standard update

```bash
cd /var/www/nightcore
git fetch origin
git switch main
git pull --ff-only origin main
git log -1 --oneline
```

Validate PHP:

```bash
find src public -name '*.php' -print0 | xargs -0 -n1 php -l
php -l config2.php
php -l config/media.php
```

Apply migrations and run readiness checks:

```bash
sudo -u www-data php bin/nightcore migrate
sudo -u www-data php bin/nightcore doctor
```

Restart PHP-FPM:

```bash
sudo systemctl restart <php-fpm-service>
sudo systemctl is-active <php-fpm-service>
```

Validate HTTP:

```bash
curl -fsS https://gdps.example.com/health.php
curl -fsS https://gdps.example.com/ready.php
```

## Private configuration

Git does not overwrite:

```text
.env
config2.php
config/media.php
```

Compare new examples manually:

```bash
diff -u .env.production.example .env || true
diff -u config2.example.php config2.php || true
diff -u config/media.php.example config/media.php || true
```

Do not replace production config blindly.

## Updates with migrations

Night Core migrations move the schema forward. Always create a database dump before an update that contains migrations.

Rolling back only the PHP files does not restore an older database schema. A complete recovery requires a compatible database backup.

## After updating

Validate login, levels, comments, songs, Daily, Weekly and Event with a real Geometry Dash client.

Do not delete the backup immediately after the first successful HTTP 200.
