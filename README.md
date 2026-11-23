# MyTube API

MyTube — самостоятельно размещаемая видеотека «как YouTube» для домашней сети.
Вы подписываетесь на каналы и плейлисты YouTube, MyTube сам находит новые видео,
скачивает их на ваш диск и показывает в веб-клиенте и в приложении для Apple TV,
без рекламы и рекомендаций.

Этот репозиторий — Laravel-приложение: база библиотеки, синхронизация с YouTube,
веб-клиент и JSON API для ТВ-клиентов.

## Как устроено

```
                 YouTube Data API
                        │  каналы, плейлисты, метаданные видео
                        ▼
┌───────────────────────────────────┐      ┌─────────────────────────────┐
│ scheduler  (этот репозиторий)     │      │ worker  (mytube-workers)    │
│ php artisan schedule:work         │      │ Python + yt-dlp + ffmpeg    │
│ youtube:parse-videos раз в минуту │      │ скачивает и транскодирует   │
└────────────────┬──────────────────┘      └──────────────┬──────────────┘
                 │ новые видео                            │ is_downloaded, файл
                 ▼                                        ▼
          ┌────────────────────── общая база (SQLite) ──────────────────────┐
          └──────────────────────────────┬──────────────────────────────────┘
                                         │                 медиатека MEDIA_ROOT
┌────────────────────────────────────────┴────────────┐    videos/<channel_id>/<id>.mp4
│ api  (этот репозиторий): Laravel + nginx            │◄──── чтение
│  /       веб-клиент (Inertia + React)               │
│  /api/*  JSON API                                   │
└──────────────┬───────────────────────────┬──────────┘
               ▼                           ▼
          браузер                 tvOS-приложение
                                  (mytube-client-apple)
```

- **api** — этот репозиторий: веб-клиент, JSON API, отдача видео и субтитров.
- **scheduler** — тот же код, команда `php artisan schedule:work`: раз в минуту
  запускает `youtube:parse-videos`, которая находит новые видео отслеживаемых
  каналов (по RSS, без квоты) и плейлистов (через YouTube Data API, не чаще раза
  в 10 минут) и записывает их в базу.
