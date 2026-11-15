<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $claimsQuery = DB::table('expense_claims')
            ->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
            ->select(
                'expense_claims.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name'
            );
        $loansQuery = DB::table('loan_requests')
            ->join('employees', 'loan_requests.employee_id', '=', 'employees.id')
            ->select(
                'loan_requests.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name'
            );

        $this->applyVisibility($claimsQuery, $user, 'expense_claims.employee_id');
        $this->applyVisibility($loansQuery, $user, 'loan_requests.employee_id');

        $claims = $claimsQuery->orderByDesc('expense_claims.id')->get();
        $claimIds = $claims->pluck('id');
        $claimReceipts = DB::table('hrms_uploads')
            ->where('module', 'expense_receipts')
            ->whereIn('record_id', $claimIds)
            ->orderByDesc('id')
            ->get([
                'id',
                'record_id',
                'employee_id',
                'original_filename',
                'mime_type',
                'file_size',
                'status',
                'created_at',
            ])
            ->groupBy('record_id');

        $claims->each(function ($claim) use ($claimReceipts) {
            $claim->receipts = $claimReceipts->get($claim->id, collect())->values();
        });

        $loans = $loansQuery->orderByDesc('loan_requests.id')->get();
        $unlinkedReceiptsQuery = DB::table('hrms_uploads')
            ->where('module', 'expense_receipts')
            ->whereNull('record_id');
        $this->applyVisibility($unlinkedReceiptsQuery, $user, 'employee_id');
        $unlinkedReceipts = $unlinkedReceiptsQuery->orderByDesc('id')->get([
            'id',
            'employee_id',
            'original_filename',
            'mime_type',
            'file_size',
            'status',
            'created_at',
        ]);

        return response()->json([
            'data' => [
                'claims' => $claims,
                'loans' => $loans,
                'unlinked_receipts' => $unlinkedReceipts,
            ],
        ]);
    }

    public function storeClaim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|in:Travel,Meals,Accommodation,Transport,Supplies,Other',
            'amount' => 'required|numeric|min:0.01|max:10000000',
            'incurred_on' => 'required|date_format:Y-m-d',
            'description' => 'required|string|max:2000',
        ]);
        $employeeId = $request->user()->employee_id;
        abort_unless($employeeId, 403, 'This account is not linked to an employee record.');

        $claimId = DB::transaction(function () use ($validated, $request, $employeeId) {
            $id = DB::table('expense_claims')->insertGetId([
                'employee_id' => $employeeId,
                'category' => $validated['category'],
                'amount' => $validated['amount'],
                'incurred_on' => $validated['incurred_on'],
                'description' => $validated['description'],
                'status' => 'submitted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'EXPENSE_CLAIM_SUBMITTED', 'Expenses', "Submitted expense claim #{$id}");

            return $id;
        });

        return response()->json(['data' => $this->loadClaim($claimId)], 201);
    }

    public function reviewClaim(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'decision_note' => 'nullable|string|max:2000',
        ]);
        if ($validated['decision'] === 'rejected' && blank($validated['decision_note'] ?? null)) {
            throw ValidationException::withMessages([
                'decision_note' => 'A note is required when rejecting an expense claim.',
            ]);
        }

        DB::transaction(function () use ($validated, $request, $id) {
            $claim = DB::table('expense_claims')->where('id', $id)->lockForUpdate()->first();
            abort_if(!$claim, 404);
            $this->authorizeReview($request, (int) $claim->employee_id);
            abort_if($claim->status !== 'submitted', 409, 'Only submitted claims can be reviewed.');

            if (
                $validated['decision'] === 'approved'
                && !DB::table('hrms_uploads')
                    ->where('module', 'expense_receipts')
                    ->where('record_id', $id)
                    ->where('employee_id', $claim->employee_id)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'receipts' => 'At least one receipt must be attached before approval.',
                ]);
            }

            DB::table('expense_claims')->where('id', $id)->update([
                'status' => $validated['decision'],
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'decision_note' => $validated['decision_note'] ?? null,
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'EXPENSE_CLAIM_REVIEWED',
                'Expenses',
                "Reviewed expense claim #{$id}: {$validated['decision']}"
            );
        });

        return response()->json(['data' => $this->loadClaim($id)]);
    }

    public function markClaimPaid(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $claim = DB::table('expense_claims')->where('id', $id)->lockForUpdate()->first();
            abort_if(!$claim, 404);
            abort_if($claim->status !== 'approved', 409, 'Only approved claims can be marked as paid.');

            DB::table('expense_claims')->where('id', $id)->update([
                'status' => 'paid',
                'paid_by_user_id' => $request->user()->id,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'EXPENSE_CLAIM_MARKED_PAID', 'Expenses', "Marked expense claim #{$id} as paid");
        });

        return response()->json(['data' => $this->loadClaim($id)]);
    }

    public function storeLoanRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'requested_amount' => 'required|numeric|min:0.01|max:10000000',
            'purpose' => 'required|string|max:2000',
        ]);
        $employeeId = $request->user()->employee_id;
        abort_unless($employeeId, 403, 'This account is not linked to an employee record.');

        $loanId = DB::transaction(function () use ($validated, $request, $employeeId) {
            $id = DB::table('loan_requests')->insertGetId([
                'employee_id' => $employeeId,
                'requested_amount' => $validated['requested_amount'],
                'purpose' => $validated['purpose'],
                'status' => 'submitted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'LOAN_REQUEST_SUBMITTED', 'Expenses', "Submitted loan request #{$id}");

            return $id;
        });

        return response()->json(['data' => $this->loadLoan($loanId)], 201);
    }

    public function reviewLoanRequest(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'decision_note' => 'nullable|string|max:2000',
        ]);
        if ($validated['decision'] === 'rejected' && blank($validated['decision_note'] ?? null)) {
            throw ValidationException::withMessages([
                'decision_note' => 'A note is required when rejecting a loan request.',
            ]);
        }

        DB::transaction(function () use ($validated, $request, $id) {
            $loan = DB::table('loan_requests')->where('id', $id)->lockForUpdate()->first();
            abort_if(!$loan, 404);
            $this->authorizeReview($request, (int) $loan->employee_id);
            abort_if($loan->status !== 'submitted', 409, 'Only submitted requests can be reviewed.');

            DB::table('loan_requests')->where('id', $id)->update([
                'status' => $validated['decision'],
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'decision_note' => $validated['decision_note'] ?? null,
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'LOAN_REQUEST_REVIEWED',
                'Expenses',
                "Reviewed loan request #{$id}: {$validated['decision']}"
            );
        });

        return response()->json(['data' => $this->loadLoan($id)]);
    }

    private function applyVisibility($query, $user, string $employeeColumn): void
    {
        if ($user->role === 'super_admin') {
            return;
        }

        if ($user->role === 'manager') {
            $employeeIds = RoleAccess::visibleEmployeeIds($user);
            $query->whereIn($employeeColumn, $employeeIds);
            return;
        }

        $query->where($employeeColumn, $user->employee_id ?? 0);
    }

    private function authorizeReview(Request $request, int $employeeId): void
    {
        $user = $request->user();
        abort_if($user->employee_id && (int) $user->employee_id === $employeeId, 403);
        if ($user->role === 'manager' && !RoleAccess::managesEmployee($user, $employeeId)) {
            abort(403);
        }
    }

    private function loadClaim(int $id): object
    {
        $claim = DB::table('expense_claims')
            ->join('employees', 'expense_claims.employee_id', '=', 'employees.id')
            ->select(
                'expense_claims.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name'
            )
            ->where('expense_claims.id', $id)
            ->first();
        abort_if(!$claim, 404);
        $claim->receipts = DB::table('hrms_uploads')
            ->where('module', 'expense_receipts')
            ->where('record_id', $id)
            ->orderByDesc('id')
            ->get([
                'id',
                'record_id',
                'employee_id',
                'original_filename',
                'mime_type',
                'file_size',
                'status',
                'created_at',
            ]);

        return $claim;
    }

    private function loadLoan(int $id): object
    {
        $loan = DB::table('loan_requests')
            ->join('employees', 'loan_requests.employee_id', '=', 'employees.id')
            ->select(
                'loan_requests.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name'
            )
            ->where('loan_requests.id', $id)
            ->first();
        abort_if(!$loan, 404);

        return $loan;
    }
}
