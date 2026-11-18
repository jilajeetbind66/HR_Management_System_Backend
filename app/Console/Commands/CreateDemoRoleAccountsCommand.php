<?php

namespace App\Console\Commands;

use App\Support\DatabaseTarget;
use App\Support\EmployeeConstants;
use App\Support\EmployeeOrganization;
use App\Support\ProbationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class CreateDemoRoleAccountsCommand extends Command
{
    protected $signature = 'hrms:create-demo-role-accounts {--force : Confirm creation in the currently configured database} {--dry-run : Validate prerequisites without writing accounts}';

    protected $description = 'Create one temporary login account for each HRMS login-panel role';

    public function handle(): int
    {
        $this->line(DatabaseTarget::description());
        if (config('app.env') === 'production' && !$this->option('force')) {
            $this->error('Refusing to create demo accounts in production without --force.');
            return Command::FAILURE;
        }

        foreach ([
            'users',
            'employees',
            'employee_statutory_details',
            'branches',
            'leave_balances',
            'leave_requests',
            'leave_request_reviews',
            'attendance_records',
            'holidays',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("Required table [{$table}] is missing. Run the approved HRMS migrations first.");
                return Command::FAILURE;
            }
        }

        $accounts = [
            [
                'key' => 'employee',
                'panel' => 'Employee',
                'name' => 'Demo Employee',
                'email' => 'demo.employee@pinweb.in',
                'employee_code' => 'DEMO-EMP-001',
                'role' => 'employee',
                'designation' => 'Software Engineer',
                'department' => 'Engineering & Technology',
                'gross_monthly' => 65000,
                'basic_monthly' => 32500,
                'ctc_annual' => 780000,
                'account_number' => '999999000001',
            ],
            [
                'key' => 'manager',
                'panel' => 'Manager',
                'name' => 'Demo Manager',
                'email' => 'demo.manager@pinweb.in',
                'employee_code' => 'DEMO-MGR-001',
                'role' => 'manager',
                'designation' => 'Engineering Manager',
                'department' => 'Engineering & Technology',
                'gross_monthly' => 110000,
                'basic_monthly' => 55000,
                'ctc_annual' => 1320000,
                'account_number' => '999999000002',
            ],
            [
                'key' => 'hr',
                'panel' => 'HR',
                'name' => 'Demo HR',
                'email' => 'demo.hr@pinweb.in',
                'employee_code' => 'DEMO-HR-001',
                'role' => 'hr_admin',
                'designation' => 'HR Administrator',
                'department' => 'People & Culture (HR)',
                'gross_monthly' => 80000,
                'basic_monthly' => 40000,
                'ctc_annual' => 960000,
                'account_number' => '999999000003',
            ],
            [
                'key' => 'admin',
                'panel' => 'Admin',
                'name' => 'Demo Admin',
                'email' => 'demo.admin@pinweb.in',
                'employee_code' => 'DEMO-ADM-001',
                'role' => 'super_admin',
                'designation' => 'System Administrator',
                'department' => 'Administration',
                'gross_monthly' => 120000,
                'basic_monthly' => 60000,
                'ctc_annual' => 1440000,
                'account_number' => '999999000004',
            ],
        ];

        $directorId = DB::table('employees')
            ->where('employee_code', 'EMP-001')
            ->value('id');
        if (!$directorId) {
            $this->error('Director employee [EMP-001] is missing; no demo accounts were changed.');
            return Command::FAILURE;
        }

        $branchId = DB::table('employees')
            ->where('employee_code', 'DEMO-MGR-001')
            ->value('branch_id');
        $branchId = $branchId ?: DB::table('employees')
            ->where('employee_code', 'DEMO-EMP-001')
            ->value('branch_id');
        $branchId = $branchId ?: DB::table('employees')
            ->where('employee_code', 'EMP-002')
            ->value('branch_id');
        $branchId = $branchId ?: DB::table('branches')->orderBy('id')->value('id');
        if (!$branchId) {
            $this->error('No branch exists. Create the company and branch records before adding demo accounts.');
            return Command::FAILURE;
        }

        $conflict = $this->identityConflict($accounts);
        if ($conflict !== null) {
            $this->error("No demo accounts were changed: {$conflict}");
            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run only; no account, employee, or reporting-chain records were written.');
            $this->line('Configured connection driver: ' . DB::connection()->getDriverName());
            $this->line('Demo account identities checked: ' . count($accounts));
            return Command::SUCCESS;
        }

        $credentials = [];
        $accountsByKey = collect($accounts)->keyBy('key');

        try {
            DB::transaction(function () use (
                $accountsByKey,
                $branchId,
                $directorId,
                &$credentials
            ): void {
                $employeeIds = [];
                $userIds = [];

                foreach (['manager', 'employee', 'hr', 'admin'] as $accountKey) {
                    $account = $accountsByKey->get($accountKey);
                    $employee = DB::table('employees')
                        ->where('employee_code', $account['employee_code'])
                        ->first();

                    if (!$employee) {
                        $organization = EmployeeOrganization::resolve(
                            null,
                            null,
                            $account['department'],
                            $account['designation']
                        );
                        $employeeId = DB::table('employees')->insertGetId([
                            'employee_code' => $account['employee_code'],
                            'first_name' => 'Demo',
                            'last_name' => ucfirst($account['key']),
                            'email' => $account['email'],
                            'gender' => EmployeeConstants::GENDER_OTHER,
                            'designation' => $organization['designation'],
                            'designation_id' => $organization['designation_id'],
                            'department' => $organization['department'],
                            'department_id' => $organization['department_id'],
                            'branch_id' => $branchId,
                            'is_demo' => true,
                            'reporting_manager_id' => null,
                            'date_of_joining' => now()->toDateString(),
                            'employment_type' => 'Full-Time Permanent',
                            'probation_status' => ProbationStatus::fromJoiningDate(now()->toDateString()),
                            'status' => 'active',
                            'version' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $employee = DB::table('employees')->where('id', $employeeId)->first();
                    } else {
                        $employeeId = (int) $employee->id;
                    }

                    $employeeIds[$accountKey] = $employeeId;

                    if (!DB::table('employee_statutory_details')->where('employee_id', $employeeId)->exists()) {
                        DB::table('employee_statutory_details')->insert([
                            'employee_id' => $employeeId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    DB::table('employees')->where('id', $employeeId)->update(['is_demo' => true]);
                    $this->seedDemoPayrollProfile($employeeId, $account);

                    $user = DB::table('users')->where('employee_id', $employeeId)->first();
                    $loginEmail = $employee->email ?: $account['email'];
                    if (!$user) {
                        $user = DB::table('users')->where('email', $loginEmail)->first();
                    }

                    if ($user && $user->employee_id && (int) $user->employee_id !== $employeeId) {
                        throw new RuntimeException("Login email [{$loginEmail}] belongs to another employee.");
                    }

                    if (!$user) {
                        $password = Str::random(32);
                        $userId = DB::table('users')->insertGetId([
                            'name' => $account['name'],
                            'email' => $loginEmail,
                            'password' => Hash::make($password),
                            'employee_id' => $employeeId,
                            'role' => $account['role'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $credentials[] = [
                            'panel' => $account['panel'],
                            'email' => $loginEmail,
                            'employee_code' => $account['employee_code'],
                            'password' => $password,
                        ];
                    } else {
                        $userId = (int) $user->id;
                        $userChanges = [];
                        if (!$user->employee_id) {
                            $userChanges['employee_id'] = $employeeId;
                        }
                        if ($user->role !== $account['role']) {
                            $userChanges['role'] = $account['role'];
                        }
                        if ($userChanges) {
                            $userChanges['updated_at'] = now();
                            DB::table('users')->where('id', $userId)->update($userChanges);
                        }
                    }
                    $userIds[$accountKey] = $userId;
                }

                $managerId = $employeeIds['manager'];
                $this->setReportingManager($managerId, $directorId);
                $this->setReportingManager($employeeIds['employee'], $managerId);
                $this->setReportingManager($employeeIds['hr'], $directorId);
                $this->setReportingManager($employeeIds['admin'], $directorId);

                $sampleEmployeeId = DB::table('employees')
                    ->join('users', 'users.employee_id', '=', 'employees.id')
                    ->where('employees.employee_code', 'EMP-002')
                    ->where('employees.first_name', 'Ankit')
                    ->where('employees.last_name', 'Singh')
                    ->where('users.role', 'employee')
                    ->value('employees.id');
                if ($sampleEmployeeId) {
                    $sampleEmployeeId = (int) $sampleEmployeeId;
                    $this->setReportingManager($sampleEmployeeId, $managerId);
                } else {
                    $sampleEmployeeId = $employeeIds['employee'];
                }

                $this->seedDemoLeaveRequests(
                    $sampleEmployeeId,
                    $sampleEmployeeId,
                    $userIds['manager'],
                    $userIds['hr']
                );
            });
        } catch (RuntimeException $exception) {
            $this->error('No demo accounts were changed: ' . $exception->getMessage());
            return Command::FAILURE;
        }

        if ($credentials) {
            usort(
                $credentials,
                fn (array $left, array $right): int =>
                    array_search($left['panel'], ['Employee', 'Manager', 'HR', 'Admin'], true)
                    <=> array_search($right['panel'], ['Employee', 'Manager', 'HR', 'Admin'], true)
            );

            $this->info('New temporary demo accounts were created. Passwords are shown only once:');
            $this->table(
                ['Login panel', 'Email', 'Employee code', 'Temporary password'],
                $credentials
            );
            $this->warn('The Admin account has super-admin access. Change these temporary passwords after testing.');
        } else {
            $this->info('Demo accounts already existed; passwords and existing employee details were preserved.');
        }

        return Command::SUCCESS;
    }

    private function seedDemoPayrollProfile(int $employeeId, array $account): void
    {
        $details = DB::table('employee_statutory_details')
            ->where('employee_id', $employeeId)
            ->first();
        $values = [
            'gross_monthly' => $account['gross_monthly'],
            'basic_monthly' => $account['basic_monthly'],
            'ctc_annual' => $account['ctc_annual'],
            'bank_name' => 'Demo Bank (Test Only)',
            'account_number' => $account['account_number'],
            'ifsc_code' => 'DEMO0000001',
        ];
        $updates = [];

        foreach (['gross_monthly', 'basic_monthly', 'ctc_annual'] as $field) {
            if ((float) ($details->{$field} ?? 0) <= 0) {
                $updates[$field] = $values[$field];
            }
        }
        foreach (['bank_name', 'account_number', 'ifsc_code'] as $field) {
            if (trim((string) ($details->{$field} ?? '')) === '') {
                $updates[$field] = $values[$field];
            }
        }

        if ($updates) {
            DB::table('employee_statutory_details')
                ->where('employee_id', $employeeId)
                ->update([...$updates, 'updated_at' => now()]);
        }
    }

    private function identityConflict(array $accounts): ?string
    {
        foreach ($accounts as $account) {
            $employee = DB::table('employees')
                ->where('employee_code', $account['employee_code'])
                ->first();
            $employeeByEmail = DB::table('employees')
                ->where('email', $account['email'])
                ->first();
            if (!$employee && $employeeByEmail) {
                return "reserved demo email [{$account['email']}] belongs to employee [{$employeeByEmail->employee_code}].";
            }

            $user = DB::table('users')->where('email', $account['email'])->first();
            if (
                $user
                && $user->employee_id
                && (!$employee || (int) $user->employee_id !== (int) $employee->id)
            ) {
                return "reserved demo email [{$account['email']}] belongs to another employee.";
            }
        }

        return null;
    }

    private function setReportingManager(int $employeeId, int $managerId): void
    {
        $employee = DB::table('employees')->where('id', $employeeId)->first();
        if (!$employee) {
            throw new RuntimeException("Employee [{$employeeId}] was not found.");
        }
        if ((int) $employee->reporting_manager_id === $managerId) {
            return;
        }

        DB::table('employees')->where('id', $employeeId)->update([
            'reporting_manager_id' => $managerId,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
    }

    private function seedDemoLeaveRequests(
        int $pendingEmployeeId,
        int $approvedEmployeeId,
        int $managerUserId,
        int $hrUserId
    ): void {
        $now = now();
        foreach (array_unique([$pendingEmployeeId, $approvedEmployeeId]) as $employeeId) {
            DB::table('leave_balances')->insertOrIgnore([
                'employee_id' => $employeeId,
                'cl' => 12,
                'sl' => 12,
                'pl' => 15,
                'comp_off' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pendingLeaveId = $this->findOrCreateSampleLeave(
            $pendingEmployeeId,
            'Demo sample pending leave request',
            'pending',
            $now->copy()->addDays(14)
        );
        $approvedLeaveId = $this->findOrCreateSampleLeave(
            $approvedEmployeeId,
            'Demo sample approved leave request',
            'approved',
            $now->copy()->addDays(21),
            DB::table('users')->where('id', $hrUserId)->value('name')
        );

        $approvedLeave = DB::table('leave_requests')->where('id', $approvedLeaveId)->first();
        if ($approvedLeave->status === 'approved') {
            $reviewers = [
                [
                    'user_id' => $managerUserId,
                    'stage' => 'Manager Approval',
                    'name' => DB::table('users')->where('id', $managerUserId)->value('name'),
                ],
                [
                    'user_id' => $hrUserId,
                    'stage' => 'HR Approval',
                    'name' => DB::table('users')->where('id', $hrUserId)->value('name'),
                ],
            ];
            foreach ($reviewers as $reviewer) {
                $exists = DB::table('leave_request_reviews')
                    ->where('leave_request_id', $approvedLeaveId)
                    ->where('stage', $reviewer['stage'])
                    ->exists();
                if (!$exists) {
                    DB::table('leave_request_reviews')->insert([
                        'leave_request_id' => $approvedLeaveId,
                        'reviewer_user_id' => $reviewer['user_id'],
                        'reviewer_name' => $reviewer['name'],
                        'stage' => $reviewer['stage'],
                        'decision' => 'approved',
                        'remarks' => 'Seeded demonstration approval.',
                        'reviewed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $countedDates = json_decode((string) $approvedLeave->counted_dates, true);
            foreach (is_array($countedDates) ? $countedDates : [] as $date) {
                DB::table('attendance_records')->insertOrIgnore([
                    'employee_id' => $approvedEmployeeId,
                    'date' => $date,
                    'status' => 'On Leave',
                    'work_hours' => 0,
                    'location' => 'Approved Leave',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function findOrCreateSampleLeave(
        int $employeeId,
        string $reason,
        string $status,
        Carbon $earliestDate,
        ?string $reviewedBy = null
    ): int {
        $existing = DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->where('reason', $reason)
            ->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $date = $this->nextAvailableSampleLeaveDate($employeeId, $earliestDate);
        $isPending = $status === 'pending';

        return DB::table('leave_requests')->insertGetId([
            'employee_id' => $employeeId,
            'leave_type' => 'Casual Leave (CL)',
            'from_date' => $date,
            'to_date' => $date,
            'days' => 1,
            'counted_dates' => json_encode([$date->toDateString()]),
            'reason' => $reason,
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $isPending ? null : now(),
            'review_remarks' => $isPending ? null : 'Seeded demonstration approval.',
            'approval_stage' => $isPending ? 'Manager Approval' : 'Approved',
            'next_approver_role' => $isPending ? 'manager' : null,
            'is_half_day' => false,
            'half_day_period' => null,
            'certificate_upload_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nextAvailableSampleLeaveDate(int $employeeId, Carbon $earliestDate): Carbon
    {
        $branchId = DB::table('employees')->where('id', $employeeId)->value('branch_id');
        $date = $earliestDate->copy()->startOfDay();

        for ($attempt = 0; $attempt < 366; $attempt++, $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            $applicableHoliday = DB::table('holidays')
                ->whereDate('date', $date->toDateString())
                ->get(['branch_ids'])
                ->contains(function ($holiday) use ($branchId) {
                    if ($holiday->branch_ids === null || $holiday->branch_ids === '') {
                        return true;
                    }
                    $branchIds = json_decode($holiday->branch_ids, true);

                    return is_array($branchIds)
                        && in_array((int) $branchId, array_map('intval', $branchIds), true);
                });
            if ($applicableHoliday) {
                continue;
            }

            $overlap = DB::table('leave_requests')
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('from_date', '<=', $date->toDateString())
                ->whereDate('to_date', '>=', $date->toDateString())
                ->exists();
            if (!$overlap) {
                return $date;
            }
        }

        throw new RuntimeException("No available weekday was found for demo leave employee [{$employeeId}].");
    }
}
