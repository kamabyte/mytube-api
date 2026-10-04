<?php

namespace App\Events;

use App\Http\Resources\Web\VideoCard;
use App\Models\Video;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Видео скачалось — веб-клиент показывает «Видео готово». Шлём сразу, без
 * очереди: воркера очереди в стеке нет, а Reverb отвечает за миллисекунды.
 * Канал публичный: пользователей в приложении нет, всё и так открыто.
 */
class VideoDownloaded implements ShouldBroadcastNow
{
    use Dispatchable;

    public const string CHANNEL = 'downloads';

    public function __construct(public readonly Video $video) {}

    public function broadcastOn(): Channel
    {
        return new Channel(self::CHANNEL);
    }

    public function broadcastAs(): string
    {
        return 'video.downloaded';
    }

    /**
     * @return array{video: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        $this->video->loadMissing('channel:id,name,thumbnail,is_playlist');

        return ['video' => (new VideoCard($this->video))->resolve()];
    }
}
