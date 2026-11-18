<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function company(): JsonResponse
    {
        $company = DB::table('companies')->first();
        if ($company) {
            $company->currency_symbol = ['INR' => '₹', 'USD' => '$', 'EUR' => '€'][$company->currency] ?? $company->currency;
        }

        return response()->json(['data' => $company]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $company = DB::table('companies')->first();
        abort_unless($company, 404, 'Company profile not found.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'],
            'cin' => ['nullable', 'string', 'max:30'],
            'pan' => ['required', 'string', 'max:10'],
            'tan' => ['nullable', 'string', 'max:10'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'pf_code' => ['nullable', 'string', 'max:100'],
            'esi_code' => ['nullable', 'string', 'max:100'],
            'registered_state' => ['required', 'string', 'max:100'],
            'headquarters' => ['required', 'string', 'max:500'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30'],
        ]);

        DB::transaction(function () use ($request, $company, $validated): void {
            DB::table('companies')->where('id', $company->id)->update([
                'name' => trim($validated['name']),
                'legal_name' => trim($validated['legal_name']),
                'cin' => isset($validated['cin']) && trim($validated['cin']) !== '' ? strtoupper(trim($validated['cin'])) : null,
                'pan' => strtoupper(trim($validated['pan'])),
                'tan' => isset($validated['tan']) && trim($validated['tan']) !== '' ? strtoupper(trim($validated['tan'])) : null,
                'gstin' => isset($validated['gstin']) && trim($validated['gstin']) !== '' ? strtoupper(trim($validated['gstin'])) : null,
                'pf_code' => isset($validated['pf_code']) && trim($validated['pf_code']) !== '' ? trim($validated['pf_code']) : null,
                'esi_code' => isset($validated['esi_code']) && trim($validated['esi_code']) !== '' ? trim($validated['esi_code']) : null,
                'registered_state' => trim($validated['registered_state']),
                'headquarters' => trim($validated['headquarters']),
                'support_email' => isset($validated['support_email']) && trim($validated['support_email']) !== '' ? strtolower(trim($validated['support_email'])) : null,
                'contact_number' => isset($validated['contact_number']) && trim($validated['contact_number']) !== '' ? trim($validated['contact_number']) : null,
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'COMPANY_UPDATED',
                'Organization',
                "Updated company profile ({$validated['legal_name']})"
            );
        });

        return $this->company();
    }

    public function branches(): JsonResponse
    {
        $employeeCounts = DB::table('employees')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->select('branch_id')
            ->selectRaw('COUNT(*) as employees_count')
            ->groupBy('branch_id');

        $branches = DB::table('branches')
            ->leftJoinSub($employeeCounts, 'employee_counts', function ($join) {
                $join->on('employee_counts.branch_id', '=', 'branches.id');
            })
            ->select(
                'branches.id',
                'branches.company_id',
                'branches.name',
                'branches.code',
                'branches.city',
                'branches.state',
                'branches.pt_applicable',
                'branches.is_active',
                'branches.created_at',
                'branches.updated_at'
            )
            ->selectRaw('COALESCE(employee_counts.employees_count, 0) as employees_count')
            ->orderBy('branches.id')
            ->get();

        return response()->json(['data' => $branches]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $company = DB::table('companies')->first();
        abort_unless($company, 404, 'Company profile not found.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:branches,code'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'pt_applicable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branchId = DB::transaction(function () use ($request, $company, $validated): int {
            $id = DB::table('branches')->insertGetId([
                'company_id' => $company->id,
                'name' => trim($validated['name']),
                'code' => strtoupper(trim($validated['code'])),
                'city' => trim($validated['city']),
                'state' => trim($validated['state']),
                'pt_applicable' => (bool) ($validated['pt_applicable'] ?? false),
                'employees_count' => 0,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'BRANCH_CREATED',
                'Organization',
                "Created branch {$validated['name']} (" . strtoupper(trim($validated['code'])) . ')'
            );

            return $id;
        });

        $branch = DB::table('branches')->where('id', $branchId)->first();
        return response()->json(['data' => $branch], 201);
    }

    public function updateBranch(Request $request, int $id): JsonResponse
    {
        $branch = DB::table('branches')->where('id', $id)->first();
        abort_unless($branch, 404, 'Branch not found.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')->ignore($id)],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'pt_applicable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['is_active']) && !$validated['is_active']) {
            $activeStaff = DB::table('employees')
                ->where('branch_id', $id)
                ->whereNull('deleted_at')
                ->whereIn('status', ['active', 'on_notice'])
                ->count();
            abort_if($activeStaff > 0, 409, "Cannot deactivate a branch with {$activeStaff} active employee(s). Reassign them first.");
        }

        DB::transaction(function () use ($request, $id, $branch, $validated): void {
            DB::table('branches')->where('id', $id)->update([
                'name' => trim($validated['name']),
                'code' => strtoupper(trim($validated['code'])),
                'city' => trim($validated['city']),
                'state' => trim($validated['state']),
                'pt_applicable' => array_key_exists('pt_applicable', $validated) ? (bool) $validated['pt_applicable'] : (bool) $branch->pt_applicable,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : (bool) $branch->is_active,
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'BRANCH_UPDATED',
                'Organization',
                "Updated branch #{$id} ({$validated['name']})"
            );
        });

        $updated = DB::table('branches')->where('id', $id)->first();
        return response()->json(['data' => $updated]);
    }

    public function departments(): JsonResponse
    {
        $departments = DB::table('departments')->orderBy('id')->get();
        return response()->json(['data' => $departments]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'head' => ['nullable', 'string', 'max:255'],
            'budget_inr' => ['nullable', 'numeric', 'min:0'],
        ]);

        $deptId = DB::transaction(function () use ($request, $validated): int {
            $id = DB::table('departments')->insertGetId([
                'branch_id' => $validated['branch_id'] ?? null,
                'name' => trim($validated['name']),
                'code' => strtoupper(trim($validated['code'])),
                'head' => isset($validated['head']) && trim($validated['head']) !== '' ? trim($validated['head']) : null,
                'budget_inr' => (float) ($validated['budget_inr'] ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'DEPARTMENT_CREATED',
                'Organization',
                "Created department {$validated['name']} (" . strtoupper(trim($validated['code'])) . ')'
            );

            return $id;
        });

        return response()->json(['data' => DB::table('departments')->where('id', $deptId)->first()], 201);
    }

    public function updateDepartment(Request $request, int $id): JsonResponse
    {
        $department = DB::table('departments')->where('id', $id)->first();
        abort_unless($department, 404, 'Department not found.');

        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'head' => ['nullable', 'string', 'max:255'],
            'budget_inr' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $id, $validated): void {
            DB::table('departments')->where('id', $id)->update([
                'branch_id' => $validated['branch_id'] ?? null,
                'name' => trim($validated['name']),
                'code' => strtoupper(trim($validated['code'])),
                'head' => isset($validated['head']) && trim($validated['head']) !== '' ? trim($validated['head']) : null,
                'budget_inr' => (float) ($validated['budget_inr'] ?? 0),
                'updated_at' => now(),
            ]);

            DB::table('employees')
                ->where('department_id', $id)
                ->update(['department' => trim($validated['name']), 'updated_at' => now()]);

            AuditLog::record(
                $request,
                'DEPARTMENT_UPDATED',
                'Organization',
                "Updated department #{$id} ({$validated['name']})"
            );
        });

        return response()->json(['data' => DB::table('departments')->where('id', $id)->first()]);
    }

    public function destroyDepartment(Request $request, int $id): JsonResponse
    {
        $department = DB::table('departments')->where('id', $id)->first();
        abort_unless($department, 404, 'Department not found.');

        $designationCount = DB::table('designations')->where('department_id', $id)->count();
        abort_if($designationCount > 0, 409, "Cannot delete department with {$designationCount} linked designation(s). Remove or move them first.");

        $employeeCount = DB::table('employees')
            ->where('department_id', $id)
            ->whereNull('deleted_at')
            ->count();
        abort_if($employeeCount > 0, 409, "Cannot delete department with {$employeeCount} active employee(s).");

        DB::transaction(function () use ($request, $id, $department): void {
            DB::table('departments')->where('id', $id)->delete();
            AuditLog::record(
                $request,
                'DEPARTMENT_DELETED',
                'Organization',
                "Deleted department #{$id} ({$department->name})"
            );
        });

        return response()->json(['message' => 'Department deleted.']);
    }

    public function designations(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('designations')->orderBy('id')->get(),
        ]);
    }

    public function storeDesignation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'title' => ['required', 'string', 'max:255'],
            'level' => ['required', 'string', 'max:10'],
            'min_experience_years' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $desigId = DB::transaction(function () use ($request, $validated): int {
            $id = DB::table('designations')->insertGetId([
                'department_id' => (int) $validated['department_id'],
                'title' => trim($validated['title']),
                'level' => strtoupper(trim($validated['level'])),
                'min_experience_years' => (int) ($validated['min_experience_years'] ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'DESIGNATION_CREATED',
                'Organization',
                "Created designation {$validated['title']} ({$validated['level']})"
            );

            return $id;
        });

        return response()->json(['data' => DB::table('designations')->where('id', $desigId)->first()], 201);
    }

    public function updateDesignation(Request $request, int $id): JsonResponse
    {
        $designation = DB::table('designations')->where('id', $id)->first();
        abort_unless($designation, 404, 'Designation not found.');

        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'title' => ['required', 'string', 'max:255'],
            'level' => ['required', 'string', 'max:10'],
            'min_experience_years' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        DB::transaction(function () use ($request, $id, $validated): void {
            DB::table('designations')->where('id', $id)->update([
                'department_id' => (int) $validated['department_id'],
                'title' => trim($validated['title']),
                'level' => strtoupper(trim($validated['level'])),
                'min_experience_years' => (int) ($validated['min_experience_years'] ?? 0),
                'updated_at' => now(),
            ]);

            DB::table('employees')
                ->where('designation_id', $id)
                ->update(['designation' => trim($validated['title']), 'updated_at' => now()]);

            AuditLog::record(
                $request,
                'DESIGNATION_UPDATED',
                'Organization',
                "Updated designation #{$id} ({$validated['title']})"
            );
        });

        return response()->json(['data' => DB::table('designations')->where('id', $id)->first()]);
    }

    public function destroyDesignation(Request $request, int $id): JsonResponse
    {
        $designation = DB::table('designations')->where('id', $id)->first();
        abort_unless($designation, 404, 'Designation not found.');

        $employeeCount = DB::table('employees')
            ->where('designation_id', $id)
            ->whereNull('deleted_at')
            ->count();
        abort_if($employeeCount > 0, 409, "Cannot delete designation assigned to {$employeeCount} active employee(s).");

        DB::transaction(function () use ($request, $id, $designation): void {
            DB::table('designations')->where('id', $id)->delete();
            AuditLog::record(
                $request,
                'DESIGNATION_DELETED',
                'Organization',
                "Deleted designation #{$id} ({$designation->title})"
            );
        });

        return response()->json(['message' => 'Designation deleted.']);
    }

    public function holidays(): JsonResponse
    {
        $holidays = DB::table('holidays')->orderBy('date')->get()->map(function ($holiday) {
            return $this->formatHoliday($holiday);
        });

        return response()->json(['data' => $holidays]);
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['National', 'Gazetted', 'Restricted'])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $branchIds = array_values(array_unique(array_map('intval', $validated['branch_ids'] ?? [])));

        $holidayId = DB::transaction(function () use ($request, $validated, $branchIds): int {
            $id = DB::table('holidays')->insertGetId([
                'name' => trim($validated['name']),
                'date' => $validated['date'],
                'type' => $validated['type'],
                'branch_ids' => json_encode($branchIds),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'HOLIDAY_CREATED',
                'Organization',
                "Created holiday {$validated['name']} on {$validated['date']}"
            );

            return $id;
        });

        return response()->json([
            'data' => $this->formatHoliday(DB::table('holidays')->where('id', $holidayId)->first()),
        ], 201);
    }

    public function updateHoliday(Request $request, int $id): JsonResponse
    {
        $holiday = DB::table('holidays')->where('id', $id)->first();
        abort_unless($holiday, 404, 'Holiday not found.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['National', 'Gazetted', 'Restricted'])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $branchIds = array_values(array_unique(array_map('intval', $validated['branch_ids'] ?? [])));

        DB::transaction(function () use ($request, $id, $validated, $branchIds): void {
            DB::table('holidays')->where('id', $id)->update([
                'name' => trim($validated['name']),
                'date' => $validated['date'],
                'type' => $validated['type'],
                'branch_ids' => json_encode($branchIds),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'HOLIDAY_UPDATED',
                'Organization',
                "Updated holiday #{$id} ({$validated['name']} on {$validated['date']})"
            );
        });

        return response()->json([
            'data' => $this->formatHoliday(DB::table('holidays')->where('id', $id)->first()),
        ]);
    }

    public function destroyHoliday(Request $request, int $id): JsonResponse
    {
        $holiday = DB::table('holidays')->where('id', $id)->first();
        abort_unless($holiday, 404, 'Holiday not found.');

        DB::transaction(function () use ($request, $id, $holiday): void {
            DB::table('holidays')->where('id', $id)->delete();
            AuditLog::record(
                $request,
                'HOLIDAY_DELETED',
                'Organization',
                "Deleted holiday #{$id} ({$holiday->name})"
            );
        });

        return response()->json(['message' => 'Holiday deleted.']);
    }

    private function formatHoliday(object $holiday): object
    {
        $branches = is_array($holiday->branch_ids)
            ? $holiday->branch_ids
            : (json_decode($holiday->branch_ids ?? '[]', true) ?: []);
        $holiday->branches = array_map('intval', $branches);

        return $holiday;
    }
}
