<?php

namespace App\Http\Requests;

use App\Actions\CreatePublicReport;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(CreatePublicReport::REASONS)],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
