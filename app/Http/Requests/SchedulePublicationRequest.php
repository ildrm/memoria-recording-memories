<?php

namespace App\Http\Requests;

use App\Enums\SocialProvider;
use App\Models\Publication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SchedulePublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $publication = $this->route('publication');

        return $publication instanceof Publication
            && ($this->user()?->can('publish', $publication) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'privacy_review_confirmed' => ['required', 'accepted'],
            'preview_confirmed' => ['required', 'accepted'],
            'scheduled_at' => ['required', 'string', 'max:64'],
            'timezone' => ['required', 'timezone:all'],
            'publish_to_website' => ['sometimes', 'boolean'],
            'social_providers' => ['sometimes', 'array', 'max:4'],
            'social_providers.*' => ['string', Rule::enum(SocialProvider::class), 'distinct'],
            'social_account_ids' => ['sometimes', 'array', 'max:10'],
            'social_account_ids.*' => ['integer', 'min:1', 'distinct'],
            'provider_text' => ['sometimes', 'array'],
            'provider_text.*' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
