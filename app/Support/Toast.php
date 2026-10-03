<?php

namespace App\Support;

use Inertia\Inertia;

/**
 * Всплывашка в веб-клиенте после редиректа. На фронте её показывает
 * FlashToaster (resources/js/components/flash-toaster.tsx).
 */
final class Toast
{
    public static function success(string $message, ?string $description = null): void
    {
        self::flash('success', $message, $description);
    }

    public static function error(string $message, ?string $description = null): void
    {
        self::flash('error', $message, $description);
    }

    private static function flash(string $type, string $message, ?string $description): void
    {
        Inertia::flash('toast', [
            'type' => $type,
            'message' => $message,
            'description' => $description,
        ]);
    }
}
