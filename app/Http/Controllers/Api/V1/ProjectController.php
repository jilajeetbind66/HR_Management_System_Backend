<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProjectAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\RoleAccess;
use App\Support\AuditLog;

class ProjectController extends Controller
{
    /**
     * GET /api/v1/projects
     * Section 9 & 11 API: List projects with pagination & envelope
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role === 'manager') {
            return $this->projectList($request, 'manager');
        }

        abort_unless(RoleAccess::isHr($user->role), 403);

        return $this->projectList($request, 'all');
    }

    /**
     * GET /api/v1/projects/mine
     * List only the authenticated employee's project assignments.
     */
    public function mine(Request $request): JsonResponse
    {
        if (!$request->user()->employee_id) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'version' => '1.2'],
            ]);
        }

        return $this->projectList($request, 'mine');
    }

    private function projectList(Request $request, string $scope): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('projects')
            ->leftJoin('project_assignments', 'projects.id', '=', 'project_assignments.project_id')
            ->leftJoin('employees', 'project_assignments.employee_id', '=', 'employees.id')
            ->select(
                'projects.*',
                'project_assignments.status as assignment_status',
                'project_assignments.id as assignment_id',
                'project_assignments.start_date',
                'project_assignments.assigned_by',
                'project_assignments.assigned_by_role',
                'project_assignments.due_date as planned_end_date',
                'project_assignments.active_due_date',
                'project_assignments.progress_percent',
                'project_assignments.actual_completed_at',
                'project_assignments.review_note',
                'project_assignments.accepted_by',
                'project_assignments.returned_by',
                'project_assignments.version',
                'project_assignments.submission_note',
                'project_assignments.deliverable_link',
                'employees.id as assigned_employee_id',
                'employees.reporting_manager_id as assigned_employee_reporting_manager_id',
                'employees.employee_code as assigned_employee_code',
                DB::raw("CONCAT(employees.first_name, ' ', employees.last_name) as assigned_employee_name"),
                'employees.avatar as assigned_employee_avatar'
            );

        if ($scope === 'mine') {
            $query->where('project_assignments.employee_id', $user->employee_id);
        } elseif ($scope === 'manager') {
            $query->where(function ($managerProjects) use ($user) {
                $managerProjects
                    ->whereIn('project_assignments.employee_id', RoleAccess::visibleEmployeeIds($user, false))
                    ->orWhere('project_assignments.assigned_by', $user->name);
            });
        }

        if ($request->filled('status')) {
            if ($request->query('status') === 'overdue') {
                $query->where('project_assignments.status', '<>', 'completed')
                    ->where('project_assignments.active_due_date', '<', now()->toDateString());
            } elseif ($request->query('status') === 'in_progress') {
                $query->where('project_assignments.status', 'in_progress')
                    ->where('project_assignments.active_due_date', '>=', now()->toDateString());
            } else {
                $query->where('project_assignments.status', $request->query('status'));
            }
        }

        $projects = $query->orderBy('projects.id', 'desc')->get();

        // Calculate dynamic overdue days as per Section 9
        $today = now()->toDateString();
        $enriched = $projects->map(function ($p) use ($today) {
            $p->project_status = $p->status;
            $p->status = $p->assignment_status ?? $p->status;
            $overdueDays = 0;
            $p->is_overdue = $p->status !== 'completed'
                && $p->active_due_date
                && $today > $p->active_due_date;
            if ($p->is_overdue) {
                $diff = (strtotime($today) - strtotime($p->active_due_date)) / 86400;
                $overdueDays = max(0, (int)$diff);
                if ($p->status === 'in_progress') {
                    $p->status = 'overdue';
                }
            }
            $p->overdue_days = $overdueDays;
            return $p;
        });

        $assignmentIds = $enriched->pluck('assignment_id')->filter()->all();
        $updates = DB::table('project_progress_updates')
            ->whereIn('assignment_id', $assignmentIds)
            ->orderBy('id')
            ->get()
            ->groupBy('assignment_id');
        $extensions = DB::table('project_extension_requests')
            ->whereIn('assignment_id', $assignmentIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('assignment_id');
        $penalties = DB::table('project_penalties')
            ->whereIn('assignment_id', $assignmentIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('assignment_id');

        $enriched->each(function ($project) use ($updates, $extensions, $penalties) {
            $project->updates = $updates->get($project->assignment_id, collect())->values();
            $project->extensions = $extensions->get($project->assignment_id, collect())->values();
            $project->penalties = $penalties->get($project->assignment_id, collect())->values();
        });

        return response()->json([
            'data' => $enriched,
            'meta' => [
                'total' => $enriched->count(),
                'version' => '1.2'
            ]
        ]);
    }

    /**
     * POST /api/v1/projects
     * Create project and assign to developer
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'deliverable' => 'nullable|string',
            'priority' => 'nullable|in:Low,Medium,High,Urgent',
            'project_type' => 'nullable|in:Feature,Bug Fix,Integration,Report,Migration,Support',
            'assigned_employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at')),
            ],
            'start_date' => 'required|date',
            'planned_end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if (
            $request->user()->role === 'manager'
            && !RoleAccess::managesEmployee($request->user(), (int) $validated['assigned_employee_id'])
        ) {
            abort(403);
        }

        $project = DB::transaction(function () use ($validated, $request) {
            $year = now()->format('Y');
            $nextNumber = (int) DB::table('projects')->max('id') + 1;
            do {
                $code = 'PRJ-' . $year . '-' . str_pad((string) $nextNumber++, 3, '0', STR_PAD_LEFT);
            } while (DB::table('projects')->where('project_code', $code)->exists());

            $projectId = DB::table('projects')->insertGetId([
                'project_code' => $code,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? 'No description provided',
                'deliverable' => $validated['deliverable'] ?? 'Project Milestone Deliverable',
                'priority' => $validated['priority'] ?? 'Medium',
                'project_type' => $validated['project_type'] ?? null,
                'status' => 'in_progress',
                'created_by' => $request->user()->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $assignment = new ProjectAssignment();
            $assignment->project_id = $projectId;
            $assignment->employee_id = $validated['assigned_employee_id'];
            $assignment->assigned_by = $request->user()->name;
            $assignment->assigned_by_role = $request->user()->role;
            $assignment->start_date = $validated['start_date'];
            $assignment->due_date = $validated['planned_end_date'];
            $assignment->active_due_date = $validated['planned_end_date'];
            $assignment->progress_percent = 0;
            $assignment->status = 'in_progress';
            $assignment->version = 1;
            $assignment->save();
            $assignmentId = $assignment->id;

            AuditLog::record(
                $request,
                'PROJECT_ASSIGNED',
                'Projects',
                "Created project {$code} and assigned it to employee {$validated['assigned_employee_id']}"
            );

            $assignedUserId = DB::table('users')->where('employee_id', $validated['assigned_employee_id'])->value('id');
            if ($assignedUserId) {
                DB::table('user_notifications')->insert([
                    'user_id' => $assignedUserId,
                    'event_key' => 'project.assigned',
                    'title' => "New Project Assigned: {$validated['name']}",
                    'message' => "You have been assigned project '{$validated['name']}' ({$code}) by {$request->user()->name}. Deadline: {$validated['planned_end_date']}.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return DB::table('projects')
                ->leftJoin('project_assignments', 'projects.id', '=', 'project_assignments.project_id')
                ->leftJoin('employees', 'project_assignments.employee_id', '=', 'employees.id')
                ->select(
                    'projects.*',
                    'project_assignments.status as assignment_status',
                    'project_assignments.id as assignment_id',
                    'project_assignments.start_date',
                    'project_assignments.assigned_by',
                    'project_assignments.assigned_by_role',
                    'project_assignments.due_date as planned_end_date',
                    'project_assignments.active_due_date',
                    'project_assignments.progress_percent',
                    'project_assignments.actual_completed_at',
                    'project_assignments.version',
                    'project_assignments.submission_note',
                    'project_assignments.deliverable_link',
                    'employees.id as assigned_employee_id',
                    'employees.reporting_manager_id as assigned_employee_reporting_manager_id',
                    'employees.employee_code as assigned_employee_code',
                    DB::raw("CONCAT(employees.first_name, ' ', employees.last_name) as assigned_employee_name"),
                    'employees.avatar as assigned_employee_avatar'
                )
                ->where('projects.id', $projectId)
                ->first();
        });

        return response()->json(['data' => $project], 201);
    }

    /**
     * POST /api/v1/project-assignments/{id}/progress
     * Update progress % and notes with concurrency check (Section 7.6)
     */
    public function updateProgress(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'progress_percent' => 'required|integer|min:0|max:100',
            'note' => 'required|string',
            'version' => 'nullable|integer'
        ]);

        $assignment = DB::table('project_assignments')->where('id', $id)->first();
        if (!$assignment) {
            return response()->json(['message' => 'Assignment not found', 'code' => 'NOT_FOUND'], 404);
        }
        abort_unless(RoleAccess::canUpdateAssignment($request->user(), $assignment), 403);
        if ($assignment->status !== 'in_progress') {
            return response()->json([
                'message' => 'Progress can only be updated while a project is in progress.',
                'code' => 'INVALID_STATE',
            ], 409);
        }

        // Section 7.6 Concurrency Check
        if ($request->has('version') && $assignment->version !== (int)$request->input('version')) {
            return response()->json([
                'message' => 'Record was modified by another user. Please reload.',
                'code' => 'STALE_RECORD'
            ], 409);
        }

        DB::transaction(function () use ($assignment, $validated, $request) {
            DB::table('project_assignments')
                ->where('id', $assignment->id)
                ->update([
                    'progress_percent' => $validated['progress_percent'],
                    'version' => $assignment->version + 1,
                    'updated_at' => now(),
                ]);

            DB::table('project_progress_updates')->insert([
                'assignment_id' => $assignment->id,
                'updated_by' => $request->user()->name,
                'progress_percent' => $validated['progress_percent'],
                'note' => $validated['note'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'PROJECT_PROGRESS_UPDATED',
                'Projects',
                "Updated progress for assignment #{$assignment->id} to {$validated['progress_percent']}%"
            );
        });

        return response()->json(['message' => 'Progress updated successfully', 'code' => 'SUCCESS']);
    }

    /**
     * POST /api/v1/project-assignments/{id}/extension-requests
     * Developer requests deadline extension
     */
    public function requestExtension(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'requested_due_date' => 'required|date_format:Y-m-d',
            'reason' => 'required|string'
        ]);

        $extId = DB::transaction(function () use ($id, $validated, $request) {
            $assignment = DB::table('project_assignments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (!$assignment) {
                abort(404, 'Assignment not found');
            }
            abort_unless(RoleAccess::canUpdateAssignment($request->user(), $assignment), 403);
            if ($assignment->status !== 'in_progress') {
                abort(409, 'Extensions can only be requested while a project is in progress.');
            }
        if ($assignment->status !== 'in_progress') {
            return response()->json([
                'message' => 'Progress can only be updated while a project is in progress.',
                'code' => 'INVALID_STATE',
            ], 409);
        }

            if ($validated['requested_due_date'] <= $assignment->active_due_date) {
                throw ValidationException::withMessages([
                    'requested_due_date' => 'The requested deadline must be after the current active deadline.',
                ]);
            }
            if (DB::table('project_extension_requests')
                ->where('assignment_id', $assignment->id)
                ->where('status', 'pending')
                ->exists()) {
                throw ValidationException::withMessages([
                    'extension_request' => 'A pending extension request already exists for this project.',
                ]);
            }

            $assignedDeveloper = DB::table('employees')
                ->where('id', $assignment->employee_id)
                ->first(['first_name', 'last_name']);
            if (!$assignedDeveloper) {
                abort(404, 'Assigned developer not found');
            }
            $requestedBy = trim($assignedDeveloper->first_name . ' ' . $assignedDeveloper->last_name);
            if ($requestedBy === '') {
                throw ValidationException::withMessages([
                    'requested_by' => 'Assigned developer name is unavailable.',
                ]);
            }
            $onBehalfOf = (int) $request->user()->employee_id === (int) $assignment->employee_id
                ? null
                : $request->user()->name;

            $extensionId = DB::table('project_extension_requests')->insertGetId([
                'assignment_id' => $assignment->id,
                'requested_by' => $requestedBy,
                'on_behalf_of' => $onBehalfOf,
                'old_due_date' => $assignment->active_due_date,
                'requested_due_date' => $validated['requested_due_date'],
                'reason' => $validated['reason'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'PROJECT_EXTENSION_REQUESTED',
                'Projects',
                "Requested extension for assignment #{$assignment->id} to {$validated['requested_due_date']}"
            );

            return $extensionId;
        });

        return response()->json(['data' => ['extension_id' => $extId], 'message' => 'Extension request submitted'], 201);
    }

    public function penaltyPolicy(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('project_penalty_policies')->where('is_active', true)->orderByDesc('id')->first(),
        ]);
    }

    /**
     * POST /api/v1/project-extension-requests/{id}/approve
     * Approves extension: appends revision history and updates active due date
     */
    public function approveExtension(Request $request, int $id): JsonResponse
    {
        $extension = DB::table('project_extension_requests')->where('id', $id)->first();
        if (!$extension || $extension->status !== 'pending') {
            return response()->json(['message' => 'Invalid extension request state', 'code' => 'INVALID_STATE'], 409);
        }
        $assignment = DB::table('project_assignments')->where('id', $extension->assignment_id)->first();
        abort_unless($assignment && RoleAccess::canReviewAssignment($request->user(), $assignment), 403);

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'decision_note' => 'nullable|string|max:2000',
        ]);
        $decision = $validated['decision'];
        $decisionNote = trim((string) ($validated['decision_note'] ?? ''));
        if ($decision === 'rejected' && $decisionNote === '') {
            throw ValidationException::withMessages([
                'decision_note' => 'Decision remarks are required when rejecting an extension request.',
            ]);
        }

        $reviewed = DB::transaction(function () use ($id, $decision, $decisionNote, $request) {
            $extension = DB::table('project_extension_requests')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (!$extension || $extension->status !== 'pending') {
                return false;
            }

            $assignment = ProjectAssignment::query()
                ->whereKey($extension->assignment_id)
                ->lockForUpdate()
                ->first();
            abort_unless($assignment && RoleAccess::canReviewAssignment($request->user(), $assignment), 403);
            if ($assignment->status === 'completed') {
                return false;
            }

            $decidedAt = now();
            DB::table('project_extension_requests')->where('id', $extension->id)->update([
                'status' => $decision,
                'reviewed_by' => $request->user()->name,
                'reviewed_at' => $decidedAt,
                'decided_by' => $request->user()->name,
                'decided_at' => $decidedAt,
                'decision_note' => $decisionNote !== '' ? $decisionNote : null,
                'updated_at' => now()
            ]);

            if ($decision === 'approved') {
                $assignment->active_due_date = $extension->requested_due_date;
                if ($assignment->status !== 'under_review') {
                    $assignment->status = 'in_progress';
                }
                $assignment->save();
            }
            AuditLog::record(
                $request,
                'PROJECT_EXTENSION_REVIEWED',
                'Projects',
                "Marked extension request #{$extension->id} {$decision}"
            );

            $developerUserId = DB::table('users')->where('employee_id', $assignment->employee_id)->value('id');
            if ($developerUserId) {
                $statusText = $decision === 'approved' ? 'approved' : 'rejected';
                DB::table('user_notifications')->insert([
                    'user_id' => $developerUserId,
                    'event_key' => 'project.extension_reviewed',
                    'title' => "Deadline Extension " . ucfirst($statusText),
                    'message' => "Your deadline extension request for project was {$statusText} by {$request->user()->name}." . ($decisionNote !== '' ? " Remarks: {$decisionNote}" : ''),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return true;
        });

        if (!$reviewed) {
            return response()->json(['message' => 'Invalid extension request state', 'code' => 'INVALID_STATE'], 409);
        }

        return response()->json(['message' => "Extension {$decision}"]);
    }

    /**
     * POST /api/v1/project-assignments/{id}/submit
     * Submit project completion for review
     */
    public function submitCompletion(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'submission_note' => 'required|string',
            'deliverable_link' => 'nullable|string'
        ]);

        $submitted = DB::transaction(function () use ($id, $validated, $request) {
            $assignment = DB::table('project_assignments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($assignment, 404);
            abort_unless(RoleAccess::canUpdateAssignment($request->user(), $assignment), 403);
            if ($assignment->status !== 'in_progress') {
                abort(409, 'Extensions can only be requested while a project is in progress.');
            }
        if ($assignment->status !== 'in_progress') {
            return response()->json([
                'message' => 'Progress can only be updated while a project is in progress.',
                'code' => 'INVALID_STATE',
            ], 409);
        }
            if ($assignment->status !== 'in_progress') {
                return false;
            }

            DB::table('project_assignments')->where('id', $id)->update([
                'status' => 'under_review',
                'progress_percent' => 100,
                'submission_note' => $validated['submission_note'],
                'deliverable_link' => $validated['deliverable_link'] ?? null,
                'updated_at' => now()
            ]);
            AuditLog::record($request, 'PROJECT_SUBMITTED', 'Projects', "Submitted assignment #{$id} for review");

            return true;
        });

        if (!$submitted) {
            return response()->json(['message' => 'Only in-progress projects can be submitted for completion.', 'code' => 'INVALID_STATE'], 409);
        }

        return response()->json(['message' => 'Project submitted for HR review']);
    }

    /**
     * POST /api/v1/project-assignments/{id}/accept
     * HR / Reviewer accepts completion -> sets actual_completed_at and completed status
     */
    public function acceptCompletion(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'sometimes|in:accept,return',
            'remarks' => 'nullable|string',
        ]);
        $decision = $validated['decision'] ?? 'accept';
        $assignment = DB::table('project_assignments')->where('id', $id)->first();
        if (!$assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }
        abort_unless(RoleAccess::canReviewAssignment($request->user(), $assignment), 403);

        $reviewed = DB::transaction(function () use ($assignment, $validated, $decision, $request) {
            $lockedAssignment = DB::table('project_assignments')
                ->where('id', $assignment->id)
                ->lockForUpdate()
                ->first();
            if (!$lockedAssignment || $lockedAssignment->status !== 'under_review') {
                return false;
            }

            if ($decision === 'accept') {
                DB::table('project_assignments')->where('id', $lockedAssignment->id)->update([
                    'status' => 'completed',
                    'actual_completed_at' => now(),
                    'accepted_at' => now(),
                    'accepted_by' => $request->user()->name,
                    'review_note' => $validated['remarks'] ?? null,
                    'updated_at' => now()
                ]);

                DB::table('projects')->where('id', $lockedAssignment->project_id)->update([
                    'status' => 'completed',
                    'updated_at' => now()
                ]);
            } else {
                DB::table('project_assignments')->where('id', $lockedAssignment->id)->update([
                    'status' => 'in_progress',
                    'progress_percent' => 85,
                    'review_note' => $validated['remarks'] ?? null,
                    'returned_by' => $request->user()->name,
                    'updated_at' => now()
                ]);

                DB::table('projects')->where('id', $lockedAssignment->project_id)->update([
                    'status' => 'in_progress',
                    'updated_at' => now()
                ]);
            }
            $reviewResult = $decision === 'accept' ? 'accepted' : 'returned';
            AuditLog::record(
                $request,
                'PROJECT_COMPLETION_REVIEWED',
                'Projects',
                "Marked assignment #{$lockedAssignment->id} completion {$reviewResult}"
            );

            $developerUserId = DB::table('users')->where('employee_id', $lockedAssignment->employee_id)->value('id');
            if ($developerUserId) {
                $statusText = $decision === 'accept' ? 'accepted and marked completed' : 'returned for revisions';
                DB::table('user_notifications')->insert([
                    'user_id' => $developerUserId,
                    'event_key' => 'project.completion_reviewed',
                    'title' => $decision === 'accept' ? 'Project Deliverable Accepted' : 'Project Returned for Revisions',
                    'message' => "Your project submission was {$statusText} by {$request->user()->name}." . (!empty($validated['remarks']) ? " Remarks: {$validated['remarks']}" : ''),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return true;
        });

        if (!$reviewed) {
            return response()->json(['message' => 'Only submitted projects can be reviewed.', 'code' => 'INVALID_STATE'], 409);
        }

        return response()->json([
            'message' => $decision === 'accept'
                ? 'Project deliverable accepted and marked completed'
                : 'Project completion returned for further work',
        ]);
    }

    public function assessPenalty(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string']);

        $policy = DB::table('project_penalty_policies')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
        $penaltyId = DB::transaction(function () use ($id, $policy, $validated, $request) {
            $assignment = DB::table('project_assignments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($assignment, 404);
            abort_unless(
                in_array($request->user()->role, ['super_admin', 'hr_admin', 'hr_executive'], true)
                    && RoleAccess::canReviewAssignment($request->user(), $assignment),
                403
            );

            $today = now()->toDateString();
            if ($assignment->status === 'completed'
                || !$assignment->active_due_date
                || $assignment->active_due_date >= $today) {
                return null;
            }

            $id = DB::table('project_penalties')->insertGetId([
                'assignment_id' => $assignment->id,
                'policy_id' => $policy->id ?? null,
                'overdue_days_at_assessment' => max(0, (int) floor((strtotime($today) - strtotime($assignment->active_due_date)) / 86400)),
                'penalty_type' => 'non_monetary',
                'amount' => null,
                'reason' => $validated['reason'],
                'status' => 'approved',
                'approved_by' => $request->user()->name,
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'PROJECT_PENALTY_ASSESSED', 'Projects', "Assessed penalty #{$id}");

            $developerUserId = DB::table('users')->where('employee_id', $assignment->employee_id)->value('id');
            if ($developerUserId) {
                DB::table('user_notifications')->insert([
                    'user_id' => $developerUserId,
                    'event_key' => 'project.penalty_assessed',
                    'title' => 'Overdue Notice Assessed',
                    'message' => "An overdue notice was assessed for project by {$request->user()->name}. Reason: {$validated['reason']}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $id;
        });

        if (!$penaltyId) {
            return response()->json([
                'message' => 'Only overdue, non-completed projects can receive an HR-reviewed notice.',
                'code' => 'INVALID_STATE',
            ], 409);
        }

        return response()->json([
            'data' => DB::table('project_penalties')->where('id', $penaltyId)->first(),
        ], 201);
    }

    public function waivePenalty(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['waiver_reason' => 'required|string']);
        $penalty = DB::table('project_penalties')->where('id', $id)->first();
        abort_unless($penalty, 404);
        abort_unless($penalty->status !== 'waived', 409);

        $assignment = DB::table('project_assignments')->where('id', $penalty->assignment_id)->first();
        abort_unless($assignment, 404);

        if ($request->user()->role === 'manager') {
            abort_if(
                $request->user()->employee_id && (int) $assignment->employee_id === (int) $request->user()->employee_id,
                403,
                'Managers cannot waive their own project penalties.'
            );
            abort_unless(RoleAccess::managesEmployee($request->user(), (int) $assignment->employee_id), 403);
        } else {
            abort_unless(RoleAccess::canManageAssignment($request->user(), $assignment), 403);
        }

        DB::transaction(function () use ($id, $validated, $request) {
            DB::table('project_penalties')->where('id', $id)->update([
                'status' => 'waived',
                'waived_at' => now(),
                'waived_by' => $request->user()->name,
                'waiver_reason' => $validated['waiver_reason'],
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'PROJECT_PENALTY_WAIVED', 'Projects', "Waived penalty #{$id}");
        });

        return response()->json(['message' => 'Project penalty waived']);
    }
}
