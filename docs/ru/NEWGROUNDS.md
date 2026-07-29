# Кастомные песни Newgrounds

Night Core V1 умеет автоматически получать данные неизвестной Geometry Dash custom song по `songID` и сохранять результат локально.

[English version](../NEWGROUNDS.md)

## Как проходит запрос

Для `songID`, которого ещё нет в `core_songs`:

1. Night Core сначала запрашивает metadata через официальный Geometry Dash/Boomlings song-info endpoint.
2. Если пригодных данных нет, можно выполнить fallback на публичную страницу аудио Newgrounds по этому ID.
3. Download URL принимается только если использует HTTPS и принадлежит Newgrounds/ngfiles.
4. Успешные metadata записываются в `core_songs`.
5. Следующие запросы обслуживаются из локальной базы без нового обращения наружу.

Один и тот же resolver используется в `getGJSongInfo.php` и при формировании song-блока в ответах поиска уровней.

## Миграция

Интеграция добавляет:

```text
0008_newgrounds_song_cache.sql
```

После обновления выполните:

```bash
php bin/nightcore migrate
php bin/nightcore doctor
```

Миграция создаёт `core_song_fetch_failures` — это только negative cache для недоступных/невалидных song ID.

## Настройки

```env
NEWGROUNDS_FETCH_ENABLED=1
NEWGROUNDS_USE_BOOMLINGS_METADATA=1
NEWGROUNDS_DIRECT_FALLBACK=1
NEWGROUNDS_TIMEOUT_SECONDS=5
NEWGROUNDS_NEGATIVE_TTL_SECONDS=3600
```

### `NEWGROUNDS_FETCH_ENABLED`

Главный переключатель автоматического внешнего поиска. При `0` Night Core работает по старой схеме: только локальная `core_songs`.

### `NEWGROUNDS_USE_BOOMLINGS_METADATA`

При включении Night Core сначала проверяет официальный Geometry Dash song-info endpoint и только затем direct fallback.

### `NEWGROUNDS_DIRECT_FALLBACK`

Разрешает прямое получение данных со страницы Newgrounds, если Geometry Dash metadata endpoint не дал пригодного результата.

### `NEWGROUNDS_TIMEOUT_SECONDS`

Timeout каждого внешнего запроса. Night Core ограничивает это значение безопасным диапазоном.

### `NEWGROUNDS_NEGATIVE_TTL_SECONDS`

Сколько секунд хранить неудачный song ID до следующей попытки. Это предотвращает повторный внешний запрос на каждый клиентский request для несуществующей песни.

## Требования

Серверу приложения нужен исходящий HTTPS-доступ.

Night Core предпочитает PHP cURL. Если cURL отсутствует, fallback HTTP-клиенту требуется `allow_url_fopen=1`.

## Безопасность

Night Core не принимает произвольный download host из внешних metadata. Песня кешируется только если URL:

- использует HTTPS;
- принадлежит `newgrounds.com` или его поддомену;
- либо `ngfiles.com` или его поддомену.

Размер внешнего ответа ограничен, redirects автоматически не обходятся.

## Проверка на сервере

После миграции запросите реальный song ID, которого ещё нет в локальной таблице:

```bash
curl -sS -X POST http://127.0.0.1/getGJSongInfo.php \
  -d 'songID=REPLACE_WITH_REAL_ID'
```

Успешный ответ имеет обычный Geometry Dash song wire format и начинается так:

```text
1~|~<songID>~|~2~|~...
```

Затем проверьте, что запись появилась в `core_songs`, обычным SQL-клиентом вашей установки. Пароли БД не вставляйте в issue, логи, скриншоты или сообщения.

## Поведение при ошибке

Когда песню получить невозможно, Night Core возвращает обычное GD-значение `-1` и сохраняет только временную запись failed lookup. Фиктивная song-запись не создаётся.

Уже существующая запись в `core_songs` является приоритетной. Если у неё `isDisabled=1`, endpoint возвращает `-2` и автоматически не заменяет её данными upstream.
