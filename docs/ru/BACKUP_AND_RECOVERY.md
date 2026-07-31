# Backup и восстановление Night Core

[English version](../BACKUP_AND_RECOVERY.md)

Полный backup должен включать:

- базу MariaDB;
- `.env`;
- `config2.php`;
- `config/media.php`;
- уровни;
- локальные песни;
- локальные SFX;
- точный Git commit;
- контрольные суммы.

Храните хотя бы одну копию вне VPS.

## Создание backup

Создайте каталог с закрытыми правами:

```bash
sudo install -d -o root -g root -m 0700 /var/backups/nightcore
sudo -i
umask 077

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="/var/backups/nightcore/$STAMP"
mkdir -p "$BACKUP"
```

### Дамп базы

```bash
mariadb-dump \
  --single-transaction \
  --quick \
  --hex-blob \
  --default-character-set=utf8mb4 \
  -u nightcore \
  -p \
  nightcore \
  > "$BACKUP/database.sql"
```

### Private config

```bash
tar -C /var/www/nightcore -czf "$BACKUP/private-config.tar.gz" \
  .env \
  config2.php \
  config/media.php
```

Если один optional-файл отсутствует, создайте архив только из существующих файлов.

### Runtime storage

```bash
tar -C /var/lib/nightcore -czf "$BACKUP/runtime-storage.tar.gz" \
  levels \
  songs \
  sfx
```

### Версия кода

```bash
git -C /var/www/nightcore rev-parse HEAD \
  > "$BACKUP/git-commit.txt"

git -C /var/www/nightcore status --short \
  > "$BACKUP/git-status.txt"
```

### Контрольные суммы

```bash
cd "$BACKUP"
sha256sum \
  database.sql \
  private-config.tar.gz \
  runtime-storage.tar.gz \
  git-commit.txt \
  git-status.txt \
  > SHA256SUMS

sha256sum -c SHA256SUMS
```

## Что нельзя считать backup

Недостаточно сохранить:

- только GitHub-репозиторий;
- только ZIP исходного кода;
- только `.env`;
- backup на том же диске без внешней копии.

Git не содержит production `.env`, private config и runtime-файлы.

## Восстановление

Перед восстановлением временно закройте публичный трафик через Nginx, firewall или Cloudflare.

Проверьте архив:

```bash
cd /var/backups/nightcore/<timestamp>
sha256sum -c SHA256SUMS
```

### Восстановление private config

```bash
sudo tar -C /var/www/nightcore -xzf \
  /var/backups/nightcore/<timestamp>/private-config.tar.gz

sudo chown "$USER":www-data \
  /var/www/nightcore/.env \
  /var/www/nightcore/config2.php \
  /var/www/nightcore/config/media.php

sudo chmod 640 \
  /var/www/nightcore/.env \
  /var/www/nightcore/config2.php \
  /var/www/nightcore/config/media.php
```

### Восстановление runtime storage

```bash
sudo tar -C /var/lib/nightcore -xzf \
  /var/backups/nightcore/<timestamp>/runtime-storage.tar.gz

sudo chown -R www-data:www-data /var/lib/nightcore
sudo find /var/lib/nightcore -type d -exec chmod 2770 {} +
sudo find /var/lib/nightcore -type f -exec chmod 0660 {} +
```

### Восстановление базы

Полное восстановление базы перезаписывает текущие данные. Выполняйте его только после проверки выбранного backup.

Создайте чистую базу и импортируйте дамп:

```bash
mariadb -u nightcore -p nightcore \
  < /var/backups/nightcore/<timestamp>/database.sql
```

При необходимости новую пустую базу сначала создаёт администратор MariaDB.

### Проверка после восстановления

```bash
cd /var/www/nightcore

php -l config2.php
php -l config/media.php
sudo -u www-data php bin/nightcore doctor

sudo systemctl restart <php-fpm-service>
sudo nginx -t
sudo systemctl reload nginx

curl -fsS https://gdps.example.com/health.php
curl -fsS https://gdps.example.com/ready.php
```

После этого откройте трафик и проверьте настоящий client Geometry Dash.

## Рекомендуемое хранение

Минимальный вариант:

- 7 ежедневных backup;
- 4 еженедельных backup;
- 3 ежемесячных backup;
- минимум одна внешняя копия;
- периодическая тестовая процедура восстановления.

Секреты backup должны быть защищены так же строго, как `.env`.
