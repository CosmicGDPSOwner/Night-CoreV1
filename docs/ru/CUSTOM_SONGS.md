# Кастомные треки, размещённые на сервере

Night Core умеет самостоятельно хранить и раздавать кастомные треки Geometry Dash с GDPS-сервера. Этот механизм не зависит от Newgrounds/Boomlings и полезен, когда VPS не может обращаться к этим сервисам.

## Как это работает

1. Администратор загружает MP3 через `/songAdmin.php`.
2. Night Core автоматически выдаёт Song ID из диапазона `90000000..99999999`.
3. MP3 сохраняется вне публичного document root.
4. Метаданные песни записываются в `core_songs`, поэтому обычные `getGJSongInfo.php` и поиск уровней уже умеют отдавать эту песню игре.
5. Geometry Dash скачивает MP3 через `/downloadCustomSong.php?songID=<ID>`.

Endpoint скачивания поддерживает обычные GET/HEAD-запросы и одиночные HTTP byte ranges. Сам каталог хранения MP3 при этом наружу не публикуется.

## Настройка

Пример production-конфигурации:

```env
CUSTOM_SONG_STORAGE_PATH=/var/lib/nightcore/songs
CUSTOM_SONG_MAX_BYTES=26214400
CUSTOM_SONG_PUBLIC_BASE_URL=https://gdps.example.com
CUSTOM_SONG_ADMIN_TOKEN=CHANGE_ME_LONG_RANDOM_SECRET
```

`CUSTOM_SONG_STORAGE_PATH` должен находиться вне `public/` и быть доступен на запись пользователю PHP/web-сервера.

`CUSTOM_SONG_MAX_BYTES` задаёт лимит Night Core на один MP3. По умолчанию — 25 MiB. При этом `upload_max_filesize`, `post_max_size`, Nginx `client_max_body_size`, лимиты Apache или хостинга тоже должны разрешать такой размер.

`CUSTOM_SONG_PUBLIC_BASE_URL` — публичный адрес Night Core. Его можно оставить пустым: тогда uploader возьмёт схему и host из запроса, которым был открыт `/songAdmin.php`. Явное значение рекомендуется при reverse proxy, нескольких доменах или когда upload-страница открывается через внутренний адрес.

`CUSTOM_SONG_ADMIN_TOKEN` включает browser-uploader. При пустом значении `/songAdmin.php` отвечает HTTP 404. Используйте длинный случайный секрет и никогда не коммитьте его в Git.

## Права на каталог

Для обычного Linux production-развёртывания:

```bash
mkdir -p /var/lib/nightcore/songs
chown www-data:www-data /var/lib/nightcore/songs
chmod 0755 /var/lib/nightcore/songs
```

На другом дистрибутиве или хостинге пользователь PHP/web-сервера может называться иначе.

После изменения конфигурации:

```bash
php bin/nightcore migrate
php bin/nightcore doctor
```

Миграция `0009_local_song_library.sql` создаёт таблицу `core_local_songs`. Doctor считает хранилище кастомных песен обязательным, когда uploader включён или в локальной библиотеке уже есть песни.

## Загрузка трека

Откройте:

```text
https://ваш-gdps.example/songAdmin.php
```

Введите:

- admin token;
- название трека;
- автора/исполнителя;
- MP3-файл.

Night Core проверяет расширение `.mp3`, MP3/ID3-заголовок и размер, атомарно сохраняет файл, записывает SHA-256 и показывает сгенерированный Song ID.

Этот Song ID затем вводится в Geometry Dash как ID кастомной песни. Патч клиента, изменение launcher, EXE или APK не требуется.

## Удаление трека

После успешной авторизации на `/songAdmin.php` под формой показывается локальная библиотека. Удаление удаляет и MP3, и метаданные Night Core. Уровни, которые продолжают ссылаться на этот Song ID, больше не смогут получить песню.

## Граница безопасности

Встроенный uploader специально сделан только для администратора. Не превращайте его в анонимный публичный файлообменник. Для загрузок от сообщества нужен отдельный авторизованный и модерируемый процесс с квотами, правилами контента и защитой хранилища от злоупотреблений.

Размещайте только те аудиофайлы, которые вы имеете право хранить и распространять.

## Совместная работа с Newgrounds

Локальная библиотека и Newgrounds resolver могут работать одновременно. Night Core сначала смотрит `core_songs`, поэтому локальный Song ID обслуживается без внешнего запроса. Внешний resolver остаётся best-effort fallback для неизвестных ID.
