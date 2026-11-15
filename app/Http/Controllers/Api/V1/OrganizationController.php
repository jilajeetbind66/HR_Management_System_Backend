<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
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
        $branches = DB::table('branches')
            ->leftJoin('employees', 'branches.id', '=', 'employees.branch_id')
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
            ->selectRaw('COUNT(employees.id) as employees_count')
            ->groupBy('branches.id')
            ->get();
        return response()->json(['data' => $branches]);
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
