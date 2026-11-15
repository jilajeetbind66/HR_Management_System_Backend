<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrmsCleanOperationalDataCommand extends Command
{
    protected $signature = 'hrms:clean-operational-data
        {--database=HRMS_DB : The expected active database name}
        {--force : Apply the cleanup after reviewing the preview}';

    protected $description = 'Remove demo operational data while retaining organization masters and the super-admin profile';

    private const TABLES = [
        'performance_key_results',
        'performance_objectives',
        'performance_cycles',
        'attendance_csv_imports',
        'hrms_uploads',
        'policy_acknowledgements',
        'hr_letters',
        'company_policies',
        'training_enrollments',
        'training_courses',
        'recruitment_candidates',
        'recruitment_jobs',
        'employee_lifecycle_tasks',
        'employee_lifecycle_cases',
        'loan_requests',
        'expense_claims',
        'personal_access_tokens',
        'notification_preferences',
        'approval_request_steps',
        'employee_change_requests',
        'employee_statutory_details',
        'payslips',
        'payroll_runs',
        'attendance_regularization_requests',
        'attendance_records',
        'leave_requests',
        'leave_balances',
        'project_penalties',
        'project_extension_requests',
        'project_progress_updates',
        'project_assignments',
        'projects',
        'project_penalty_policies',
        'audit_logs',
        'scheduler_runs',
        'helpdesk_ticket_messages',
        'helpdesk_tickets',
        'announcement_reads',
        'announcements',
        'company_assets',
        'holidays',
        'password_reset_tokens',
        'sessions',
    ];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $expectedDatabase = $this->option('database');

        if ($database !== $expectedDatabase) {
            $this->error("Active database '{$database}' does not match expected database '{$expectedDatabase}'. No data was changed.");

            return self::FAILURE;
        }

        $admin = DB::table('users')->where('role', 'super_admin')->first(['id', 'employee_id']);
        if (!$admin || !$admin->employee_id || !DB::table('employees')->where('id', $admin->employee_id)->exists()) {
            $this->error('Exactly one linked super-admin account is required to preserve access. No data was changed.');

            return self::FAILURE;
        }

        $otherAdmins = DB::table('users')
            ->where('role', 'super_admin')
            ->where('id', '!=', $admin->id)
            ->count();
        if ($otherAdmins > 0) {
            $this->error('More than one super-admin account exists. Resolve the admin account choice before cleanup.');

            return self::FAILURE;
        }

        $this->info("Target database: {$database}");
        $this->line('Retained: company, branches, departments, designations, and the linked super-admin account/profile.');
        $this->line('Planned removals: all other employees/users, employee statutory details, holidays, and operational records.');

        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }
        $counts['other_users'] = DB::table('users')->where('id', '!=', $admin->id)->count();
        $counts['other_employees'] = DB::table('employees')->where('id', '!=', $admin->employee_id)->count();

        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-40s %d', $table, $count));
        }

        if (!$this->option('force')) {
            $this->warn('Preview only. Re-run with --force to apply this cleanup.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($admin): void {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            DB::table('users')->where('id', '!=', $admin->id)->delete();
            DB::table('employees')->where('id', '!=', $admin->employee_id)->delete();
            DB::table('departments')->update(['head' => null]);

            foreach (DB::table('branches')->get(['id']) as $branch) {
                DB::table('branches')->where('id', $branch->id)->update([
                    'employees_count' => DB::table('employees')->where('branch_id', $branch->id)->count(),
                ]);
            }
        });

        $this->info('Operational demo data removed; retained masters and super-admin account are unchanged.');

        return self::SUCCESS;
    }
}
