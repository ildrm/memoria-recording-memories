<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'formats' => ['sometimes', 'array', 'min:1', 'max:2'],
            'formats.*' => ['string', Rule::in(['json', 'markdown']), 'distinct'],
            'include_attachments' => ['sometimes', 'boolean'],
        ];
    }
}
