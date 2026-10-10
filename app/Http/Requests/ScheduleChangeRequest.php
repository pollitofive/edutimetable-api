<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleChangeRequest extends FormRequest
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
            'slots' => ['required', 'array', 'min:1'],
            'slots.*.schedule_id' => [
                'required',
                'integer',
                Rule::exists('schedules', 'id')->where('business_id', $businessId),
            ],
            'slots.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'slots.*.starts_at' => ['required', 'date_format:H:i'],
            'slots.*.ends_at' => ['required', 'date_format:H:i'],
            'drop_student_ids' => ['sometimes', 'array'],
            'drop_student_ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'slots.*.schedule_id.exists' => __('schedule.change.slots_mismatch'),
        ];
    }
}
