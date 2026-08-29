<?php

namespace App\Http\Requests;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreEntryShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');
        $user = $this->user();

        return $entry instanceof Entry
            && $user instanceof User
            && $user->can('share', $entry);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $maximumExpiration = now()->addDays(
            (int) config('memoria.shares.maximum_expiration_days', 365),
        );

        return [
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before_or_equal:'.$maximumExpiration->toIso8601String(),
            ],
            'include_attachments' => ['sometimes', 'boolean'],
        ];
    }
}
