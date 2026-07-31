# Обновление Night Core

[English version](../UPDATING.md)

Эта инструкция предназначена для production-установки в `/var/www/nightcore`.

## Перед обновлением

1. Прочитайте описание патча и список миграций.
2. Сделайте backup базы и runtime-хранилищ.
3. Запишите текущий commit.
4. Проверьте рабочее дерево.

```bash
cd /var/www/nightcore
git rev-parse HEAD
git status --short
```

`git status --short` должен быть пустым.

Если видны изменённые отслеживаемые файлы, остановите обновление и выясните происхождение изменений. Не удаляйте их автоматически.

## Стандартное обновление

```bash
cd /var/www/nightcore
git fetch origin
git switch main
git pull --ff-only origin main
git log -1 --oneline
```

Проверьте PHP:

```bash
find src public -name '*.php' -print0 | xargs -0 -n1 php -l
php -l config2.php
php -l config/media.php
```

Примените миграции и запустите doctor:

```bash
sudo -u www-data php bin/nightcore migrate
sudo -u www-data php bin/nightcore doctor
```

Перезапустите PHP-FPM:

```bash
sudo systemctl restart <php-fpm-service>
sudo systemctl is-active <php-fpm-service>
```

Проверьте сайт:

```bash
curl -fsS https://gdps.example.com/health.php
curl -fsS https://gdps.example.com/ready.php
```

## Private config

Git не перезаписывает:

```text
.env
config2.php
config/media.php
```

Новые версии могут добавлять параметры в example-файлы. Сравнивайте их вручную:

```bash
diff -u .env.production.example .env || true
diff -u config2.example.php config2.php || true
diff -u config/media.php.example config/media.php || true
```

Не копируйте example-файл поверх production-конфига без проверки.

## Обновление с миграциями

Миграции Night Core выполняются вперёд. Перед обновлением с миграциями обязательно сделайте дамп базы.

Откат только PHP-кода не возвращает старую схему базы. Для полного восстановления нужен совместимый backup базы.

## После успешного обновления

Проверьте настоящим client Geometry Dash:

- вход;
- список уровней;
- скачивание и загрузку уровней;
- комментарии;
- Daily;
- Weekly;
- Event;
- песни.

Не удаляйте backup сразу после первого успешного HTTP 200.
