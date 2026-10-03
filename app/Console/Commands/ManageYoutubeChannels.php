<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksWritability;
use App\Console\Commands\Concerns\InteractsWithChannels;
use App\Models\Channel;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\form;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('youtube:channels')]
#[Description('Interactive youtube channel management: list, add, edit and remove channels')]
class ManageYoutubeChannels extends Command
{
    use ChecksWritability;
    use InteractsWithChannels;

    /** Laravel Prompts walks a form back one step on Ctrl+U. */
    private const string REVERT_HINT = 'Ctrl+U goes back to the previous question';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('The command is interactive, run it from a terminal. Use youtube:add-channel and youtube:remove-channel in scripts.');

            return self::FAILURE;
        }

        $writabilityProblem = $this->findWritabilityProblem();

        if ($writabilityProblem !== null) {
            $this->components->error($writabilityProblem);

            return self::FAILURE;
        }

        while (true) {
            $action = select(
                label: 'MyTube channels',
                options: [
                    'list' => 'List channels',
                    'add' => 'Add a channel',
                    'add-playlist' => 'Add a playlist',
                    'edit' => 'Edit a channel',
                    'remove' => 'Remove a channel',
                    'exit' => 'Exit',
                ],
                hint: '↑ ↓ to move, Enter to choose, Ctrl+C to quit',
            );

            if ($action === 'exit') {
                return self::SUCCESS;
            }

            match ($action) {
                'list' => $this->listChannels(),
                'add' => $this->addChannel(),
                'add-playlist' => $this->addPlaylist(),
                'edit' => $this->editChannel(),
                'remove' => $this->removeChannel(),
                default => null,
            };
        }
    }

    private function listChannels(): void
    {
        $channels = $this->channelsOverview();

        if ($channels->isEmpty()) {
            $this->components->warn('There are no channels yet.');

            return;
        }

        $this->renderChannelsTable($channels);
    }

    /**
     * Adding goes through youtube:add-channel: it is the only place that talks to the
     * YouTube api and resolves the uploads playlist, the title and the avatar.
     */
    private function addChannel(): void
    {
        // Каждый шаг после первого — addIf: пустой адрес канала означает «назад
        // в меню», и дальше спрашивать уже нечего.
        $asked = fn (array $responses) => filled($responses['channelUrl']);

        $answers = form()
            ->text(
                label: 'Channel url, @handle or channel id',
                placeholder: 'https://www.youtube.com/@handle',
                hint: 'Leave empty to go back to the menu',
                name: 'channelUrl',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => confirm(
                    label: 'Parse latest uploads?',
                    default: $previous ?? true,
                    hint: self::REVERT_HINT,
                ),
                'parseLatest',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => confirm(
                    label: 'Parse popular videos?',
                    default: $previous ?? false,
                    hint: self::REVERT_HINT,
                ),
                'parsePopular',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => text(
                    label: 'Uploads playlist id',
                    default: (string) ($previous ?? ''),
                    hint: 'Leave empty to use the playlist the channel reports. '.self::REVERT_HINT,
                ),
                'playlistId',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => text(
                    label: 'Fetch videos published after',
                    placeholder: '2026-01-31',
                    default: (string) ($previous ?? ''),
                    validate: fn (string $value) => $this->parseDate($value) === false
                        ? 'Enter a date like 2026-01-31.'
                        : null,
                    hint: 'Leave empty to start from the oldest uploads. '.self::REVERT_HINT,
                ),
                'syncFrom',
            )
            ->submit();

        $channelUrl = trim((string) $answers['channelUrl']);

        if ($channelUrl === '') {
            return;
        }

        $arguments = [
            'channelUrl' => $channelUrl,
            '--parse-latest' => ($answers['parseLatest'] ?? true) ? '1' : '0',
        ];

        if ($answers['parsePopular']) {
            $arguments['--parse-popular'] = true;
        }

        $playlistId = trim((string) ($answers['playlistId'] ?? ''));

        if ($playlistId !== '') {
            $arguments['--playlist-id'] = $playlistId;
        }

        $syncFrom = $this->parseDate((string) ($answers['syncFrom'] ?? ''));

        if ($syncFrom instanceof Carbon) {
            $arguments['--sync-from'] = $syncFrom->toDateString();
        }

        if ($this->call('youtube:add-channel', $arguments) !== self::SUCCESS) {
            $this->components->error('The channel was not added.');

            return;
        }

        $this->components->info('The channel was saved.');
    }

    /**
     * A playlist lands in the same table as a channel: youtube:add-playlist resolves the
     * title, the owner of the videos and the artwork, everything here is just an override.
     */
    private function addPlaylist(): void
    {
        $asked = fn (array $responses) => filled($responses['playlistUrl']);

        $answers = form()
            ->text(
                label: 'Playlist url or id',
                placeholder: 'https://www.youtube.com/playlist?list=PL...',
                hint: 'Leave empty to go back to the menu',
                name: 'playlistUrl',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => text(
                    label: 'Name',
                    default: (string) ($previous ?? ''),
                    hint: 'Leave empty to use the playlist title. '.self::REVERT_HINT,
                ),
                'name',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => text(
                    label: 'Source channel url, @handle or channel id',
                    default: (string) ($previous ?? ''),
                    hint: 'Leave empty to take the owner of the first video. '.self::REVERT_HINT,
                ),
                'sourceChannel',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => text(
                    label: 'Artwork url',
                    default: (string) ($previous ?? ''),
                    hint: 'Leave empty to use the source channel avatar. '.self::REVERT_HINT,
                ),
                'thumbnail',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => confirm(
                    label: 'Parse latest uploads?',
                    default: $previous ?? true,
                    hint: self::REVERT_HINT,
                ),
                'parseLatest',
            )
            ->addIf(
                $asked,
                fn (array $responses, mixed $previous) => confirm(
                    label: 'Parse popular videos?',
                    default: $previous ?? false,
                    hint: self::REVERT_HINT,
                ),
                'parsePopular',
            )
            ->submit();

        $playlistUrl = trim((string) $answers['playlistUrl']);

        if ($playlistUrl === '') {
            return;
        }

        $arguments = [
            'playlistUrl' => $playlistUrl,
            '--parse-latest' => ($answers['parseLatest'] ?? true) ? '1' : '0',
        ];

        if ($answers['parsePopular']) {
            $arguments['--parse-popular'] = true;
        }

        foreach (['name' => '--name', 'sourceChannel' => '--source-channel', 'thumbnail' => '--thumbnail'] as $answer => $option) {
            $value = trim((string) ($answers[$answer] ?? ''));

            if ($value !== '') {
                $arguments[$option] = $value;
            }
        }

        if ($this->call('youtube:add-playlist', $arguments) !== self::SUCCESS) {
            $this->components->error('The playlist was not added.');

            return;
        }

        $this->components->info('The playlist was saved.');
    }

    private function editChannel(): void
    {
        $channel = $this->selectChannel('Which channel do you want to edit?', $this->channelsOverview());

        if (! $channel instanceof Channel) {
            return;
        }

        while (true) {
            $this->renderChannelDetails($channel);

            $field = select(
                label: 'What do you want to change?',
                options: [
                    'name' => 'Name',
                    'username' => 'Username',
                    'parse_latest' => 'Parse latest uploads',
                    'parse_popular' => 'Parse popular videos',
                    'uploads_playlist_id' => 'Uploads playlist id',
                    'last_synced_at' => 'Last synced at',
                    'back' => '← Back to the menu',
                ],
            );

            if ($field === 'back') {
                return;
            }

            $channel->forceFill([$field => $this->askForValue($channel, $field)])->save();

            $this->components->info('Saved.');
        }
    }

    private function askForValue(Channel $channel, string $field): string|bool|Carbon|null
    {
        return match ($field) {
            'name' => trim(text(
                label: 'Name',
                default: (string) $channel->name,
                required: true,
            )),
            'username' => $this->askForUsername($channel),
            'parse_latest' => confirm('Parse latest uploads?', default: (bool) $channel->parse_latest),
            'parse_popular' => confirm('Parse popular videos?', default: (bool) $channel->parse_popular),
            'uploads_playlist_id' => $this->emptyToNull(text(
                label: 'Uploads playlist id',
                default: (string) $channel->uploads_playlist_id,
                hint: $channel->is_playlist
                    ? 'This row is a playlist, the parser has nothing to fall back to'
                    : 'Leave empty to let the parser resolve it again',
            )),
            'last_synced_at' => $this->askForDate(
                'Last synced at',
                default: $channel->last_synced_at?->toDateTimeString() ?? '',
                hint: 'Videos published after this date are fetched on the next run. Leave empty to start over',
            ),
            default => null,
        };
    }

    private function askForUsername(Channel $channel): ?string
    {
        $username = ltrim(trim(text(
            label: 'Username',
            default: (string) $channel->username,
            hint: 'The @handle without the "at" sign. Leave empty to drop it',
            validate: fn (string $value) => Channel::query()
                ->where('username', ltrim(trim($value), '@'))
                ->whereKeyNot($channel->getKey())
                ->exists()
                    ? 'Another channel already uses this username.'
                    : null,
        )), '@');

        return $this->emptyToNull($username);
    }

    private function askForDate(string $label, string $default = '', string $hint = ''): ?Carbon
    {
        $answer = trim(text(
            label: $label,
            placeholder: '2026-01-31',
            default: $default,
            hint: $hint,
            validate: fn (string $value) => $this->parseDate($value) === false
                ? 'Enter a date like 2026-01-31.'
                : null,
        ));

        $date = $this->parseDate($answer);

        return $date === false ? null : $date;
    }

    private function parseDate(string $value): Carbon|null|false
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return false;
        }
    }

    private function removeChannel(): void
    {
        $channel = $this->selectChannel('Which channel do you want to remove?', $this->channelsOverview());

        if (! $channel instanceof Channel) {
            return;
        }

        $this->call('youtube:remove-channel', ['channel' => $channel->id]);
    }

    private function renderChannelDetails(Channel $channel): void
    {
        $channel->refresh()->loadCount([
            'videos as videos_total' => fn ($query) => $query->withoutGlobalScopes(),
            'videos as videos_downloaded' => fn ($query) => $query->withoutGlobalScopes()->where('is_downloaded', true),
        ]);

        $this->newLine();
        $this->components->twoColumnDetail(
            $channel->is_playlist ? '<fg=yellow>Playlist</>' : '<fg=yellow>Channel</>',
            "{$channel->name} (#{$channel->id})",
        );
        $this->components->twoColumnDetail('Username', $this->formatUsername($channel));
        $this->components->twoColumnDetail('External id', $channel->external_id);
        $this->components->twoColumnDetail('Uploads playlist id', $channel->uploads_playlist_id ?: '—');

        $this->components->twoColumnDetail('Parse latest uploads', $this->formatFlag((bool) $channel->parse_latest));
        $this->components->twoColumnDetail('Parse popular videos', $this->formatFlag((bool) $channel->parse_popular));
        $this->components->twoColumnDetail('Last synced at', $channel->last_synced_at?->toDateTimeString() ?: '—');
        $this->components->twoColumnDetail(
            'Videos',
            sprintf('%d (%d downloaded)', $channel->videos_total, $channel->videos_downloaded),
        );
        $this->newLine();
    }

    private function emptyToNull(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
}
