<?php

namespace App\Support;

use Inertia\Inertia;

/**
 * Всплывашка в веб-клиенте после редиректа. На фронте её показывает
 * FlashToaster (resources/js/components/flash-toaster.tsx).
 */
final class Toast
{
    public static function success(string $message, ?string $description = null, ?string $id = null): void
    {
        self::flash('success', $message, $description, $id);
    }

    public static function error(string $message, ?string $description = null): void
    {
        self::flash('error', $message, $description);
    }

    private static function flash(string $type, string $message, ?string $description, ?string $id = null): void
    {
        Inertia::flash('toast', [
            'id' => $id,
            'type' => $type,
            'message' => $message,
            'description' => $description,
        ]);
    }
}
