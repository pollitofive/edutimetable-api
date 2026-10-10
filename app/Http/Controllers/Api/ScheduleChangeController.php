<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleChangeRequest;
use App\Services\ScheduleChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ScheduleChangeController extends Controller
{
    public function __construct(private readonly ScheduleChangeService $service) {}

    public function preview(ScheduleChangeRequest $request): JsonResponse
    {
        try {
            $result = $this->service->preview($request->input('slots'));
        } catch (ValidationException $e) {
            return $this->validationError($e);
        }

        return response()->json($result);
    }

    public function apply(ScheduleChangeRequest $request): JsonResponse
    {
        try {
            $result = $this->service->apply(
                $request->input('slots'),
                $request->input('drop_student_ids', [])
            );
        } catch (ValidationException $e) {
            return $this->validationError($e);
        }

        return response()->json($result);
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json(
            ['message' => collect($e->errors())->flatten()->first()],
            422
        );
    }
}
