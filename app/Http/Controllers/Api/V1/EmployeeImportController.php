<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\EmployeeAccountProvisioner;
use App\Support\EmployeeAccountSetupNotifier;
use App\Support\EmployeeConstants;
use App\Support\EmployeeCodeGenerator;
use App\Support\EmployeeOrganization;
use App\Support\ProbationStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmployeeImportController extends Controller
{
    private const REQUIRED_HEADERS = [
        'first_name',
        'last_name',
        'email',
        'branch_id',
        'department',
        'designation',
        'date_of_joining',
    ];

    public function preview(Request $request): JsonResponse
    {
        $rows = $this->validatedRows($request);

        return response()->json(['data' => $this->report($rows)]);
    }

    public function import(Request $request): JsonResponse
    {
        $rows = $this->validatedRows($request);
        $report = $this->report($rows);

        if ($report['invalid_count'] > 0) {
            return response()->json([
                'message' => 'No employees were imported. Correct every invalid row and upload the CSV again.',
                'data' => $report,
            ], 422);
        }

        if ($rows !== [] && config('mail.default') === 'log') {
            return response()->json([
                'message' => 'Email delivery is not configured. Configure SMTP before importing employee accounts.',
            ], 503);
        }

        $roleOptions = EmployeeAccountProvisioner::assignableRoles();
        if ($request->user()->role === 'hr_admin') {
            $roleOptions = array_values(array_intersect($roleOptions, [
                'employee',
                'manager',
                'hr_executive',
                'recruiter',
            ]));
        }

        try {
            $result = DB::transaction(function () use ($request, $rows, $roleOptions): array {
                $credentials = [];

                foreach ($rows as $importRow) {
                    $row = $importRow['data'];
                    $role = $row['role'] ?: 'employee';
                    if (!in_array($role, $roleOptions, true)) {
                        throw ValidationException::withMessages([
                            "rows.{$importRow['line']}.role" => ['The selected role cannot be assigned by your account.'],
                        ]);
                    }

                    $organization = EmployeeOrganization::resolve(
                        $row['department_id'] !== '' ? (int) $row['department_id'] : null,
                        $row['designation_id'] !== '' ? (int) $row['designation_id'] : null,
                        $row['department'],
                        $row['designation']
                    );

                    $code = app(EmployeeCodeGenerator::class)->next();

                    $now = now();
                    $employeeId = DB::table('employees')->insertGetId([
                        'employee_code' => $code,
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'email' => $row['email'],
                        'phone' => $row['phone'] ?: null,
                        'gender' => $row['gender'] ?: EmployeeConstants::GENDER_OTHER,
                        'date_of_birth' => $row['date_of_birth'] ?: null,
                        'designation' => $organization['designation'],
                        'designation_id' => $organization['designation_id'],
                        'department' => $organization['department'],
                        'department_id' => $organization['department_id'],
                        'branch_id' => (int) $row['branch_id'],
                        'date_of_joining' => $row['date_of_joining'],
                        'employment_type' => $row['employment_type'] ?: 'Full-Time Permanent',
                        'probation_status' => ProbationStatus::fromJoiningDate($row['date_of_joining']),
                        'status' => 'active',
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    app(EmployeeAccountProvisioner::class)->provision(
                        $employeeId,
                        trim($row['first_name'] . ' ' . $row['last_name']),
                        $row['email'],
                        $role
                    );
                    $user = User::where('employee_id', $employeeId)->firstOrFail();
                    app(EmployeeAccountSetupNotifier::class)->send($user, $code, $role);

                    DB::table('employee_statutory_details')->insert([
                        'employee_id' => $employeeId,
                        'pan' => $row['pan'] ?: null,
                        'uan' => $row['uan'] ?: null,
                        'pf_applicable' => $this->booleanValue($row['pf_applicable'], true),
                        'esi_applicable' => $this->booleanValue($row['esi_applicable'], false),
                        'pt_state' => $row['pt_state'] ?: 'Uttar Pradesh',
                        'tax_regime' => $row['tax_regime'] ?: 'New',
                        'ctc_annual' => $row['ctc_annual'] !== '' ? $row['ctc_annual'] : 0,
                        'gross_monthly' => $row['gross_monthly'] !== '' ? $row['gross_monthly'] : 0,
                        'basic_monthly' => $row['basic_monthly'] !== '' ? $row['basic_monthly'] : 0,
                        'bank_name' => $row['bank_name'] ?: null,
                        'account_number' => $row['account_number'] ?: null,
                        'ifsc_code' => $row['ifsc_code'] ?: null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('leave_balances')->insert([
                        'employee_id' => $employeeId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $credentials[] = [
                        'employee_code' => $code,
                        'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                        'login_identifier' => $row['email'],
                        'role' => $role,
                        'setup_email_sent' => true,
                    ];
                }

                AuditLog::record(
                    $request,
                    'EMPLOYEES_BULK_IMPORTED',
                    'Employees',
                    'Imported ' . count($credentials) . ' employees from CSV'
                );

                return $credentials;
            });
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return response()->json([
                'message' => 'No employee accounts were imported because a setup email could not be sent. Check SMTP settings and try again.',
            ], 503);
        }

        return response()->json([
            'message' => count($result) . ' employees imported successfully.',
            'data' => [
                'imported_count' => count($result),
                'credentials' => $result,
                'setup_link_expires_in_minutes' => (int) config('auth.passwords.users.expire'),
            ],
        ], 201);
    }

    private function validatedRows(Request $request): array
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            abort(422, 'The uploaded CSV file could not be read.');
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers)) {
            fclose($handle);
            abort(422, 'The uploaded CSV is empty.');
        }
        $headers = array_map(static function ($header): string {
            $header = trim((string) $header);
            return strtolower(ltrim($header, "\xEF\xBB\xBF"));
        }, $headers);

        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
        if ($missingHeaders !== []) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => ['Missing required CSV columns: ' . implode(', ', $missingHeaders) . '.'],
            ]);
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            if (count($rows) >= 500) {
                fclose($handle);
                throw ValidationException::withMessages([
                    'file' => ['The CSV may contain no more than 500 employee rows.'],
                ]);
            }
            if (count($values) !== count($headers)) {
                $rows[] = ['line' => $line, 'data' => [], 'errors' => ['row' => ['The number of values does not match the CSV headers.']]];
                continue;
            }

            $row = array_combine($headers, array_map(static fn ($value): string => trim((string) $value), $values));
            $row = array_merge([
                'role' => '',
                'phone' => '',
                'gender' => '',
                'date_of_birth' => '',
                'department_id' => '',
                'designation_id' => '',
                'employment_type' => '',
                'pan' => '',
                'uan' => '',
                'pf_applicable' => '',
                'esi_applicable' => '',
                'pt_state' => '',
                'tax_regime' => '',
                'ctc_annual' => '',
                'gross_monthly' => '',
                'basic_monthly' => '',
                'bank_name' => '',
                'account_number' => '',
                'ifsc_code' => '',
            ], $row);
            $row['email'] = strtolower($row['email']);
            $validationRow = $row;
            if (isset($validationRow['pf_applicable']) && is_string($validationRow['pf_applicable'])) {
                $validationRow['pf_applicable'] = strtolower(trim($validationRow['pf_applicable']));
            }
            if (isset($validationRow['esi_applicable']) && is_string($validationRow['esi_applicable'])) {
                $validationRow['esi_applicable'] = strtolower(trim($validationRow['esi_applicable']));
            }
            foreach ([
                'role', 'phone', 'gender', 'date_of_birth', 'department_id', 'designation_id',
                'employment_type', 'pan', 'uan', 'pf_applicable', 'esi_applicable', 'pt_state',
                'tax_regime', 'ctc_annual', 'gross_monthly', 'basic_monthly', 'bank_name',
                'account_number', 'ifsc_code',
            ] as $optionalField) {
                if ($validationRow[$optionalField] === '') {
                    $validationRow[$optionalField] = null;
                }
            }

            $roleOptions = EmployeeAccountProvisioner::assignableRoles();
            if ($request->user()->role === 'hr_admin') {
                $roleOptions = array_values(array_intersect($roleOptions, [
                    'employee',
                    'manager',
                    'hr_executive',
                    'recruiter',
                ]));
            }

            $validator = Validator::make($validationRow, [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'role' => ['nullable', Rule::in($roleOptions)],
                'phone' => 'nullable|digits:10',
                'gender' => ['nullable', Rule::in(EmployeeConstants::GENDERS)],
                'date_of_birth' => 'nullable|date',
                'branch_id' => 'required|integer|exists:branches,id',
                'department_id' => 'nullable|integer|exists:departments,id',
                'designation_id' => 'nullable|integer|exists:designations,id',
                'department' => 'required|string|max:255',
                'designation' => 'required|string|max:255',
                'date_of_joining' => 'required|date',
                'employment_type' => 'nullable|string|max:100',
                'pan' => 'nullable|string|max:10',
                'uan' => 'nullable|string|max:12',
                'pf_applicable' => ['nullable', Rule::in(['0', '1', 'true', 'false', 'yes', 'no'])],
                'esi_applicable' => ['nullable', Rule::in(['0', '1', 'true', 'false', 'yes', 'no'])],
                'pt_state' => 'nullable|string|max:255',
                'tax_regime' => 'nullable|in:New,Old',
                'ctc_annual' => 'nullable|numeric|min:0',
                'gross_monthly' => 'nullable|numeric|min:0',
                'basic_monthly' => 'nullable|numeric|min:0',
                'bank_name' => 'nullable|string|max:255',
                'account_number' => 'nullable|string|max:34',
                'ifsc_code' => ['nullable', 'string', 'max:15', Rule::regex('/^[A-Z]{4}0[A-Z0-9]{6}$/i')],
            ]);
            $errors = $validator->errors()->toArray();

            if (DB::table('employees')->whereRaw('LOWER(email) = ?', [$row['email']])->exists()
                || DB::table('users')->whereRaw('LOWER(email) = ?', [$row['email']])->exists()) {
                $errors['email'][] = 'This email is already in use.';
            }
            if ($row['phone'] !== '' && DB::table('employees')->where('phone', $row['phone'])->whereNotNull('phone')->exists()) {
                $rowWarnings = ['This phone number is already used by another employee. Confirm it is intentional.'];
            } else {
                $rowWarnings = [];
            }
            if ($row['basic_monthly'] !== ''
                && is_numeric($row['basic_monthly']) && is_numeric($row['gross_monthly'])
                && (float) $row['basic_monthly'] > (float) $row['gross_monthly']) {
                $errors['basic_monthly'][] = 'Basic monthly salary cannot exceed gross monthly salary.';
            }

            if (!isset($errors['department_id']) && !isset($errors['designation_id']) && !isset($errors['department']) && !isset($errors['designation'])) {
                try {
                    EmployeeOrganization::resolve(
                        $validationRow['department_id'] !== null ? (int) $validationRow['department_id'] : null,
                        $validationRow['designation_id'] !== null ? (int) $validationRow['designation_id'] : null,
                        $validationRow['department'],
                        $validationRow['designation']
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$field] = array_merge($errors[$field] ?? [], $messages);
                    }
                }
            }

            $rows[] = ['line' => $line, 'data' => $row, 'errors' => $errors, 'warnings' => $rowWarnings];
        }
        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => ['The CSV does not contain any employee rows.'],
            ]);
        }

        $seenEmails = [];
        $seenPhones = [];
        foreach ($rows as $index => &$row) {
            $email = $row['data']['email'] ?? null;
            if ($email && isset($seenEmails[$email])) {
                $row['errors']['email'][] = 'This email is duplicated in the CSV.';
                $rows[$seenEmails[$email]]['errors']['email'][] = 'This email is duplicated in the CSV.';
            } elseif ($email) {
                $seenEmails[$email] = $index;
            }

            $phone = $row['data']['phone'] ?? null;
            if ($phone) {
                if (isset($seenPhones[$phone])) {
                    $warning = 'This phone number is duplicated in the CSV; confirm it is intentional.';
                    $row['warnings'][] = $warning;
                    $rows[$seenPhones[$phone]]['warnings'][] = $warning;
                } else {
                    $seenPhones[$phone] = $index;
                }
            }
        }
        unset($row);

        return $rows;
    }

    private function report(array $rows): array
    {
        return [
            'total_rows' => count($rows),
            'valid_count' => count(array_filter($rows, static fn (array $row): bool => $row['errors'] === [])),
            'invalid_count' => count(array_filter($rows, static fn (array $row): bool => $row['errors'] !== [])),
            'rows' => array_map(static fn (array $row): array => [
                'line' => $row['line'],
                'employee' => array_intersect_key($row['data'], array_flip(['first_name', 'last_name', 'email', 'employee_code'])),
                'errors' => $row['errors'],
                'warnings' => $row['warnings'] ?? [],
            ], $rows),
        ];
    }

    private function booleanValue(?string $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }
}
