<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;

/**
 * Регистронезависимый поиск по названию (name) — общий для веба и JSON API.
 * LIKE в SQLite игнорирует регистр только для ASCII, а в каталоге в основном
 * кириллица, поэтому для SQLite регистрируется функция unicode_lower().
 */
class TitleSearch
{
    /** Максимальная длина запроса (веб обрезает, API отвечает 422). */
    public const int MAX_LENGTH = 100;

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $builder
     * @return TBuilder
     */
    public static function apply(Builder $builder, string $query, string $column = 'name'): Builder
    {
        self::registerUnicodeLower();

        $pattern = '%'.addcslashes(mb_strtolower($query), '%_\\').'%';
        $column = $builder->getQuery()->getGrammar()->wrap($column);

        if (DB::connection($builder->getModel()->getConnectionName())->getDriverName() === 'sqlite') {
            return $builder->whereRaw("unicode_lower({$column}) LIKE ? ESCAPE '\\'", [$pattern]);
        }

        return $builder->whereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$pattern]);
    }

    public static function registerUnicodeLower(?string $connection = null): void
    {
        $connection = DB::connection($connection);

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = $connection->getPdo();
        $lower = fn (?string $value): ?string => $value === null ? null : mb_strtolower($value);

        // PHP 8.4+: PDO::connect() отдаёт Pdo\Sqlite с createFunction();
        // sqliteCreateFunction() там объявлен устаревшим.
        if ($pdo instanceof Sqlite) {
            $pdo->createFunction('unicode_lower', $lower, 1, Sqlite::DETERMINISTIC);
        } else {
            $pdo->sqliteCreateFunction('unicode_lower', $lower, 1);
        }
    }
}
