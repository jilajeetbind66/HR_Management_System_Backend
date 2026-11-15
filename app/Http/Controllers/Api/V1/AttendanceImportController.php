<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Carbon\Carbon;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Exception\ApiError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AttendanceImportController extends Controller
{
    private const TIMEZONE = 'Asia/Kolkata';

    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    private const MAX_CSV_ROWS = 100000;

    private const MAX_ERROR_DETAILS = 100;

    public function index(): JsonResponse
    {
        $imports = DB::table('hrms_uploads')
            ->leftJoin('attendance_csv_imports', 'hrms_uploads.id', '=', 'attendance_csv_imports.upload_id')
            ->leftJoin('users as uploaders', 'hrms_uploads.uploaded_by_user_id', '=', 'uploaders.id')
            ->where('hrms_uploads.module', 'attendance_csv')
            ->orderByDesc('hrms_uploads.id')
            ->get([
                'hrms_uploads.id',
                'hrms_uploads.original_filename',
                'hrms_uploads.file_size',
                'hrms_uploads.status as upload_status',
                'hrms_uploads.created_at as uploaded_at',
                'uploaders.name as uploaded_by_name',
                'attendance_csv_imports.status as import_status',
                'attendance_csv_imports.row_count',
                'attendance_csv_imports.attendance_day_count',
                'attendance_csv_imports.imported_count',
                'attendance_csv_imports.existing_count',
                'attendance_csv_imports.duplicate_count',
                'attendance_csv_imports.error_count',
                'attendance_csv_imports.summary',
                'attendance_csv_imports.processed_at',
            ]);

        $imports->each(function ($import) {
            $import->summary = $import->summary
                ? json_decode($import->summary, true, 512, JSON_THROW_ON_ERROR)
                : null;
        });

        return response()->json(['data' => $imports]);
    }

    public function preview(int $uploadId): JsonResponse
    {
        $upload = $this->loadUpload($uploadId);
        $existingImport = DB::table('attendance_csv_imports')->where('upload_id', $uploadId)->first();
        if ($existingImport?->status === 'processed') {
            return response()->json(['data' => $this->decodeSummary($existingImport->summary)]);
        }

        $parsed = $this->parseCsv($this->downloadCsv($upload));
        $summary = $parsed['summary'];
        $summary['upload_id'] = $uploadId;
        $summary['file_name'] = $upload->original_filename;
        $summary['status'] = $summary['error_count'] > 0 ? 'invalid' : 'previewed';

        $importId = DB::transaction(function () use ($uploadId, $summary) {
            DB::table('hrms_uploads')->where('id', $uploadId)->lockForUpdate()->first();
            $existingImport = DB::table('attendance_csv_imports')
                ->where('upload_id', $uploadId)
                ->lockForUpdate()
                ->first();

            if ($existingImport?->status === 'processed') {
                return (int) $existingImport->id;
            }

            $values = [
                'status' => $summary['status'],
                'row_count' => $summary['row_count'],
                'attendance_day_count' => $summary['attendance_day_count'],
                'existing_count' => $summary['existing_count'],
                'duplicate_count' => $summary['duplicate_count'],
                'error_count' => $summary['error_count'],
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                'previewed_at' => now(),
                'updated_at' => now(),
            ];

            if ($existingImport) {
                $summary['import_id'] = (int) $existingImport->id;
                $values['summary'] = json_encode($summary, JSON_THROW_ON_ERROR);
                DB::table('attendance_csv_imports')->where('id', $existingImport->id)->update($values);

                return (int) $existingImport->id;
            }

            $importId = DB::table('attendance_csv_imports')->insertGetId([
                ...$values,
                'upload_id' => $uploadId,
                'created_at' => now(),
            ]);
            $summary['import_id'] = $importId;
            DB::table('attendance_csv_imports')->where('id', $importId)->update([
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
            ]);

            return $importId;
        });

        $stored = DB::table('attendance_csv_imports')->where('id', $importId)->first();

        return response()->json(['data' => $this->decodeSummary($stored->summary)]);
    }

    public function process(Request $request, int $uploadId): JsonResponse
    {
        $upload = $this->loadUpload($uploadId);
        $import = DB::table('attendance_csv_imports')->where('upload_id', $uploadId)->first();
        abort_unless($import, 409, 'Preview this attendance file before processing it.');
        if ($import->status === 'processed') {
            return response()->json(['data' => $this->decodeSummary($import->summary)]);
        }

        $parsed = $this->parseCsv($this->downloadCsv($upload));
        $summary = $parsed['summary'];
        $summary['upload_id'] = $uploadId;
        $summary['file_name'] = $upload->original_filename;
        $summary['import_id'] = (int) $import->id;
        if ($summary['error_count'] > 0) {
            $summary['status'] = 'invalid';
            $processedSummary = $this->updateImportPreview($import->id, $summary);
            if ($processedSummary) {
                return response()->json(['data' => $processedSummary]);
            }

            return response()->json([
                'message' => 'The file contains invalid rows. Correct the CSV and upload it again.',
                'data' => $summary,
            ], 422);
        }

        $result = DB::transaction(function () use ($request, $uploadId, $import, $parsed, $summary) {
            $lockedImport = DB::table('attendance_csv_imports')
                ->where('id', $import->id)
                ->lockForUpdate()
                ->first();
            abort_unless($lockedImport, 404);
            if ($lockedImport->status === 'processed') {
                return [
                    'status' => 200,
                    'summary' => $this->decodeSummary($lockedImport->summary),
                ];
            }

            $records = array_values($parsed['records']);
            $existingKeys = $this->existingAttendanceKeys($records);
            $importedCount = 0;
            $existingCount = 0;

            foreach ($records as $record) {
                $key = $record['employee_id'].'|'.$record['date'];
                if (isset($existingKeys[$key])) {
                    $existingCount++;

                    continue;
                }

                $inserted = DB::table('attendance_records')->insertOrIgnore([
                    'employee_id' => $record['employee_id'],
                    'attendance_csv_import_id' => $lockedImport->id,
                    'date' => $record['date'],
                    'punch_in' => $record['punch_in'],
                    'punch_out' => $record['punch_out'],
                    'status' => 'Present',
                    'work_hours' => $record['work_hours'],
                    'ip_address' => null,
                    'location' => $record['location'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 1) {
                    $importedCount++;

                    continue;
                }

                $exists = DB::table('attendance_records')
                    ->where('employee_id', $record['employee_id'])
                    ->where('date', $record['date'])
                    ->exists();
                if (! $exists) {
                    throw new RuntimeException('Attendance CSV row could not be persisted.');
                }
                $existingCount++;
            }

            $summary['status'] = 'processed';
            $summary['imported_count'] = $importedCount;
            $summary['existing_count'] = $existingCount;
            $summary['new_record_count'] = $importedCount;
            $summary['can_process'] = false;
            $now = now();

            DB::table('attendance_csv_imports')->where('id', $lockedImport->id)->update([
                'status' => 'processed',
                'processed_by_user_id' => $request->user()->id,
                'row_count' => $summary['row_count'],
                'attendance_day_count' => $summary['attendance_day_count'],
                'imported_count' => $importedCount,
                'existing_count' => $existingCount,
                'duplicate_count' => $summary['duplicate_count'],
                'error_count' => $summary['error_count'],
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                'processed_at' => $now,
                'updated_at' => $now,
            ]);
            AuditLog::record(
                $request,
                'ATTENDANCE_CSV_PROCESSED',
                'Attendance',
                "Processed attendance CSV #{$uploadId}: {$importedCount} days imported, {$existingCount} existing days skipped"
            );

            return ['status' => 200, 'summary' => $summary];
        });

        return response()->json(['data' => $result['summary']], $result['status']);
    }

    private function loadUpload(int $uploadId): object
    {
        $upload = DB::table('hrms_uploads')
            ->where('id', $uploadId)
            ->where('module', 'attendance_csv')
            ->first();
        abort_if(! $upload, 404, 'Attendance CSV file not found.');

        return $upload;
    }

    private function downloadCsv(object $upload): string
    {
        if ($upload->file_size > self::MAX_FILE_BYTES) {
            abort(413, 'Attendance CSV files cannot exceed 20 MB.');
        }
        if ($upload->cloudinary_delivery_type !== 'authenticated') {
            abort(422, 'Attendance CSV files must be stored as private Cloudinary assets.');
        }

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        if (! $cloudName || ! $apiKey || ! $apiSecret) {
            abort(503, 'Cloudinary is not configured.');
        }

        $configuration = sprintf(
            'cloudinary://%s:%s@%s?secure=true',
            rawurlencode($apiKey),
            rawurlencode($apiSecret),
            $cloudName
        );

        try {
            $url = (new UploadApi($configuration))->privateDownloadUrl(
                $upload->cloudinary_public_id,
                $upload->cloudinary_format ?? '',
                [
                    'type' => $upload->cloudinary_delivery_type,
                    'resource_type' => $upload->cloudinary_resource_type,
                    'expires_at' => now()->addMinutes(5)->timestamp,
                ]
            );
        } catch (ApiError $exception) {
            Log::error('Cloudinary attendance CSV URL generation failed.', ['upload_id' => $upload->id]);
            abort(502, 'The private attendance file could not be accessed.');
        }

        try {
            $response = Http::timeout(30)->get($url);
        } catch (ConnectionException $exception) {
            Log::error('Cloudinary attendance CSV download failed.', ['upload_id' => $upload->id]);
            abort(502, 'The private attendance file could not be downloaded.');
        }

        if (! $response->successful()) {
            Log::warning('Cloudinary returned an unsuccessful attendance CSV response.', [
                'upload_id' => $upload->id,
                'status' => $response->status(),
            ]);
            abort(502, 'The private attendance file could not be downloaded.');
        }

        $content = $response->body();
        if (strlen($content) > self::MAX_FILE_BYTES) {
            abort(413, 'Attendance CSV files cannot exceed 20 MB.');
        }
        if ($content === '') {
            abort(422, 'The attendance CSV file is empty.');
        }

        return $content;
    }

    private function parseCsv(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for attendance CSV parsing.');
        }

        fwrite($stream, $content);
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        if (! is_array($header)) {
            fclose($stream);

            return $this->invalidCsvResult('The CSV header row is missing.');
        }

        $headers = array_map(
            fn ($value) => strtolower(trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF")),
            $header
        );
        if (count($headers) !== count(array_unique($headers))) {
            fclose($stream);

            return $this->invalidCsvResult('The CSV contains duplicate column names.');
        }
        foreach (['employee_code', 'punch_time'] as $requiredHeader) {
            if (! in_array($requiredHeader, $headers, true)) {
                fclose($stream);

                return $this->invalidCsvResult("The required {$requiredHeader} column is missing.");
            }
        }

        $headerIndexes = array_flip($headers);
        $employees = DB::table('employees')
            ->get(['id', 'employee_code'])
            ->keyBy(fn ($employee) => strtoupper($employee->employee_code));
        $records = [];
        $seenEvents = [];
        $duplicateCount = 0;
        $rowCount = 0;
        $errors = [];
        $errorCount = 0;
        $rowNumber = 1;

        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rowNumber++;
            if (! array_filter($row, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }
            $rowCount++;
            if ($rowCount > self::MAX_CSV_ROWS) {
                $this->addError($errors, $errorCount, $rowNumber, 'CSV files cannot contain more than 100,000 punch rows.');
                break;
            }

            if (count($row) !== count($headers)) {
                $this->addError($errors, $errorCount, $rowNumber, 'The number of CSV values does not match the header columns.');

                continue;
            }

            $employeeCode = strtoupper(trim((string) $row[$headerIndexes['employee_code']]));
            $punchTime = trim((string) $row[$headerIndexes['punch_time']]);
            $deviceId = isset($headerIndexes['device_id']) ? trim((string) $row[$headerIndexes['device_id']]) : '';
            $locationCode = isset($headerIndexes['location_code']) ? trim((string) $row[$headerIndexes['location_code']]) : '';

            if ($employeeCode === '' || $punchTime === '') {
                $this->addError($errors, $errorCount, $rowNumber, 'Employee code and punch time are required.');

                continue;
            }
            if (mb_strlen($deviceId) > 100 || mb_strlen($locationCode) > 100) {
                $this->addError($errors, $errorCount, $rowNumber, 'Device ID and location code cannot exceed 100 characters.');

                continue;
            }

            $timestamp = $this->parsePunchTimestamp($punchTime);
            if (! $timestamp) {
                $this->addError($errors, $errorCount, $rowNumber, 'Punch time has an unsupported or invalid date-time format.');

                continue;
            }

            $employee = $employees->get($employeeCode);
            if (! $employee) {
                $this->addError(
                    $errors,
                    $errorCount,
                    $rowNumber,
                    "Employee code {$employeeCode} was not found."
                );

                continue;
            }

            $eventKey = $employee->id.'|'.$timestamp->format('U.u');
            if (isset($seenEvents[$eventKey])) {
                $duplicateCount++;

                continue;
            }
            $seenEvents[$eventKey] = true;

            $date = $timestamp->toDateString();
            $recordKey = $employee->id.'|'.$date;
            $timestampValue = $timestamp->getTimestamp();
            if (! isset($records[$recordKey])) {
                $records[$recordKey] = [
                    'employee_id' => (int) $employee->id,
                    'date' => $date,
                    'first_timestamp' => $timestampValue,
                    'last_timestamp' => $timestampValue,
                    'punch_in' => $timestamp->format('h:i A'),
                    'punch_out' => null,
                    'location_code' => $locationCode,
                    'device_id' => $deviceId,
                ];

                continue;
            }

            if ($timestampValue < $records[$recordKey]['first_timestamp']) {
                $records[$recordKey]['first_timestamp'] = $timestampValue;
                $records[$recordKey]['punch_in'] = $timestamp->format('h:i A');
                $records[$recordKey]['location_code'] = $locationCode;
                $records[$recordKey]['device_id'] = $deviceId;
            }
            if ($timestampValue > $records[$recordKey]['last_timestamp']) {
                $records[$recordKey]['last_timestamp'] = $timestampValue;
                $records[$recordKey]['punch_out'] = $timestamp->format('h:i A');
            }
        }
        fclose($stream);

        if ($rowCount === 0) {
            $this->addError($errors, $errorCount, 1, 'The CSV does not contain any punch rows.');
        }

        foreach ($records as &$record) {
            $hasPunchOut = $record['first_timestamp'] !== $record['last_timestamp'];
            $record['work_hours'] = $hasPunchOut
                ? round(($record['last_timestamp'] - $record['first_timestamp']) / 3600, 2)
                : 0;
            $locationParts = array_filter([
                $record['location_code'] !== '' ? $record['location_code'] : null,
                $record['device_id'] !== '' ? "Device {$record['device_id']}" : null,
            ]);
            $record['location'] = $locationParts
                ? 'Biometric: '.implode(' / ', $locationParts)
                : 'Biometric CSV Import';
            unset($record['first_timestamp'], $record['last_timestamp'], $record['location_code'], $record['device_id']);
        }
        unset($record);

        $existingKeys = $this->existingAttendanceKeys(array_values($records));
        $existingCount = count($existingKeys);
        $summary = [
            'row_count' => $rowCount,
            'attendance_day_count' => count($records),
            'new_record_count' => count($records) - $existingCount,
            'imported_count' => 0,
            'existing_count' => $existingCount,
            'duplicate_count' => $duplicateCount,
            'error_count' => $errorCount,
            'errors' => $errors,
            'can_process' => $errorCount === 0,
        ];

        return ['summary' => $summary, 'records' => $records];
    }

    private function parsePunchTimestamp(string $value): ?Carbon
    {
        $formats = [
            ['Y-m-d\TH:i:s.uP', self::TIMEZONE],
            ['Y-m-d\TH:i:sP', self::TIMEZONE],
            ['Y-m-d\TH:i:s.u\Z', 'UTC'],
            ['Y-m-d\TH:i:s\Z', 'UTC'],
            ['Y-m-d H:i:s', self::TIMEZONE],
            ['Y-m-d H:i', self::TIMEZONE],
            ['Y-m-d h:i:s A', self::TIMEZONE],
            ['Y-m-d h:i A', self::TIMEZONE],
            ['d/m/Y H:i:s', self::TIMEZONE],
            ['d/m/Y H:i', self::TIMEZONE],
            ['d-m-Y H:i:s', self::TIMEZONE],
            ['d-m-Y H:i', self::TIMEZONE],
        ];

        foreach ($formats as [$format, $timezone]) {
            if (! Carbon::hasFormatWithModifiers($value, $format)) {
                continue;
            }

            $timestamp = Carbon::createFromFormat($format, $value, $timezone);
            if ($timestamp !== false && strtoupper($timestamp->format($format)) === strtoupper($value)) {
                return $timestamp->setTimezone(self::TIMEZONE);
            }
        }

        return null;
    }

    private function existingAttendanceKeys(array $records): array
    {
        $existingKeys = [];
        foreach (array_chunk($records, 200) as $chunk) {
            $query = DB::table('attendance_records')
                ->where(function ($query) use ($chunk) {
                    foreach ($chunk as $record) {
                        $query->orWhere(function ($recordQuery) use ($record) {
                            $recordQuery->where('employee_id', $record['employee_id'])
                                ->where('date', $record['date']);
                        });
                    }
                });

            foreach ($query->get(['employee_id', 'date']) as $existing) {
                $existingKeys[$existing->employee_id.'|'.$existing->date] = true;
            }
        }

        return $existingKeys;
    }

    private function invalidCsvResult(string $message): array
    {
        return [
            'summary' => [
                'row_count' => 0,
                'attendance_day_count' => 0,
                'new_record_count' => 0,
                'imported_count' => 0,
                'existing_count' => 0,
                'duplicate_count' => 0,
                'error_count' => 1,
                'errors' => [['row' => 1, 'message' => $message]],
                'can_process' => false,
            ],
            'records' => [],
        ];
    }

    private function addError(array &$errors, int &$errorCount, int $row, string $message): void
    {
        $errorCount++;
        if (count($errors) < self::MAX_ERROR_DETAILS) {
            $errors[] = ['row' => $row, 'message' => $message];
        }
    }

    private function decodeSummary(?string $summary): array
    {
        return $summary
            ? json_decode($summary, true, 512, JSON_THROW_ON_ERROR)
            : [];
    }

    private function updateImportPreview(int $importId, array $summary): ?array
    {
        return DB::transaction(function () use ($importId, $summary) {
            $import = DB::table('attendance_csv_imports')->where('id', $importId)->lockForUpdate()->first();
            abort_unless($import, 404);
            if ($import->status === 'processed') {
                return $this->decodeSummary($import->summary);
            }

            DB::table('attendance_csv_imports')->where('id', $importId)->update([
                'status' => 'invalid',
                'row_count' => $summary['row_count'],
                'attendance_day_count' => $summary['attendance_day_count'],
                'existing_count' => $summary['existing_count'],
                'duplicate_count' => $summary['duplicate_count'],
                'error_count' => $summary['error_count'],
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return null;
        });
    }
}
