<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The resolve.business middleware already validated that the
        // authenticated user has access to the current business.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::in(['active', 'completed', 'dropped', 'pending'])],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
