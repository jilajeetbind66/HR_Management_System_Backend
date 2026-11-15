<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        $query = DB::table('projects')
            ->leftJoin('project_assignments', 'projects.id', '=', 'project_assignments.project_id')
            ->leftJoin('employees', 'project_assignments.employee_id', '=', 'employees.id')
            ->select(
                'projects.*',
                'project_assignments.status as assignment_status',
                'project_assignments.id as assignment_id',
                'project_assignments.start_date',
                'project_assignments.due_date as planned_end_date',
                'project_assignments.active_due_date',
                'project_assignments.progress_percent',
                'project_assignments.actual_completed_at',
                'project_assignments.version',
                'project_assignments.submission_note',
                'project_assignments.deliverable_link',
                'employees.id as assigned_employee_id',
                'employees.employee_code as assigned_employee_code',
                DB::raw("CONCAT(employees.first_name, ' ', employees.last_name) as assigned_employee_name"),
                'employees.avatar as assigned_employee_avatar'
            );

        if ($user->role === 'manager') {
            $query->whereIn('project_assignments.employee_id', RoleAccess::visibleEmployeeIds($user));
        } elseif ($user->role !== 'super_admin') {
            $query->where('project_assignments.employee_id', $user->employee_id ?? 0);
        }

        if ($request->filled('status')) {
            if ($request->query('status') === 'overdue') {
                $query->where('project_assignments.status', 'in_progress')
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
            if ($p->status === 'in_progress' && $p->active_due_date && $today > $p->active_due_date) {
                $diff = (strtotime($today) - strtotime($p->active_due_date)) / 86400;
                $overdueDays = max(0, (int)$diff);
                $p->status = 'overdue';
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
            'assigned_employee_id' => 'required|exists:employees,id',
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
            $count = DB::table('projects')->count();
            $code = 'PRJ-2026-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

            $projectId = DB::table('projects')->insertGetId([
                'project_code' => $code,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? 'No description provided',
                'deliverable' => $validated['deliverable'] ?? 'Project Milestone Deliverable',
                'priority' => $validated['priority'] ?? 'Medium',
                'status' => 'in_progress',
                'created_by' => $request->user()->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $assignmentId = DB::table('project_assignments')->insertGetId([
                'project_id' => $projectId,
                'employee_id' => $validated['assigned_employee_id'],
                'assigned_by' => $request->user()->name,
                'start_date' => $validated['start_date'],
                'due_date' => $validated['planned_end_date'], // Immutable baseline
                'active_due_date' => $validated['planned_end_date'],
                'progress_percent' => 0,
                'status' => 'in_progress',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'PROJECT_ASSIGNED',
                'Projects',
                "Created project {$code} and assigned it to employee {$validated['assigned_employee_id']}"
            );

            return DB::table('projects')
                ->leftJoin('project_assignments', 'projects.id', '=', 'project_assignments.project_id')
                ->leftJoin('employees', 'project_assignments.employee_id', '=', 'employees.id')
                ->select(
                    'projects.*',
                    'project_assignments.status as assignment_status',
                    'project_assignments.id as assignment_id',
                    'project_assignments.start_date',
                    'project_assignments.due_date as planned_end_date',
                    'project_assignments.active_due_date',
                    'project_assignments.progress_percent',
                    'project_assignments.actual_completed_at',
                    'project_assignments.version',
                    'project_assignments.submission_note',
                    'project_assignments.deliverable_link',
                    'employees.id as assigned_employee_id',
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
        abort_unless(RoleAccess::canManageAssignment($request->user(), $assignment), 403);

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
            'requested_due_date' => 'required|date',
            'reason' => 'required|string'
        ]);

        $assignment = DB::table('project_assignments')->where('id', $id)->first();
        if (!$assignment) {
            return response()->json(['message' => 'Assignment not found'], 404);
        }
        abort_unless(RoleAccess::canManageAssignment($request->user(), $assignment), 403);

        $extId = DB::transaction(function () use ($assignment, $validated, $request) {
            $extensionId = DB::table('project_extension_requests')->insertGetId([
                'assignment_id' => $assignment->id,
                'requested_by' => $request->user()->name,
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
        $validated = $request->validate([
            'decision' => 'sometimes|in:approved,rejected',
            'decision_note' => 'nullable|string',
        ]);
        $decision = $validated['decision'] ?? 'approved';
        $ext = DB::table('project_extension_requests')->where('id', $id)->first();
        if (!$ext || $ext->status !== 'pending') {
            return response()->json(['message' => 'Invalid extension request state', 'code' => 'INVALID_STATE'], 409);
        }
        $assignment = DB::table('project_assignments')->where('id', $ext->assignment_id)->first();
        abort_unless($assignment && RoleAccess::canManageAssignment($request->user(), $assignment), 403);

        DB::transaction(function () use ($ext, $validated, $decision, $request) {
            DB::table('project_extension_requests')->where('id', $ext->id)->update([
                'status' => $decision,
                'reviewed_by' => $request->user()->name,
                'reviewed_at' => now(),
                'decision_note' => $validated['decision_note'] ?? null,
                'updated_at' => now()
            ]);

            if ($decision === 'approved') {
                DB::table('project_assignments')->where('id', $ext->assignment_id)->update([
                    'active_due_date' => $ext->requested_due_date,
                    'status' => 'in_progress',
                    'updated_at' => now()
                ]);
            }
            AuditLog::record(
                $request,
                'PROJECT_EXTENSION_REVIEWED',
                'Projects',
                "Marked extension request #{$ext->id} {$decision}"
            );
        });

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

        $assignment = DB::table('project_assignments')->where('id', $id)->first();
        abort_unless($assignment, 404);
        abort_unless(RoleAccess::canManageAssignment($request->user(), $assignment), 403);

        DB::transaction(function () use ($id, $validated, $request) {
            DB::table('project_assignments')->where('id', $id)->update([
                'status' => 'under_review',
                'progress_percent' => 100,
                'submission_note' => $validated['submission_note'],
                'deliverable_link' => $validated['deliverable_link'] ?? null,
                'updated_at' => now()
            ]);
            AuditLog::record($request, 'PROJECT_SUBMITTED', 'Projects', "Submitted assignment #{$id} for review");
        });

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
        abort_unless(RoleAccess::canManageAssignment($request->user(), $assignment), 403);

        DB::transaction(function () use ($assignment, $validated, $decision, $request) {
            if ($decision === 'accept') {
                DB::table('project_assignments')->where('id', $assignment->id)->update([
                    'status' => 'completed',
                    'actual_completed_at' => now(),
                    'accepted_at' => now(),
                    'accepted_by' => $request->user()->name,
                    'review_note' => $validated['remarks'] ?? null,
                    'updated_at' => now()
                ]);

                DB::table('projects')->where('id', $assignment->project_id)->update([
                    'status' => 'completed',
                    'updated_at' => now()
                ]);
            } else {
                DB::table('project_assignments')->where('id', $assignment->id)->update([
                    'status' => 'in_progress',
                    'progress_percent' => 85,
                    'review_note' => $validated['remarks'] ?? null,
                    'returned_by' => $request->user()->name,
                    'updated_at' => now()
                ]);

                DB::table('projects')->where('id', $assignment->project_id)->update([
                    'status' => 'in_progress',
                    'updated_at' => now()
                ]);
            }
            $reviewResult = $decision === 'accept' ? 'accepted' : 'returned';
            AuditLog::record(
                $request,
                'PROJECT_COMPLETION_REVIEWED',
                'Projects',
                "Marked assignment #{$assignment->id} completion {$reviewResult}"
            );
        });

        return response()->json([
            'message' => $decision === 'accept'
                ? 'Project deliverable accepted and marked completed'
                : 'Project completion returned for further work',
        ]);
    }

    public function assessPenalty(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string']);
        $assignment = DB::table('project_assignments')->where('id', $id)->first();
        abort_unless($assignment, 404);
        abort_unless(RoleAccess::canManageAssignment($request->user(), $assignment), 403);

        $policy = DB::table('project_penalty_policies')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
        $penaltyId = DB::transaction(function () use ($assignment, $policy, $validated, $request) {
            $id = DB::table('project_penalties')->insertGetId([
                'assignment_id' => $assignment->id,
                'policy_id' => $policy->id ?? null,
                'overdue_days_at_assessment' => max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime($assignment->active_due_date)) / 86400)),
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

            return $id;
        });

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
        abort_unless($assignment && RoleAccess::canManageAssignment($request->user(), $assignment), 403);

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
