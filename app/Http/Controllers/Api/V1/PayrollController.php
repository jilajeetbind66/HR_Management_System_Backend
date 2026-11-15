<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
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
            ->where('payroll_runs.status', 'locked')
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
        });

        return response()->json(['data' => $payslips]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_year' => 'required|integer|min:2000|max:2100',
            'period_month' => 'required|integer|min:1|max:12',
            'scope_key' => ['required', 'string', 'max:50', 'regex:/^(company|branch:[1-9][0-9]*)$/'],
            'month_name' => 'nullable|string|max:30',
        ]);

        $branchId = null;
        if ($validated['scope_key'] !== 'company') {
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
            ->whereIn('employees.status', ['active', 'on_notice'])
            ->when($branchId, fn ($query) => $query->where('employees.branch_id', $branchId))
            ->select([
                'employees.id',
                'employee_statutory_details.gross_monthly',
                'employee_statutory_details.basic_monthly',
                'employee_statutory_details.pf_applicable',
                'employee_statutory_details.esi_applicable',
            ])
            ->get();

        if ($employees->isEmpty()) {
            return response()->json([
                'message' => 'No active employees with salary records were found for this payroll scope.',
                'code' => 'PAYROLL_NO_EMPLOYEES',
            ], 422);
        }

        $hasInvalidSalaryData = $employees->contains(function ($employee) {
            $gross = (float) $employee->gross_monthly;
            $basic = (float) $employee->basic_monthly;

            return $gross <= 0 || $basic <= 0 || $basic > $gross;
        });

        if ($hasInvalidSalaryData) {
            return response()->json([
                'message' => 'Payroll cannot be generated until every active employee in scope has valid gross and basic salary values.',
                'code' => 'PAYROLL_SALARY_DATA_INVALID',
            ], 422);
        }

        $monthName = Carbon::createFromDate(
            $validated['period_year'],
            $validated['period_month'],
            1
        )->format('F Y');

        try {
            $run = DB::transaction(function () use ($validated, $period, $employees, $monthName, $request) {
                if (DB::table('payroll_runs')->where($period)->exists()) {
                    return null;
                }

                $now = now();
                $payslips = [];
                $grossTotal = 0;
                $deductionsTotal = 0;
                $netTotal = 0;

                foreach ($employees as $employee) {
                    $gross = round((float) $employee->gross_monthly, 2);
                    $basic = round((float) $employee->basic_monthly, 2);
                    $hra = min(round($basic * 0.40, 2), round($gross - $basic, 2));
                    $specialAllowance = round($gross - $basic - $hra, 2);
                    $pf = $employee->pf_applicable
                        ? min(round($basic * 0.12, 2), 1800)
                        : 0;
                    $esi = $employee->esi_applicable
                        ? round($gross * 0.0075, 2)
                        : 0;
                    $baseDeductions = round($pf + $esi, 2);

                    $grossTotal += $gross;
                    $deductionsTotal += $baseDeductions;
                    $netTotal += round($gross - $baseDeductions, 2);

                    $payslips[] = [
                        'employee_id' => $employee->id,
                        'gross_salary' => $gross,
                        'basic_salary' => $basic,
                        'hra' => $hra,
                        'special_allowance' => $specialAllowance,
                        'pf_employee' => $pf,
                        'esi_employee' => $esi,
                        'professional_tax' => 0,
                        'tds' => 0,
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
                    'status' => 'review',
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
        ]);

        $result = DB::transaction(function () use ($request, $runId, $payslipId, $validated) {
            $run = DB::table('payroll_runs')
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();
            abort_unless($run, 404, 'Payroll run not found.');
            abort_if($run->status !== 'review', 409, 'Finalized payroll deductions cannot be changed.');

            $payslip = DB::table('payslips')
                ->where('id', $payslipId)
                ->where('payroll_run_id', $runId)
                ->lockForUpdate()
                ->first();
            abort_unless($payslip, 404, 'Payslip not found for this payroll run.');

            $professionalTax = round((float) $validated['professional_tax'], 2);
            $tds = round((float) $validated['tds'], 2);
            $totalDeductions = round(
                (float) $payslip->pf_employee
                + (float) $payslip->esi_employee
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

            AuditLog::record(
                $request,
                'PAYSLIP_DEDUCTIONS_CONFIRMED',
                'Payroll',
                "Confirmed TDS and professional tax for payslip #{$payslipId}"
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

            return $updatedPayslip;
        });

        return response()->json(['data' => $result]);
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($id, $request) {
            $run = DB::table('payroll_runs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404, 'Payroll run not found.');
            abort_if($run->status === 'locked', 409, 'Payroll run is already finalized.');

            $payslipCount = DB::table('payslips')->where('payroll_run_id', $id)->count();
            $pendingCount = DB::table('payslips')
                ->where('payroll_run_id', $id)
                ->where('deductions_confirmed', false)
                ->count();

            if ($payslipCount === 0 || $pendingCount > 0) {
                return false;
            }

            DB::table('payroll_runs')->where('id', $id)->update([
                'status' => 'locked',
                'approved_by' => $request->user()->name,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'PAYROLL_LOCKED', 'Payroll', "Finalized payroll run #{$id}");

            return true;
        });

        if (!$result) {
            return response()->json([
                'message' => 'Confirm TDS and professional tax for every employee before finalizing payroll.',
                'code' => 'PAYSLIP_REVIEW_REQUIRED',
            ], 409);
        }

        return response()->json(['message' => 'Payroll finalized and payslips published.']);
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
}
