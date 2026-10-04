<?php

namespace App\Http\Requests\Web;

use App\Support\Youtube\ChannelImporter;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Добавление канала или плейлиста — те же параметры, что у
 * youtube:add-channel и youtube:add-playlist. Пустые строки уже
 * превращены в null глобальными middleware.
 */
class StoreChannelRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:500'],
            'parse_latest' => ['boolean'],
            'parse_popular' => ['boolean'],
            'download_on_demand' => ['boolean'],
            // Только для канала.
            'sync_from' => ['nullable', 'date', 'before_or_equal:today'],
            'playlist_id' => ['nullable', 'string', 'max:100'],
            // Только для плейлиста.
            'name' => ['nullable', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'url', 'max:500'],
            'source_channel' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sync_from.date' => 'Укажите дату.',
            'sync_from.before_or_equal' => 'Дата не может быть в будущем.',
            'thumbnail.url' => 'Нужна ссылка на картинку (https://…).',
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->parseLatest() && ! $this->parsePopular()) {
                    $validator->errors()->add(
                        'parse_latest',
                        'Включите хотя бы одно: новые или популярные видео — иначе парсер канал не тронет.',
                    );
                }
            },
        ];
    }

    public function channelUrl(): string
    {
        return $this->string('url')->trim()->value();
    }

    public function isPlaylist(): bool
    {
        return ChannelImporter::looksLikePlaylist($this->channelUrl());
    }

    public function parseLatest(): bool
    {
        return $this->boolean('parse_latest', true);
    }

    public function parsePopular(): bool
    {
        return $this->boolean('parse_popular');
    }

    public function downloadOnDemand(): bool
    {
        return $this->boolean('download_on_demand');
    }

    public function syncFrom(): ?CarbonInterface
    {
        return $this->date('sync_from')?->startOfDay();
    }

    public function playlistId(): ?string
    {
        return $this->input('playlist_id');
    }

    public function playlistName(): ?string
    {
        return $this->input('name');
    }

    public function thumbnail(): ?string
    {
        return $this->input('thumbnail');
    }

    public function sourceChannel(): ?string
    {
        return $this->input('source_channel');
    }
}
