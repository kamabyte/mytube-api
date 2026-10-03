<?php

namespace App\Http\Middleware;

use App\Http\Resources\Web\ChannelCard;
use App\Models\Channel;
use App\Support\DeletePin;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Нужно ли спрашивать PIN в диалоге удаления.
            'deletePin' => fn () => [
                'configured' => app(DeletePin::class)->isConfigured(),
                'unlocked' => app(DeletePin::class)->isUnlocked($request),
            ],
            // Каналы в боковой панели. Лениво: частичные перезагрузки
            // (подгрузка следующей страницы) их не запрашивают.
            'sidebarChannels' => fn () => ChannelCard::collection(
                Channel::query()
                    ->has('videos')
                    ->select('id', 'name', 'thumbnail', 'is_playlist')
                    ->orderBy('name')
                    ->get()
            )->resolve(),
        ];
    }
}
