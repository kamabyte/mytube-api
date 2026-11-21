<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChannelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'download_on_demand' => ['required', 'boolean'],
            'sync_paused' => ['sometimes', 'boolean'],
        ];
    }

    public function syncPaused(): bool
    {
        return $this->boolean('sync_paused');
    }

    public function downloadOnDemand(): bool
    {
        return $this->syncPaused() || $this->boolean('download_on_demand');
    }
}
