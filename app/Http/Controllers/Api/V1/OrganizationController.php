<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function branches(): JsonResponse
    {
        $employeeCounts = DB::table('employees')
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
            ->get();

        return response()->json(['data' => $branches]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:branches,name',
            'code' => 'nullable|string|max:20|unique:branches,code',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'pt_applicable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $companyId = DB::table('companies')->value('id') ?? 1;

        if (empty($validated['code'])) {
            $rawPrefix = preg_replace('/[^A-Za-z0-9]/', '', $validated['city'] ?: $validated['name']);
            $prefix = strtoupper(substr($rawPrefix, 0, 3));
            if (strlen($prefix) < 3) {
                $prefix = str_pad($prefix, 3, 'X');
            }
            $nextSeq = (int) DB::table('branches')->max('id') + 1;
            $code = sprintf('%s-%02d', $prefix, $nextSeq);
            while (DB::table('branches')->where('code', $code)->exists()) {
                $code = sprintf('%s-%02d', $prefix, ++$nextSeq);
            }
            $validated['code'] = $code;
        }

        $branchId = DB::table('branches')->insertGetId([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'city' => $validated['city'],
            'state' => $validated['state'],
            'pt_applicable' => (bool) ($validated['pt_applicable'] ?? false),
            'employees_count' => 0,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branch = DB::table('branches')->where('id', $branchId)->first();
        if ($branch) {
            $branch->employees_count = 0;
        }

        AuditLog::record(
            $request,
            'BRANCH_CREATED',
            'Organization',
            "Created new branch {$branch->name} ({$branch->code}) in {$branch->city}, {$branch->state}"
        );

        return response()->json([
            'data' => $branch,
            'message' => 'Branch created successfully.',
        ], 201);
    }

    public function departments(): JsonResponse
    {
        $departments = DB::table('departments')->get();
        return response()->json(['data' => $departments]);
    }

    public function designations(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('designations')->orderBy('id')->get(),
        ]);
    }

    public function holidays(): JsonResponse
    {
        $holidays = DB::table('holidays')->get()->map(function ($holiday) {
            $branches = is_array($holiday->branch_ids)
                ? $holiday->branch_ids
                : (json_decode($holiday->branch_ids ?? '[]', true) ?: []);
            $holiday->branches = array_map('intval', $branches);

            return $holiday;
        });

        return response()->json(['data' => $holidays]);
    }
}
