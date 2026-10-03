<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * The channel commands write to the database and to the log file, so they fail in a very
 * noisy way when they run as a user that only has read access to the deployed application.
 */
trait ChecksWritability
{
    protected function findWritabilityProblem(): ?string
    {
        $paths = [];
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $database = $connection->getDatabaseName();

            if (is_string($database) && is_file($database)) {
                $paths[] = $database;
                $paths[] = dirname($database);
            }
        }

        $logs = storage_path('logs');

        if (is_dir($logs)) {
            $paths[] = $logs;
        }

        $unwritable = collect($paths)
            ->reject(fn (string $path) => is_writable($path))
            ->map(fn (string $path) => realpath($path) ?: $path)
            ->unique()
            ->values();

        if ($unwritable->isEmpty()) {
            return null;
        }

        $currentUser = $this->currentUserName();
        $owner = $this->ownerName($unwritable->first());

        return sprintf(
            'The current user "%s" cannot write to: %s.%s',
            $currentUser,
            $unwritable->implode(', '),
            $owner !== null && $owner !== $currentUser
                ? " Run the command as the user that owns the application: sudo -u {$owner} php artisan {$this->getName()}."
                : '',
        );
    }

    protected function currentUserName(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && isset($user['name'])) {
                return (string) $user['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }

    protected function ownerName(string $path): ?string
    {
        $uid = @fileowner($path);

        if ($uid === false || ! function_exists('posix_getpwuid')) {
            return null;
        }

        $owner = posix_getpwuid($uid);

        return is_array($owner) && isset($owner['name']) ? (string) $owner['name'] : null;
    }
}
