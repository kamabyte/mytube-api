## Web client

The same Laravel app serves a web client: Inertia v3 + React 19 + TypeScript + Tailwind v4 + shadcn/ui (`resources/js`).

- JSON API for the TV clients lives under `/api` (`apiPrefix: 'api'`, route names `api.*`, `routes/api.php`). The web client owns the root: `/`, `/videos`, `/watch/{id}`, `/channels`, `/channels/{id}`, `/search`, `/statistics` (`routes/web.php`).
- JSON API for the tvOS client (additive to the Android contract; errors under `/api/*` are always JSON). Shared logic with the web: `App\Support\VideoSort` (`new`/`added`/`popular`/`old`, id tiebreak), `App\Support\TitleSearch` (Cyrillic case-insensitive, SQLite `unicode_lower`), `App\Support\UpNext`.
  - `GET /api/home` → `featured` (≤6), `latest` (≤16), `recently_added` (≤16), `channels` (≤12, each with `videos` ≤12). List videos omit `description`, always carry `channel {id,name,thumbnail,is_playlist}`.
  - `GET /api/search?q=&page[number]=&page[size]=` → `query`, `channels` (≤12), `data`, `meta`, `links`; `q` > 100 chars → 422 (the web truncates instead).
  - `GET /api/videos/{video}/up-next?limit=1..30` (default 20) → `data`.
  - `GET /api/videos/{video}` includes `channel` (with `is_playlist`, `videos_count`); `GET /api/videos` also accepts `sort=new|added|popular|old`; `GET /api/channels` always has `videos_count`, `is_playlist`, `filter[is_playlist]=1|0|true|false`, stable `sort=name`. Videos carry `view_count`.
  - On-demand catalog for TV clients (opt-in, so the Android contract is unchanged): `GET /api/videos?filter[catalog]=1` also returns not-downloaded catalog videos; every video carries `download_state` (`available|queued|downloading|downloaded|unavailable`), catalog ones have `video_url: null`. `GET /api/videos/{id}` works for catalog videos (status polling). `POST`/`DELETE /api/videos/{id}/download` request/cancel a download (422 if unavailable) and return the video. Channels carry `catalog_count` (next to downloaded `videos_count`) and `download_on_demand`.
