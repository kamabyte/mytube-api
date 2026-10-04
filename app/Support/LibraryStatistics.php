<?php

namespace App\Support;

use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Сводка по архиву: общая, по каналам и по дням. Общая для JSON API
 * (StatisticsController) и веб-клиента.
 */
class LibraryStatistics
{
    /**
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        // В архиве — скачанное и очередь. Удалённые вручную видео — только метка
        // «не скачивать снова», а каталог каналов «по запросу» — лишь то, что
        // можно попросить: ни то ни другое в архив не входит.
        $videoStats = Video::catalog()
            ->where(fn (Builder $query) => $query
                ->where('is_downloaded', true)
                ->orWhere(fn (Builder $query) => $query->awaitingDownload()))
            ->toBase()
            ->selectRaw('COUNT(*) as total_videos')
            ->selectRaw('COALESCE(SUM(file_size), 0) as total_video_size')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as total_duration_seconds')
            ->selectRaw('SUM(CASE WHEN is_downloaded = 0 THEN 1 ELSE 0 END) as videos_in_progress')
            ->first();

        $totalVideoSizeBytes = $videoStats->total_video_size;
        $totalDurationSeconds = $videoStats->total_duration_seconds;

        return [
            'total_videos' => (int) $videoStats->total_videos,
            'total_channels' => DB::table('channels')->count(),
            'total_video_size' => $totalVideoSizeBytes,
            'total_video_size_gb' => round($totalVideoSizeBytes / 1024 / 1024 / 1024, 2),
            'total_duration_seconds' => $totalDurationSeconds,
            'total_duration_hours' => round($totalDurationSeconds / 3600, 2),
            'videos_in_progress' => (int) $videoStats->videos_in_progress,
        ];
    }

    /**
     * @return list<array<string, int|float|string>>
     */
    public function channels(): array
    {
        // Видео из нескольких источников считается в каждом из них.
        $rows = DB::table('channels')
            ->leftJoin('channel_video', 'channel_video.channel_id', '=', 'channels.id')
            ->leftJoin('videos', function ($join): void {
                $join->on('videos.id', '=', 'channel_video.video_id')
                    ->where('videos.is_downloaded', true);
            })
            ->groupBy('channels.id', 'channels.name')
            ->orderByDesc('total_size_bytes')
            ->orderBy('channels.id')
            ->selectRaw('channels.id as channel_id, channels.name as channel_title')
            ->selectRaw('COUNT(videos.id) as video_count')
            ->selectRaw('COALESCE(SUM(videos.file_size), 0) as total_size_bytes')
            ->get();

        return $rows->map(fn ($row): array => [
            'channel_id' => (int) $row->channel_id,
            'channel_title' => $row->channel_title,
            'video_count' => (int) $row->video_count,
            'total_size_bytes' => $row->total_size_bytes,
            'total_size_gb' => round($row->total_size_bytes / 1024 / 1024 / 1024, 2),
        ])->all();
    }

    /**
     * Скачанное за последние 14 дней (UTC), по дню на элемент, без пропусков.
     *
     * @return list<array<string, int|float|string>>
     */
    public function daily(): array
    {
        $start = Carbon::now('UTC')->startOfDay()->subDays(13);

        $rows = DB::table('videos')
            ->where('is_downloaded', true)
            ->where('downloaded_at', '>=', $start)
            ->groupByRaw('DATE(downloaded_at)')
            ->selectRaw('DATE(downloaded_at) as day, COUNT(*) as video_count, COALESCE(SUM(file_size), 0) as total_size_bytes')
            ->get()
            ->keyBy('day');

        $result = [];

        for ($i = 0; $i < 14; $i++) {
            $date = $start->copy()->addDays($i)->format('Y-m-d');
            $row = $rows->get($date);
            $bytes = $row->total_size_bytes ?? 0;

            $result[] = [
                'date' => $date,
                'video_count' => $row ? (int) $row->video_count : 0,
                'total_size_bytes' => $bytes,
                'total_size_gb' => round($bytes / 1024 / 1024 / 1024, 2),
            ];
        }

        return $result;
    }
}
