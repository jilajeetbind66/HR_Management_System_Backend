<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollStatutoryController extends Controller
{
    private const DEDUCTION_KEYS = [
        'section_80c',
        'section_80d',
        'section_80ccd_1b',
        'hra_exemption',
        'home_loan_interest',
        'other_deductions',
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'contribution_configs' => DB::table('payroll_statutory_configs')
                    ->orderByDesc('effective_from')
                    ->get(),
                'professional_tax_rule_sets' => $this->ruleSetsWithSlabs('payroll_pt_rule_sets', 'payroll_pt_slabs'),
                'income_tax_rule_sets' => $this->ruleSetsWithSlabs('payroll_tax_rule_sets', 'payroll_tax_slabs'),
                'financial_year' => $this->financialYear(now()->year, now()->month),
            ],
        ]);
    }

    public function storeContributionConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'effective_from' => 'required|date',
            'pf_employee_rate' => 'required|numeric|min:0|max:100',
            'pf_employer_rate' => 'required|numeric|min:0|max:100',
            'eps_rate' => 'required|numeric|min:0|max:100',
            'pf_wage_ceiling' => 'required|numeric|min:0|max:99999999',
            'apply_pf_wage_ceiling' => 'required|boolean',
            'esi_employee_rate' => 'required|numeric|min:0|max:100',
            'esi_employer_rate' => 'required|numeric|min:0|max:100',
            'esi_gross_limit' => 'required|numeric|min:0|max:99999999',
        ]);
        if ((float) $validated['eps_rate'] > (float) $validated['pf_employer_rate']) {
            throw ValidationException::withMessages([
                'eps_rate' => 'The employer EPS rate cannot exceed the employer PF rate.',
            ]);
        }

        $latestDate = DB::table('payroll_statutory_configs')->max('effective_from');
        if ($latestDate && $validated['effective_from'] <= $latestDate) {
            throw ValidationException::withMessages([
                'effective_from' => 'A contribution configuration must take effect after the latest saved configuration.',
            ]);
        }

        $id = DB::transaction(function () use ($request, $validated): int {
            $id = DB::table('payroll_statutory_configs')->insertGetId([
                ...$validated,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'PAYROLL_STATUTORY_CONFIG_CREATED',
                'Payroll',
                "Created PF/ESI contribution configuration effective {$validated['effective_from']}."
            );

            return $id;
        });

        return response()->json([
            'data' => DB::table('payroll_statutory_configs')->where('id', $id)->first(),
        ], 201);
    }

    public function storeProfessionalTaxRules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => 'required|string|max:100',
            'is_applicable' => 'required|boolean',
            'effective_from' => 'required|date',
            'slabs' => 'array',
            'slabs.*.salary_from' => 'required_with:slabs|numeric|min:0',
            'slabs.*.salary_to' => 'nullable|numeric',
            'slabs.*.monthly_tax' => 'required_with:slabs|numeric|min:0',
        ]);
        $slabs = $validated['slabs'] ?? [];
        if ($validated['is_applicable']) {
            $this->validateRanges($slabs, 'salary_from', 'salary_to', 'slabs');
        } elseif ($slabs !== []) {
            throw ValidationException::withMessages([
                'slabs' => 'A state marked as having no Professional Tax cannot include tax slabs.',
            ]);
        }

        $id = DB::transaction(function () use ($request, $validated, $slabs): int {
            $this->ensureLatestRuleSet('payroll_pt_rule_sets', 'state', $validated['state'], $validated['effective_from']);
            $ruleSetId = DB::table('payroll_pt_rule_sets')->insertGetId([
                'state' => $validated['state'],
                'is_applicable' => $validated['is_applicable'],
                'effective_from' => $validated['effective_from'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($slabs as $slab) {
                DB::table('payroll_pt_slabs')->insert([
                    'rule_set_id' => $ruleSetId,
                    'salary_from' => $slab['salary_from'],
                    'salary_to' => $slab['salary_to'] ?? null,
                    'monthly_tax' => $slab['monthly_tax'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            AuditLog::record(
                $request,
                'PAYROLL_PT_RULE_CREATED',
                'Payroll',
                "Created Professional Tax rules for {$validated['state']} effective {$validated['effective_from']}."
            );

            return $ruleSetId;
        });

        return response()->json([
            'data' => DB::table('payroll_pt_rule_sets')->where('id', $id)->first(),
        ], 201);
    }

    public function storeIncomeTaxRules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tax_regime' => 'required|in:New,Old',
            'effective_from' => 'required|date',
            'slabs' => 'required|array|min:1',
            'slabs.*.income_from' => 'required|numeric|min:0',
            'slabs.*.income_to' => 'nullable|numeric',
            'slabs.*.tax_rate' => 'required|numeric|min:0|max:100',
        ]);
        $this->validateRanges($validated['slabs'], 'income_from', 'income_to', 'slabs');
        $id = DB::transaction(function () use ($request, $validated): int {
            $this->ensureLatestRuleSet(
                'payroll_tax_rule_sets',
                'tax_regime',
                $validated['tax_regime'],
                $validated['effective_from']
            );
            $ruleSetId = DB::table('payroll_tax_rule_sets')->insertGetId([
                'tax_regime' => $validated['tax_regime'],
                'effective_from' => $validated['effective_from'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($validated['slabs'] as $slab) {
                DB::table('payroll_tax_slabs')->insert([
                    'rule_set_id' => $ruleSetId,
                    'income_from' => $slab['income_from'],
                    'income_to' => $slab['income_to'] ?? null,
                    'tax_rate' => $slab['tax_rate'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            AuditLog::record(
                $request,
                'PAYROLL_TDS_RULE_CREATED',
                'Payroll',
                "Created {$validated['tax_regime']} income-tax slabs effective {$validated['effective_from']}."
            );

            return $ruleSetId;
        });

        return response()->json([
            'data' => DB::table('payroll_tax_rule_sets')->where('id', $id)->first(),
        ], 201);
    }

    public function updateBranchProfessionalTax(Request $request, int $branchId): JsonResponse
    {
        $validated = $request->validate([
            'pt_applicable' => 'required|boolean',
        ]);
        $branch = DB::table('branches')->where('id', $branchId)->first();
        abort_unless($branch, 404, 'Branch not found.');

        DB::transaction(function () use ($request, $branch, $branchId, $validated): void {
            DB::table('branches')->where('id', $branchId)->update([
                'pt_applicable' => $validated['pt_applicable'],
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'BRANCH_PT_APPLICABILITY_UPDATED',
                'Payroll',
                "Updated Professional Tax applicability for branch {$branch->code} ({$branch->state}) to "
                . ($validated['pt_applicable'] ? 'enabled' : 'disabled') . '.'
            );
        });

        return response()->json([
            'data' => DB::table('branches')->where('id', $branchId)->first(),
        ]);
    }

    public function declarations(): JsonResponse
    {
        $financialYear = $this->financialYear(now()->year, now()->month);
        $employees = DB::table('employees')
            ->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id')
            ->leftJoin('employee_tax_declarations', function ($join) use ($financialYear): void {
                $join->on('employee_tax_declarations.employee_id', '=', 'employees.id')
                    ->where('employee_tax_declarations.financial_year', '=', $financialYear);
            })
            ->whereIn('employees.status', ['active', 'on_notice'])
            ->where('employees.is_demo', false)
            ->whereNull('employees.deleted_at')
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->get([
                'employees.id as employee_id',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employee_statutory_details.tax_regime',
                'employee_tax_declarations.financial_year',
                'employee_tax_declarations.tax_regime as declaration_regime',
                'employee_tax_declarations.other_annual_income',
                'employee_tax_declarations.previous_employer_income',
                'employee_tax_declarations.previous_employer_tds',
                'employee_tax_declarations.annual_deductions',
                'employee_tax_declarations.reviewed_by',
                'employee_tax_declarations.reviewed_at',
            ]);
        $employees->each(function ($employee): void {
            $employee->annual_deductions = $employee->annual_deductions
                ? json_decode($employee->annual_deductions, true)
                : [];
        });

        return response()->json([
            'data' => $employees,
            'meta' => ['financial_year' => $financialYear],
        ]);
    }

    public function updateDeclaration(Request $request, int $employeeId): JsonResponse
    {
        $validated = $request->validate([
            'financial_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'tax_regime' => 'required|in:New,Old',
            'other_annual_income' => 'required|numeric|min:0',
            'previous_employer_income' => 'required|numeric|min:0',
            'previous_employer_tds' => 'required|numeric|min:0',
            'annual_deductions' => 'required|array',
            ...array_fill_keys(array_map(
                fn (string $key): string => "annual_deductions.{$key}",
                self::DEDUCTION_KEYS
            ), 'required|numeric|min:0'),
        ]);
        $years = explode('-', $validated['financial_year']);
        if ((int) $years[1] !== (int) $years[0] + 1) {
            throw ValidationException::withMessages([
                'financial_year' => 'Enter a financial year using consecutive years, such as 2026-2027.',
            ]);
        }

        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();
        abort_unless($employee, 404, 'Employee not found.');

        DB::transaction(function () use ($request, $employeeId, $validated): void {
            DB::table('employee_statutory_details')->updateOrInsert(
                ['employee_id' => $employeeId],
                ['tax_regime' => $validated['tax_regime'], 'updated_at' => now()]
            );
            $existing = DB::table('employee_tax_declarations')
                ->where('employee_id', $employeeId)
                ->where('financial_year', $validated['financial_year'])
                ->exists();
            $declarationValues = [
                'tax_regime' => $validated['tax_regime'],
                'other_annual_income' => $validated['other_annual_income'],
                'previous_employer_income' => $validated['previous_employer_income'],
                'previous_employer_tds' => $validated['previous_employer_tds'],
                'annual_deductions' => json_encode(
                    array_intersect_key($validated['annual_deductions'], array_flip(self::DEDUCTION_KEYS)),
                    JSON_THROW_ON_ERROR
                ),
                'reviewed_by' => $request->user()->name,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('employee_tax_declarations')->updateOrInsert(
                [
                    'employee_id' => $employeeId,
                    'financial_year' => $validated['financial_year'],
                ],
                $existing ? $declarationValues : [...$declarationValues, 'created_at' => now()]
            );
            AuditLog::record(
                $request,
                'PAYROLL_TAX_DECLARATION_REVIEWED',
                'Payroll',
                "Reviewed tax regime and annual declarations for employee #{$employeeId} for {$validated['financial_year']}."
            );
        });

        return response()->json(['message' => 'Tax declarations saved and marked as reviewed.']);
    }

    private function ruleSetsWithSlabs(string $setsTable, string $slabsTable): array
    {
        $sets = DB::table($setsTable)->orderByDesc('effective_from')->get();

        return $sets->map(function ($set) use ($slabsTable): array {
            $slabs = DB::table($slabsTable)
                ->where('rule_set_id', $set->id)
                ->orderBy($slabsTable === 'payroll_pt_slabs' ? 'salary_from' : 'income_from')
                ->get();

            return [...(array) $set, 'slabs' => $slabs];
        })->all();
    }

    private function validateRanges(array $slabs, string $fromKey, string $toKey, string $field): void
    {
        if ($slabs === []) {
            throw ValidationException::withMessages([
                $field => 'At least one salary or income range is required.',
            ]);
        }

        usort($slabs, fn (array $left, array $right): int => (float) $left[$fromKey] <=> (float) $right[$fromKey]);
        if ((float) $slabs[0][$fromKey] !== 0.0) {
            throw ValidationException::withMessages([
                $field => 'The first range must start at zero.',
            ]);
        }

        foreach ($slabs as $index => $slab) {
            $to = $slab[$toKey] ?? null;
            $isLast = $index === count($slabs) - 1;
            if ($to !== null && (float) $to <= (float) $slab[$fromKey]) {
                throw ValidationException::withMessages([
                    $field => 'Each range upper limit must be greater than its lower limit.',
                ]);
            }
            if ($isLast && $to !== null) {
                throw ValidationException::withMessages([
                    $field => 'The final range must have no upper limit.',
                ]);
            }
            if (!$isLast && ($to === null || (float) $to !== (float) $slabs[$index + 1][$fromKey])) {
                throw ValidationException::withMessages([
                    $field => 'Ranges must be consecutive with no gaps or overlaps.',
                ]);
            }
        }
    }

    private function ensureLatestRuleSet(string $table, string $keyColumn, string $key, string $effectiveFrom): void
    {
        $latest = DB::table($table)
            ->where($keyColumn, $key)
            ->orderByDesc('effective_from')
            ->first();
        if ($latest && $effectiveFrom <= $latest->effective_from) {
            throw ValidationException::withMessages([
                'effective_from' => 'The rule set must take effect after the latest saved set for this state or regime.',
            ]);
        }

        if ($latest) {
            DB::table($table)->where('id', $latest->id)->update([
                'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                'updated_at' => now(),
            ]);
        }
    }

    private function financialYear(int $year, int $month): string
    {
        $startYear = $month >= 4 ? $year : $year - 1;

        return sprintf('%d-%d', $startYear, $startYear + 1);
    }
}
