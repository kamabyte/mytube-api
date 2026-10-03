<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'name' => fake()->name(),
            'description' => fake()->text(),
            'thumbnail' => 'thumbnails/video.png',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ];
    }
}