- **worker** — отдельный репозиторий [`kamabyte/mytube-workers`](https://github.com/kamabyte/mytube-workers)
  (Python, yt-dlp). Работает с той же базой: берёт ещё не скачанные видео, кладёт
  файлы в `MEDIA_ROOT/videos/<channel_id>/<video_id>.<ext>`, отмечает видео
  скачанным и пишет журнал попыток в `video_download_runs`.
- **Клиенты** — веб-клиент из этого репозитория и приложение для tvOS
  [`kamabyte/mytube-client-apple`](https://github.com/kamabyte/mytube-client-apple),
  которое работает через JSON API.

Клиентам видны только скачанные видео (глобальный скоуп `DownloadedVideo`).

## Возможности

- Подписка на каналы и плейлисты YouTube (плейлист хранится как «канал» с
  `is_playlist = true`); одно видео может входить в несколько источников.
- Автоматический поиск новых видео; фильтр по длительности (по умолчанию
  от 2 минут до 6 часов).
- Веб-клиент: главная с полками, лента, каналы, просмотр с очередью «Далее»,
  поиск по названиям (без учёта регистра, с кириллицей), статистика библиотеки.
- Добавление каналов и плейлистов из веба; удаление каналов и видео вместе
  с файлами и обложками — под PIN-кодом.
- Вшитые в MP4 субтитры отдаются веб-плееру в WebVTT (через ffmpeg).
- Видео отдаёт nginx по `X-Accel-Redirect`, с поддержкой Range-запросов.

> Авторизации в приложении нет. PIN на удаление защищает от случайных нажатий,
> а не от злоумышленника. Разворачивайте MyTube только в доверенной сети.

## Установка веб-приложения

- **iPhone и iPad:** в Safari откройте MyTube → «Поделиться» → «На экран Домой».
- **Android:** в меню Chrome выберите «Добавить на главный экран» / «Установить приложение».
- **Windows, macOS и Linux:** в Chrome или Edge нажмите значок установки в адресной строке;
  в Safari на macOS — «Файл» → «Добавить в Dock».

Для установки через Chrome и Edge нужен HTTPS (или `localhost` при разработке).
По обычному HTTP на IP-адресе в домашней сети браузер может предложить только ярлык.
Иконки вкладок, закладок и домашнего экрана входят в приложение;
манифест `/site.webmanifest` открывает установленное приложение с главной страницы
в отдельном окне. Потяните страницу вниз от верхнего края, чтобы обновить её. При потере связи
приложение показывает статус сети, а на HTTPS и localhost при повторном открытии
доступен экран с кнопкой «Повторить». Для просмотра и воспроизведения нужен
доступ к серверу MyTube.

Исходник и описание иконки — в [`resources/images/`](resources/images/README.md).
После замены исходника обновите размеры командой `sh scripts/generate-icons.sh`
(нужен ImageMagick; при сборке и запуске приложения он не требуется).

## JSON API

Все маршруты — под префиксом `/api`, ответы и ошибки (404, 422) всегда в JSON.
Проверка живости — `GET /up`.

| Метод и путь | Назначение |
|---|---|
| `GET /api/home` | Главная: `featured`, `latest`, `recently_added`, `channels` (полки с ограниченным размером) |
| `GET /api/videos` | Список видео (пагинация, фильтры, сортировка) |
| `GET /api/videos/{id}` | Видео с каналом |
| `PATCH /api/videos/{id}` | Установить режим скачивания отдельного видео |
| `POST /api/videos/{id}/download` | Поставить видео в очередь вручную |
| `DELETE /api/videos/{id}/download` | Снять ручную заявку |
| `DELETE /api/videos/{id}/file` | Выгрузить видео из медиатеки |
| `GET /api/videos/{id}/up-next?limit=` | Очередь «Далее» (`limit` 1–30) |
| `GET /api/videos/{id}/stream` | Файл видео (`video_url` в ответах указывает сюда) |
| `GET /api/videos/{id}/subtitles/{track}` | Дорожка субтитров в WebVTT |
| `GET /api/channels` | Список каналов и плейлистов |
| `GET /api/channels/{id}` | Канал с `videos_count` |
| `PATCH /api/channels/{id}` | Переключить режим скачивания канала |
| `GET /api/search?q=` | Видео (с пагинацией) и до 12 каналов в поле `channels` |
| `GET /api/statistics` | Сводка: число видео и каналов, объём, длительность, видео в очереди |
| `GET /api/statistics/channels` | Объём и число видео по каналам |
| `GET /api/statistics/daily` | Скачано по дням за последние 14 дней |

### Режимы скачивания

`PATCH /api/channels/{id}` принимает `{"download_on_demand":true}` для каталога
по запросу, `{"download_on_demand":false}` для всего каталога или
`{"download_on_demand":false,"download_new_only":true}` для видео, опубликованных
с момента переключения. В последнем режиме ответ содержит границу
`auto_download_from`. `sync_paused:true` приостанавливает обновление и
автоскачивание через этот источник.

`PATCH /api/videos/{id}` принимает поле `download_on_demand`. Значение `true`
разрешает скачивать видео только по ручному запросу, `false` ставит видео
в очередь автоматически при наличии активного источника, `null` возвращает
настройки источников. Поле `auto_download` в ответе показывает итоговое правило
для видео.

`DELETE /api/videos/{id}/file` принимает `{"pin":"…"}`. Видео остаётся в каталоге
с обложкой и режимом скачивания по запросу. `POST /api/videos/{id}/download`
ставит его в очередь снова. `GET /api/videos?filter[catalog]=1` включает
нескачанные видео каталога.

### Списки

Списки построены на [spatie/laravel-query-builder](https://github.com/spatie/laravel-query-builder)
и [spatie/laravel-json-api-paginate](https://github.com/spatie/laravel-json-api-paginate):

- **Пагинация** — `page[number]` и `page[size]` (по умолчанию и максимум — 30);
  в ответе стандартные `data`, `links`, `meta` Laravel.
- **Сортировка** — `sort=<поле>`, `-` перед полем — по убыванию; неизвестное
  поле даёт 400.
  - `/api/videos`: `published_at` (по умолчанию `-published_at`), `created_at`,
    `view_count`, а также ключи веб-клиента `new`, `added`, `popular`, `old`.
  - `/api/channels`: `name`, `created_at` (по умолчанию `-created_at`),
    `latest_video_published_at`.
- **Фильтры** — `filter[...]`:
  - `/api/videos`: `filter[channel_id]=1,2` — все видео канала или плейлиста.
  - `/api/channels`: `filter[has_videos]=1`, `filter[is_playlist]=true|false`.
- **Связи** — `include=channel` для видео; `include=videos` или
  `include=recentVideos` для каналов.
- **Поля** — `fields[videos]=id,name,thumbnail`, `fields[channels]=id,name`.

Пример:

```bash
curl 'http://localhost:8000/api/videos?filter[channel_id]=3&sort=-published_at&page[size]=10&include=channel'
```

## Требования

- PHP 8.3+ (образ собирается на PHP 8.5) с расширениями `pdo_sqlite`, `intl`
- Composer, Node.js 20.19+ и npm
- SQLite (основная и единственная проверенная СУБД)
- ffmpeg — для субтитров
- Ключ YouTube Data API v3
- Для скачивания видео — [mytube-workers](https://github.com/kamabyte/mytube-workers)

## Локальная разработка

```bash
composer setup          # зависимости, .env, APP_KEY, миграции, сборка фронтенда
composer dev            # php artisan serve + queue:listen + pail
npm run dev             # Vite с горячей перезагрузкой (в отдельном терминале)
php artisan schedule:work   # если нужна периодическая синхронизация
```

Приложение откроется на `http://localhost:8000`. Добавить источники можно из
веба или командами:

```bash
php artisan youtube:add-channel https://www.youtube.com/@channel
php artisan youtube:add-playlist <url или id плейлиста>
php artisan youtube:channels                # интерактивное управление
php artisan youtube:remove-channel [--dry-run]
php artisan youtube:parse-videos [--channel=ID] [--popular]
php artisan youtube:sync-thumbnails
php artisan mytube:delete-pin               # PIN для удаления из веба (4–8 цифр)
```

Пока PIN не задан, удаление из веба недоступно.

`php artisan serve` не умеет `X-Accel-Redirect`, поэтому `/api/videos/{id}/stream`
локально вернёт пустой ответ — для проигрывания видео нужен nginx (см. Docker).

Тесты (Pest, SQLite в памяти) и проверки:

```bash
composer test           # или php artisan test
vendor/bin/pint         # стиль кода
npm run types:check     # TypeScript
```

## Конфигурация

Основа — `.env.example`. Главное:

| Переменная | Назначение |
|---|---|
| `APP_KEY`, `APP_URL` | Ключ приложения; `APP_URL` — абсолютный адрес, из которого строятся `video_url` и обложки для ТВ-клиентов |
| `APP_LOCALE` | Язык интерфейса (`ru`, `en`) |
| `DB_CONNECTION`, `DB_DATABASE` | База; по умолчанию SQLite. Воркер должен смотреть в ту же базу |
| `MEDIA_ROOT` | Корень медиатеки; видео лежат в `MEDIA_ROOT/videos/` |
| `YOUTUBE_API_KEY` | Ключ YouTube Data API v3 |
| `MIN_VIDEO_DURATION`, `MAX_VIDEO_DURATION` | Границы длительности в секундах (по умолчанию 120 и 21600) |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | По умолчанию `database` |

Обложки каналов и видео сохраняются на диск `public` (`storage/app/public`),
поэтому нужен `php artisan storage:link`.

## Docker

Образ `ghcr.io/kamabyte/mytube-api` (собирается GitHub Actions из `Dockerfile`)
содержит PHP-FPM и nginx ([serversideup/php](https://serversideup.net/open-source/docker-php/)),
собранный фронтенд, ffmpeg и sqlite3. Теги: `latest` для `main` и `sha-<коммит>`
для любой ветки.

В `deploy/compose.yml` три сервиса:

- `api` — веб и API на порту контейнера 8080; при старте делает копию базы
  (`docker/entrypoint.d/45-mytube-db-backup.sh`), выполняет миграции и
  собирает кеши; медиатека подключена только на чтение;
- `scheduler` — тот же образ с `php artisan schedule:work`;
- `worker` — образ `mytube-workers`, пишет в медиатеку.

Все сервисы делят каталог с базой SQLite (в режиме WAL). Медиатека в контейнерах
всегда смонтирована как `/media` (`MEDIA_ROOT=/media`): этот путь задан `alias` в
`docker/nginx/mytube.conf`, а каталог на хосте выбирается переменной `MEDIA_PATH`.
Данные держите на хосте, например в `/srv/mytube/...`.

Подробности развёртывания, обновления и отката — в [deploy/README.md](deploy/README.md).

## Лицензия

[MIT](LICENSE).
