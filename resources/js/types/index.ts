export interface ChannelSummary {
    id: number;
    name: string;
    thumbnail: string | null;
    is_playlist: boolean;
    videos_count?: number;
    queued_count?: number;
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
    channel?: ChannelSummary;
}

export interface VideoDetail extends Video {
    external_id: string;
    description: string | null;
    file_size: number | null;
    stream_url: string;
    subtitles: SubtitleTrack[];
}

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

export type VideoSort = 'new' | 'added' | 'popular' | 'old';

export interface Toast {
    type: 'success' | 'error';
    message: string;
    description?: string | null;
}

export interface SharedProps {
    sidebarChannels: ChannelSummary[];
    deletePin: { configured: boolean; unlocked: boolean };
    [key: string]: unknown;
}
