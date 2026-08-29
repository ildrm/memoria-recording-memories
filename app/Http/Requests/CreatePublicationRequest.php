<?php

namespace App\Http\Requests;

use App\Models\Entry;
use Illuminate\Foundation\Http\FormRequest;

class CreatePublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $entry instanceof Entry
            && ($this->user()?->can('publish', $entry) ?? false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
        ];
    }
}
