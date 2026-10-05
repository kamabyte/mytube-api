<?php

namespace App\Http\Middleware;

use App\Http\Resources\Web\ChannelCard;
use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoDownloadRun;
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
            // Непрочитанные — для бейджа на колокольчике; сам список панель грузит по клику.
            'unreadNotifications' => fn () => User::owner()->unreadNotifications()->count(),
            // Для кнопки «Загрузки» в шапке: бейдж очереди и анимация, пока воркер качает.
            'downloads' => fn () => [
                'queued' => Video::catalog()->awaitingDownload()->count(),
                'active' => VideoDownloadRun::query()->running()->exists(),
            ],
            // Подписки в боковой панели — все каналы, и те, где ещё ничего не скачано
            // (каталог «по запросу»). Лениво: частичные перезагрузки
            // (подгрузка следующей страницы) их не запрашивают.
            'sidebarChannels' => fn () => ChannelCard::collection(
                Channel::query()
                    ->select('id', 'name', 'thumbnail', 'is_playlist', 'download_on_demand')
                    ->orderBy('name')
                    ->get()
            )->resolve(),
        ];
    }
}
