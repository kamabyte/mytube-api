export interface ChannelSummary {
    id: number;
    name: string;
    thumbnail: string | null;
    is_playlist: boolean;
    /** Каталог без автоскачивания: видео скачиваются, только когда их попросят. */
    download_on_demand: boolean;
    auto_download_from?: string | null;
    sync_paused?: boolean;
    videos_count?: number;
    queued_count?: number;
    /** Скачанные и нет, без удалённых и недоступных. */
    catalog_count?: number;
    total_size_bytes?: number | null;
    total_duration_seconds?: number | null;
    latest_published_at?: string | null;
    videos?: Video[];
}

export interface Video {
    id: number;
    channel_id: number;
    name: string;
    thumbnail: string | null;
    duration_seconds: number;
    view_count: number;
    published_at: string | null;
    downloaded_at: string | null;
    download_state: DownloadState;
    /** Запросили скачать — только свой запрос можно отменить. */
    download_requested_at: string | null;
    /** null — использовать настройки источников. */
    download_on_demand: boolean | null;
    /** Видео может попасть в очередь без ручного запроса. */
    auto_download: boolean;
    channel?: ChannelSummary;
}

/** App\Support\DownloadState: где видео на пути в медиатеку. */
export type DownloadState =
    "available" | "queued" | "downloading" | "downloaded" | "unavailable";

export interface VideoDetail extends Video {
    external_id: string;
    description: string | null;
    file_size: number | null;
    /** null — видео из каталога, ещё не скачано. */
    stream_url: string | null;
    subtitles: SubtitleTrack[];
}

/** Скачанное видео — то, что умеет играть плеер. */
export type PlayableVideo = VideoDetail & { stream_url: string };

/** Дорожка субтитров, вшитая в файл; url отдаёт её в WebVTT. */
export interface SubtitleTrack {
    track: number;
    language: string | null;
    label: string;
    url: string;
}

/** Постраничная коллекция Laravel-ресурсов (Inertia::scroll). */
export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

export type VideoSort = "new" | "added" | "popular" | "old";

export interface Toast {
    type: "success" | "error";
    message: string;
    description?: string | null;
}

export interface SharedProps {
    sidebarChannels: ChannelSummary[];
    deletePin: { configured: boolean; unlocked: boolean };
    /** Непрочитанные уведомления — бейдж колокольчика. */
    unreadNotifications: number;
    /** Очередь скачивания на сервер в «Статистике». */
    downloads: { queued: number; active: boolean };
    [key: string]: unknown;
}
