<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CreateDemoRoleAccountsCommand extends Command
{
    protected $signature = 'hrms:create-demo-role-accounts {--force : Confirm creation in the currently configured database} {--dry-run : Validate prerequisites without writing accounts}';

    protected $description = 'Create one temporary login account for each HRMS login-panel role';

    public function handle(): int
    {
        if (app()->environment('production') && !$this->option('force')) {
            $confirmed = $this->confirm(
                'This creates four demo accounts in the configured production database. Continue?'
            );
            if (!$confirmed) {
                return Command::FAILURE;
            }
        }

        foreach (['users', 'employees', 'employee_statutory_details', 'branches'] as $table) {
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
            ],
        ];

        $branchId = DB::table('branches')->orderBy('id')->value('id');
        if (!$branchId) {
            $this->error('No branch exists. Create the company and branch records before adding demo accounts.');
            return Command::FAILURE;
        }

        $emails = array_column($accounts, 'email');
        $employeeCodes = array_column($accounts, 'employee_code');
        $emailConflict = DB::table('users')->whereIn('email', $emails)->value('email');
        $employeeConflict = DB::table('employees')->whereIn('employee_code', $employeeCodes)->value('employee_code');
        if ($emailConflict || $employeeConflict) {
            $this->error('No accounts were changed: one or more reserved demo emails or employee codes already exist.');
            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run only; no account or employee records were written.');
            $this->line('Configured connection driver: '.DB::connection()->getDriverName());
            $this->line('Demo accounts ready to create: '.count($accounts));
            return Command::SUCCESS;
        }

        $credentials = [];
        $accountsByKey = collect($accounts)->keyBy('key');

        DB::transaction(function () use ($accountsByKey, $branchId, &$credentials): void {
            $managerEmployeeId = null;
            foreach (['manager', 'employee', 'hr', 'admin'] as $accountKey) {
                $account = $accountsByKey->get($accountKey);
                $password = Str::random(32);
                $employeeId = DB::table('employees')->insertGetId([
                    'employee_code' => $account['employee_code'],
                    'first_name' => 'Demo',
                    'last_name' => ucfirst($account['key']),
                    'email' => $account['email'],
                    'gender' => 'Other',
                    'designation' => $account['designation'],
                    'department' => $account['department'],
                    'branch_id' => $branchId,
                    'reporting_manager_id' => $account['key'] === 'employee' ? $managerEmployeeId : null,
                    'date_of_joining' => now()->toDateString(),
                    'employment_type' => 'Full-Time Permanent',
                    'probation_status' => 'Confirmed',
                    'status' => 'active',
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('employee_statutory_details')->insert([
                    'employee_id' => $employeeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('users')->insert([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => Hash::make($password),
                    'employee_id' => $employeeId,
                    'role' => $account['role'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($account['key'] === 'manager') {
                    $managerEmployeeId = $employeeId;
                }

                $credentials[] = [
                    'panel' => $account['panel'],
                    'email' => $account['email'],
                    'employee_code' => $account['employee_code'],
                    'password' => $password,
                ];
            }
        });

        usort(
            $credentials,
            fn (array $left, array $right): int =>
                array_search($left['panel'], ['Employee', 'Manager', 'HR', 'Admin'], true)
                <=> array_search($right['panel'], ['Employee', 'Manager', 'HR', 'Admin'], true)
        );

        $this->info('Four temporary demo accounts were created. Passwords are shown only once:');
        $this->table(
            ['Login panel', 'Email', 'Employee code', 'Temporary password'],
            $credentials
        );
        $this->warn('The Admin account has super-admin access. Change these temporary passwords after testing.');

        return Command::SUCCESS;
    }
}
