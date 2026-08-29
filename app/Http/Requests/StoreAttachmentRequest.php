<?php

namespace App\Http\Requests;

use App\Models\Attachment;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');
        $user = $this->user();

        return $entry instanceof Entry
            && $user instanceof User
            && $user->can('update', $entry)
            && $user->can('create', Attachment::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $extensions = (array) config('memoria.attachments.extensions', []);

        return [
            'file' => [
                'required',
                File::types($extensions)->max(
                    (int) config('memoria.attachments.maximum_kilobytes', 20480),
                ),
                'extensions:'.implode(',', $extensions),
            ],
        ];
    }
}
