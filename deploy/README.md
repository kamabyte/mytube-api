# Деплой MyTube

MyTube работает в Docker под управлением **Dokploy**: стек Compose из
[`compose.yml`](compose.yml) (тип Compose, источник Raw) — три сервиса из двух образов.

    push ──► GitHub Actions ──► ghcr.io/kamabyte/mytube-api:{sha-…, latest}
                                ghcr.io/kamabyte/mytube-workers:{sha-…, latest}
                                        │
               Dokploy → стек mytube → Deploy ──► api · scheduler · worker

| Сервис | Что | Снаружи |
|---|---|---|
| `api` | Laravel + nginx (serversideup/php 8.5), миграции при старте; проксирует WebSocket `/app/` в `reverb` | домен стека в Dokploy; `http://<сервер>:8000` для ТВ-клиентов |
| `reverb` | `php artisan reverb:start` — WebSocket для уведомлений «видео готово»; наружу не опубликован | — |
| `scheduler` | `php artisan schedule:work` — разбор каналов раз в минуту (плейлистов — раз в 10 минут) | — |
| `worker` | `video-downloader worker` из [mytube-workers](https://github.com/kamabyte/mytube-workers) | — |

## Подготовка хоста

Данные — на хосте, в `/srv/mytube/data` (владелец — отдельный пользователь, например `mytube`):

| Каталог | Что |
|---|---|
| `database/` | база SQLite в режиме WAL |
| `storage/` | `storage/` Laravel: обложки, логи, кеш |
| `backups/` | копия базы перед каждым стартом `api`, последние 7 |
| `worker/` | HOME воркера: `cookies.txt` для yt-dlp, кеш |

Медиатека — любой каталог на хосте (`MEDIA_PATH`), в контейнерах он виден как `/media`;
у `api` — только чтение. Все тома — длинная запись с `create_host_path: false`: без
смонтированного диска контейнер не стартует, а не пишет молча на системный диск.

## Environment стека

| Переменная | Что |
|---|---|
| `APP_KEY` | ключ Laravel (`php artisan key:generate --show`) |
| `APP_URL` | адрес, от которого строятся ссылки для ТВ-клиентов, например `http://192.168.1.10:8000` |
| `YOUTUBE_API_KEY` | ключ YouTube Data API v3 для разбора каналов |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | ключи Reverb (уведомления «видео готово»): любые случайные строки, например `openssl rand -hex 20`; ключ публичный — уходит в браузер |
| `WORKER_HOOK_TOKEN` | общий токен воркера и api для хука «видео скачалось» (`openssl rand -hex 20`) |
| `MEDIA_PATH` | каталог медиатеки на хосте |
| `MEDIA_GID` | группа с правом записи в медиатеку (по умолчанию `1000`) |
| `API_TAG`, `WORKERS_TAG` | теги образов: `latest` или `sha-<коммит>` |

## Выкатить новую версию

Одной командой с Mac — [`deploy/deploy.sh`](deploy.sh): ждёт сборку образа
коммита в Actions, копирует `deploy/compose.yml` в Raw-стек Dokploy, ставит
`API_TAG=sha-<коммит>` (`WORKERS_TAG` не трогает) и запускает Deploy.

```sh
echo 'DOKPLOY_URL=http://dokploy.home.internal' > deploy/deploy.env   # один раз, файл в .gitignore
security add-generic-password -U -a "$USER" -s dokploy-api -w        # один раз, API-ключ Dokploy
deploy/deploy.sh             # задеплоить origin/main
deploy/deploy.sh <commit>    # откатиться / закрепить конкретный коммит
```

Нужны `gh`, `jq` и `curl`. Вручную — так:

1. Push — Actions собирает образ с тегом `sha-<коммит>` для любой ветки и
   `latest` для `main`.
2. Dokploy → mytube → Environment: `API_TAG` / `WORKERS_TAG` = нужный тег.
   Надёжнее конкретный `sha-…`: на уже скачанный `latest` Dokploy может не обновиться.
3. **Deploy.** Контейнер `api` при старте снимает копию базы, выполняет
   миграции, собирает кеши Laravel.
4. Проверка: `curl -s http://<сервер>:8000/up`, логи сервисов в Dokploy.

Если меняется сам `compose.yml` — вставить новую версию в Dokploy (стек →
Compose File) и Deploy. Стек в Dokploy — копия файла из репозитория, источник
правды — репозиторий.

## Откат версии

Вернуть прежний `sha-…` в `API_TAG`/`WORKERS_TAG` и Deploy. Если новая
версия успела выполнить миграции, которые старая не понимает, — база из
`/srv/mytube/data/backups/database-<время>.sqlite` (остановить стек,
заменить `database.sqlite`, удалить `-wal`/`-shm`, запустить).

## Диагностика

| Симптом | Где смотреть |
|---|---|
| Видео не играет, API отдаёт 200 с пустым телом | потерян `location ^~ /_protected_media/` в `docker/nginx/mytube.conf` |
| Внутренний редирект зацикливается на `/index.php` | у location нет `^~` — регулярный location образа для медиа перехватил запрос |
| `api` не стартует после перезагрузки | не смонтирован диск медиатеки (том с `create_host_path: false`) |
| `database is locked` | база не в WAL: `sqlite3 …/database.sqlite 'PRAGMA journal_mode;'` → `wal` |
| `Sign in to confirm you're not a bot`, `HTTP 403` при скачивании | просрочены cookies: заменить `/srv/mytube/data/worker/cookies.txt` (права 600), перезапустить `worker` |
| Новые видео не появляются | лог разбора: `/srv/mytube/data/storage/logs/schedule-youtube-parse-recent-videos.log`; `YOUTUBE_API_KEY` в Environment |
