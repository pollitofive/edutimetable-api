<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The resolve.business middleware already validated that the
        // authenticated user has access to the current business.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $businessId = $this->user()->default_business_id;

        return [
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where('business_id', $businessId),
            ],
            'schedule_ids' => ['required', 'array', 'min:1'],
            'schedule_ids.*' => [
                'integer',
                Rule::exists('schedules', 'id')->where('business_id', $businessId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required' => __('enrollment.student_not_found'),
            'student_id.exists'   => __('enrollment.student_not_found'),
            'schedule_ids.required' => __('enrollment.schedule_not_found'),
            'schedule_ids.*.exists' => __('enrollment.schedule_not_found'),
        ];
    }
}
