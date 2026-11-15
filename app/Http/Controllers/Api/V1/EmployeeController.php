<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\EmployeeAccountProvisioner;
use App\Support\RoleAccess;
use App\Support\AuditLog;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive', 'manager', 'auditor'], true)) {
            abort(403);
        }

        $query = DB::table('employees')
            ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id');
        $columns = ['employees.*', 'branches.name as branch_name'];

        if ($user->role === 'manager') {
            $query->whereIn('employees.id', RoleAccess::visibleEmployeeIds($user, false));
        }

        if ($user->role === 'super_admin') {
            $query->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
            $columns = array_merge($columns, [
                'employee_statutory_details.pan',
                'employee_statutory_details.uan',
                'employee_statutory_details.pt_state',
                'employee_statutory_details.tax_regime',
                'employee_statutory_details.pf_applicable',
                'employee_statutory_details.esi_applicable',
                'employee_statutory_details.gross_monthly',
                'employee_statutory_details.basic_monthly',
                'employee_statutory_details.bank_name',
                'employee_statutory_details.account_number',
                'employee_statutory_details.ifsc_code',
            ]);
        } elseif (in_array($user->role, ['hr_admin', 'hr_executive'], true)) {
            $query->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
            $columns = array_merge($columns, [
                'employee_statutory_details.pan',
                'employee_statutory_details.uan',
                'employee_statutory_details.pt_state',
                'employee_statutory_details.tax_regime',
                'employee_statutory_details.pf_applicable',
                'employee_statutory_details.esi_applicable',
            ]);
        }

        $employees = $query->select($columns)->get();

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

        $validated = $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => ['required', 'email', 'unique:employees,email', 'unique:users,email'],
            'role' => ['sometimes', 'required', 'string', Rule::in($assignableRoles)],
            'phone' => ['nullable', 'digits:10'],
            'gender' => 'nullable|in:Male,Female,Other',
            'date_of_birth' => 'nullable|date',
            'branch_id' => 'required|exists:branches,id',
            'designation' => 'required|string|max:255',
            'department' => 'required|string|max:255',
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
        $result = DB::transaction(function () use ($validated, $request, $role) {
            $count = DB::table('employees')->count();
            $code = 'EMP-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
            $branchId = $validated['branch_id'];

            $empId = DB::table('employees')->insertGetId([
                'employee_code' => $code,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $request->input('phone'),
                'gender' => $validated['gender'] ?? 'Other',
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'designation' => $validated['designation'],
                'department' => $validated['department'],
                'branch_id' => $branchId,
                'date_of_joining' => $validated['date_of_joining'],
                'employment_type' => 'Full-Time Permanent',
                'probation_status' => 'Probation (6 Mo)',
                'status' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $temporaryPassword = app(EmployeeAccountProvisioner::class)->provision(
                $empId,
                trim($validated['first_name'] . ' ' . $validated['last_name']),
                $validated['email'],
                $role
            );

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

            $employeeQuery = DB::table('employees')
                ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
                ->where('employees.id', $empId);
            $employeeColumns = ['employees.*', 'branches.name as branch_name'];

            if ($request->user()->role === 'super_admin') {
                $employeeQuery->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id');
                $employeeColumns = array_merge($employeeColumns, [
                    'employee_statutory_details.pan',
                    'employee_statutory_details.uan',
                    'employee_statutory_details.pt_state',
                    'employee_statutory_details.tax_regime',
                    'employee_statutory_details.pf_applicable',
                    'employee_statutory_details.esi_applicable',
                    'employee_statutory_details.gross_monthly',
                    'employee_statutory_details.basic_monthly',
                    'employee_statutory_details.bank_name',
                    'employee_statutory_details.account_number',
                    'employee_statutory_details.ifsc_code',
                ]);
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

            $employee = $employeeQuery->select($employeeColumns)->first();

            return [
                'employee' => $employee,
                'role' => $role,
                'temporary_password' => $temporaryPassword,
            ];
        });

        return response()->json([
            'data' => $result['employee'],
            'credentials' => [
                'login_identifier' => $result['employee']->email,
                'employee_code' => $result['employee']->employee_code,
                'role' => $result['role'],
                'temporary_password' => $result['temporary_password'],
                'must_change_password' => false,
            ],
        ], 201);
    }
}
