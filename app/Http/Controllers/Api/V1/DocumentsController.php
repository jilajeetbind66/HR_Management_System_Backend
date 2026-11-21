<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentsController extends Controller
{
    public function policies(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewAllPolicies = in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive'], true);
        $policyQuery = DB::table('company_policies')->orderByDesc('id');
        if (!$canViewAllPolicies) {
            $policyQuery->where('status', 'active');
        }

        $policies = $policyQuery->get();
        $employeeIds = $this->visibleActiveEmployeeIds($user);
        $acknowledgements = DB::table('policy_acknowledgements')
            ->join('employees', 'policy_acknowledgements.employee_id', '=', 'employees.id')
            ->whereIn('policy_acknowledgements.company_policy_id', $policies->pluck('id'))
            ->whereIn('policy_acknowledgements.employee_id', $employeeIds)
            ->select(
                'policy_acknowledgements.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name'
            )
            ->orderByDesc('policy_acknowledgements.acknowledged_at')
            ->get()
            ->groupBy('company_policy_id');

        $policies->each(function ($policy) use ($acknowledgements, $employeeIds, $user) {
            $policyAcks = $acknowledgements->get($policy->id, collect())->values();
            $policy->acknowledgements = $policyAcks;
            $policy->acknowledged_count = $policyAcks->count();
            $policy->visible_employee_count = count($employeeIds);
            $policy->acknowledged_by_current_user = $user->employee_id
                ? $policyAcks->contains(fn ($ack) => (int) $ack->employee_id === (int) $user->employee_id)
                : false;
        });

        return response()->json(['data' => $policies]);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('company_policies')->where(
                    fn ($query) => $query->where('version', $request->input('version'))
                ),
            ],
            'category' => 'required|in:Code of Conduct,Information Security,POSH,Privacy & Data Protection,Attendance,Expense & Travel,Other',
            'version' => 'required|string|max:20',
            'effective_date' => 'required|date_format:Y-m-d',
            'mandatory' => 'required|boolean',
            'content' => 'required|string|max:20000',
        ]);

        $id = DB::transaction(function () use ($validated, $request) {
            $policyId = DB::table('company_policies')->insertGetId([
                'title' => $validated['title'],
                'category' => $validated['category'],
                'version' => $validated['version'],
                'effective_date' => $validated['effective_date'],
                'mandatory' => $validated['mandatory'],
                'content' => $validated['content'],
                'status' => 'active',
                'created_by_user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'POLICY_PUBLISHED',
                'Documents',
                "Published policy {$validated['title']} version {$validated['version']}"
            );

            return $policyId;
        });

        return response()->json([
            'data' => DB::table('company_policies')->where('id', $id)->first(),
        ], 201);
    }

    public function acknowledgePolicy(Request $request, int $id): JsonResponse
    {
        $employeeId = $request->user()->employee_id;
        abort_unless($employeeId, 403, 'This account is not linked to an employee record.');
        abort_unless(
            DB::table('employees')->where('id', $employeeId)->where('status', 'active')->exists(),
            403,
            'Only active employees can acknowledge policies.'
        );

        $acknowledgementId = DB::transaction(function () use ($request, $id, $employeeId) {
            $policy = DB::table('company_policies')->where('id', $id)->lockForUpdate()->first();
            abort_if(!$policy, 404);
            abort_if($policy->status !== 'active', 409, 'Archived policies cannot be acknowledged.');
            abort_if($policy->effective_date > now()->toDateString(), 409, 'This policy is not effective yet.');

            $alreadyAcknowledged = DB::table('policy_acknowledgements')
                ->where('company_policy_id', $id)
                ->where('employee_id', $employeeId)
                ->exists();
            abort_if($alreadyAcknowledged, 409, 'This policy version has already been acknowledged.');

            $now = now();
            $ackId = DB::table('policy_acknowledgements')->insertGetId([
                'company_policy_id' => $id,
                'employee_id' => $employeeId,
                'policy_version' => $policy->version,
                'acknowledged_by_user_id' => $request->user()->id,
                'acknowledged_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            AuditLog::record(
                $request,
                'POLICY_ACKNOWLEDGED',
                'Documents',
                "Acknowledged policy #{$id} version {$policy->version}"
            );

            return $ackId;
        });

        return response()->json([
            'data' => DB::table('policy_acknowledgements')->where('id', $acknowledgementId)->first(),
        ], 201);
    }

    public function archivePolicy(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $policy = DB::table('company_policies')->where('id', $id)->lockForUpdate()->first();
            abort_if(!$policy, 404);
            abort_if($policy->status !== 'active', 409, 'This policy is already archived.');

            DB::table('company_policies')->where('id', $id)->update([
                'status' => 'archived',
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'POLICY_ARCHIVED', 'Documents', "Archived policy #{$id}");
        });

        return response()->json([
            'data' => DB::table('company_policies')->where('id', $id)->first(),
        ]);
    }

    public function letters(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('hr_letters')
            ->join('employees', 'hr_letters.employee_id', '=', 'employees.id')
            ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
            ->leftJoin('users as creators', 'hr_letters.created_by_user_id', '=', 'creators.id')
            ->select(
                'hr_letters.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.branch_id',
                'branches.name as branch_name',
                'creators.name as created_by_name'
            )
            ->orderByDesc('hr_letters.id');

        if (!in_array($user->role, ['super_admin', 'hr_admin'], true)) {
            $query->where('hr_letters.employee_id', $user->employee_id ?? 0);
        }

        $letters = $query->get();
        $letters->each(function ($letter) {
            $letter->snapshot = json_decode($letter->snapshot, true, 512, JSON_THROW_ON_ERROR);
        });

        return response()->json(['data' => $letters]);
    }

    public function createLetter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'letter_type' => 'required|in:appointment_details,employment_verification,experience,relieving',
        ]);

        $letterId = DB::transaction(function () use ($validated, $request) {
            $employee = DB::table('employees')
                ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
                ->where('employees.id', $validated['employee_id'])
                ->select(
                    'employees.id',
                    'employees.employee_code',
                    'employees.first_name',
                    'employees.last_name',
                    'employees.designation',
                    'employees.department',
                    'employees.date_of_joining',
                    'branches.name as branch_name'
                )
                ->first();
            abort_if(!$employee, 404);

            $lastWorkingDate = DB::table('employee_lifecycle_cases')
                ->where('employee_id', $employee->id)
                ->where('case_type', 'offboarding')
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->value('effective_date');

            if ($validated['letter_type'] === 'relieving' && !$lastWorkingDate) {
                throw ValidationException::withMessages([
                    'letter_type' => 'A relieving draft requires a completed offboarding clearance case.',
                ]);
            }

            $snapshot = [
                'employee_name' => trim($employee->first_name . ' ' . $employee->last_name),
                'employee_code' => $employee->employee_code,
                'designation' => $employee->designation,
                'department' => $employee->department,
                'branch' => $employee->branch_name,
                'date_of_joining' => $employee->date_of_joining,
                'last_working_date' => $lastWorkingDate,
                'issued_on' => now()->toDateString(),
                'draft_disclaimer' => 'Draft for review only. It is not an executed contract, settlement statement, or proof of payment.',
            ];
            $now = now();
            $id = DB::table('hr_letters')->insertGetId([
                'letter_number' => $this->newLetterNumber(),
                'employee_id' => $employee->id,
                'letter_type' => $validated['letter_type'],
                'status' => 'draft',
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_by_user_id' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            AuditLog::record(
                $request,
                'HR_LETTER_DRAFT_CREATED',
                'Documents',
                "Created {$validated['letter_type']} draft {$id} for {$employee->employee_code}"
            );

            return $id;
        });

        return response()->json(['data' => $this->loadLetter($letterId)], 201);
    }

    public function reviewEmployeeDocument(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:verified,rejected',
            'review_note' => 'nullable|string|max:2000',
        ]);
        if ($validated['decision'] === 'rejected' && blank($validated['review_note'] ?? null)) {
            throw ValidationException::withMessages([
                'review_note' => 'A note is required when rejecting a document.',
            ]);
        }

        DB::transaction(function () use ($validated, $request, $id) {
            $document = DB::table('hrms_uploads')
                ->where('id', $id)
                ->where('module', 'employee_documents')
                ->lockForUpdate()
                ->first();
            abort_if(!$document, 404);
            abort_if($document->status !== 'pending', 409, 'Only pending documents can be reviewed.');

            DB::table('hrms_uploads')->where('id', $id)->update([
                'status' => $validated['decision'],
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'EMPLOYEE_DOCUMENT_REVIEWED',
                'Documents',
                "Reviewed employee document #{$id}: {$validated['decision']}"
            );
        });

        return response()->json(['data' => $this->loadEmployeeDocument($id)]);
    }

    private function visibleActiveEmployeeIds($user): array
    {
        if (in_array($user->role, ['super_admin', 'hr_admin'], true)) {
            return DB::table('employees')->where('status', 'active')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $employeeIds = $user->role === 'manager'
            ? RoleAccess::visibleEmployeeIds($user)
            : ($user->employee_id ? [(int) $user->employee_id] : []);

        return DB::table('employees')
            ->whereIn('id', $employeeIds)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function newLetterNumber(): string
    {
        do {
            $number = 'HRL-' . now()->format('Y') . '-' . Str::upper(Str::random(10));
        } while (DB::table('hr_letters')->where('letter_number', $number)->exists());

        return $number;
    }

    private function loadLetter(int $id): object
    {
        $letter = DB::table('hr_letters')
            ->join('employees', 'hr_letters.employee_id', '=', 'employees.id')
            ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
            ->leftJoin('users as creators', 'hr_letters.created_by_user_id', '=', 'creators.id')
            ->select(
                'hr_letters.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.branch_id',
                'branches.name as branch_name',
                'creators.name as created_by_name'
            )
            ->where('hr_letters.id', $id)
            ->first();
        abort_if(!$letter, 404);
        $letter->snapshot = json_decode($letter->snapshot, true, 512, JSON_THROW_ON_ERROR);

        return $letter;
    }

    private function loadEmployeeDocument(int $id): object
    {
        $document = DB::table('hrms_uploads')
            ->leftJoin('employees', 'hrms_uploads.employee_id', '=', 'employees.id')
            ->leftJoin('users as reviewers', 'hrms_uploads.reviewed_by_user_id', '=', 'reviewers.id')
            ->where('hrms_uploads.id', $id)
            ->where('hrms_uploads.module', 'employee_documents')
            ->select(
                'hrms_uploads.id',
                'hrms_uploads.employee_id',
                'hrms_uploads.document_type',
                'hrms_uploads.original_filename',
                'hrms_uploads.mime_type',
                'hrms_uploads.file_size',
                'hrms_uploads.status',
                'hrms_uploads.reviewed_at',
                'hrms_uploads.review_note',
                'reviewers.name as reviewed_by_name',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code'
            )
            ->first();
        abort_if(!$document, 404);

        return $document;
    }
}
