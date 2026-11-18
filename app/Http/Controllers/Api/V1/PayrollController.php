<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\LeaveDayCalculator;
use App\Support\PayrollComponentCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class PayrollController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->payrollRunsQuery()->get()]);
    }

    public function mine(Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee_id;
        abort_unless($employeeId, 403, 'This account is not linked to an employee record.');

        $payslips = DB::table('payslips')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->where('payslips.employee_id', $employeeId)
            ->whereIn('payroll_runs.status', ['published', 'locked'])
            ->where('payslips.deductions_confirmed', true)
            ->select([
                'payslips.id',
                'payslips.gross_salary',
                'payslips.basic_salary',
                'payslips.hra',
                'payslips.special_allowance',
                'payslips.pf_employee',
                'payslips.esi_employee',
                'payslips.professional_tax',
                'payslips.tds',
                'payslips.professional_tax_computed',
                'payslips.tds_computed',
                'payslips.tds_taxable_annual_income',
                'payslips.professional_tax_override_reason',
                'payslips.tds_override_reason',
                'payslips.tax_financial_year',
                'payslips.component_breakdown',
                'payslips.employer_cost_total',
                'payslips.total_ctc_monthly',
                'payslips.ctc_annual',
                'payslips.lop_days',
                'payslips.lop_deduction',
                'payslips.total_deductions',
                'payslips.net_salary',
                'payslips.payment_status',
                'payroll_runs.period_year',
                'payroll_runs.period_month',
                'payroll_runs.month_name',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.designation',
            ])
            ->orderByDesc('payroll_runs.period_year')
            ->orderByDesc('payroll_runs.period_month')
            ->get();
        $payslips->each(function ($payslip) {
            $payslip->component_breakdown = json_decode((string) $payslip->component_breakdown, true) ?: [];
        });

        return response()->json(['data' => $payslips]);
    }

    public function payslips(int $runId): JsonResponse
    {
        $run = DB::table('payroll_runs')->where('id', $runId)->first();
        abort_unless($run, 404, 'Payroll run not found.');

        $payslips = DB::table('payslips')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->where('payslips.payroll_run_id', $runId)
            ->select([
                'payslips.id',
                'payslips.payroll_run_id',
                'payslips.employee_id',
                'payslips.gross_salary',
                'payslips.basic_salary',
                'payslips.hra',
                'payslips.special_allowance',
                'payslips.pf_employee',
                'payslips.esi_employee',
                'payslips.professional_tax',
                'payslips.tds',
                'payslips.professional_tax_computed',
                'payslips.tds_computed',
                'payslips.tds_taxable_annual_income',
                'payslips.professional_tax_override_reason',
                'payslips.tds_override_reason',
                'payslips.tax_financial_year',
                'payslips.component_breakdown',
                'payslips.employer_cost_total',
                'payslips.total_ctc_monthly',
                'payslips.ctc_annual',
                'payslips.lop_days',
                'payslips.lop_deduction',
                'payslips.total_deductions',
                'payslips.net_salary',
                'payslips.payment_status',
                'payslips.deductions_confirmed',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.designation',
            ])
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->get();
        $payslips->each(function ($payslip) {
            $payslip->deductions_confirmed = (bool) $payslip->deductions_confirmed;
            $payslip->component_breakdown = json_decode((string) $payslip->component_breakdown, true) ?: [];
        });

        return response()->json(['data' => $payslips]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_year' => 'required|integer|min:2000|max:2100',
            'period_month' => 'required|integer|min:1|max:12',
            'scope_key' => ['required', 'string', 'max:50', 'regex:/^(company|demo|branch:[1-9][0-9]*)$/'],
            'month_name' => 'nullable|string|max:30',
        ]);

        $demoScope = $validated['scope_key'] === 'demo';
        $branchId = null;
        if (strpos($validated['scope_key'], 'branch:') === 0) {
            $branchId = (int) substr($validated['scope_key'], strlen('branch:'));
            if (!DB::table('branches')->where('id', $branchId)->exists()) {
                throw ValidationException::withMessages([
                    'scope_key' => 'The selected branch does not exist.',
                ]);
            }
        }

        $period = [
            'period_year' => $validated['period_year'],
            'period_month' => $validated['period_month'],
            'scope_key' => $validated['scope_key'],
        ];

        if (DB::table('payroll_runs')->where($period)->exists()) {
            return $this->duplicateRunResponse();
        }

        $employees = DB::table('employees')
            ->leftJoin('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id')
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->whereIn('employees.status', ['active', 'on_notice'])
            ->where('employees.is_demo', $demoScope)
            ->when($branchId, fn ($query) => $query->where('employees.branch_id', $branchId))
            ->select([
                'employees.id',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'branches.city as branch_city',
                'branches.state as branch_state',
                'branches.pt_applicable as branch_pt_applicable',
                'employee_statutory_details.ctc_annual',
                'employee_statutory_details.gross_monthly',
                'employee_statutory_details.basic_monthly',
                'employee_statutory_details.pf_applicable',
                'employee_statutory_details.esi_applicable',
                'employee_statutory_details.pt_state',
                'employee_statutory_details.tax_regime',
            ])
            ->get();

        if ($employees->isEmpty()) {
            return response()->json([
                'message' => 'No active employees with salary records were found for this payroll scope.',
                'code' => 'PAYROLL_NO_EMPLOYEES',
            ], 422);
        }

        $invalidSalaryEmployees = $employees->map(function ($employee) {
            $gross = (float) $employee->gross_monthly;
            $basic = (float) $employee->basic_monthly;
            $issues = [];

            if ($gross <= 0) {
                $issues[] = 'gross_monthly';
            }
            if ($basic <= 0) {
                $issues[] = 'basic_monthly';
            } elseif ($basic > $gross) {
                $issues[] = 'basic_exceeds_gross';
            }

            return $issues === [] ? null : [
                'employee_id' => (int) $employee->id,
                'employee_code' => $employee->employee_code,
                'employee_name' => trim($employee->first_name . ' ' . $employee->last_name),
                'issues' => $issues,
            ];
        })->filter()->values();

        if ($invalidSalaryEmployees->isNotEmpty()) {
            return response()->json([
                'message' => 'Payroll cannot be generated until every active employee in scope has valid gross and basic salary values. Open each employee’s Compensation tab to update the missing or invalid values.',
                'code' => 'PAYROLL_SALARY_DATA_INVALID',
                'invalid_employees' => $invalidSalaryEmployees,
            ], 422);
        }

        $monthName = Carbon::createFromDate(
            $validated['period_year'],
            $validated['period_month'],
            1
        )->format('F Y');
        $periodDate = sprintf('%04d-%02d-01', $validated['period_year'], $validated['period_month']);
        $statutoryConfig = DB::table('payroll_statutory_configs')
            ->where('effective_from', '<=', $periodDate)
            ->orderByDesc('effective_from')
            ->first();
        if (!$statutoryConfig) {
            $statutoryConfig = DB::table('payroll_statutory_configs')->orderBy('effective_from')->first();
        }
        abort_if(!$statutoryConfig, 422, 'Payroll statutory contribution rates are not configured.');
        $payrollComponents = DB::table('payroll_components')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        try {
            $run = DB::transaction(function () use ($validated, $period, $employees, $monthName, $request, $payrollComponents, $statutoryConfig, $periodDate) {
                if (DB::table('payroll_runs')->where($period)->exists()) {
                    return null;
                }

                $now = now();
                $lopDaysByEmployee = $this->approvedLopDays(
                    $employees->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    $validated['period_year'],
                    $validated['period_month']
                );
                $payslips = [];
                $grossTotal = 0;
                $deductionsTotal = 0;
                $netTotal = 0;

                foreach ($employees as $employee) {
                    $gross = round((float) $employee->gross_monthly, 2);
                    $basic = round((float) $employee->basic_monthly, 2);
                    $componentBreakdown = PayrollComponentCalculator::calculate(
                        $payrollComponents,
                        $gross,
                        $basic,
                        (string) ($employee->branch_city ?? ''),
                        (bool) $employee->pf_applicable,
                        (bool) $employee->esi_applicable,
                        (float) $employee->ctc_annual,
                        $employee->employee_code,
                        (array) $statutoryConfig
                    );
                    $hra = $componentBreakdown['hra'];
                    $specialAllowance = $componentBreakdown['special_allowance'];
                    $pfWageBase = $statutoryConfig->apply_pf_wage_ceiling
                        ? min($basic, (float) $statutoryConfig->pf_wage_ceiling)
                        : $basic;
                    $pf = $employee->pf_applicable
                        ? round($pfWageBase * (float) $statutoryConfig->pf_employee_rate / 100, 2)
                        : 0;
                    $esi = $employee->esi_applicable
                        && $gross <= (float) $statutoryConfig->esi_gross_limit
                        ? round($gross * (float) $statutoryConfig->esi_employee_rate / 100, 2)
                        : 0;
                    $taxFinancialYear = $this->financialYear(
                        $validated['period_year'],
                        $validated['period_month']
                    );
                    $professionalTax = $this->calculateProfessionalTax(
                        $employee,
                        $gross,
                        $periodDate
                    );
                    $tdsCalculation = $this->calculateTds(
                        $employee,
                        $gross,
                        $validated['period_year'],
                        $validated['period_month'],
                        $periodDate,
                        $taxFinancialYear
                    );
                    $tds = $tdsCalculation['amount'];
                    $lopDays = (float) ($lopDaysByEmployee[$employee->id] ?? 0);
                    $lopDeduction = min(
                        round($gross * $lopDays / Carbon::createFromDate(
                            $validated['period_year'],
                            $validated['period_month'],
                            1
                        )->daysInMonth, 2),
                        max(0, round($gross - $pf - $esi, 2))
                    );
                    $baseDeductions = round($pf + $esi + $lopDeduction + ($professionalTax ?? 0) + ($tds ?? 0), 2);

                    $grossTotal += $gross;
                    $deductionsTotal += $baseDeductions;
                    $netTotal += round($gross - $baseDeductions, 2);

                    $payslips[] = [
                        'employee_id' => $employee->id,
                        'gross_salary' => $gross,
                        'basic_salary' => $basic,
                        'hra' => $hra,
                        'special_allowance' => $specialAllowance,
                        'component_breakdown' => json_encode($componentBreakdown, JSON_THROW_ON_ERROR),
                        'employer_cost_total' => $componentBreakdown['employer_cost_total'],
                        'total_ctc_monthly' => $componentBreakdown['total_ctc_monthly'],
                        'ctc_annual' => $componentBreakdown['ctc_annual'],
                        'pf_employee' => $pf,
                        'esi_employee' => $esi,
                        'professional_tax' => $professionalTax ?? 0,
                        'tds' => $tds ?? 0,
                        'professional_tax_computed' => $professionalTax,
                        'tds_computed' => $tds,
                        'tds_taxable_annual_income' => $tdsCalculation['taxable_income'],
                        'tax_financial_year' => $taxFinancialYear,
                        'professional_tax_override_reason' => null,
                        'tds_override_reason' => null,
                        'lop_days' => $lopDays,
                        'lop_deduction' => $lopDeduction,
                        'total_deductions' => $baseDeductions,
                        'net_salary' => round($gross - $baseDeductions, 2),
                        'payment_status' => 'unpaid',
                        'deductions_confirmed' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $runId = DB::table('payroll_runs')->insertGetId([
                    ...$period,
                    'month_name' => $monthName,
                    'status' => 'draft',
                    'total_employees' => count($payslips),
                    'gross_payout_inr' => round($grossTotal, 2),
                    'total_deductions_inr' => round($deductionsTotal, 2),
                    'net_payout_inr' => round($netTotal, 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($payslips as &$payslip) {
                    $payslip['payroll_run_id'] = $runId;
                }
                unset($payslip);

                DB::table('payslips')->insert($payslips);
                AuditLog::record(
                    $request,
                    'PAYROLL_RUN_CREATED',
                    'Payroll',
                    "Generated payroll run for {$monthName}"
                );

                return $this->payrollRunsQuery()->where('payroll_runs.id', $runId)->first();
            });
        } catch (QueryException $exception) {
            if (DB::table('payroll_runs')->where($period)->exists()) {
                return $this->duplicateRunResponse();
            }

            throw $exception;
        }

        if (!$run) {
            return $this->duplicateRunResponse();
        }

        return response()->json(['data' => $run], 201);
    }

    public function updatePayslipDeductions(Request $request, int $runId, int $payslipId): JsonResponse
    {
        $validated = $request->validate([
            'professional_tax' => 'required|numeric|min:0',
            'tds' => 'required|numeric|min:0',
            'professional_tax_override_reason' => 'nullable|string|max:1000',
            'tds_override_reason' => 'nullable|string|max:1000',
        ]);

        $result = DB::transaction(function () use ($request, $runId, $payslipId, $validated) {
            $run = DB::table('payroll_runs')
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();
            abort_unless($run, 404, 'Payroll run not found.');
            abort_if(!in_array($run->status, ['draft', 'review'], true), 409, 'Payslip deductions can only be edited while a payroll run is in draft.');

            $payslip = DB::table('payslips')
                ->where('id', $payslipId)
                ->where('payroll_run_id', $runId)
                ->lockForUpdate()
                ->first();
            abort_unless($payslip, 404, 'Payslip not found for this payroll run.');

            $professionalTax = round((float) $validated['professional_tax'], 2);
            $tds = round((float) $validated['tds'], 2);
            $ptOverride = $payslip->professional_tax_computed === null
                || abs($professionalTax - (float) $payslip->professional_tax_computed) >= 0.01;
            $tdsOverride = $payslip->tds_computed === null
                || abs($tds - (float) $payslip->tds_computed) >= 0.01;
            $ptOverrideReason = trim((string) ($validated['professional_tax_override_reason'] ?? ''));
            $tdsOverrideReason = trim((string) ($validated['tds_override_reason'] ?? ''));
            $errors = [];
            if ($ptOverride && $ptOverrideReason === '') {
                $errors['professional_tax_override_reason'] = 'A reason is required when Professional Tax is entered manually or differs from the computed amount.';
            }
            if ($tdsOverride && $tdsOverrideReason === '') {
                $errors['tds_override_reason'] = 'A reason is required when TDS is entered manually or differs from the computed amount.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $totalDeductions = round(
                (float) $payslip->pf_employee
                + (float) $payslip->esi_employee
                + (float) $payslip->lop_deduction
                + $professionalTax
                + $tds,
                2
            );
            $netSalary = round((float) $payslip->gross_salary - $totalDeductions, 2);

            if ($netSalary < 0) {
                throw ValidationException::withMessages([
                    'tds' => 'Combined deductions cannot exceed the employee gross salary.',
                ]);
            }

            DB::table('payslips')->where('id', $payslipId)->update([
                'professional_tax' => $professionalTax,
                'tds' => $tds,
                'professional_tax_override_reason' => $ptOverride ? $ptOverrideReason : null,
                'tds_override_reason' => $tdsOverride ? $tdsOverrideReason : null,
                'total_deductions' => $totalDeductions,
                'net_salary' => $netSalary,
                'deductions_confirmed' => true,
                'updated_at' => now(),
            ]);

            $totals = DB::table('payslips')
                ->where('payroll_run_id', $runId)
                ->selectRaw('COUNT(*) as total_employees, SUM(gross_salary) as gross_total, SUM(total_deductions) as deductions_total, SUM(net_salary) as net_total')
                ->first();

            DB::table('payroll_runs')->where('id', $runId)->update([
                'total_employees' => $totals->total_employees,
                'gross_payout_inr' => round((float) $totals->gross_total, 2),
                'total_deductions_inr' => round((float) $totals->deductions_total, 2),
                'net_payout_inr' => round((float) $totals->net_total, 2),
                'updated_at' => now(),
            ]);

            $overrideReasons = [];
            if ($ptOverride) {
                $overrideReasons[] = "Professional Tax: {$ptOverrideReason}";
            }
            if ($tdsOverride) {
                $overrideReasons[] = "TDS: {$tdsOverrideReason}";
            }
            AuditLog::record(
                $request,
                $overrideReasons === [] ? 'PAYSLIP_DEDUCTIONS_CONFIRMED' : 'PAYSLIP_DEDUCTIONS_OVERRIDDEN',
                'Payroll',
                "Reviewed TDS and Professional Tax for payslip #{$payslipId}"
                . ($overrideReasons === [] ? '.' : '; override reasons: ' . implode(' | ', $overrideReasons))
            );

            $updatedPayslip = DB::table('payslips')
                ->join('employees', 'employees.id', '=', 'payslips.employee_id')
                ->where('payslips.id', $payslipId)
                ->select([
                    'payslips.*',
                    'employees.employee_code',
                    'employees.first_name',
                    'employees.last_name',
                    'employees.designation',
                ])
                ->first();
            $updatedPayslip->deductions_confirmed = (bool) $updatedPayslip->deductions_confirmed;
            $updatedPayslip->component_breakdown = json_decode((string) $updatedPayslip->component_breakdown, true) ?: [];

            return $updatedPayslip;
        });

        return response()->json(['data' => $result]);
    }

    public function verify(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($id, $request) {
            $run = DB::table('payroll_runs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404, 'Payroll run not found.');
            abort_if(!in_array($run->status, ['draft', 'review'], true), 409, 'Only draft payroll runs can be verified.');

            $payslipCount = DB::table('payslips')->where('payroll_run_id', $id)->count();
            $pendingCount = DB::table('payslips')
                ->where('payroll_run_id', $id)
                ->where('deductions_confirmed', false)
                ->count();

            if ($payslipCount === 0 || $pendingCount > 0) {
                return false;
            }

            DB::table('payroll_runs')->where('id', $id)->update([
                'status' => 'verified',
                'approved_by' => $request->user()->name,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'PAYROLL_VERIFIED', 'Payroll', "Verified payroll run #{$id}");

            return true;
        });

        if (!$result) {
            return response()->json([
                'message' => 'Confirm TDS and professional tax for every employee before finalizing payroll.',
                'code' => 'PAYSLIP_REVIEW_REQUIRED',
            ], 409);
        }

        return response()->json([
            'data' => $this->payrollRunsQuery()->where('payroll_runs.id', $id)->first(),
            'message' => 'Payroll run verified and ready to publish.',
        ]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request): void {
            $run = DB::table('payroll_runs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404, 'Payroll run not found.');
            abort_if($run->status !== 'verified', 409, 'Only verified payroll runs can be published.');

            DB::table('payroll_runs')->where('id', $id)->update([
                'status' => 'published',
                'approved_by' => $request->user()->name,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'PAYROLL_PUBLISHED', 'Payroll', "Published payroll run #{$id}");
        });

        return response()->json([
            'data' => $this->payrollRunsQuery()->where('payroll_runs.id', $id)->first(),
            'message' => 'Payroll published and payslips are now available to employees.',
        ]);
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        return $this->publish($request, $id);
    }

    private function payrollRunsQuery()
    {
        return DB::table('payroll_runs')
            ->select('payroll_runs.*')
            ->selectSub(
                DB::table('payslips')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('payslips.payroll_run_id', 'payroll_runs.id'),
                'payslip_count'
            )
            ->selectSub(
                DB::table('payslips')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('payslips.payroll_run_id', 'payroll_runs.id')
                    ->where('payslips.deductions_confirmed', false),
                'pending_deductions_count'
            )
            ->orderByDesc('payroll_runs.id');
    }

    private function duplicateRunResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Payroll run already exists for period and scope.',
            'code' => 'DUPLICATE_PAYROLL_SCOPE',
        ], 409);
    }

    private function calculateProfessionalTax(object $employee, float $gross, string $periodDate): ?float
    {
        if (!(bool) $employee->branch_pt_applicable) {
            return 0.0;
        }

        $state = trim((string) ($employee->pt_state ?: $employee->branch_state));
        if ($state === '') {
            return null;
        }

        $ruleSet = DB::table('payroll_pt_rule_sets')
            ->whereRaw('LOWER(state) = ?', [mb_strtolower($state)])
            ->where('effective_from', '<=', $periodDate)
            ->where(function ($query) use ($periodDate): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $periodDate);
            })
            ->orderByDesc('effective_from')
            ->first();
        if (!$ruleSet) {
            return null;
        }
        if (!(bool) $ruleSet->is_applicable) {
            return 0.0;
        }

        $slab = DB::table('payroll_pt_slabs')
            ->where('rule_set_id', $ruleSet->id)
            ->where('salary_from', '<=', $gross)
            ->where(function ($query) use ($gross): void {
                $query->whereNull('salary_to')->orWhere('salary_to', '>', $gross);
            })
            ->first();

        return $slab ? round((float) $slab->monthly_tax, 2) : null;
    }

    private function calculateTds(
        object $employee,
        float $gross,
        int $year,
        int $month,
        string $periodDate,
        string $financialYear
    ): array {
        $regime = (string) ($employee->tax_regime ?? '');
        if (!in_array($regime, ['New', 'Old'], true)) {
            return ['amount' => null, 'taxable_income' => null];
        }

        $declaration = DB::table('employee_tax_declarations')
            ->where('employee_id', $employee->id)
            ->where('financial_year', $financialYear)
            ->where('tax_regime', $regime)
            ->first();
        if (!$declaration) {
            return ['amount' => null, 'taxable_income' => null];
        }

        $ruleSet = DB::table('payroll_tax_rule_sets')
            ->where('tax_regime', $regime)
            ->where('effective_from', '<=', $periodDate)
            ->where(function ($query) use ($periodDate): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $periodDate);
            })
            ->orderByDesc('effective_from')
            ->first();
        if (!$ruleSet) {
            return ['amount' => null, 'taxable_income' => null];
        }
        $slabs = DB::table('payroll_tax_slabs')
            ->where('rule_set_id', $ruleSet->id)
            ->orderBy('income_from')
            ->get();
        if ($slabs->isEmpty()) {
            return ['amount' => null, 'taxable_income' => null];
        }

        $financialYearStart = $month >= 4 ? $year : $year - 1;
        $startPeriodIndex = $financialYearStart * 12 + 4;
        $currentPeriodIndex = $year * 12 + $month;
        $monthsRemaining = 13 - ($month >= 4 ? $month - 3 : $month + 9);
        $yearToDate = DB::table('payroll_runs')
            ->join('payslips', 'payslips.payroll_run_id', '=', 'payroll_runs.id')
            ->where('payslips.employee_id', $employee->id)
            ->whereIn('payroll_runs.status', ['published', 'locked'])
            ->whereRaw('(payroll_runs.period_year * 12 + payroll_runs.period_month) >= ?', [$startPeriodIndex])
            ->whereRaw('(payroll_runs.period_year * 12 + payroll_runs.period_month) < ?', [$currentPeriodIndex])
            ->selectRaw('COALESCE(SUM(payslips.gross_salary), 0) as gross_total, COALESCE(SUM(payslips.tds), 0) as tds_total')
            ->first();

        $deductions = json_decode((string) $declaration->annual_deductions, true) ?: [];
        $annualDeductions = array_sum(array_map('floatval', $deductions));
        $projectedGross = (float) $yearToDate->gross_total + $gross * $monthsRemaining;
        $taxableIncome = max(
            0,
            $projectedGross
            + (float) $declaration->other_annual_income
            + (float) $declaration->previous_employer_income
            - $annualDeductions
        );
        $annualTax = 0.0;
        foreach ($slabs as $slab) {
            $upperBound = $slab->income_to === null
                ? $taxableIncome
                : min($taxableIncome, (float) $slab->income_to);
            $taxableInSlab = max(0, $upperBound - (float) $slab->income_from);
            $annualTax += $taxableInSlab * (float) $slab->tax_rate / 100;
        }
        $withheldBeforeCurrent = (float) $yearToDate->tds_total
            + (float) $declaration->previous_employer_tds;

        return [
            'amount' => round(max(0, ($annualTax - $withheldBeforeCurrent) / $monthsRemaining), 2),
            'taxable_income' => round($taxableIncome, 2),
        ];
    }

    private function financialYear(int $year, int $month): string
    {
        $startYear = $month >= 4 ? $year : $year - 1;

        return sprintf('%d-%d', $startYear, $startYear + 1);
    }

    private function approvedLopDays(array $employeeIds, int $year, int $month): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $leaves = DB::table('leave_requests')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('leave_type', 'Leave Without Pay (LOP)')
            ->whereDate('from_date', '<=', $periodEnd->toDateString())
            ->whereDate('to_date', '>=', $periodStart->toDateString())
            ->get(['employee_id', 'from_date', 'to_date', 'is_half_day', 'counted_dates']);

        $lopDaysByDate = [];
        foreach ($leaves as $leave) {
            $countedDates = json_decode((string) ($leave->counted_dates ?? ''), true);
            if (!is_array($countedDates) || $countedDates === []) {
                $countedDates = LeaveDayCalculator::calculate(
                    (int) $leave->employee_id,
                    $leave->from_date,
                    $leave->to_date,
                    (bool) $leave->is_half_day
                )['included_dates'];
            }
            $periodDays = array_filter(
                $countedDates,
                fn ($date) => $date >= $periodStart->toDateString() && $date <= $periodEnd->toDateString()
            );
            foreach ($periodDays as $date) {
                $lopDaysByDate[$leave->employee_id][$date] = max(
                    $lopDaysByDate[$leave->employee_id][$date] ?? 0,
                    $leave->is_half_day ? 0.5 : 1
                );
            }
        }

        $attendance = DB::table('attendance_records')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereIn('status', ['Absent', 'Half Day'])
            ->get(['employee_id', 'date', 'status']);
        foreach ($attendance as $record) {
            $attendanceLopDays = $record->status === 'Absent' ? 1 : 0.5;
            $lopDaysByDate[$record->employee_id][$record->date] = max(
                $lopDaysByDate[$record->employee_id][$record->date] ?? 0,
                $attendanceLopDays
            );
        }

        $daysByEmployee = [];
        foreach ($lopDaysByDate as $employeeId => $dates) {
            $daysByEmployee[$employeeId] = array_sum($dates);
        }

        return $daysByEmployee;
    }
}
