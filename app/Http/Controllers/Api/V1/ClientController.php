<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    private const ALLOWED_ROLES = ['super_admin', 'hr_admin'];

    /**
     * Enforce access to Super Admin and HR Admin only.
     */
    private function authorizeClientAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && in_array($user->role, self::ALLOWED_ROLES, true),
            403,
            'Only Super Admin and HR Admin have permission to access Client Management.'
        );
    }

    /**
     * Display a listing of clients with stats and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeClientAccess($request);

        $query = DB::table('clients')
            ->leftJoin('users as creator', 'clients.created_by_user_id', '=', 'creator.id')
            ->select([
                'clients.*',
                'creator.name as creator_name',
            ]);

        // Search by client name, project name, phone, email, or service type
        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('clients.client_name', 'like', $search)
                    ->orWhere('clients.project_name', 'like', $search)
                    ->orWhere('clients.phone', 'like', $search)
                    ->orWhere('clients.email', 'like', $search)
                    ->orWhere('clients.service_type', 'like', $search);
            });
        }

        // Filter by project status
        if ($request->filled('project_status') && $request->input('project_status') !== 'all') {
            $query->where('clients.project_status', $request->input('project_status'));
        }

        // Filter by payment status
        if ($request->filled('payment_status') && $request->input('payment_status') !== 'all') {
            $query->where('clients.payment_status', $request->input('payment_status'));
        }

        // Filter by start date range
        if ($request->filled('date_from')) {
            $query->whereDate('clients.start_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('clients.start_date', '<=', $request->input('date_to'));
        }

        // Filter by deadline due upcoming / overdue
        if ($request->boolean('overdue_only')) {
            $today = Carbon::today()->toDateString();
            $query->whereNotNull('clients.deadline')
                ->whereDate('clients.deadline', '<', $today)
                ->where('clients.project_status', '!=', 'Completed');
        }

        $clients = $query->orderByDesc('clients.id')->get();

        // Attach date-wise payment history for every client
        $clientIds = $clients->pluck('id')->all();
        $paymentsByClient = [];
        if (!empty($clientIds)) {
            $allPayments = DB::table('client_payments')
                ->leftJoin('users as recorder', 'client_payments.recorded_by_user_id', '=', 'recorder.id')
                ->whereIn('client_payments.client_id', $clientIds)
                ->select([
                    'client_payments.*',
                    'recorder.name as recorded_by_name',
                ])
                ->orderByDesc('client_payments.payment_date')
                ->orderByDesc('client_payments.id')
                ->get();

            foreach ($allPayments as $p) {
                $paymentsByClient[$p->client_id][] = $p;
            }
        }

        $clients = $clients->map(function ($c) use ($paymentsByClient) {
            $c->payments = $paymentsByClient[$c->id] ?? [];
            return $c;
        });

        // Calculate pipeline financial and operational statistics
        $all = DB::table('clients')->get();
        $totalDealValue = (float) $all->sum('total_amount');
        $totalReceived = (float) $all->sum('paid_amount');
        $totalPending = (float) $all->sum('remaining_amount');

        $today = Carbon::today()->toDateString();
        $overdueCount = $all->filter(function ($item) use ($today) {
            return $item->deadline && $item->deadline < $today && $item->project_status !== 'Completed';
        })->count();

        $stats = [
            'total_clients' => $all->count(),
            'total_deal_value' => round($totalDealValue, 2),
            'total_received' => round($totalReceived, 2),
            'total_pending' => round($totalPending, 2),
            'active_projects' => $all->where('project_status', 'In Progress')->count(),
            'upcoming_projects' => $all->where('project_status', 'Upcoming')->count(),
            'completed_projects' => $all->where('project_status', 'Completed')->count(),
            'fully_paid' => $all->where('payment_status', 'Paid')->count(),
            'partially_paid' => $all->where('payment_status', 'Partially Paid')->count(),
            'unpaid' => $all->where('payment_status', 'Unpaid')->count(),
            'overdue_deadlines' => $overdueCount,
        ];

        return response()->json([
            'data' => $clients,
            'meta' => [
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Store a newly created client deal in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeClientAccess($request);

        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'digits:10', 'regex:/^[6-9]\d{9}$/'],
            'email' => 'nullable|email|max:255',
            'project_name' => 'required|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'deadline' => 'nullable|date|after_or_equal:start_date',
            'total_amount' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:50',
            'project_status' => 'required|in:Upcoming,In Progress,Completed,On Hold',
            'remarks' => 'nullable|string|max:5000',
        ]);

        $totalAmount = round((float) $validated['total_amount'], 2);
        $paidAmount = round((float) ($validated['paid_amount'] ?? 0), 2);

        if ($paidAmount > $totalAmount) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Paid amount cannot exceed total agreed project deal amount.',
            ]);
        }

        $remainingAmount = max(0, round($totalAmount - $paidAmount, 2));
        $paymentStatus = $paidAmount >= $totalAmount && $totalAmount > 0
            ? 'Paid'
            : ($paidAmount > 0 ? 'Partially Paid' : 'Unpaid');

        $clientId = DB::transaction(function () use ($request, $validated, $totalAmount, $paidAmount, $remainingAmount, $paymentStatus) {
            $id = DB::table('clients')->insertGetId([
                'client_name' => trim($validated['client_name']),
                'phone' => trim($validated['phone']),
                'email' => !empty($validated['email']) ? strtolower(trim($validated['email'])) : null,
                'project_name' => trim($validated['project_name']),
                'service_type' => !empty($validated['service_type']) ? trim($validated['service_type']) : null,
                'start_date' => $validated['start_date'] ?? null,
                'deadline' => $validated['deadline'] ?? null,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'payment_status' => $paymentStatus,
                'project_status' => $validated['project_status'],
                'remarks' => !empty($validated['remarks']) ? trim($validated['remarks']) : null,
                'created_by_user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // If initial payment was made, record date-wise installment in client_payments table
            if ($paidAmount > 0) {
                $payDate = $validated['payment_date'] ?? ($validated['start_date'] ?? now()->toDateString());
                $payMethod = $validated['payment_method'] ?? 'Bank Transfer';
                DB::table('client_payments')->insert([
                    'client_id' => $id,
                    'payment_date' => $payDate,
                    'amount' => $paidAmount,
                    'payment_method' => $payMethod,
                    'transaction_reference' => 'Initial Advance',
                    'notes' => 'Initial token advance payment recorded during client registration',
                    'recorded_by_user_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            AuditLog::record(
                $request,
                'CLIENT_CREATED',
                'Client Management',
                "Registered new client '{$validated['client_name']}' for project '{$validated['project_name']}' (Deal: INR {$totalAmount})"
            );

            return $id;
        });

        return response()->json([
            'message' => 'Client deal registered successfully.',
            'data' => $this->loadClientWithDetails($clientId),
        ], 201);
    }

    /**
     * Display the specified client.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientAccess($request);
        $client = $this->loadClientWithDetails($id);

        return response()->json(['data' => $client]);
    }

    /**
     * Update the specified client in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientAccess($request);

        $client = DB::table('clients')->where('id', $id)->first();
        abort_unless($client, 404, 'Client record not found.');

        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'digits:10', 'regex:/^[6-9]\d{9}$/'],
            'email' => 'nullable|email|max:255',
            'project_name' => 'required|string|max:255',
            'service_type' => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'deadline' => 'nullable|date',
            'total_amount' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'project_status' => 'required|in:Upcoming,In Progress,Completed,On Hold',
            'remarks' => 'nullable|string|max:5000',
        ]);

        $totalAmount = round((float) $validated['total_amount'], 2);
        $paidAmount = round((float) ($validated['paid_amount'] ?? 0), 2);

        if ($paidAmount > $totalAmount) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Paid amount cannot exceed total agreed project deal amount.',
            ]);
        }

        $remainingAmount = max(0, round($totalAmount - $paidAmount, 2));
        $paymentStatus = $paidAmount >= $totalAmount && $totalAmount > 0
            ? 'Paid'
            : ($paidAmount > 0 ? 'Partially Paid' : 'Unpaid');

        DB::transaction(function () use ($request, $id, $validated, $totalAmount, $paidAmount, $remainingAmount, $paymentStatus) {
            DB::table('clients')->where('id', $id)->update([
                'client_name' => trim($validated['client_name']),
                'phone' => trim($validated['phone']),
                'email' => !empty($validated['email']) ? strtolower(trim($validated['email'])) : null,
                'project_name' => trim($validated['project_name']),
                'service_type' => !empty($validated['service_type']) ? trim($validated['service_type']) : null,
                'start_date' => $validated['start_date'] ?? null,
                'deadline' => $validated['deadline'] ?? null,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'payment_status' => $paymentStatus,
                'project_status' => $validated['project_status'],
                'remarks' => !empty($validated['remarks']) ? trim($validated['remarks']) : null,
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'CLIENT_UPDATED',
                'Client Management',
                "Updated client record #{$id} ({$validated['client_name']})"
            );
        });

        return response()->json([
            'message' => 'Client details updated successfully.',
            'data' => $this->loadClientWithDetails($id),
        ]);
    }

    /**
     * Record a date-wise payment installment received from the client.
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientAccess($request);

        $client = DB::table('clients')->where('id', $id)->first();
        abort_unless($client, 404, 'Client record not found.');

        $validated = $request->validate([
            'payment_amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:50', // UPI, Bank Transfer, Cash, Cheque, Card
            'transaction_reference' => 'nullable|string|max:100',
            'payment_note' => 'nullable|string|max:1000',
        ]);

        $incomingPayment = round((float) $validated['payment_amount'], 2);
        $newPaidAmount = round((float) $client->paid_amount + $incomingPayment, 2);
        $totalAmount = (float) $client->total_amount;

        if ($newPaidAmount > $totalAmount) {
            throw ValidationException::withMessages([
                'payment_amount' => 'Payment amount exceeds remaining balance. Max allowed: INR ' . ($totalAmount - (float) $client->paid_amount),
            ]);
        }

        $remainingAmount = max(0, round($totalAmount - $newPaidAmount, 2));
        $paymentStatus = $newPaidAmount >= $totalAmount && $totalAmount > 0
            ? 'Paid'
            : ($newPaidAmount > 0 ? 'Partially Paid' : 'Unpaid');

        $paymentDate = $validated['payment_date'];
        $paymentMethod = !empty($validated['payment_method']) ? trim($validated['payment_method']) : 'Bank Transfer';
        $txRef = !empty($validated['transaction_reference']) ? trim($validated['transaction_reference']) : null;
        $noteText = !empty($validated['payment_note']) ? trim($validated['payment_note']) : null;

        // Structured remark entry
        $note = "Received INR " . number_format($incomingPayment, 2) . " on " . Carbon::parse($paymentDate)->format('d M Y') . " via {$paymentMethod}";
        if ($txRef) {
            $note .= " (Ref: {$txRef})";
        }
        if ($noteText) {
            $note .= " - Note: {$noteText}";
        }
        $updatedRemarks = trim(($client->remarks ? $client->remarks . "\n" : '') . $note);

        DB::transaction(function () use ($request, $id, $newPaidAmount, $remainingAmount, $paymentStatus, $updatedRemarks, $incomingPayment, $paymentDate, $paymentMethod, $txRef, $noteText, $client) {
            // 1. Insert into client_payments ledger
            DB::table('client_payments')->insert([
                'client_id' => $id,
                'payment_date' => $paymentDate,
                'amount' => $incomingPayment,
                'payment_method' => $paymentMethod,
                'transaction_reference' => $txRef,
                'notes' => $noteText,
                'recorded_by_user_id' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Update client totals
            DB::table('clients')->where('id', $id)->update([
                'paid_amount' => $newPaidAmount,
                'remaining_amount' => $remainingAmount,
                'payment_status' => $paymentStatus,
                'remarks' => $updatedRemarks,
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'CLIENT_PAYMENT_RECORDED',
                'Client Management',
                "Recorded payment of INR {$incomingPayment} on {$paymentDate} via {$paymentMethod} for client {$client->client_name} (#{$id})"
            );
        });

        return response()->json([
            'message' => 'Payment installment recorded successfully.',
            'data' => $this->loadClientWithDetails($id),
        ]);
    }

    /**
     * Remove the specified client from storage.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeClientAccess($request);

        $client = DB::table('clients')->where('id', $id)->first();
        abort_unless($client, 404, 'Client record not found.');

        DB::transaction(function () use ($request, $id, $client) {
            DB::table('client_payments')->where('client_id', $id)->delete();
            DB::table('clients')->where('id', $id)->delete();

            AuditLog::record(
                $request,
                'CLIENT_DELETED',
                'Client Management',
                "Deleted client '{$client->client_name}' (#{$id})"
            );
        });

        return response()->json(['message' => 'Client deal deleted successfully.']);
    }

    /**
     * Helper to load single client with creator metadata and date-wise payments.
     */
    private function loadClientWithDetails(int $id): object
    {
        $client = DB::table('clients')
            ->leftJoin('users as creator', 'clients.created_by_user_id', '=', 'creator.id')
            ->where('clients.id', $id)
            ->select([
                'clients.*',
                'creator.name as creator_name',
            ])
            ->first();

        abort_unless($client, 404, 'Client not found.');

        $client->payments = DB::table('client_payments')
            ->leftJoin('users as recorder', 'client_payments.recorded_by_user_id', '=', 'recorder.id')
            ->where('client_payments.client_id', $id)
            ->select([
                'client_payments.*',
                'recorder.name as recorded_by_name',
            ])
            ->orderByDesc('client_payments.payment_date')
            ->orderByDesc('client_payments.id')
            ->get();

        return $client;
    }
}
