<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\EmployeeAccountProvisioner;
use App\Support\EmployeeAccountSetupNotifier;
use App\Support\EmployeeCodeGenerator;
use App\Support\EmployeeConstants;
use App\Support\EmployeeOrganization;
use App\Support\ProbationStatus;
use App\Support\RoleAccess;
use App\Support\AuditLog;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive', 'manager', 'auditor'], true)) {
            abort(403);
        }

        $query = $this->employeeProfileQuery();
        $columns = $user->role === 'manager'
            ? $this->managerDirectoryColumns()
            : $this->employeeProfileColumns();

        if ($user->role === 'manager') {
            $query->whereIn('employees.id', RoleAccess::visibleEmployeeIds($user, false));
        }

        if ($user->role === 'super_admin') {
            $query->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
            $columns = array_merge($columns, [
                'employee_statutory_details.pan',
                'employee_statutory_details.uan',
                'employee_statutory_details.pf_account_no',
                'employee_statutory_details.pt_state',
                'employee_statutory_details.tax_regime',
                'employee_statutory_details.pf_applicable',
                'employee_statutory_details.esi_applicable',
            ], $this->compensationColumns());
        } elseif ($user->role === 'hr_admin') {
            $query->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
            $columns = array_merge($columns, [
                'employee_statutory_details.pan',
                'employee_statutory_details.uan',
                'employee_statutory_details.pt_state',
                'employee_statutory_details.tax_regime',
                'employee_statutory_details.pf_applicable',
                'employee_statutory_details.esi_applicable',
            ], $this->compensationColumns());
        } elseif (in_array($user->role, ['hr_executive', 'auditor'], true)) {
            $query->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
            $columns = array_merge($columns, [
                'employee_statutory_details.pan',
                'employee_statutory_details.uan',
                'employee_statutory_details.pf_account_no',
                'employee_statutory_details.pt_state',
                'employee_statutory_details.tax_regime',
                'employee_statutory_details.pf_applicable',
                'employee_statutory_details.esi_applicable',
                'employee_statutory_details.bank_name',
                'employee_statutory_details.account_number',
                'employee_statutory_details.ifsc_code',
            ]);
        }

        $employees = $query->select($columns)
            ->get()
            ->map(fn (object $employee): object => $this->appendProfileFields($employee));

        return response()->json([
            'data' => $employees,
            'meta' => ['total' => $employees->count()]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!in_array($request->user()->role, ['super_admin', 'hr_admin'], true)) {
            abort(403);
        }

        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => strtolower(trim($email))]);
        }

        $assignableRoles = EmployeeAccountProvisioner::assignableRoles();
        if ($request->user()->role === 'hr_admin') {
            $assignableRoles = array_values(array_intersect($assignableRoles, [
                'employee',
                'manager',
                'hr_executive',
                'recruiter',
            ]));
        }

        if (config('mail.default') === 'log') {
            return response()->json([
                'message' => 'Email delivery is not configured. Configure SMTP before creating employee accounts.',
            ], 503);
        }

        $validated = $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => ['required', 'email', 'unique:employees,email', 'unique:users,email'],
            'role' => ['sometimes', 'required', 'string', Rule::in($assignableRoles)],
            'phone' => ['nullable', 'digits:10'],
            'gender' => ['nullable', Rule::in(EmployeeConstants::GENDERS)],
            'date_of_birth' => 'nullable|date',
            'branch_id' => 'required|exists:branches,id',
            'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'designation' => ['required_without:designation_id', 'nullable', 'string', 'max:255'],
            'department' => ['required_without:department_id', 'nullable', 'string', 'max:255'],
            'date_of_joining' => 'required|date',
            'pan' => 'nullable|string|max:10',
            'uan' => 'nullable|string|max:12',
            'tax_regime' => 'nullable|in:New,Old',
            'ctc_annual' => 'nullable|numeric|min:0',
            'gross_monthly' => 'nullable|numeric|min:0',
            'basic_monthly' => 'nullable|numeric|min:0',
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'ifsc_code' => 'nullable|string|max:15',
        ]);

        $role = $validated['role'] ?? 'employee';
        try {
            $result = DB::transaction(function () use ($validated, $request, $role) {
            $organization = EmployeeOrganization::resolve(
                isset($validated['department_id']) ? (int) $validated['department_id'] : null,
                isset($validated['designation_id']) ? (int) $validated['designation_id'] : null,
                $validated['department'] ?? null,
                $validated['designation'] ?? null
            );
            $code = app(EmployeeCodeGenerator::class)->next();
            $branchId = $validated['branch_id'];

            $empId = DB::table('employees')->insertGetId([
                'employee_code' => $code,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $request->input('phone'),
                'gender' => $validated['gender'] ?? EmployeeConstants::GENDER_OTHER,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'designation' => $organization['designation'],
                'designation_id' => $organization['designation_id'],
                'department' => $organization['department'],
                'department_id' => $organization['department_id'],
                'branch_id' => $branchId,
                'date_of_joining' => $validated['date_of_joining'],
                'employment_type' => 'Full-Time Permanent',
                'probation_status' => ProbationStatus::fromJoiningDate($validated['date_of_joining']),
                'status' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(EmployeeAccountProvisioner::class)->provision(
                $empId,
                trim($validated['first_name'] . ' ' . $validated['last_name']),
                $validated['email'],
                $role
            );

            $user = User::where('employee_id', $empId)->firstOrFail();
            app(EmployeeAccountSetupNotifier::class)->send($user, $code, $role);

            // Statutory details
            DB::table('employee_statutory_details')->insert([
                'employee_id' => $empId,
                'pan' => $validated['pan'] ?? null,
                'uan' => $validated['uan'] ?? null,
                'pf_applicable' => $request->boolean('pf_applicable', true),
                'esi_applicable' => $request->boolean('esi_applicable', false),
                'pt_state' => $request->input('pt_state', 'Uttar Pradesh'),
                'tax_regime' => $validated['tax_regime'] ?? 'New',
                'ctc_annual' => (float) ($validated['ctc_annual'] ?? 0),
                'gross_monthly' => (float) ($validated['gross_monthly'] ?? 0),
                'basic_monthly' => (float) ($validated['basic_monthly'] ?? 0),
                'bank_name' => $validated['bank_name'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'ifsc_code' => $validated['ifsc_code'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('leave_balances')->insert([
                'employee_id' => $empId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'EMPLOYEE_ADDED',
                'Employees',
                "Added employee {$code} ({$validated['first_name']} {$validated['last_name']}) with role {$role}"
            );

            $employeeQuery = $this->employeeProfileQuery()
                ->where('employees.id', $empId);
            $employeeColumns = $this->employeeProfileColumns();

            if ($request->user()->role === 'super_admin') {
                $employeeQuery->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
                $employeeColumns = array_merge($employeeColumns, [
                    'employee_statutory_details.pan',
                    'employee_statutory_details.uan',
                    'employee_statutory_details.pt_state',
                    'employee_statutory_details.tax_regime',
                    'employee_statutory_details.pf_applicable',
                    'employee_statutory_details.esi_applicable',
                ], $this->compensationColumns());
            } else {
                $employeeQuery->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
                $employeeColumns = array_merge($employeeColumns, [
                    'employee_statutory_details.pan',
                    'employee_statutory_details.uan',
                    'employee_statutory_details.pt_state',
                    'employee_statutory_details.tax_regime',
                    'employee_statutory_details.pf_applicable',
                    'employee_statutory_details.esi_applicable',
                ]);
            }

            $employee = $this->appendProfileFields($employeeQuery->select($employeeColumns)->first());

            return [
                'employee' => $employee,
                'role' => $role,
                'duplicate_phone_warning' => !empty($validated['phone'])
                    && DB::table('employees')
                        ->where('phone', $validated['phone'])
                        ->where('id', '<>', $empId)
                        ->exists(),
            ];
            });
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return response()->json([
                'message' => 'Employee account was not created because its setup email could not be sent. Check SMTP settings and try again.',
            ], 503);
        }

        return response()->json([
            'data' => $result['employee'],
            'credentials' => [
                'login_identifier' => $result['employee']->email,
                'employee_code' => $result['employee']->employee_code,
                'role' => $result['role'],
                'setup_email_sent' => true,
                'setup_link_expires_in_minutes' => (int) config('auth.passwords.users.expire'),
                'must_change_password' => true,
            ],
            'warnings' => $result['duplicate_phone_warning']
                ? ['This phone number is already used by another employee. Confirm it is intentional.']
                : [],
        ], 201);
    }

    public function updateHierarchy(Request $request, int $employeeId): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'hr_admin'], true), 403);

        $assignableRoles = EmployeeAccountProvisioner::assignableRoles();
        if ($user->role === 'hr_admin') {
            $assignableRoles = array_values(array_intersect($assignableRoles, [
                'employee',
                'manager',
                'hr_executive',
                'recruiter',
            ]));
        }

        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();
        abort_if(!$employee, 404);
        $targetUser = DB::table('users')->where('employee_id', $employeeId)->first();
        $currentRole = $targetUser?->role;
        if ($currentRole) {
            $assignableRoles[] = $currentRole;
        }

        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'reporting_manager_id' => ['present', 'nullable', 'integer', 'exists:employees,id', Rule::notIn([$employeeId])],
            'role' => ['required', 'string', Rule::in($assignableRoles)],
            'version' => ['required', 'integer', 'min:1'],
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('employees', 'email')->ignore($employeeId),
                Rule::unique('users', 'email')->ignore($targetUser?->id ?? 0),
            ],
            'phone' => ['sometimes', 'nullable', 'digits:10'],
            'gender' => ['sometimes', 'required', Rule::in(EmployeeConstants::GENDERS)],
            'date_of_birth' => 'sometimes|nullable|date',
            'branch_id' => 'sometimes|required|integer|exists:branches,id',
            'department_id' => 'sometimes|nullable|integer|exists:departments,id',
            'designation_id' => 'sometimes|nullable|integer|exists:designations,id',
            'department' => 'sometimes|required_without:department_id|string|max:255',
            'designation' => 'sometimes|required_without:designation_id|string|max:255',
            'date_of_joining' => 'sometimes|required|date',
            'employment_type' => 'sometimes|required|string|max:100',
        ]);

        if ((int) $employee->version !== (int) $validated['version']) {
            return response()->json([
                'message' => 'This employee profile changed since it was opened. Reload it and try again.',
                'code' => 'STALE_EMPLOYEE_PROFILE',
            ], 409);
        }

        $managerId = $validated['reporting_manager_id'] ?? null;
        $profileUpdates = array_intersect_key($validated, array_flip([
            'first_name',
            'last_name',
            'email',
            'phone',
            'gender',
            'date_of_birth',
            'branch_id',
            'date_of_joining',
            'employment_type',
        ]));
        if (array_key_exists('department', $validated) || array_key_exists('department_id', $validated)
            || array_key_exists('designation', $validated) || array_key_exists('designation_id', $validated)) {
            $organization = EmployeeOrganization::resolve(
                isset($validated['department_id']) ? (int) $validated['department_id'] : null,
                isset($validated['designation_id']) ? (int) $validated['designation_id'] : null,
                $validated['department'] ?? $employee->department,
                $validated['designation'] ?? $employee->designation
            );
            $profileUpdates = array_merge($profileUpdates, $organization);
        }
        if (array_key_exists('date_of_joining', $profileUpdates)) {
            $profileUpdates['probation_status'] = ProbationStatus::fromJoiningDate($profileUpdates['date_of_joining']);
        }

        if ($managerId !== null) {
            if ((int) $managerId === $employeeId || $this->managerChainContains((int) $managerId, $employeeId)) {
                return response()->json([
                    'message' => 'The reporting manager would create an invalid reporting hierarchy.',
                    'errors' => ['reporting_manager_id' => ['Choose a manager outside this employee reporting chain.']],
                ], 422);
            }

            $manager = DB::table('employees')
                ->join('users', 'users.employee_id', '=', 'employees.id')
                ->where('employees.id', $managerId)
                ->where('employees.status', 'active')
                ->whereNull('employees.deleted_at')
                ->whereIn('users.role', ['manager', 'hr_admin', 'hr_executive', 'super_admin'])
                ->first(['employees.id', 'employees.branch_id']);

            if (!$manager) {
                return response()->json([
                    'message' => 'The selected reporting manager must be an active manager, HR, or admin account.',
                    'errors' => ['reporting_manager_id' => ['Choose an eligible active account.']],
                ], 422);
            }

            if (
                !config('hrms.allow_cross_branch_managers')
                && (int) $manager->branch_id !== (int) ($profileUpdates['branch_id'] ?? $employee->branch_id)
                && (
                    (int) $managerId !== (int) $employee->reporting_manager_id
                    || (int) ($profileUpdates['branch_id'] ?? $employee->branch_id) !== (int) $employee->branch_id
                )
            ) {
                return response()->json([
                    'message' => 'Reporting managers must be in the same branch.',
                    'errors' => ['reporting_manager_id' => ['Choose an eligible manager from the same branch.']],
                ], 422);
            }
        }

        $savedEmployee = DB::transaction(function () use ($request, $employeeId, $employee, $validated, $managerId, $profileUpdates, $targetUser): ?object {
            if (!$targetUser) {
                abort(409, 'This employee does not have a linked login account.');
            }

            $updated = DB::table('employees')
                ->where('id', $employeeId)
                ->where('version', $validated['version'])
                ->update([
                    ...$profileUpdates,
                    'reporting_manager_id' => $managerId,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if (!$updated) {
                return null;
            }

            $newFirstName = $profileUpdates['first_name'] ?? $employee->first_name;
            $newLastName = $profileUpdates['last_name'] ?? $employee->last_name;
            DB::table('users')->where('id', $targetUser->id)->update([
                'role' => $validated['role'],
                'email' => $profileUpdates['email'] ?? $targetUser->email,
                'name' => trim($newFirstName . ' ' . $newLastName),
                'updated_at' => now(),
            ]);

            $changedProfileFields = [];
            foreach ($profileUpdates as $field => $value) {
                if ((string) ($employee->{$field} ?? '') !== (string) ($value ?? '')) {
                    $changedProfileFields[] = $field;
                }
            }
            if ($changedProfileFields !== []) {
                AuditLog::record(
                    $request,
                    'EMPLOYEE_PROFILE_UPDATED',
                    'Employees',
                    "Updated employee #{$employeeId} profile fields: " . implode(', ', $changedProfileFields)
                );
            }

            AuditLog::record(
                $request,
                'EMPLOYEE_HIERARCHY_UPDATED',
                'Employees',
                "Updated employee #{$employeeId}: manager {$employee->reporting_manager_id} -> {$managerId}; role {$targetUser->role} -> {$validated['role']}"
            );

            $query = $this->employeeProfileQuery()->where('employees.id', $employeeId);
            if (in_array($request->user()->role, ['super_admin', 'hr_admin'], true)) {
                $query->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
                $columns = array_merge($this->employeeProfileColumns(), [
                    'employee_statutory_details.pan',
                    'employee_statutory_details.uan',
                    'employee_statutory_details.pf_account_no',
                    'employee_statutory_details.pt_state',
                    'employee_statutory_details.tax_regime',
                    'employee_statutory_details.pf_applicable',
                    'employee_statutory_details.esi_applicable',
                ], $this->compensationColumns());
            } else {
                $columns = $this->employeeProfileColumns();
            }

            return $this->appendProfileFields($query->select($columns)->first());
        });

        if (!$savedEmployee) {
            return response()->json([
                'message' => 'This employee profile changed while it was being saved. Reload it and try again.',
                'code' => 'STALE_EMPLOYEE_PROFILE',
            ], 409);
        }

        $duplicatePhoneWarning = !empty($savedEmployee->phone) && DB::table('employees')
            ->where('phone', $savedEmployee->phone)
            ->where('id', '<>', $employeeId)
            ->exists();

        return response()->json([
            'data' => $savedEmployee,
            'warnings' => $duplicatePhoneWarning
                ? ['This phone number is already used by another employee. Confirm it is intentional.']
                : [],
        ]);
    }

    public function updateCompensation(Request $request, int $employeeId): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'hr_admin'], true), 403);

        $validated = $request->validate([
            'ctc_annual' => 'required|numeric|min:0',
            'gross_monthly' => 'required|numeric|min:0',
            'basic_monthly' => 'required|numeric|min:0',
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:34',
            'ifsc_code' => ['nullable', 'string', 'max:15', Rule::regex('/^[A-Z]{4}0[A-Z0-9]{6}$/i')],
        ]);

        if ((float) $validated['basic_monthly'] > (float) $validated['gross_monthly']) {
            throw ValidationException::withMessages([
                'basic_monthly' => 'Basic monthly salary cannot exceed gross monthly salary.',
            ]);
        }
        if (isset($validated['ifsc_code'])) {
            $validated['ifsc_code'] = strtoupper($validated['ifsc_code']);
        }

        $savedEmployee = DB::transaction(function () use ($request, $employeeId, $validated): ?object {
            $employee = DB::table('employees')
                ->where('id', $employeeId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'employee_code']);
            abort_unless($employee, 404, 'Employee not found.');

            $details = DB::table('employee_statutory_details')
                ->where('employee_id', $employeeId)
                ->lockForUpdate()
                ->first();
            $changedFields = [];
            foreach ($validated as $field => $value) {
                if ((string) ($details->{$field} ?? '') !== (string) ($value ?? '')) {
                    $changedFields[] = $field;
                }
            }

            if ($details) {
                DB::table('employee_statutory_details')
                    ->where('employee_id', $employeeId)
                    ->update([...$validated, 'updated_at' => now()]);
            } else {
                DB::table('employee_statutory_details')->insert([
                    'employee_id' => $employeeId,
                    ...$validated,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($changedFields !== []) {
                AuditLog::record(
                    $request,
                    'EMPLOYEE_COMPENSATION_UPDATED',
                    'Employees',
                    "Updated compensation and bank fields for {$employee->employee_code}: " . implode(', ', $changedFields)
                );
            }

            $query = $this->employeeProfileQuery()
                ->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id')
                ->where('employees.id', $employeeId);

            return $this->appendProfileFields($query
                ->select(array_merge($this->employeeProfileColumns(), [
                    'employee_statutory_details.pan',
                    'employee_statutory_details.uan',
                    'employee_statutory_details.pf_account_no',
                    'employee_statutory_details.pt_state',
                    'employee_statutory_details.tax_regime',
                    'employee_statutory_details.pf_applicable',
                    'employee_statutory_details.esi_applicable',
                ], $this->compensationColumns()))
                ->first());
        });

        return response()->json(['data' => $savedEmployee]);
    }

    public function updateStatutory(Request $request, int $employeeId): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'hr_admin'], true), 403);

        $validated = $request->validate([
            'pan' => ['nullable', 'string', 'max:10', Rule::regex('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/i')],
            'uan' => ['nullable', 'digits:12'],
            'pf_account_no' => 'nullable|string|max:50',
            'pf_applicable' => 'required|boolean',
            'esi_applicable' => 'required|boolean',
            'pt_state' => 'required|string|max:100',
            'tax_regime' => ['required', Rule::in(['New', 'Old'])],
        ]);

        if (isset($validated['pan'])) {
            $validated['pan'] = strtoupper($validated['pan']);
        }

        $savedEmployee = DB::transaction(function () use ($request, $employeeId, $validated): ?object {
            $employee = DB::table('employees')
                ->where('id', $employeeId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'employee_code']);
            abort_unless($employee, 404, 'Employee not found.');

            $details = DB::table('employee_statutory_details')
                ->where('employee_id', $employeeId)
                ->lockForUpdate()
                ->first();

            if ($details) {
                DB::table('employee_statutory_details')
                    ->where('employee_id', $employeeId)
                    ->update([...$validated, 'updated_at' => now()]);
            } else {
                DB::table('employee_statutory_details')->insert([
                    'employee_id' => $employeeId,
                    ...$validated,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            AuditLog::record(
                $request,
                'EMPLOYEE_STATUTORY_UPDATED',
                'Employees',
                "Updated statutory compliance details for {$employee->employee_code}"
            );

            $query = $this->employeeProfileQuery()
                ->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id')
                ->where('employees.id', $employeeId);

            return $this->appendProfileFields($query
                ->select(array_merge($this->employeeProfileColumns(), [
                    'employee_statutory_details.pan',
                    'employee_statutory_details.uan',
                    'employee_statutory_details.pf_account_no',
                    'employee_statutory_details.pt_state',
                    'employee_statutory_details.tax_regime',
                    'employee_statutory_details.pf_applicable',
                    'employee_statutory_details.esi_applicable',
                ], $this->compensationColumns()))
                ->first());
        });

        return response()->json(['data' => $savedEmployee]);
    }

    public function destroy(Request $request, int $employeeId): JsonResponse
    {
        $actor = $request->user();
        if ((int) $actor->employee_id === $employeeId) {
            abort(422, 'You cannot archive your own employee record.');
        }

        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();
        abort_if(!$employee, 404);

        $targetUser = DB::table('users')->where('employee_id', $employeeId)->first();
        if ($actor->role === 'hr_admin' && $targetUser && !in_array($targetUser->role, [
            'employee',
            'manager',
            'hr_executive',
            'recruiter',
        ], true)) {
            abort(403, 'HR Admin cannot archive this system role.');
        }

        if ($targetUser?->role === 'super_admin') {
            $activeSuperAdmins = DB::table('users')
                ->where('role', 'super_admin')
                ->where(function ($query) {
                    $query->whereNull('employee_id')
                        ->orWhereIn('employee_id', DB::table('employees')
                            ->select('id')
                            ->whereNull('deleted_at'));
                })
                ->count();
            if ($activeSuperAdmins <= 1) {
                abort(409, 'At least one active Super Admin account must remain.');
            }
        }

        $validated = $request->validate([
            'reporting_manager_id' => ['nullable', 'integer', 'exists:employees,id'],
            'reassign_project_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'cancel_pending_requests' => ['sometimes', 'boolean'],
        ]);
        $directReports = DB::table('employees')
            ->where('reporting_manager_id', $employeeId)
            ->whereNull('deleted_at')
            ->get(['id', 'branch_id']);
        $openProjects = DB::table('project_assignments')
            ->where('employee_id', $employeeId)
            ->where('status', '<>', 'completed')
            ->get(['id']);
        $pendingLeaveCount = DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('status', 'pending')
            ->count();
        $pendingRegularizationCount = DB::table('attendance_regularization_requests')
            ->where('employee_id', $employeeId)
            ->where('status', 'pending')
            ->count();

        $requiredReassignments = [];
        if ($directReports->isNotEmpty() && empty($validated['reporting_manager_id'])) {
            $requiredReassignments['reporting_manager_id'] = ['Select an active manager to receive this employee\'s direct reports.'];
        }
        if ($openProjects->isNotEmpty() && empty($validated['reassign_project_employee_id'])) {
            $requiredReassignments['reassign_project_employee_id'] = ['Select an active employee to receive this employee\'s open projects.'];
        }
        if (($pendingLeaveCount + $pendingRegularizationCount) > 0 && empty($validated['cancel_pending_requests'])) {
            $requiredReassignments['cancel_pending_requests'] = [
                'Confirm cancellation of the employee’s pending leave and attendance requests before archiving.',
            ];
        }
        if ($requiredReassignments !== []) {
            throw ValidationException::withMessages($requiredReassignments);
        }

        $replacementManagerId = $validated['reporting_manager_id'] ?? null;
        if ($replacementManagerId !== null) {
            $replacementManager = DB::table('employees')
                ->join('users', 'users.employee_id', '=', 'employees.id')
                ->where('employees.id', $replacementManagerId)
                ->where('employees.status', 'active')
                ->whereNull('employees.deleted_at')
                ->whereIn('users.role', ['manager', 'hr_admin', 'hr_executive', 'super_admin'])
                ->first(['employees.id', 'employees.branch_id']);
            if (!$replacementManager
                || (int) $replacementManagerId === $employeeId
                || $this->managerChainContains((int) $replacementManagerId, $employeeId)) {
                throw ValidationException::withMessages([
                    'reporting_manager_id' => ['Choose an active manager who is not this employee or one of their direct reports.'],
                ]);
            }
            if (!config('hrms.allow_cross_branch_managers') && $directReports->contains(
                fn (object $report): bool => (int) $report->branch_id !== (int) $replacementManager->branch_id
            )) {
                throw ValidationException::withMessages([
                    'reporting_manager_id' => ['The replacement manager must share a branch with every direct report.'],
                ]);
            }
        }

        $replacementEmployeeId = $validated['reassign_project_employee_id'] ?? null;
        if ($replacementEmployeeId !== null) {
            $replacementEmployee = DB::table('employees')
                ->where('id', $replacementEmployeeId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->exists();
            if (!$replacementEmployee || (int) $replacementEmployeeId === $employeeId) {
                throw ValidationException::withMessages([
                    'reassign_project_employee_id' => ['Choose another active employee for the open projects.'],
                ]);
            }
        }

        DB::transaction(function () use (
            $request,
            $employeeId,
            $targetUser,
            $replacementManagerId,
            $replacementEmployeeId,
            $directReports,
            $openProjects,
            $pendingLeaveCount,
            $pendingRegularizationCount
        ): void {
            if ($replacementManagerId !== null && $directReports->isNotEmpty()) {
                DB::table('employees')
                    ->whereIn('id', $directReports->pluck('id'))
                    ->update([
                        'reporting_manager_id' => $replacementManagerId,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
            }
            if ($replacementEmployeeId !== null && $openProjects->isNotEmpty()) {
                DB::table('project_assignments')
                    ->whereIn('id', $openProjects->pluck('id'))
                    ->update([
                        'employee_id' => $replacementEmployeeId,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
            }
            if ($pendingLeaveCount > 0) {
                DB::table('leave_requests')
                    ->where('employee_id', $employeeId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'approval_stage' => 'Cancelled',
                        'next_approver_role' => null,
                        'review_remarks' => 'Cancelled because the employee was archived.',
                        'updated_at' => now(),
                    ]);
            }
            if ($pendingRegularizationCount > 0) {
                DB::table('attendance_regularization_requests')
                    ->where('employee_id', $employeeId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'rejected',
                        'approved_by' => 'Employee archived',
                        'approved_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $updated = DB::table('employees')
                ->where('id', $employeeId)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'inactive',
                    'deleted_at' => now(),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            if (!$updated) {
                abort(409, 'This employee was already archived.');
            }

            if ($targetUser) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $targetUser->id)
                    ->delete();
            }

            AuditLog::record(
                $request,
                'EMPLOYEE_ARCHIVED',
                'Employees',
                "Archived employee #{$employeeId}; reassigned {$directReports->count()} direct reports and {$openProjects->count()} open projects; cancelled {$pendingLeaveCount} leave and {$pendingRegularizationCount} attendance requests"
            );
        });

        return response()->json(['message' => 'Employee archived successfully.']);
    }

    public function archiveImpact(Request $request, int $employeeId): JsonResponse
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, ['super_admin', 'hr_admin'], true), 403);
        if ((int) $actor->employee_id === $employeeId) {
            abort(422, 'You cannot archive your own employee record.');
        }

        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();
        abort_unless($employee, 404);

        $directReports = DB::table('employees')
            ->where('reporting_manager_id', $employeeId)
            ->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'branch_id']);
        $eligibleManagers = DB::table('employees')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->where('employees.status', 'active')
            ->whereNull('employees.deleted_at')
            ->whereIn('users.role', ['manager', 'hr_admin', 'hr_executive', 'super_admin'])
            ->get(['employees.id', 'employees.first_name', 'employees.last_name', 'employees.employee_code', 'employees.branch_id'])
            ->filter(fn (object $candidate): bool => (
                !$this->managerChainContains((int) $candidate->id, $employeeId)
                && (
                    config('hrms.allow_cross_branch_managers')
                    || $directReports->every(fn (object $report): bool => (int) $report->branch_id === (int) $candidate->branch_id)
                )
            ))
            ->values();
        $openProjects = DB::table('project_assignments')
            ->join('projects', 'projects.id', '=', 'project_assignments.project_id')
            ->where('project_assignments.employee_id', $employeeId)
            ->where('project_assignments.status', '<>', 'completed')
            ->get(['project_assignments.id', 'projects.name', 'project_assignments.status']);

        return response()->json([
            'data' => [
                'direct_reports' => $directReports,
                'eligible_managers' => $eligibleManagers,
                'open_projects' => $openProjects,
                'allocated_assets' => DB::table('company_assets')
                    ->where('assigned_employee_id', $employeeId)
                    ->where('status', 'Allocated')
                    ->get(['id', 'name', 'asset_code']),
                'pending_leave_requests' => DB::table('leave_requests')
                    ->where('employee_id', $employeeId)
                    ->where('status', 'pending')
                    ->count(),
                'pending_attendance_requests' => DB::table('attendance_regularization_requests')
                    ->where('employee_id', $employeeId)
                    ->where('status', 'pending')
                    ->count(),
            ],
        ]);
    }

    public function updateLeadAccess(Request $request, int $employeeId): JsonResponse
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, ['super_admin', 'hr_admin', 'hr_executive'], true), 403, 'Unauthorized to manage employee lead access.');

        $validated = $request->validate([
            'has_lead_access' => 'required|boolean',
        ]);

        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();

        abort_unless($employee, 404, 'Employee not found.');

        DB::table('employees')
            ->where('id', $employeeId)
            ->update([
                'has_lead_access' => $validated['has_lead_access'] ? 1 : 0,
                'updated_at' => now(),
            ]);

        AuditLog::record(
            $request,
            'EMPLOYEE_LEAD_ACCESS_UPDATED',
            'Lead Management',
            ($validated['has_lead_access'] ? 'Granted' : 'Revoked') . " lead access for employee {$employee->first_name} {$employee->last_name} (#{$employeeId})"
        );

        return response()->json([
            'message' => 'Employee lead access updated successfully.',
            'data' => [
                'employee_id' => $employeeId,
                'has_lead_access' => (bool) $validated['has_lead_access'],
            ],
        ]);
    }

    private function employeeProfileQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('employees')
            ->whereNull('employees.deleted_at')
            ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
            ->leftJoin('employees as reporting_managers', 'employees.reporting_manager_id', '=', 'reporting_managers.id')
            ->leftJoin('users as employee_users', 'employee_users.employee_id', '=', 'employees.id');
    }

    private function employeeProfileColumns(): array
    {
        return [
            'employees.*',
            'branches.name as branch_name',
            'reporting_managers.first_name as reporting_manager_first_name',
            'reporting_managers.last_name as reporting_manager_last_name',
            'employee_users.role as role',
        ];
    }

    private function compensationColumns(): array
    {
        return [
            'employee_statutory_details.ctc_annual',
            'employee_statutory_details.gross_monthly',
            'employee_statutory_details.basic_monthly',
            'employee_statutory_details.bank_name',
            'employee_statutory_details.account_number',
            'employee_statutory_details.ifsc_code',
        ];
    }

    private function managerDirectoryColumns(): array
    {
        return [
            'employees.id',
            'employees.employee_code',
            'employees.first_name',
            'employees.last_name',
            'employees.designation',
            'employees.designation_id',
            'employees.department',
            'employees.department_id',
            'employees.branch_id',
            'employees.reporting_manager_id',
            'employees.date_of_joining',
            'employees.employment_type',
            'employees.probation_status',
            'employees.status',
            'employees.has_lead_access',
            'employees.version',
            'branches.name as branch_name',
            'reporting_managers.first_name as reporting_manager_first_name',
            'reporting_managers.last_name as reporting_manager_last_name',
            'employee_users.role as role',
        ];
    }

    private function appendProfileFields(?object $employee): ?object
    {
        if (!$employee) {
            return null;
        }

        $employee->reporting_manager_name = $employee->reporting_manager_id === null
            ? null
            : trim(($employee->reporting_manager_first_name ?? '') . ' ' . ($employee->reporting_manager_last_name ?? ''));
        $employee->allow_cross_branch_managers = (bool) config('hrms.allow_cross_branch_managers');
        $employee->probation_status = ProbationStatus::fromJoiningDate($employee->date_of_joining);
        $employee->has_lead_access = (bool) ($employee->has_lead_access ?? false);

        unset($employee->reporting_manager_first_name, $employee->reporting_manager_last_name);

        return $employee;
    }

    private function managerChainContains(int $managerId, int $employeeId): bool
    {
        $visited = [];
        $currentId = $managerId;

        while ($currentId > 0) {
            if ($currentId === $employeeId || isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $currentId = (int) DB::table('employees')
                ->where('id', $currentId)
                ->value('reporting_manager_id');
        }

        return false;
    }
}
