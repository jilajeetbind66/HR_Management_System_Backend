<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Exception\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\RoleAccess;

class UploadController extends Controller
{
    private const MODULES = [
        'employee_documents',
        'employee_photos',
        'expense_receipts',
        'attendance_csv',
        'project_deliverables',
        'leave_certificates',
        'training_material',
        'training_certificates',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'in:'.implode(',', self::MODULES)],
            'record_id' => 'nullable|integer',
        ]);
        $user = $request->user();
        $module = $validated['module'];
        $query = DB::table('hrms_uploads')->where('module', $module);

        if (in_array($module, ['employee_documents', 'employee_photos'], true)
            && !$this->canManageAllEmployeeDocuments($user->role)) {
            if (!$user->employee_id) {
                abort(403);
            }
            $query->where('employee_id', $user->employee_id);
        }

        if ($module === 'expense_receipts' && $user->role !== 'super_admin') {
            if ($user->role === 'manager') {
                $employeeIds = RoleAccess::visibleEmployeeIds($user);
                if (!$employeeIds) {
                    abort(403);
                }
                $query->whereIn('employee_id', $employeeIds);
            } else {
                if (!$user->employee_id) {
                    abort(403);
                }
                $query->where('employee_id', $user->employee_id);
            }
        }

        if ($module === 'attendance_csv'
            && !in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive'], true)) {
            abort(403);
        }

        if ($module === 'leave_certificates' && !RoleAccess::isHr($user->role)) {
            abort_unless($user->employee_id, 403);
            $query->where('employee_id', $user->employee_id);
        }

        if ($module === 'training_certificates' && !RoleAccess::isHr($user->role)) {
            if ($user->role === 'manager') {
                $query->whereIn('employee_id', RoleAccess::visibleEmployeeIds($user));
            } else {
                abort_unless($user->employee_id, 403);
                $query->where('employee_id', $user->employee_id);
            }
        }

        if ($module === 'training_material'
            && !in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive'], true)) {
            $employeeIds = $user->role === 'manager'
                ? RoleAccess::visibleEmployeeIds($user)
                : [$user->employee_id ?? 0];
            abort_unless($user->employee_id || $user->role === 'manager', 403);
            $query->whereIn('record_id', DB::table('training_enrollments')
                ->select('course_id')
                ->whereIn('employee_id', $employeeIds));
        }

        if ($module === 'project_deliverables') {
            if (!in_array($user->role, ['super_admin', 'employee', 'manager'], true)) {
                abort(403);
            }
            if ($user->role !== 'super_admin') {
                $employeeIds = RoleAccess::visibleEmployeeIds($user);
                if (!$employeeIds) {
                    abort(403);
                }
                $query->whereIn('record_id', DB::table('project_assignments')
                    ->select('id')
                    ->whereIn('employee_id', $employeeIds));
            }
        }

        if (!empty($validated['record_id'])) {
            $query->where('record_id', $validated['record_id']);
        }

        return response()->json([
            'data' => $query->orderByDesc('id')->get([
                'id',
                'module',
                'employee_id',
                'record_id',
                'document_type',
                'original_filename',
                'mime_type',
                'file_size',
                'status',
                'created_at',
            ]),
        ]);
    }

    public function employeeDocuments(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $request->integer('employee_id');

        if (!$this->canManageAllEmployeeDocuments($user->role)) {
            if (!$user->employee_id || ($employeeId && $employeeId !== $user->employee_id)) {
                abort(403);
            }

            $employeeId = $user->employee_id;
        }

        $documents = DB::table('hrms_uploads')
            ->leftJoin('employees', 'hrms_uploads.employee_id', '=', 'employees.id')
            ->where('hrms_uploads.module', 'employee_documents')
            ->when($employeeId, fn ($query) => $query->where('hrms_uploads.employee_id', $employeeId))
            ->select(
                'hrms_uploads.id',
                'hrms_uploads.employee_id',
                'hrms_uploads.document_type',
                'hrms_uploads.original_filename',
                'hrms_uploads.mime_type',
                'hrms_uploads.file_size',
                'hrms_uploads.status',
                'hrms_uploads.reviewed_at',
                'hrms_uploads.review_note',
                'hrms_uploads.created_at',
                'reviewers.name as reviewed_by_name',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code'
            )
            ->leftJoin('users as reviewers', 'hrms_uploads.reviewed_by_user_id', '=', 'reviewers.id')
            ->orderByDesc('hrms_uploads.id')
            ->get();

        return response()->json(['data' => $documents]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'in:'.implode(',', self::MODULES)],
            'employee_id' => 'nullable|integer|exists:employees,id',
            'record_id' => 'nullable|integer',
            'document_type' => 'nullable|string|max:100',
            'file' => 'required|file|max:' . (in_array($request->input('module'), ['leave_certificates', 'training_certificates'], true) ? 10240 : 20480),
        ]);

        $module = $validated['module'];
        $this->validateFileType($request, $module);
        if ($module === 'training_material' && empty($validated['record_id'])) {
            return response()->json([
                'message' => 'Course material must be attached to a training course.',
                'errors' => ['record_id' => ['A training course is required.']],
            ], 422);
        }
        if ($module === 'training_certificates' && empty($validated['record_id'])) {
            return response()->json([
                'message' => 'A training certificate must be attached to an enrollment.',
                'errors' => ['record_id' => ['A training enrollment is required.']],
            ], 422);
        }

        if ($module === 'employee_documents' && empty($validated['document_type'])) {
            return response()->json([
                'message' => 'The document type field is required.',
                'errors' => ['document_type' => ['The document type field is required.']],
            ], 422);
        }

        $employeeId = in_array($module, ['attendance_csv', 'training_material'], true)
            ? null
            : (isset($validated['employee_id'])
                ? (int) $validated['employee_id']
                : ($request->user()->employee_id ? (int) $request->user()->employee_id : null));
        $recordId = $module === 'attendance_csv'
            ? null
            : (isset($validated['record_id']) ? (int) $validated['record_id'] : null);
        $this->authorizeUpload($request, $module, $employeeId, $recordId);

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (!$cloudName || !$apiKey || !$apiSecret) {
            return response()->json([
                'message' => 'Cloudinary is not configured. Set CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET.',
                'code' => 'CLOUDINARY_NOT_CONFIGURED',
            ], 503);
        }

        $deliveryType = $module === 'employee_photos' ? 'upload' : 'authenticated';
        $configuration = sprintf(
            'cloudinary://%s:%s@%s?secure=true',
            rawurlencode($apiKey),
            rawurlencode($apiSecret),
            $cloudName
        );
        $publicId = (string) Str::uuid();

        try {
            $result = (new UploadApi($configuration))->upload($request->file('file')->getRealPath(), [
                'resource_type' => 'auto',
                'type' => $deliveryType,
                'folder' => 'pinweb-hrms/'.$module,
                'public_id' => $publicId,
                'overwrite' => false,
                'use_filename' => false,
                'unique_filename' => false,
            ]);
        } catch (ApiError $exception) {
            Log::error('Cloudinary upload failed.', [
                'module' => $module,
                'user_id' => $request->user()->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The file could not be uploaded to Cloudinary.',
                'code' => 'CLOUDINARY_UPLOAD_FAILED',
            ], 502);
        }

        $asset = $result->getArrayCopy();
        $uploadId = DB::table('hrms_uploads')->insertGetId([
            'module' => $module,
            'employee_id' => $employeeId,
            'record_id' => $recordId,
            'uploaded_by_user_id' => $request->user()->id,
            'document_type' => $validated['document_type'] ?? null,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'cloudinary_public_id' => $asset['public_id'],
            'cloudinary_resource_type' => $asset['resource_type'],
            'cloudinary_delivery_type' => $deliveryType,
            'cloudinary_format' => $asset['format'] ?? null,
            'mime_type' => $request->file('file')->getMimeType(),
            'file_size' => $request->file('file')->getSize(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($module === 'employee_photos') {
            DB::table('employees')->where('id', $employeeId)->update([
                'avatar' => $asset['secure_url'],
                'updated_at' => now(),
            ]);
        }
        if ($module === 'training_material') {
            DB::table('training_courses')->where('id', $recordId)->update([
                'material_upload_id' => $uploadId,
                'updated_at' => now(),
            ]);
        }
        if ($module === 'training_certificates') {
            DB::table('training_enrollments')->where('id', $recordId)->update([
                'certificate_upload_id' => $uploadId,
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $uploadId,
                'module' => $module,
                'employee_id' => $employeeId,
                'record_id' => $recordId,
                'document_type' => $validated['document_type'] ?? null,
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'mime_type' => $request->file('file')->getMimeType(),
                'file_size' => $request->file('file')->getSize(),
                'status' => 'pending',
                'url' => $deliveryType === 'upload' ? $asset['secure_url'] : null,
            ],
        ], 201);
    }

    public function download(Request $request, int $id): JsonResponse
    {
        $upload = DB::table('hrms_uploads')->where('id', $id)->first();
        if (!$upload) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $this->authorizeUpload(
            $request,
            $upload->module,
            $upload->employee_id,
            $upload->record_id,
            true
        );

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (!$cloudName || !$apiKey || !$apiSecret) {
            return response()->json([
                'message' => 'Cloudinary is not configured.',
                'code' => 'CLOUDINARY_NOT_CONFIGURED',
            ], 503);
        }

        $configuration = sprintf(
            'cloudinary://%s:%s@%s?secure=true',
            rawurlencode($apiKey),
            rawurlencode($apiSecret),
            $cloudName
        );
        $url = (new UploadApi($configuration))->privateDownloadUrl(
            $upload->cloudinary_public_id,
            $upload->cloudinary_format ?? '',
            [
                'type' => $upload->cloudinary_delivery_type,
                'resource_type' => $upload->cloudinary_resource_type,
                'attachment' => true,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ]
        );

        return response()->json([
            'data' => [
                'url' => $url,
                'expires_at' => now()->addMinutes(5)->toISOString(),
            ],
        ]);
    }

    private function validateFileType(Request $request, string $module): void
    {
        $extensions = match ($module) {
            'employee_documents', 'expense_receipts' => 'pdf,jpg,jpeg,png',
            'leave_certificates', 'training_certificates' => 'pdf,jpg,jpeg,png',
            'employee_photos' => 'jpg,jpeg,png,webp',
            'attendance_csv' => 'csv,txt',
            'project_deliverables' => 'pdf,zip,jpg,jpeg,png,doc,docx,xls,xlsx',
            'training_material' => 'pdf',
        };

        $request->validate([
            'file' => ['mimes:'.$extensions],
        ]);
    }

    private function authorizeUpload(
        Request $request,
        string $module,
        ?int $employeeId,
        ?int $recordId,
        bool $isDownload = false
    ): void
    {
        $user = $request->user();
        $isHr = $this->isHr($user->role);

        if ($module === 'leave_certificates') {
            if ($isDownload && $isHr) {
                return;
            }
            abort_unless(
                $user->employee_id && (int) $user->employee_id === (int) $employeeId,
                403
            );
            if ($recordId) {
                abort_unless(DB::table('leave_requests')
                    ->where('id', $recordId)
                    ->where('employee_id', $employeeId)
                    ->exists(), 403);
            }
            return;
        }

        if (in_array($module, ['employee_documents', 'employee_photos'], true)) {
            if (
                !$employeeId
                || (!$this->canManageAllEmployeeDocuments($user->role)
                    && (int) $user->employee_id !== $employeeId)
            ) {
                abort(403);
            }
        }

        if ($module === 'expense_receipts') {
            if (!$employeeId) {
                abort(403);
            }

            if ($recordId && !DB::table('expense_claims')
                ->where('id', $recordId)
                ->where('employee_id', $employeeId)
                ->when(!$isDownload, fn ($q) => $q->where('status', 'submitted'))
                ->exists()) {
                abort(403, $isDownload ? 'Forbidden.' : 'Receipts can only be uploaded to submitted expense claims.');
            }

            if (!$isDownload && !$recordId) {
                abort(403, 'Expense receipts must be attached to a claim.');
            }

            if ($user->role === 'super_admin') {
                return;
            }

            if ((int) $user->employee_id === $employeeId) {
                return;
            }

            if ($isDownload && $user->role === 'manager' && RoleAccess::managesEmployee($user, $employeeId)) {
                return;
            }

            abort(403);
        }

        if ($module === 'attendance_csv') {
            $allowedRoles = $isDownload
                ? ['super_admin', 'hr_admin', 'hr_executive']
                : ['super_admin', 'hr_admin'];
            abort_unless(in_array($user->role, $allowedRoles, true), 403);
        }

        if ($module === 'project_deliverables') {
            $assignment = $recordId
                ? DB::table('project_assignments')->where('id', $recordId)->first()
                : null;

            if (!$assignment || !RoleAccess::canManageAssignment($user, $assignment)) {
                abort(403);
            }
        }

        if ($module === 'training_material') {
            abort_unless($recordId && DB::table('training_courses')->where('id', $recordId)->exists(), 404);
            if (!$isDownload) {
                abort_unless(in_array($user->role, ['super_admin', 'hr_admin'], true), 403);

                return;
            }
            if (in_array($user->role, ['super_admin', 'hr_admin', 'hr_executive'], true)) {
                return;
            }
            $employeeIds = $user->role === 'manager'
                ? RoleAccess::visibleEmployeeIds($user)
                : [$user->employee_id ?? 0];
            abort_unless(DB::table('training_enrollments')
                ->where('course_id', $recordId)
                ->whereIn('employee_id', $employeeIds)
                ->exists(), 403);
        }

        if ($module === 'training_certificates') {
            $enrollment = $recordId
                ? DB::table('training_enrollments')->where('id', $recordId)->first()
                : null;
            abort_unless($enrollment && (int) $enrollment->employee_id === (int) $employeeId, 403);

            if ($isDownload && RoleAccess::isHr($user->role)) {
                return;
            }
            if ($isDownload && $user->role === 'manager'
                && RoleAccess::managesEmployee($user, (int) $enrollment->employee_id)) {
                return;
            }
            abort_unless(
                $user->employee_id
                    && (int) $user->employee_id === (int) $enrollment->employee_id
                    && in_array($user->role, ['employee', 'manager'], true),
                403
            );
        }
    }

    private function isHr(string $role): bool
    {
        return in_array($role, ['super_admin', 'hr_admin', 'hr_executive'], true);
    }

    private function canManageAllEmployeeDocuments(string $role): bool
    {
        return in_array($role, ['super_admin', 'hr_admin'], true);
    }
}
