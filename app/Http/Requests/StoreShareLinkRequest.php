<?php

namespace App\Http\Requests;

use App\Enums\SharePermission;
use App\Models\Entry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShareLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $entry instanceof Entry
            && ($this->user()?->can('share', $entry) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maximumExpiration = now()->addDays(
            (int) config('memoria.shares.maximum_expiration_days', 365),
        );

        return [
            'label' => ['nullable', 'string', 'max:255'],
            'permission' => ['sometimes', Rule::enum(SharePermission::class)],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before_or_equal:'.$maximumExpiration->toIso8601String(),
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:1024', 'confirmed'],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'track_views' => ['sometimes', 'boolean'],
            'include_attachments' => ['sometimes', 'boolean'],
        ];
    }
}
