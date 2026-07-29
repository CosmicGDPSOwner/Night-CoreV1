# Тестирование Night Core V1

Используйте отдельную тестовую базу данных. Не выполняйте ранние проверки на production-базе GDPS.

[English version](../TESTING.md)

## Docker Desktop

Из директории репозитория:

```bash
docker compose up --build -d
docker compose exec web php bin/nightcore migrate
docker compose exec web php bin/nightcore doctor
docker compose exec web php bin/nightcore self-test
```

Ожидается:

- миграции применяются без ошибок;
- `doctor` показывает критические проверки как `OK`;
- `self-test` выводит `Night Core self-test: OK`;
- `/health.php` возвращает `ok`;
- `/info.php` возвращает несекретную информацию об установке.

## Регистрация и вход

Проверьте совместимые пути регистрации и login как минимум одним тестовым аккаунтом. Успешная регистрация возвращает `1`, успешный login — два числовых ID через запятую. Неверные credentials должны отклоняться.

## Newgrounds

После миграций запросите реальный Newgrounds `songID`, которого ещё нет в `core_songs`:

```bash
curl -sS -X POST http://127.0.0.1:8080/getGJSongInfo.php \
  -d 'songID=REPLACE_WITH_REAL_ID'
```

При успехе ответ начинается в обычном GD song wire format:

```text
1~|~<songID>~|~2~|~...
```

Повторный запрос того же ID должен использовать локальный кеш `core_songs`.

Подробности: `docs/ru/NEWGROUNDS.md`.

## Проверка настоящим Geometry Dash 2.2

После HTTP и CI-проверок протестируйте реальным клиентом:

1. регистрацию и login;
2. профиль;
3. level upload/search/download;
4. profile и level comments;
5. likes;
6. friends/messages;
7. save/sync;
8. custom song по Newgrounds ID.

Реальный клиент может обнаружить wire-format ошибку, которую не видно в простом HTTP baseline.

## Остановка и сброс

```bash
docker compose down
```

Для полного удаления тестовых volumes:

```bash
docker compose down -v
```

## Существующая Cvolton-совместимая база

Сначала восстановите backup в отдельную тестовую базу. Затем настройте `.env` и выполните:

```bash
php bin/nightcore doctor
```

Не запускайте миграции на production, пока копия базы не прошла compatibility tests.
