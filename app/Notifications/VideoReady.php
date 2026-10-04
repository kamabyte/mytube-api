<?php

namespace App\Notifications;

use App\Models\Video;
use App\Support\MediaUrl;
use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * «Видео готово» — и для автоскачивания, и для скачанного по запросу.
 * Хранится в базе (колокольчик в шапке) и сразу уходит в открытые вкладки через Reverb.
 */
class VideoReady extends Notification
{
    /** Публичный канал: входа пока нет. С профилями — приватный канал пользователя. */
    public const string CHANNEL = 'notifications';

    public function __construct(public readonly Video $video) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Снимок на момент скачивания: колокольчик показывает его, даже если видео потом удалят.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->video->loadMissing('channel:id,name');

        return [
            'video_id' => $this->video->id,
            'title' => $this->video->name,
            'thumbnail' => MediaUrl::public($this->video->getRawOriginal('thumbnail')),
            'channel_id' => $this->video->channel_id,
            'channel_name' => $this->video->channel?->name,
            'duration_seconds' => $this->video->duration_seconds,
        ];
    }

    /**
     * Сразу, без очереди: воркера очереди в стеке нет, а Reverb отвечает за миллисекунды.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function databaseType(object $notifiable): string
    {
        return 'video-ready';
    }

    public function broadcastType(): string
    {
        return 'video-ready';
    }
}
