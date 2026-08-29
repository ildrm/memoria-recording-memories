<?php

namespace App\Http\Requests;

use App\Enums\EntryStatus;
use App\Enums\Mood;
use App\Models\Entry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $entry instanceof Entry
            ? ($this->user()?->can('update', $entry) ?? false)
            : ($this->user()?->can('create', Entry::class) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'journal_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:'.(int) config('memoria.rich_text.maximum_characters', 125000)],
            'occurred_at' => ['nullable', 'date'],
            'timezone' => ['required', 'timezone:all'],
            'mood' => ['nullable', Rule::enum(Mood::class)],
            'custom_mood' => ['nullable', 'string', 'max:80'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'importance' => ['sometimes', 'integer', 'between:0,5'],
            'status' => ['sometimes', Rule::enum(EntryStatus::class)],
            'is_favorite' => ['sometimes', 'boolean'],
            'archived_at' => ['nullable', 'date'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
            'autosave' => ['sometimes', 'boolean'],
        ];
    }
}
