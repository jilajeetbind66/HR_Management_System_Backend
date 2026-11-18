<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\TrainingNotification;
use App\Support\TrainingReport;

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
        $enrollments->each(function ($enrollment): void {
            $enrollment->is_overdue = $enrollment->status !== 'completed'
                && $enrollment->due_date !== null
                && $enrollment->due_date < Carbon::today()->toDateString();
        });
        $courseQuery = DB::table('training_courses')
            ->leftJoin('hrms_uploads as materials', 'materials.id', '=', 'training_courses.material_upload_id')
            ->select('training_courses.*', 'materials.original_filename as material_filename')
            ->orderByDesc('training_courses.id');

        if (!in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive'], true)) {
            $courseQuery->whereIn('training_courses.id', $enrollments->pluck('course_id'));
        }

        $courses = $courseQuery->get()->map(function ($course) use ($enrollments) {
            $courseEnrollments = $enrollments->where('course_id', $course->id);
            $course->assigned_count = $courseEnrollments->count();
            $course->completed_count = $courseEnrollments->where('status', 'completed')->count();
            $course->overdue_count = $courseEnrollments->where('is_overdue', true)->count();

            return $course;
        });

        $teamEmployeeIds = $user->role === 'manager'
            ? RoleAccess::visibleEmployeeIds($user, false)
            : null;
        $teamEnrollments = $user->role === 'manager'
            ? DB::table('training_enrollments as enrollment')
                ->join('training_courses as course', 'course.id', '=', 'enrollment.course_id')
                ->whereIn('enrollment.employee_id', $teamEmployeeIds)
                ->where('course.status', 'active')
                ->where('course.mandatory', true)
                ->get(['enrollment.status', 'course.due_date'])
            : collect();

        return response()->json([
            'data' => [
                'courses' => $courses->values(),
                'enrollments' => $enrollments,
            ],
            'meta' => $user->role === 'manager' ? [
                'team_training' => [
                    'pending_count' => $teamEnrollments->whereNotIn('status', ['completed'])->count(),
                    'overdue_count' => $teamEnrollments->filter(fn ($item) => $item->status !== 'completed'
                        && $item->due_date !== null
                        && $item->due_date < Carbon::today()->toDateString())->count(),
                ],
            ] : (RoleAccess::isHr($user->role) || $user->role === 'super_admin' ? [
                'branch_compliance' => TrainingReport::branchCompliance(),
            ] : []),
        ]);
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|in:Compliance,Security,Role-based,Professional Development,Wellness,Other',
            'description' => 'nullable|string|max:5000',
            'mandatory' => 'required|boolean',
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'material_url' => 'nullable|url:http,https|max:2000',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'required|integer|distinct|exists:employees,id',
        ]);
        if ($request->boolean('mandatory') && empty($validated['due_date'])) {
            throw ValidationException::withMessages([
                'due_date' => 'A due date is required for mandatory training.',
            ]);
        }

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
                'material_url' => $validated['material_url'] ?? null,
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
                $employeeUserId = DB::table('users')->where('employee_id', $employeeId)->value('id');
                if ($employeeUserId) {
                    TrainingNotification::sendOnce(
                        (int) DB::table('training_enrollments')
                            ->where('course_id', $id)
                            ->where('employee_id', $employeeId)
                            ->value('id'),
                        (int) $employeeUserId,
                        'assigned:v1',
                        'training.assigned',
                        'New training assigned',
                        "You have been assigned {$validated['title']}."
                    );
                }
            }

            AuditLog::record(
                $request,
                'TRAINING_COURSE_CREATED',
                'Training',
                "Created course {$validated['title']} and assigned it to " . count($employeeIds) . ' employees'
            );
            AuditLog::record(
                $request,
                'TRAINING_COURSE_ASSIGNED',
                'Training',
                "Assigned course #{$id} to " . count($employeeIds) . ' employees'
            );

            return $id;
        });

        return response()->json(['data' => $this->loadCourse($courseId)], 201);
    }

    public function updateCourse(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:Compliance,Security,Role-based,Professional Development,Wellness,Other',
            'description' => 'sometimes|nullable|string|max:5000',
            'mandatory' => 'sometimes|required|boolean',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'material_url' => 'sometimes|nullable|url:http,https|max:2000',
            'status' => 'sometimes|required|in:active,archived',
        ]);
        DB::transaction(function () use ($validated, $request, $id): void {
            $course = DB::table('training_courses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($course, 404, 'Training course not found.');

            $mandatory = (bool) ($validated['mandatory'] ?? $course->mandatory);
            $dueDate = array_key_exists('due_date', $validated) ? $validated['due_date'] : $course->due_date;
            if (
                array_key_exists('due_date', $validated)
                && $validated['due_date'] !== null
                && $validated['due_date'] !== $course->due_date
                && $validated['due_date'] < Carbon::today()->toDateString()
            ) {
                throw ValidationException::withMessages([
                    'due_date' => 'A newly selected due date must be today or later.',
                ]);
            }
            if ($mandatory && !$dueDate) {
                throw ValidationException::withMessages([
                    'due_date' => 'A due date is required for mandatory training.',
                ]);
            }

            DB::table('training_courses')->where('id', $id)->update([
                ...$validated,
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                ($validated['status'] ?? null) === 'archived' ? 'TRAINING_COURSE_ARCHIVED' : 'TRAINING_COURSE_UPDATED',
                'Training',
                "Updated course #{$id}"
            );
        });

        return response()->json(['data' => $this->loadCourse($id)]);
    }

    public function deleteCourse(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request): void {
            $course = DB::table('training_courses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($course, 404, 'Training course not found.');
            abort_if(
                DB::table('training_enrollments')->where('course_id', $id)->exists(),
                409,
                'Courses with assignments cannot be deleted. Archive the course instead.'
            );

            DB::table('training_courses')->where('id', $id)->delete();
            AuditLog::record($request, 'TRAINING_COURSE_DELETED', 'Training', "Deleted course #{$id}");
        });

        return response()->json(['message' => 'Training course deleted.']);
    }

    public function assignEmployees(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'required|integer|distinct|exists:employees,id',
        ]);

        DB::transaction(function () use ($validated, $request, $id): void {
            $course = DB::table('training_courses')->where('id', $id)->lockForUpdate()->first();
            abort_unless($course, 404, 'Training course not found.');
            abort_if($course->status !== 'active', 409, 'Archived courses cannot receive new assignments.');

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

            if (DB::table('training_enrollments')
                ->where('course_id', $id)
                ->whereIn('employee_id', $employeeIds)
                ->exists()) {
                throw ValidationException::withMessages([
                    'employee_ids' => 'One or more selected employees are already assigned this course.',
                ]);
            }

            foreach ($employeeIds as $employeeId) {
                $enrollmentId = DB::table('training_enrollments')->insertGetId([
                    'course_id' => $id,
                    'employee_id' => $employeeId,
                    'assigned_by_user_id' => $request->user()->id,
                    'status' => 'assigned',
                    'progress_percent' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $employeeUserId = DB::table('users')->where('employee_id', $employeeId)->value('id');
                if ($employeeUserId) {
                    TrainingNotification::sendOnce(
                        $enrollmentId,
                        (int) $employeeUserId,
                        'assigned:v1',
                        'training.assigned',
                        'New training assigned',
                        "You have been assigned {$course->title}."
                    );
                }
            }

            AuditLog::record(
                $request,
                'TRAINING_COURSE_ASSIGNED',
                'Training',
                "Assigned course #{$id} to " . count($employeeIds) . ' additional employees'
            );
        });

        return response()->json(['data' => $this->loadCourse($id)]);
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
            abort_if(
                DB::table('training_courses')->where('id', $enrollment->course_id)->value('status') !== 'active',
                409,
                'Archived training cannot be updated.'
            );
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
                $progress === 100 ? 'TRAINING_COMPLETION_SUBMITTED' : 'TRAINING_PROGRESS_UPDATED',
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
            AuditLog::record(
                $request,
                $approved ? 'TRAINING_COMPLETION_VERIFIED' : 'TRAINING_COMPLETION_RETURNED',
                'Training',
                "Training enrollment #{$id} was {$validated['decision']}"
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
                'training_courses.status as course_status',
                'training_courses.due_date',
                'training_courses.material_url',
                'training_courses.material_upload_id',
                'materials.original_filename as material_filename',
                'training_enrollments.certificate_upload_id',
                'certificates.original_filename as certificate_filename',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'assignees.name as assigned_by_name',
                'reviewers.name as reviewed_by_name'
            )
            ->leftJoin('hrms_uploads as materials', 'materials.id', '=', 'training_courses.material_upload_id')
            ->leftJoin('hrms_uploads as certificates', 'certificates.id', '=', 'training_enrollments.certificate_upload_id');
    }

    private function loadEnrollment(int $id): object
    {
        $enrollment = $this->enrollmentQuery()
            ->where('training_enrollments.id', $id)
            ->first();
        abort_if(!$enrollment, 404);
        $enrollment->is_overdue = $enrollment->status !== 'completed'
            && $enrollment->due_date !== null
            && $enrollment->due_date < Carbon::today()->toDateString();

        return $enrollment;
    }

    private function loadCourse(int $id): array
    {
        $course = DB::table('training_courses')
            ->leftJoin('hrms_uploads as materials', 'materials.id', '=', 'training_courses.material_upload_id')
            ->where('training_courses.id', $id)
            ->select('training_courses.*', 'materials.original_filename as material_filename')
            ->first();
        abort_if(!$course, 404);

        $enrollments = $this->enrollmentQuery()
            ->where('training_enrollments.course_id', $id)
            ->orderBy('employees.first_name')
            ->get();
        $enrollments->each(function ($enrollment): void {
            $enrollment->is_overdue = $enrollment->status !== 'completed'
                && $enrollment->due_date !== null
                && $enrollment->due_date < Carbon::today()->toDateString();
        });
        $course->assigned_count = $enrollments->count();
        $course->completed_count = $enrollments->where('status', 'completed')->count();
        $course->overdue_count = $enrollments->where('is_overdue', true)->count();

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
