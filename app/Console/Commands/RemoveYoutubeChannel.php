<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksWritability;
use App\Console\Commands\Concerns\InteractsWithChannels;
use App\Models\Channel;
use App\Support\LibraryCleaner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

#[Signature('youtube:remove-channel
    {channel? : Channel id, external id, username or url. Omit to pick one from the list}
    {--force : Remove without asking for a confirmation}
    {--dry-run : Only report what would be removed}'
)]
#[Description('Remove youtube channel with its videos, thumbnails and downloaded files')]
class RemoveYoutubeChannel extends Command
{
    use ChecksWritability;
    use InteractsWithChannels;

    public function __construct(private readonly LibraryCleaner $cleaner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $channel = $this->resolveChannel();

        if (! $channel instanceof Channel) {
            return self::FAILURE;
        }

        $summary = $this->summarize($channel);

        $this->renderSummary($channel, $summary);

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was removed.');

            return self::SUCCESS;
        }

        $writabilityProblem = $this->findWritabilityProblem();

        if ($writabilityProblem !== null) {
            $this->components->error($writabilityProblem);

            return self::FAILURE;
        }

        if (! $this->confirmRemoval($channel, $summary)) {
            $this->components->warn('Aborted, nothing was removed.');

            return self::FAILURE;
        }

        return $this->remove($channel, $summary);
    }

    private function resolveChannel(): ?Channel
    {
        $needle = $this->argument('channel');

        if ($needle !== null) {
            return $this->findChannel($needle);
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('The channel argument is required when the command runs non-interactively.');

            return null;
        }

        return $this->chooseChannel();
    }

    private function findChannel(string $needle): ?Channel
    {
        $normalized = ltrim(Str::of($needle)->trim()->explode('/')->last() ?? '', '@');

        if ($normalized === '') {
            $this->components->error('The channel argument is empty.');

            return null;
        }

        $matches = Channel::query()
            ->where(function ($query) use ($normalized): void {
                $query
                    ->where('external_id', $normalized)
                    ->orWhere('username', $normalized);

                if (ctype_digit($normalized)) {
                    $query->orWhere('id', (int) $normalized);
                }
            })
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            $this->components->error("Channel \"{$needle}\" was not found.");

            return null;
        }

        if ($matches->count() > 1) {
            $this->components->error(sprintf(
                'Channel "%s" is ambiguous, it matches ids: %s. Pass the exact id.',
                $needle,
                $matches->pluck('id')->implode(', '),
            ));

            return null;
        }

        return $matches->first();
    }

    private function chooseChannel(): ?Channel
    {
        $channels = $this->channelsOverview();

        if ($channels->isEmpty()) {
            $this->components->error('There are no channels to remove.');

            return null;
        }

        $this->renderChannelsTable($channels);

        $channel = $this->selectChannel('Which channel do you want to remove?', $channels, '← Cancel');

        if (! $channel instanceof Channel) {
            $this->components->warn('Aborted, nothing was removed.');
        }

        return $channel;
    }

    /**
     * @return array{videos_total: int, videos_downloaded: int, thumbnails: Collection<int, string>, media_directory: string, media_available: bool, media_files: list<string>, media_bytes: int}
     */
    private function summarize(Channel $channel): array
    {
        return $this->cleaner->channelSummary($channel);
    }

    private function renderSummary(Channel $channel, array $summary): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Channel</>', "{$channel->name} (#{$channel->id})");
        $this->components->twoColumnDetail('Username', $this->formatUsername($channel));
        $this->components->twoColumnDetail('External id', $channel->external_id);
        $this->components->twoColumnDetail(
            'Videos',
            sprintf('%d (%d downloaded)', $summary['videos_total'], $summary['videos_downloaded']),
        );
        $this->components->twoColumnDetail('Thumbnails', (string) $summary['thumbnails']->count());
        $this->components->twoColumnDetail(
            'Downloaded files',
            $summary['media_available']
                ? sprintf('%d (%s) in %s', count($summary['media_files']), $this->formatBytes($summary['media_bytes']), $summary['media_directory'])
                : '<fg=red>media disk is not available, files will be kept</>',
        );
        $this->newLine();

        if (! $summary['media_available']) {
            $this->components->warn(sprintf(
                'The media disk (%s) is not available, so downloaded files will not be removed.',
                config('filesystems.disks.media.root') ?: 'MEDIA_ROOT is not set',
            ));
        }
    }

    private function confirmRemoval(Channel $channel, array $summary): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Pass --force to remove the channel non-interactively.');

            return false;
        }

        return confirm(
            label: sprintf(
                'Permanently remove "%s" with %d video(s) and %s of downloaded files?',
                $channel->name,
                $summary['videos_total'],
                $this->formatBytes($summary['media_bytes']),
            ),
            default: false,
        );
    }

    private function remove(Channel $channel, array $summary): int
    {
        $removal = $this->cleaner->removeChannel($channel, $summary);
        $keptMedia = $removal->keptMedia;
        $keptThumbnails = $removal->keptThumbnails;

        $this->components->info(sprintf(
            'Removed channel "%s" (#%d): %d video(s), %d thumbnail(s), %d file(s)%s.',
            $channel->name,
            $channel->id,
            $summary['videos_total'],
            $summary['thumbnails']->count() - $keptThumbnails->count(),
            $keptMedia ? 0 : count($summary['media_files']),
            $summary['media_available'] && ! $keptMedia ? ' ('.$this->formatBytes($summary['media_bytes']).' freed)' : '',
        ));

        if (! $summary['media_available']) {
            $this->components->warn(sprintf(
                'Downloaded files were kept, remove "%s" from the media storage manually.',
                $summary['media_directory'],
            ));

            return self::SUCCESS;
        }

        if ($keptMedia || $keptThumbnails->isNotEmpty()) {
            $this->components->error(sprintf(
                'The database records are gone, but some files could not be deleted (check the permissions of the current user "%s"): %s.',
                $this->currentUserName(),
                collect([$keptMedia ? Storage::disk('media')->path($summary['media_directory']) : null])
                    ->merge($keptThumbnails->map(fn (string $path) => Storage::disk('public')->path($path)))
                    ->filter()
                    ->take(5)
                    ->implode(', '),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
