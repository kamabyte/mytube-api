<?php

namespace App\Console\Commands;

use App\Support\Youtube\ChannelImporter;
use App\Support\Youtube\ImportFailed;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('youtube:add-channel
    {channelUrl : The channel url}
    {--parse-popular : Toggle popular videos parser}
    {--parse-latest=1 : Toggle latest videos parser (default: 1, pass 0 to disable)}
    {--sync-from= : Set last_synced_at so the next parser run fetches videos newer than this date}
    {--playlist-id= : Instead of using main channel playlist id set your own}'
)]
#[Description('Add youtube channel')]
class AddYoutubeChannel extends Command
{
    public function handle(ChannelImporter $importer): int
    {
        $syncFromOption = $this->option('sync-from');
        $syncFrom = null;

        if ($syncFromOption !== null) {
            try {
                $syncFrom = Carbon::parse($syncFromOption);
            } catch (\Exception $e) {
                $this->error("Invalid --sync-from date: {$syncFromOption}");

                return self::FAILURE;
            }
        }

        try {
            $importer->importChannel(
                channelUrl: $this->argument('channelUrl'),
                parseLatest: (bool) $this->option('parse-latest'),
                parsePopular: (bool) $this->option('parse-popular'),
                syncFrom: $syncFrom,
                playlistId: $this->option('playlist-id'),
            );
        } catch (ImportFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
