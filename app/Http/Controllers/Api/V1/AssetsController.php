<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetsController extends Controller
{
    public function index(): JsonResponse
    {
        $assets = $this->assetQuery()
            ->orderByDesc('company_assets.id')
            ->get()
            ->map(fn ($asset) => $this->formatAsset($asset));

        return response()->json(['data' => $assets]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'serial_number' => 'required|string|max:255|unique:company_assets,serial_number',
            'branch_id' => 'required|integer|exists:branches,id',
        ]);
        $branch = DB::table('branches')->where('id', $validated['branch_id'])->first();
        $assetCode = $this->newAssetCode();

        $assetId = DB::transaction(function () use ($request, $validated, $branch, $assetCode) {
            $id = DB::table('company_assets')->insertGetId([
                'asset_code' => $assetCode,
                'name' => $validated['name'],
                'category' => $validated['category'],
                'serial_number' => $validated['serial_number'],
                'branch' => $branch->name,
                'branch_id' => $branch->id,
                'status' => 'Available',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'ASSET_REGISTERED', 'Assets', "Registered asset #{$id}");

            return $id;
        });

        return response()->json(['data' => $this->loadAsset($assetId)], 201);
    }

    public function assign(Request $request, int $assetId): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
        ]);

        DB::transaction(function () use ($request, $assetId, $validated) {
            $asset = DB::table('company_assets')->where('id', $assetId)->lockForUpdate()->first();
            abort_unless($asset, 404, 'Asset not found.');
            abort_if(
                $asset->status !== 'Available' || $asset->assigned_employee_id || filled($asset->assigned_to),
                409,
                'Only unassigned available assets can be allocated.'
            );

            $employee = DB::table('employees')
                ->where('id', $validated['employee_id'])
                ->where('status', 'active')
                ->first();
            abort_unless($employee, 422, 'Select an active employee.');
            $branch = $employee->branch_id
                ? DB::table('branches')->where('id', $employee->branch_id)->first()
                : null;
            $assignedTo = trim($employee->first_name.' '.$employee->last_name);

            DB::table('company_assets')->where('id', $assetId)->update([
                'assigned_employee_id' => $employee->id,
                'assigned_to' => $assignedTo,
                'branch_id' => $branch?->id ?? $asset->branch_id,
                'branch' => $branch?->name ?? $asset->branch,
                'status' => 'Allocated',
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'ASSET_ALLOCATED', 'Assets', "Allocated asset #{$assetId} to employee #{$employee->id}");
        });

        return response()->json(['data' => $this->loadAsset($assetId)]);
    }

    public function returnAsset(Request $request, int $assetId): JsonResponse
    {
        DB::transaction(function () use ($request, $assetId) {
            $asset = DB::table('company_assets')->where('id', $assetId)->lockForUpdate()->first();
            abort_unless($asset, 404, 'Asset not found.');
            abort_if($asset->status !== 'Allocated', 409, 'Only allocated assets can be returned.');

            DB::table('company_assets')->where('id', $assetId)->update([
                'assigned_employee_id' => null,
                'assigned_to' => null,
                'status' => 'Available',
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'ASSET_RETURNED', 'Assets', "Returned asset #{$assetId}");
        });

        return response()->json(['data' => $this->loadAsset($assetId)]);
    }

    public function updateStatus(Request $request, int $assetId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:Available,Maintenance,Retired',
        ]);

        DB::transaction(function () use ($request, $assetId, $validated) {
            $asset = DB::table('company_assets')->where('id', $assetId)->lockForUpdate()->first();
            abort_unless($asset, 404, 'Asset not found.');
            abort_if($asset->status === 'Retired' && $validated['status'] !== 'Retired', 409, 'Retired assets cannot be reactivated.');
            abort_if(
                ($asset->status === 'Allocated' || $asset->assigned_employee_id || filled($asset->assigned_to))
                    && $validated['status'] !== 'Retired',
                409,
                'Return the allocated asset before changing its availability.'
            );
            abort_if(
                ($asset->status === 'Allocated' || $asset->assigned_employee_id || filled($asset->assigned_to))
                    && $validated['status'] === 'Retired',
                409,
                'Return the allocated asset before retiring it.'
            );

            DB::table('company_assets')->where('id', $assetId)->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'ASSET_STATUS_UPDATED',
                'Assets',
                "Updated asset #{$assetId} status to {$validated['status']}"
            );
        });

        return response()->json(['data' => $this->loadAsset($assetId)]);
    }

    private function assetQuery()
    {
        return DB::table('company_assets')
            ->leftJoin('branches as asset_branch', 'asset_branch.id', '=', 'company_assets.branch_id')
            ->leftJoin('employees as assigned_employee', 'assigned_employee.id', '=', 'company_assets.assigned_employee_id')
            ->select([
                'company_assets.*',
                'asset_branch.name as branch_name',
                'assigned_employee.employee_code as assigned_employee_code',
                'assigned_employee.first_name as assigned_first_name',
                'assigned_employee.last_name as assigned_last_name',
            ]);
    }

    private function loadAsset(int $assetId): object
    {
        $asset = $this->assetQuery()->where('company_assets.id', $assetId)->first();
        abort_unless($asset, 404, 'Asset not found.');

        return $this->formatAsset($asset);
    }

    private function formatAsset(object $asset): object
    {
        $asset->branch = $asset->branch_name ?: $asset->branch;
        if ($asset->assigned_employee_id && $asset->assigned_first_name) {
            $asset->assigned_to = trim($asset->assigned_first_name.' '.$asset->assigned_last_name);
        }
        unset($asset->branch_name, $asset->assigned_first_name, $asset->assigned_last_name);

        return $asset;
    }

    private function newAssetCode(): string
    {
        do {
            $code = 'AST-'.Str::upper(Str::random(8));
        } while (DB::table('company_assets')->where('asset_code', $code)->exists());

        return $code;
    }
}
