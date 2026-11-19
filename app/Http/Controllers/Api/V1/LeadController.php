<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    private const HR_ROLES = ['super_admin', 'hr_admin'];

    /**
     * Check if user is HR / Admin or an employee with explicit lead access.
     */
    private function verifyLeadAccess(Request $request): array
    {
        $user = $request->user();
        $isHr = in_array($user->role, self::HR_ROLES, true);

        if ($isHr) {
            return ['isHr' => true, 'employeeId' => $user->employee_id];
        }

        if ($user->employee_id) {
            $hasAccess = (bool) DB::table('employees')
                ->where('id', $user->employee_id)
                ->whereNull('deleted_at')
                ->value('has_lead_access');

            if ($hasAccess) {
                return ['isHr' => false, 'employeeId' => (int) $user->employee_id];
            }

            abort(403, 'You do not have permission to access Lead Management. Please contact HR.');
        }

        abort(403, 'Unauthorized access to Lead Management.');
    }

    /**
     * Get active employees available for lead assignment and filtering.
     */
    public function assignees(Request $request): JsonResponse
    {
        $this->verifyLeadAccess($request);

        $employees = DB::table('employees')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->select([
                'id',
                'employee_code',
                'first_name',
                'last_name',
                'designation',
                'department',
                'avatar',
                'has_lead_access',
            ])
            ->orderBy('first_name')
            ->get();

        return response()->json(['data' => $employees]);
    }

    /**
     * Display a listing of leads with statistics and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $access = $this->verifyLeadAccess($request);
        $isHr = $access['isHr'];
        $employeeId = $access['employeeId'];

        $query = DB::table('leads')
            ->leftJoin('users as creator', 'leads.created_by_user_id', '=', 'creator.id')
            ->select([
                'leads.*',
                'creator.name as creator_name',
            ]);

        // Anyone with lead access sees all leads. Apply assignee filter if requested:
        if ($request->filled('employee_id') && $request->input('employee_id') !== 'all') {
            $filterEmpId = (int) $request->input('employee_id');
            $query->join('lead_assignments', function ($join) use ($filterEmpId) {
                $join->on('leads.id', '=', 'lead_assignments.lead_id')
                    ->where('lead_assignments.employee_id', '=', $filterEmpId);
            });
        }

        // Search by lead name or remarks
        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('leads.lead_name', 'like', $search)
                    ->orWhere('leads.remarks', 'like', $search);
            });
        }

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('leads.status', $request->input('status'));
        }

        // Filter by lead date range
        if ($request->filled('date_from')) {
            $query->whereDate('leads.lead_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('leads.lead_date', '<=', $request->input('date_to'));
        }

        // Filter by follow-up due today
        if ($request->boolean('follow_up_due_today')) {
            $today = Carbon::today()->toDateString();
            $query->where('leads.follow_up_required', true)
                ->whereDate('leads.follow_up_date', '<=', $today)
                ->whereNotIn('leads.status', ['Converted', 'Lost']);
        }

        $leads = $query->orderByDesc('leads.id')->get();

        // Load assignments for visible leads
        $leadIds = $leads->pluck('id')->all();
        $assignmentsByLead = [];
        if (!empty($leadIds)) {
            $assignments = DB::table('lead_assignments')
                ->join('employees', 'lead_assignments.employee_id', '=', 'employees.id')
                ->whereIn('lead_assignments.lead_id', $leadIds)
                ->whereNull('employees.deleted_at')
                ->select([
                    'lead_assignments.lead_id',
                    'employees.id as employee_id',
                    'employees.employee_code',
                    'employees.first_name',
                    'employees.last_name',
                    'employees.avatar',
                    'employees.designation',
                    'employees.department',
                ])
                ->get();

            foreach ($assignments as $a) {
                $assignmentsByLead[$a->lead_id][] = [
                    'id' => $a->employee_id,
                    'employee_code' => $a->employee_code,
                    'name' => trim("{$a->first_name} {$a->last_name}"),
                    'first_name' => $a->first_name,
                    'last_name' => $a->last_name,
                    'avatar' => $a->avatar,
                    'designation' => $a->designation,
                    'department' => $a->department,
                ];
            }
        }

        // Attach assignments to leads
        $today = Carbon::today()->toDateString();
        $leads = $leads->map(function ($lead) use ($assignmentsByLead, $today) {
            $lead->assigned_employees = $assignmentsByLead[$lead->id] ?? [];
            $lead->follow_up_required = (bool) $lead->follow_up_required;
            $lead->is_follow_up_due = $lead->follow_up_required && $lead->follow_up_date && $lead->follow_up_date <= $today && !in_array($lead->status, ['Converted', 'Lost'], true);
            return $lead;
        });

        // Compute dashboard statistics across all leads in the pipeline
        $allVisible = DB::table('leads')->get(['leads.id', 'leads.status', 'leads.follow_up_required', 'leads.follow_up_date']);

        $stats = [
            'total' => $allVisible->count(),
            'new' => $allVisible->where('status', 'New')->count(),
            'in_progress' => $allVisible->where('status', 'In Progress')->count(),
            'converted' => $allVisible->where('status', 'Converted')->count(),
            'lost' => $allVisible->where('status', 'Lost')->count(),
            'follow_ups_due_today' => $allVisible->filter(function ($item) use ($today) {
                return (bool) $item->follow_up_required && $item->follow_up_date && $item->follow_up_date <= $today && !in_array($item->status, ['Converted', 'Lost'], true);
            })->count(),
        ];

        return response()->json([
            'data' => $leads,
            'meta' => [
                'stats' => $stats,
                'is_hr' => $isHr,
            ],
        ]);
    }

    /**
     * Store a newly created lead in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $access = $this->verifyLeadAccess($request);

        $validated = $request->validate([
            'lead_name' => 'required|string|max:255',
            'lead_date' => 'required|date',
            'status' => 'required|in:New,In Progress,Converted,Lost',
            'assigned_employee_ids' => 'sometimes|array',
            'assigned_employee_ids.*' => 'integer|exists:employees,id',
            'follow_up_required' => 'sometimes|boolean',
            'follow_up_date' => 'required_if:follow_up_required,true,1|nullable|date',
            'remarks' => 'nullable|string|max:5000',
        ]);

        $lead = DB::transaction(function () use ($request, $validated, $access) {
            $followUpRequired = (bool) ($validated['follow_up_required'] ?? false);
            $followUpDate = $followUpRequired ? ($validated['follow_up_date'] ?? null) : null;

            $leadId = DB::table('leads')->insertGetId([
                'lead_name' => trim($validated['lead_name']),
                'lead_date' => $validated['lead_date'],
                'status' => $validated['status'],
                'follow_up_required' => $followUpRequired,
                'follow_up_date' => $followUpDate,
                'remarks' => isset($validated['remarks']) ? trim($validated['remarks']) : null,
                'created_by_user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Determine assignees (use provided array or default to creator)
            $assignedIds = !empty($validated['assigned_employee_ids'])
                ? array_unique($validated['assigned_employee_ids'])
                : (!empty($access['employeeId']) ? [$access['employeeId']] : []);

            // Insert employee assignments
            $assignments = [];
            $now = now();
            foreach ($assignedIds as $empId) {
                $assignments[] = [
                    'lead_id' => $leadId,
                    'employee_id' => (int) $empId,
                    'assigned_by_user_id' => $request->user()->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($assignments)) {
                DB::table('lead_assignments')->insert($assignments);
            }

            // Log initial activity note
            DB::table('lead_activities')->insert([
                'lead_id' => $leadId,
                'user_id' => $request->user()->id,
                'action' => 'created',
                'note' => 'Lead created and assigned to ' . count($assignments) . ' employee(s).',
                'created_at' => $now,
            ]);

            AuditLog::record(
                $request,
                'LEAD_CREATED',
                'Lead Management',
                "Created lead '{$validated['lead_name']}' (#{$leadId})"
            );

            return DB::table('leads')->find($leadId);
        });

        return response()->json([
            'message' => 'Lead created successfully.',
            'data' => $this->loadLeadWithDetails((int) $lead->id),
        ], 201);
    }

    /**
     * Display the specified lead.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->verifyLeadAccess($request);
        $lead = $this->loadLeadWithDetails($id);

        return response()->json(['data' => $lead]);
    }

    /**
     * Update the specified lead in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $access = $this->verifyLeadAccess($request);

        $lead = DB::table('leads')->where('id', $id)->first();
        abort_unless($lead, 404, 'Lead not found.');

        $validated = $request->validate([
            'lead_name' => 'required|string|max:255',
            'lead_date' => 'required|date',
            'status' => 'required|in:New,In Progress,Converted,Lost',
            'assigned_employee_ids' => 'sometimes|array',
            'assigned_employee_ids.*' => 'integer|exists:employees,id',
            'follow_up_required' => 'sometimes|boolean',
            'follow_up_date' => 'required_if:follow_up_required,true,1|nullable|date',
            'remarks' => 'nullable|string|max:5000',
        ]);

        DB::transaction(function () use ($request, $id, $validated, $lead, $access) {
            $followUpRequired = (bool) ($validated['follow_up_required'] ?? false);
            $followUpDate = $followUpRequired ? ($validated['follow_up_date'] ?? null) : null;

            DB::table('leads')->where('id', $id)->update([
                'lead_name' => trim($validated['lead_name']),
                'lead_date' => $validated['lead_date'],
                'status' => $validated['status'],
                'follow_up_required' => $followUpRequired,
                'follow_up_date' => $followUpDate,
                'remarks' => isset($validated['remarks']) ? trim($validated['remarks']) : null,
                'updated_at' => now(),
            ]);

            // Sync assignments if provided
            if (isset($validated['assigned_employee_ids'])) {
                DB::table('lead_assignments')->where('lead_id', $id)->delete();
                $assignments = [];
                $now = now();
                $assignedIds = !empty($validated['assigned_employee_ids'])
                    ? array_unique($validated['assigned_employee_ids'])
                    : (!empty($access['employeeId']) ? [$access['employeeId']] : []);

                foreach ($assignedIds as $empId) {
                    $assignments[] = [
                        'lead_id' => $id,
                        'employee_id' => (int) $empId,
                        'assigned_by_user_id' => $request->user()->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (!empty($assignments)) {
                    DB::table('lead_assignments')->insert($assignments);
                }
            }

            // Log update activity
            $changeNote = "Lead details updated by {$request->user()->name}.";
            if ($lead->status !== $validated['status']) {
                $changeNote .= " Status changed from '{$lead->status}' to '{$validated['status']}'.";
            }
            DB::table('lead_activities')->insert([
                'lead_id' => $id,
                'user_id' => $request->user()->id,
                'action' => 'updated',
                'note' => $changeNote,
                'created_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'LEAD_UPDATED',
                'Lead Management',
                "Updated lead '{$validated['lead_name']}' (#{$id})"
            );
        });

        return response()->json([
            'message' => 'Lead updated successfully.',
            'data' => $this->loadLeadWithDetails($id),
        ]);
    }

    /**
     * Remove the specified lead from storage (HR only).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless(in_array($request->user()->role, self::HR_ROLES, true), 403, 'Only HR or Super Admin can delete leads.');

        $lead = DB::table('leads')->where('id', $id)->first();
        abort_unless($lead, 404, 'Lead not found.');

        DB::transaction(function () use ($request, $id, $lead) {
            DB::table('lead_assignments')->where('lead_id', $id)->delete();
            DB::table('lead_activities')->where('lead_id', $id)->delete();
            DB::table('leads')->where('id', $id)->delete();

            AuditLog::record(
                $request,
                'LEAD_DELETED',
                'Lead Management',
                "Deleted lead '{$lead->lead_name}' (#{$id})"
            );
        });

        return response()->json(['message' => 'Lead deleted successfully.']);
    }

    /**
     * Update progress/status/follow-up notes (Available to HR and assigned employees).
     */
    public function updateProgress(Request $request, int $id): JsonResponse
    {
        $access = $this->verifyLeadAccess($request);
        $lead = DB::table('leads')->where('id', $id)->first();
        abort_unless($lead, 404, 'Lead not found.');

        $validated = $request->validate([
            'status' => 'sometimes|nullable|in:New,In Progress,Converted,Lost',
            'remarks' => 'sometimes|nullable|string|max:5000',
            'follow_up_required' => 'sometimes|boolean',
            'follow_up_date' => 'sometimes|nullable|date',
            'mark_follow_up_completed' => 'sometimes|boolean',
            'progress_note' => 'sometimes|nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $id, $validated, $lead) {
            $updates = ['updated_at' => now()];

            if (isset($validated['status'])) {
                $updates['status'] = $validated['status'];
            }

            if (isset($validated['remarks'])) {
                $updates['remarks'] = trim($validated['remarks']);
            }

            if (!empty($validated['mark_follow_up_completed'])) {
                $updates['follow_up_required'] = false;
                $updates['follow_up_date'] = null;
            } elseif (isset($validated['follow_up_required'])) {
                $updates['follow_up_required'] = (bool) $validated['follow_up_required'];
                $updates['follow_up_date'] = $updates['follow_up_required'] ? ($validated['follow_up_date'] ?? null) : null;
            }

            DB::table('leads')->where('id', $id)->update($updates);

            // Log activity note
            $notes = [];
            if (isset($validated['status']) && $validated['status'] !== $lead->status) {
                $notes[] = "Status changed from '{$lead->status}' to '{$validated['status']}'.";
            }
            if (!empty($validated['mark_follow_up_completed'])) {
                $notes[] = "Marked follow-up as completed.";
            } elseif (isset($updates['follow_up_date']) && $updates['follow_up_date']) {
                $notes[] = "Scheduled next follow-up for {$updates['follow_up_date']}.";
            }
            if (!empty($validated['progress_note'])) {
                $notes[] = trim($validated['progress_note']);
            }

            if (!empty($notes)) {
                DB::table('lead_activities')->insert([
                    'lead_id' => $id,
                    'user_id' => $request->user()->id,
                    'action' => 'progress_updated',
                    'note' => implode(' | ', $notes),
                    'created_at' => now(),
                ]);
            }

            AuditLog::record(
                $request,
                'LEAD_PROGRESS_UPDATED',
                'Lead Management',
                "Updated progress for lead '{$lead->lead_name}' (#{$id})"
            );
        });

        return response()->json([
            'message' => 'Lead progress updated successfully.',
            'data' => $this->loadLeadWithDetails($id),
        ]);
    }

    /**
     * Helper to load single lead with full relations.
     */
    private function loadLeadWithDetails(int $id): object
    {
        $lead = DB::table('leads')
            ->leftJoin('users as creator', 'leads.created_by_user_id', '=', 'creator.id')
            ->where('leads.id', $id)
            ->select([
                'leads.*',
                'creator.name as creator_name',
            ])
            ->first();

        abort_unless($lead, 404, 'Lead not found.');

        $lead->assigned_employees = DB::table('lead_assignments')
            ->join('employees', 'lead_assignments.employee_id', '=', 'employees.id')
            ->where('lead_assignments.lead_id', $id)
            ->whereNull('employees.deleted_at')
            ->select([
                'employees.id',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.avatar',
                'employees.designation',
                'employees.department',
            ])
            ->get()
            ->map(fn ($emp) => [
                'id' => $emp->id,
                'employee_code' => $emp->employee_code,
                'name' => trim("{$emp->first_name} {$emp->last_name}"),
                'first_name' => $emp->first_name,
                'last_name' => $emp->last_name,
                'avatar' => $emp->avatar,
                'designation' => $emp->designation,
                'department' => $emp->department,
            ])
            ->all();

        $lead->activities = DB::table('lead_activities')
            ->leftJoin('users', 'lead_activities.user_id', '=', 'users.id')
            ->where('lead_activities.lead_id', $id)
            ->select([
                'lead_activities.id',
                'lead_activities.action',
                'lead_activities.note',
                'lead_activities.created_at',
                'users.name as user_name',
            ])
            ->orderByDesc('lead_activities.id')
            ->get();

        $today = Carbon::today()->toDateString();
        $lead->follow_up_required = (bool) $lead->follow_up_required;
        $lead->is_follow_up_due = $lead->follow_up_required && $lead->follow_up_date && $lead->follow_up_date <= $today && !in_array($lead->status, ['Converted', 'Lost'], true);

        return $lead;
    }
}
