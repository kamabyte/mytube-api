<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Колокольчик в шапке. Уведомления — встроенные Laravel, у владельца
 * библиотеки (User::owner()): профилей пока нет.
 */
class NotificationController extends Controller
{
    private const int LIMIT = 50;

    public function index(): JsonResponse
    {
        $owner = User::owner();

        $notifications = $owner->notifications()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification) => self::present($notification));
        $unread = $owner->unreadNotifications()->count();

        return response()->json([
            'unread_count' => $unread,
            'data' => $notifications,
        ]);
    }

    /** Отметить прочитанным одно — по клику на него. */
    public function update(string $notification): Response
    {
        User::owner()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    public function markAllRead(): Response
    {
        User::owner()->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function destroy(): Response
    {
        User::owner()->notifications()->delete();

        return response()->noContent();
    }

    /**
     * Как в событии Reverb (BroadcastNotificationCreated): id, type и данные
     * уведомления на верхнем уровне — фронтенд разбирает их одинаково.
     *
     * @return array<string, mixed>
     */
    public static function present(DatabaseNotification $notification): array
    {
        return [
            ...$notification->data,
            'id' => $notification->id,
            'type' => $notification->type,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}
