# Night Core backup and recovery

[Русская версия](ru/BACKUP_AND_RECOVERY.md)

A complete backup includes:

- MariaDB;
- `.env`;
- `config2.php`;
- `config/media.php`;
- levels;
- local songs;
- local SFX;
- the exact Git commit;
- checksums.

Keep at least one copy outside the VPS.

## Create a backup

```bash
sudo install -d -o root -g root -m 0700 /var/backups/nightcore
sudo -i
umask 077

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="/var/backups/nightcore/$STAMP"
mkdir -p "$BACKUP"
```

Database:

```bash
mariadb-dump \
  --single-transaction \
  --quick \
  --hex-blob \
  --default-character-set=utf8mb4 \
  -u nightcore -p nightcore \
  > "$BACKUP/database.sql"
```

Private configuration:

```bash
tar -C /var/www/nightcore -czf "$BACKUP/private-config.tar.gz" \
  .env config2.php config/media.php
```

Runtime storage:

```bash
tar -C /var/lib/nightcore -czf "$BACKUP/runtime-storage.tar.gz" \
  levels songs sfx
```

Version metadata and checksums:

```bash
git -C /var/www/nightcore rev-parse HEAD > "$BACKUP/git-commit.txt"
git -C /var/www/nightcore status --short > "$BACKUP/git-status.txt"

cd "$BACKUP"
sha256sum \
  database.sql private-config.tar.gz runtime-storage.tar.gz \
  git-commit.txt git-status.txt \
  > SHA256SUMS

sha256sum -c SHA256SUMS
```

A Git repository or source ZIP alone is not a production backup because private config and runtime data are intentionally outside Git.

## Restore

Temporarily remove the origin from public traffic and verify the selected backup:

```bash
cd /var/backups/nightcore/<timestamp>
sha256sum -c SHA256SUMS
```

Restore private configuration and runtime storage:

```bash
sudo tar -C /var/www/nightcore -xzf \
  /var/backups/nightcore/<timestamp>/private-config.tar.gz

sudo tar -C /var/lib/nightcore -xzf \
  /var/backups/nightcore/<timestamp>/runtime-storage.tar.gz

sudo chown -R www-data:www-data /var/lib/nightcore
```

Import the database only after confirming the target database and backup:

```bash
mariadb -u nightcore -p nightcore \
  < /var/backups/nightcore/<timestamp>/database.sql
```

Validate:

```bash
cd /var/www/nightcore
sudo -u www-data php bin/nightcore doctor
sudo systemctl restart <php-fpm-service>
curl -fsS https://gdps.example.com/ready.php
```

Keep at least daily, weekly and monthly retention sets, with one copy outside the VPS.
