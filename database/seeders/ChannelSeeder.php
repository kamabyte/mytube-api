<?php

namespace Database\Seeders;

use App\Models\Channel;
use Illuminate\Database\Seeder;

class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            [
                'external_id' => 'UCs_uv3QyUIQjBoL1Ij5BdlQ',
                'name' => 'Фиксики',
                'thumbnail' => 'https://yt3.ggpht.com/F7k_H3w2JIc1T0lyL1LnvN7ZXchiksOCPMbfTana7OESdQOTSbKKIOoT-BMVB0NpzCKRZ_J6oQ=s800-c-k-c0x00ffffff-no-rj'
            ],
            [
                'external_id' => 'UCHS2LM1n3f5cyL-ebgkqyLw',
                'name' => 'Союзмультфильм',
                'thumbnail' => 'https://yt3.ggpht.com/4k1NkDk3omeEgQGuhxRjRB0k9PYTlW5nj_jqBH0ApPNJwY-Qh4BzVDzmXH0AYzYdH80rM8n8=s800-c-k-c0x00ffffff-no-rj'
            ],
            [
                'external_id' => 'UCAOtE1V7Ots4DjM8JLlrYgg',
                'name' => 'Peppa Pig - Official Channel',
                'thumbnail' => 'https://yt3.ggpht.com/yOGkYbrYZWokyxAwY8vIqVNaPEDYHSUSovT9xXffr-zpPGfVM198_KZI3E1IzHxHMYcGb9Uiyw=s800-c-k-c0x00ffffff-no-rj'
            ]
        ];

        Channel::insert($channels);
    }
}
