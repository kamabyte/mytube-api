<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVideoDownloadPolicyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'download_on_demand' => ['present', 'nullable', 'boolean'],
        ];
    }

    public function downloadOnDemand(): ?bool
    {
        $value = $this->validated('download_on_demand');

        return $value === null ? null : (bool) $value;
    }
}
