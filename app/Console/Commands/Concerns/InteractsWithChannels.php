<?php

namespace App\Console\Commands\Concerns;

use App\Models\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

use function Laravel\Prompts\select;

trait InteractsWithChannels
{
    /** The option every channel list ends with, so no menu is a dead end. */
    private const string BACK = 'back';

    /**
     * Channels with the counters the interactive commands show.
     *
     * The videos relation is filtered by the DownloadedVideo global scope, so every
     * aggregate drops it explicitly: the counters cover queued videos as well.
     *
     * @return Collection<int, Channel>
     */
    protected function channelsOverview(): Collection
    {
        return Channel::query()
            ->withCount([
                'videos as videos_total' => fn ($query) => $query->withoutGlobalScopes(),
                'videos as videos_downloaded' => fn ($query) => $query->withoutGlobalScopes()->where('is_downloaded', true),
            ])
            ->withSum(['videos as videos_bytes' => fn ($query) => $query->withoutGlobalScopes()], 'file_size')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, Channel>  $channels
     */
    protected function renderChannelsTable(Collection $channels): void
    {
        $this->table(
            ['ID', 'Name', 'Username', 'Videos', 'Downloaded', 'Size', 'Latest', 'Popular'],
            $channels->map(fn (Channel $channel) => [
                $channel->id,
                $channel->name,
                $this->formatUsername($channel),
                $channel->videos_total,
                $channel->videos_downloaded,
                $this->formatBytes((int) $channel->videos_bytes),
                $this->formatFlag((bool) $channel->parse_latest),
                $this->formatFlag((bool) $channel->parse_popular),
            ])->all(),
        );
    }

    /**
     * Returns null when there is nothing to pick from, or when the last option was chosen
     * to step back: every channel list is a dead end otherwise.
     *
     * @param  Collection<int, Channel>  $channels
     */
    protected function selectChannel(string $label, Collection $channels, string $backLabel = '← Back'): ?Channel
    {
        if ($channels->isEmpty()) {
            $this->components->warn('There are no channels yet.');

            return null;
        }

        $options = $channels
            ->mapWithKeys(fn (Channel $channel) => [
                $channel->id => sprintf(
                    '%s%s — %d video(s), %s',
                    $channel->name,
                    $channel->username ? ' (@'.$channel->username.')' : '',
                    $channel->videos_total,
                    $this->formatBytes((int) $channel->videos_bytes),
                ),
            ])
            ->all();

        $options[self::BACK] = $backLabel;

        $selected = select(
            label: $label,
            options: $options,
            scroll: 15,
            hint: 'Press ↑ once to reach "'.ltrim($backLabel, '← ').'"',
        );

        if ($selected === self::BACK) {
            return null;
        }

        return $channels->firstWhere('id', (int) $selected);
    }

    protected function formatUsername(Channel $channel): string
    {
        return $channel->username ? '@'.$channel->username : '—';
    }

    protected function formatFlag(bool $enabled): string
    {
        return $enabled ? 'yes' : '—';
    }

    protected function formatBytes(int $bytes): string
    {
        return $bytes > 0 ? Number::fileSize($bytes, maxPrecision: 2) : '0 B';
    }
}