- Library management (add channel/playlist, remove channel, remove video) goes through `App\Support\Youtube\ChannelImporter` and `App\Support\LibraryCleaner` — shared by the `youtube:*` commands and the web. A removed video keeps its row (`removed_at` + `is_unavailable`) so the parser does not re-create it and the worker does not re-download it. "Remove file" (`DELETE /videos/{video}/file`, `LibraryCleaner::unloadVideo`) deletes only the media files and returns the video to the catalog (requestable again); it is refused for videos with an auto-download source (the worker would re-download them). Both live in the video card's ⋮ menu and need the delete PIN. The api container mounts `/media` read-write (with the worker's `MEDIA_GID`) for this.
- Web controllers are in `app/Http/Controllers/Web`, resources in `app/Http/Resources/Web`. Pass resources to Inertia via `->resolve()` (otherwise they arrive wrapped in `{data: ...}`); paginated lists use `Inertia::scroll()` + `<InfiniteScroll>`.
- Media URLs for the web are host-relative (`App\Support\MediaUrl`, `route(..., absolute: false)`): the browser may come via a domain or the IP, unlike TV clients that use `APP_URL`.
- Subtitles: the worker embeds them in the MP4 as `mov_text`, which browsers ignore. `App\Support\Subtitles` lists tracks with `ffprobe` (cached by file fingerprint) and extracts one to WebVTT with `ffmpeg` into `storage/app/subtitles`; `GET /api/videos/{video}/subtitles/{track}?v=` serves it, the web `VideoDetail` carries `subtitles`. The player renders cues itself. `ffmpeg` is installed in the image.
- Parser quota: for channels with a sync point and their own uploads playlist, `youtube:parse-videos` finds new uploads via the channel RSS (`App\Support\Youtube\UploadsFeed`, free, last 15 entries) and only calls `videos.list` when there is something new; it falls back to `playlistItems.list` when the feed fails or is full. Playlists still go through the API in full every run.
- Notifications are Laravel's built-in ones (`notifications` table). There are no users/profiles yet, so they all go to `User::owner()` (auto-created; becomes the first profile later). "Video is ready": the worker calls `POST /api/internal/videos/{id}/downloaded` (bearer `WORKER_HOOK_TOKEN`) after each download → `User::owner()->notify(new App\Notifications\VideoReady)` → stored (`database`) + broadcast now over Reverb (`broadcast`, sync connection, public channel `notifications`, event `notification.created`). The `reverb` service is in `deploy/compose.yml`; the api nginx proxies `/app/` to it; the Reverb key reaches the browser via `<meta name="reverb-key">`. Web: the header bell (`NotificationBell`, `GET/PATCH/POST/DELETE /notifications`, `unreadNotifications` shared prop) and live toasts (`LiveNotifications`). In-tab only — the web is plain HTTP, so no system notifications/Web Push.
- Downloads in the header (`DownloadsMenu`, next to the bell): `GET /downloads` (`Web\DownloadController`) → `current` (the video of a `VideoDownloadRun::running()` run) + `started_at`, `queue` (≤20, `Video::inDownloadOrder()` — must match the worker's `ORDER BY`) and `queued_count`; the `downloads {queued, active}` shared prop drives the badge. The worker reports no percentage, so the current download shows an indeterminate bar and elapsed time. The popover polls while open; the badge refreshes every 20 s while the queue is not empty and on every `notification.created`.
- On-demand downloads: a channel with `download_on_demand` only keeps a catalog (videos + thumbnails); a video is downloaded when requested (`videos.download_requested_at`, `Web\DownloadRequestController`) or when any of its sources is not on-demand. `Video::awaitingDownload()` must match the worker's job query (mytube-workers `DbJobSource`).
- The catalog is part of the web library: `Video::catalog()` (+ `withDownloadState()` for `App\Support\DownloadState` badges) feeds home "Новые видео" and channel shelves, the `/videos` feed, search, the channel page and `/watch/{catalogVideo}` (binding in `AppServiceProvider` — route files don't run with `route:cache`). Downloaded-only by design: the home hero, up next (autoplay), continue watching, and the whole TV API (the `DownloadedVideo` global scope). Channel cards carry `videos_count` (downloaded) + `catalog_count` (`Channel::catalogCount()`); freshness uses `Channel::catalogVideos()`; the sidebar lists all channels.
- Watch progress / "continue watching" is client-only (localStorage, `resources/js/lib/watch-progress.ts`).
- Dev: `npm run dev` + `php artisan serve`. Checks: `npm run types:check`, `npm run build`, `php artisan test`.
- Deploy: Docker image `ghcr.io/kamabyte/mytube-api` (built by GitHub Actions, `Dockerfile`, frontend built in the image), run by Dokploy from `deploy/compose.yml` together with the scheduler and `mytube-workers`. See `deploy/README.md`.

## Code style (PHP)

- One controller per resource: `index`/`show`/`store`/`destroy` live together (e.g. `Web\ChannelController`), no `Manage*` splits.
- Validation: a single field may be validated inline with `$request->validate()`; two or more fields go into a FormRequest (`app/Http/Requests`, e.g. `Web\StoreChannelRequest`) with typed accessors instead of `filled(...) ? trim(...) : null` in the controller. `TrimStrings` / `ConvertEmptyStringsToNull` are on, so no manual trimming.
- Flat returns: build queries and collections into variables first; `return`, `Inertia::render()` and `response()->json()` get a flat array of variables (see `HomeController` and `Web\HomeController`).
- Typed class constants (`private const int SHELF_SIZE = 16;`) and return types on every method, controllers included.
- Toasts for the web: `App\Support\Toast::success($message, $description)` / `Toast::error(...)`, never `Inertia::flash('toast', ...)` directly; branch with a plain `if`, not a ternary of arrays.
- Services return small readonly result objects instead of shaped arrays (`ChannelRemoval`, `VideoRemoval`) and throw dedicated exceptions (`MediaUnavailable`, `ImportFailed`).
- Run `vendor/bin/pint --dirty` before committing.

## Notion Integration

This project is wired into the [`claude-notion-pipeline`] — a cron orchestrator that picks tasks from a shared Notion database and walks them through automated planning → human review → implementation → merge.

- **Project Slug**: `mytube-api`  ← value of the Notion `Project` select on tasks belonging to this repo
- **Pipeline statuses (happy path)**:
  `Draft → To Do → Planning → Plan Ready → Plan Approved → In Progress → In Review → Done`
- **Revision loops**:
  - `Plan Ready → Plan Revision → Planning → Plan Ready` — request changes to a plan
  - `In Review → Changes Requested → In Progress → In Review` — request changes to a PR
- `Blocked` is a parking lot, can be entered from any active stage.

### Who moves the status

| Transition | Mover |
| --- | --- |
| Draft → To Do | human (Notion UI) |
| To Do → Planning | orchestrator (claims the task) |
| Planning → Plan Ready | Claude (`/plan-task` finishes) |
| Plan Ready → Plan Approved | human (after reviewing the plan) |
| **Plan Ready → Plan Revision** | human (after leaving Notion comments asking for changes) |
| **Plan Revision → Planning** | orchestrator (claims for re-plan) |
| Plan Approved → In Progress | orchestrator (claims the task) |
| In Progress → In Review | Claude (`/work-task` finishes, PR opened) |
| **In Review → Changes Requested** | human (after leaving PR comments asking for changes) |
| **Changes Requested → In Progress** | orchestrator (claims for re-work) |
| In Review → Done | orchestrator (detects merged PR) |
| any → Blocked | Claude / orchestrator on failure |

### Pipeline commands

User-level (`~/.claude/commands/`): `plan-task`, `work-task`, `submit-task`, `complete-task`, `pick-task`, `fix-bug`.

The orchestrator only invokes `plan-task` and `work-task`. The rest are manual helpers for interactive Claude Code use.

### Plan format

The plan lives in the page body under the `## 📋 План реализации` heading.

- If you want to **edit the plan yourself**: just edit it inline in `Plan Ready`, then move to `Plan Approved`. Claude will use the latest version when work begins.
- If you want **Claude to revise**: leave Notion comments on the parts you want changed (use the comment sidebar or inline thread on a specific block), then move the task `Plan Ready → Plan Revision`. Next orchestrator cycle, Claude reads the comments, writes a new revision (the previous version goes into a collapsible `<details>` block under "История планов"), and lands the task back in `Plan Ready`.

### Code-review loop

Same idea on the implementation side. Leave PR review comments on GitHub. To ask Claude to address them, move the task `In Review → Changes Requested`. The orchestrator will run `/work-task` again; Claude pulls the branch, reads the review comments via `gh`, addresses them with additional commits on the same branch (no force-push, no new PR), and lands the task back in `In Review`. Claude does NOT post replies on review threads — the new commits are the signal.
