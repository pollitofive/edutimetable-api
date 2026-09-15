<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Requests\UpdateEnrollmentRequest;
use App\Models\StudentEnrollment;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $service) {}

    public function schedules(Request $request): JsonResponse
    {
        $schedules = $this->service->getSchedules(
            $request->only(['course_ids', 'teacher_ids', 'days_of_week', 'track_ids'])
        );

        return response()->json($schedules);
    }

    public function eligibleStudents(Request $request, int $scheduleId): JsonResponse
    {
        $students = $this->service->getEligibleStudents(
            $scheduleId,
            $request->string('search', '')->toString()
        );

        return response()->json($students);
    }

    public function compatibleSchedules(int $studentId): JsonResponse
    {
        $data = $this->service->getCompatibleSchedules($studentId);

        return response()->json($data);
    }

    public function students(Request $request): JsonResponse
    {
        $students = $this->service->searchStudents(
            $request->string('search', '')->toString()
        );

        return response()->json($students);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'schedule_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'completed', 'dropped', 'pending'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $enrollments = $this->service->getEnrollments($validated, (int) ($validated['per_page'] ?? 10));

        return response()->json($enrollments);
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        try {
            $enrollments = $this->service->createEnrollments(
                $request->integer('student_id'),
                $request->input('schedule_ids')
            );
        } catch (ValidationException $e) {
            return response()->json(
                ['message' => collect($e->errors())->flatten()->first()],
                422
            );
        }

        return response()->json($enrollments, 201);
    }

    public function update(UpdateEnrollmentRequest $request, int $id): JsonResponse
    {
        $enrollment = $this->service->updateEnrollment($id, $request->validated());

        return response()->json($enrollment);
    }

    public function destroy(int $id): JsonResponse
    {
        // BelongsToBusiness global scope ensures only enrollments from the
        // current business are found; returns 404 automatically otherwise.
        $enrollment = StudentEnrollment::findOrFail($id);
        $enrollment->delete();

        return response()->json(null, 204);
    }
}
