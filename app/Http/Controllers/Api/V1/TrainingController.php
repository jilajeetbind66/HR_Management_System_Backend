<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrainingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollmentQuery = $this->enrollmentQuery();

        if ($user->role === 'manager') {
            $enrollmentQuery->whereIn('training_enrollments.employee_id', RoleAccess::visibleEmployeeIds($user));
        } elseif (!in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive'], true)) {
            $enrollmentQuery->where('training_enrollments.employee_id', $user->employee_id ?? 0);
        }

        $enrollments = $enrollmentQuery->orderByDesc('training_enrollments.id')->get();
        $courseQuery = DB::table('training_courses')->orderByDesc('id');

        if (!in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive'], true)) {
            $courseQuery->whereIn('id', $enrollments->pluck('course_id'));
        }

        $courses = $courseQuery->get()->map(function ($course) use ($enrollments) {
            $courseEnrollments = $enrollments->where('course_id', $course->id);
            $course->assigned_count = $courseEnrollments->count();
            $course->completed_count = $courseEnrollments->where('status', 'completed')->count();

            return $course;
        });

        return response()->json([
            'data' => [
                'courses' => $courses->values(),
                'enrollments' => $enrollments,
            ],
        ]);
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|in:Compliance,Security,Role-based,Professional Development,Wellness,Other',
            'description' => 'nullable|string|max:5000',
            'mandatory' => 'required|boolean',
            'due_date' => 'nullable|date_format:Y-m-d',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'required|integer|distinct|exists:employees,id',
        ]);

        $courseId = DB::transaction(function () use ($validated, $request) {
            $employeeIds = array_map('intval', $validated['employee_ids']);
            $activeCount = DB::table('employees')
                ->whereIn('id', $employeeIds)
                ->where('status', 'active')
                ->count();

            if ($activeCount !== count($employeeIds)) {
                throw ValidationException::withMessages([
                    'employee_ids' => 'Training can only be assigned to active employees.',
                ]);
            }

            $now = now();
            $id = DB::table('training_courses')->insertGetId([
                'title' => $validated['title'],
                'category' => $validated['category'],
                'description' => $validated['description'] ?? null,
                'mandatory' => $validated['mandatory'],
                'due_date' => $validated['due_date'] ?? null,
                'status' => 'active',
                'created_by_user_id' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($employeeIds as $employeeId) {
                DB::table('training_enrollments')->insert([
                    'course_id' => $id,
                    'employee_id' => $employeeId,
                    'assigned_by_user_id' => $request->user()->id,
                    'status' => 'assigned',
                    'progress_percent' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            AuditLog::record(
                $request,
                'TRAINING_COURSE_CREATED',
                'Training',
                "Created course {$validated['title']} and assigned it to " . count($employeeIds) . ' employees'
            );

            return $id;
        });

        return response()->json(['data' => $this->loadCourse($courseId)], 201);
    }

    public function updateProgress(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'progress_percent' => 'required|integer|min:0|max:100',
        ]);

        DB::transaction(function () use ($validated, $request, $id) {
            $enrollment = DB::table('training_enrollments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_if(!$enrollment, 404);
            abort_unless((int) $enrollment->employee_id === (int) $request->user()->employee_id, 403);
            abort_if($enrollment->status === 'completed', 409, 'Completed training cannot be changed.');
            abort_if($enrollment->status === 'pending_review', 409, 'This completion is waiting for review.');

            $progress = (int) $validated['progress_percent'];
            $status = $progress === 100
                ? 'pending_review'
                : ($progress > 0 ? 'in_progress' : 'assigned');

            $updates = [
                'progress_percent' => $progress,
                'status' => $status,
                'submitted_at' => $progress === 100 ? now() : null,
                'updated_at' => now(),
            ];
            if ($progress === 100) {
                $updates['review_note'] = null;
            }

            DB::table('training_enrollments')->where('id', $id)->update($updates);

            AuditLog::record(
                $request,
                'TRAINING_PROGRESS_UPDATED',
                'Training',
                "Updated training enrollment #{$id} to {$progress}%"
            );
        });

        return response()->json(['data' => $this->loadEnrollment($id)]);
    }

    public function reviewCompletion(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approved,returned',
            'review_note' => 'nullable|string|max:2000',
        ]);

        if ($validated['decision'] === 'returned' && blank($validated['review_note'] ?? null)) {
            throw ValidationException::withMessages([
                'review_note' => 'A note is required when returning a completion for changes.',
            ]);
        }

        DB::transaction(function () use ($validated, $request, $id) {
            $enrollment = DB::table('training_enrollments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_if(!$enrollment, 404);
            abort_if(
                $request->user()->employee_id
                    && (int) $request->user()->employee_id === (int) $enrollment->employee_id,
                403,
                'Employees cannot review their own training completion.'
            );

            if (
                $request->user()->role === 'manager'
                && !RoleAccess::managesEmployee($request->user(), (int) $enrollment->employee_id)
            ) {
                abort(403);
            }

            abort_if($enrollment->status !== 'pending_review', 409, 'Only submitted completions can be reviewed.');

            $approved = $validated['decision'] === 'approved';
            $certificateCode = $approved ? $this->newCertificateCode() : null;
            DB::table('training_enrollments')
                ->where('id', $id)
                ->update([
                    'status' => $approved ? 'completed' : 'in_progress',
                    'reviewed_by_user_id' => $request->user()->id,
                    'reviewed_at' => now(),
                    'review_note' => $validated['review_note'] ?? null,
                    'certificate_code' => $certificateCode,
                    'completed_at' => $approved ? now() : null,
                    'updated_at' => now(),
                ]);

            AuditLog::record(
                $request,
                'TRAINING_COMPLETION_REVIEWED',
                'Training',
                "Reviewed training enrollment #{$id}: {$validated['decision']}"
            );
        });

        return response()->json(['data' => $this->loadEnrollment($id)]);
    }

    private function enrollmentQuery()
    {
        return DB::table('training_enrollments')
            ->join('training_courses', 'training_enrollments.course_id', '=', 'training_courses.id')
            ->join('employees', 'training_enrollments.employee_id', '=', 'employees.id')
            ->leftJoin('users as assignees', 'training_enrollments.assigned_by_user_id', '=', 'assignees.id')
            ->leftJoin('users as reviewers', 'training_enrollments.reviewed_by_user_id', '=', 'reviewers.id')
            ->select(
                'training_enrollments.*',
                'training_courses.title as course_title',
                'training_courses.category as course_category',
                'training_courses.description as course_description',
                'training_courses.mandatory',
                'training_courses.due_date',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'assignees.name as assigned_by_name',
                'reviewers.name as reviewed_by_name'
            );
    }

    private function loadEnrollment(int $id): object
    {
        $enrollment = $this->enrollmentQuery()
            ->where('training_enrollments.id', $id)
            ->first();
        abort_if(!$enrollment, 404);

        return $enrollment;
    }

    private function loadCourse(int $id): array
    {
        $course = DB::table('training_courses')->where('id', $id)->first();
        abort_if(!$course, 404);

        $enrollments = $this->enrollmentQuery()
            ->where('training_enrollments.course_id', $id)
            ->orderBy('employees.first_name')
            ->get();
        $course->assigned_count = $enrollments->count();
        $course->completed_count = $enrollments->where('status', 'completed')->count();

        return ['course' => $course, 'enrollments' => $enrollments];
    }

    private function newCertificateCode(): string
    {
        do {
            $code = 'TRN-' . Str::upper(Str::random(12));
        } while (DB::table('training_enrollments')->where('certificate_code', $code)->exists());

        return $code;
    }
}
