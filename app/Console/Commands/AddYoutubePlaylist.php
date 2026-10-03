<?php

namespace App\Console\Commands;

use App\Support\Youtube\ChannelImporter;
use App\Support\Youtube\ImportFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('youtube:add-playlist
    {playlistUrl : The playlist url or its id}
    {--name= : Override the title (default: the playlist title)}
    {--thumbnail= : Override the artwork url (default: the source channel avatar)}
    {--source-channel= : The channel the videos belong to (default: the owner of the first video)}
    {--parse-popular : Toggle popular videos parser}
    {--parse-latest=1 : Toggle latest videos parser (default: 1, pass 0 to disable)}'
)]
#[Description('Add a youtube playlist as a channel')]
class AddYoutubePlaylist extends Command
{
    public function handle(ChannelImporter $importer): int
    {
        try {
            $importer->importPlaylist(
                playlistUrl: $this->argument('playlistUrl'),
                name: $this->option('name'),
                thumbnail: $this->option('thumbnail'),
                sourceChannel: $this->option('source-channel'),
                parseLatest: (bool) $this->option('parse-latest'),
                parsePopular: (bool) $this->option('parse-popular'),
                warn: fn (string $message) => $this->warn($message),
            );
        } catch (ImportFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
