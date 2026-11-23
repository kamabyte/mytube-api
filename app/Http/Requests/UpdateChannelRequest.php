<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChannelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'download_on_demand' => ['required', 'boolean'],
            'download_new_only' => ['sometimes', 'boolean'],
            'sync_paused' => ['sometimes', 'boolean'],
        ];
    }

    public function syncPaused(): bool
    {
        return $this->boolean('sync_paused');
    }

    public function downloadOnDemand(): bool
    {
        return $this->boolean('download_on_demand');
    }

    public function downloadNewOnly(): bool
    {
        return $this->boolean('download_new_only');
    }
}
