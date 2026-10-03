<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Сортировки списков видео — общие для веба и JSON API ТВ-клиентов.
 * id в конце каждой — чтобы порядок был стабильным между страницами
 * при одинаковых датах.
 */
class VideoSort
{
    public const string NEW = 'new';

    public const string ADDED = 'added';

    public const string POPULAR = 'popular';

    public const string OLD = 'old';

    /** Первая — по умолчанию. */
    public const array ALL = [self::NEW, self::ADDED, self::POPULAR, self::OLD];

    public const string DEFAULT = self::NEW;

    public static function normalize(?string $sort): string
    {
        return in_array($sort, self::ALL, true) ? $sort : self::DEFAULT;
    }

    public static function fromRequest(Request $request, string $key = 'sort'): string
    {
        return self::normalize($request->string($key)->value());
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function apply(Builder $query, string $sort): Builder
    {
        return match (self::normalize($sort)) {
            self::ADDED => $query
                ->orderByRaw('downloaded_at IS NULL')
                ->orderByDesc('downloaded_at')
                ->orderByDesc('id'),
            self::POPULAR => $query->orderByDesc('view_count')->orderByDesc('id'),
            self::OLD => $query->orderBy('published_at')->orderBy('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }
}
