<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class HrmsInstallCommand extends Command
{
    protected $signature = 'hrms:install {--force : Force re-installation even if already installed}';
    protected $description = 'Bootstrap HRMS production baseline: company master, branches, statutory configs, and first super_admin';

    public function handle(): int
    {
        $this->info("====================================================================");
        $this->info("    Pinweb HRMS v1.2 Installation & Production Bootstrap (India Baseline)");
        $this->info("====================================================================");

        $existingCompany = DB::table('companies')->first();
        if ($existingCompany && !$this->option('force')) {
            $this->warn("HRMS is already initialized for company: {$existingCompany->name}");
            $this->line("Use --force if you need to run migration/seeding again.");
            return Command::SUCCESS;
        }

        if ($this->option('force')) {
            $this->line("Cleaning existing records for fresh installation...");
            $driver = DB::getDriverName();
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF;');
            } elseif ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            }

            foreach ([
                'performance_key_results', 'performance_objectives', 'performance_cycles',
                'policy_acknowledgements', 'company_policies', 'hr_letters',
                'attendance_csv_imports',
                'training_enrollments', 'training_courses',
                'employee_lifecycle_tasks', 'employee_lifecycle_cases',
                'loan_requests', 'expense_claims', 'hrms_uploads',
                'recruitment_candidates', 'recruitment_jobs',
                'audit_logs', 'helpdesk_ticket_messages', 'helpdesk_tickets',
                'announcement_reads', 'announcements', 'company_assets',
                'project_penalties', 'project_extension_requests', 'project_progress_updates',
                'project_assignments', 'projects', 'project_penalty_policies',
                'payslips', 'payroll_runs', 'leave_requests', 'leave_balances',
                'attendance_regularization_requests', 'attendance_records',
                'employee_change_requests', 'employee_statutory_details',
                'users', 'employees', 'designations', 'departments', 'branches', 'companies'
            ] as $tbl) {
                if (Schema::hasTable($tbl)) {
                    DB::table($tbl)->truncate();
                }
            }

            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            } elseif ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }

        DB::transaction(function () {
            // 1. Company Master
            $companyId = DB::table('companies')->insertGetId([
                'name' => 'Pinweb Pvt. Ltd.',
                'legal_name' => 'Pinweb Private Limited',
                'cin' => 'U72200UP2018PTC334521',
                'pan' => 'AAACA1234D',
                'tan' => 'PRGA12345B',
                'gstin' => '09AAACA1234D1Z5',
                'pf_code' => 'UP/PRG/0054321/000',
                'esi_code' => '21000543210000101',
                'registered_state' => 'Uttar Pradesh',
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
                'headquarters' => 'Civil Lines, Prayagraj, Uttar Pradesh - 211001',
                'support_email' => 'hr@pinweb.in',
                'contact_number' => '+91 532 245 6789',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Branches (Exclusively Prayagraj & Kaushambi)
            $prayagrajId = DB::table('branches')->insertGetId([
                'company_id' => $companyId,
                'name' => 'Prayagraj Branch',
                'code' => 'PRG-01',
                'city' => 'Prayagraj',
                'state' => 'Uttar Pradesh',
                'pt_applicable' => false,
                'employees_count' => 580,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $kaushambiId = DB::table('branches')->insertGetId([
                'company_id' => $companyId,
                'name' => 'Kaushambi Branch',
                'code' => 'KSM-01',
                'city' => 'Kaushambi',
                'state' => 'Uttar Pradesh',
                'pt_applicable' => false,
                'employees_count' => 420,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Departments
            $engDeptId = DB::table('departments')->insertGetId([
                'branch_id' => $prayagrajId,
                'name' => 'Engineering & Technology',
                'code' => 'ENG',
                'head' => 'Rajesh Sharma',
                'budget_inr' => 85000000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $hrDeptId = DB::table('departments')->insertGetId([
                'branch_id' => $kaushambiId,
                'name' => 'People & Culture (HR)',
                'code' => 'HRM',
                'head' => 'Priya Nair',
                'budget_inr' => 15000000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Designations
            DB::table('designations')->insert([
                ['department_id' => $engDeptId, 'title' => 'Director of Engineering', 'level' => 'L7', 'min_experience_years' => 12, 'created_at' => now(), 'updated_at' => now()],
                ['department_id' => $engDeptId, 'title' => 'Senior Frontend Developer', 'level' => 'L4', 'min_experience_years' => 5, 'created_at' => now(), 'updated_at' => now()],
                ['department_id' => $hrDeptId, 'title' => 'Head of People Operations', 'level' => 'L6', 'min_experience_years' => 10, 'created_at' => now(), 'updated_at' => now()],
            ]);

            // 5. Seed Comprehensive Employees & Users (10 Staff Baseline)
            $employeesData = [
                ['code' => 'EMP-001', 'first' => 'Rajesh', 'last' => 'Sharma', 'email' => 'rajesh.sharma@pinweb.in', 'phone' => '+91 98112 34567', 'gender' => 'Male', 'dob' => '1983-05-14', 'desig' => 'Director of Engineering', 'dept' => 'Engineering & Technology', 'branch' => $prayagrajId, 'role' => 'super_admin', 'mgr' => null, 'doj' => '2019-02-01', 'ctc' => 3600000, 'gross' => 300000, 'basic' => 150000, 'pan' => 'AAACS1234F', 'uan' => '100904567890'],
                ['code' => 'EMP-002', 'first' => 'Priya', 'last' => 'Nair', 'email' => 'priya.nair@pinweb.in', 'phone' => '+91 98201 11223', 'gender' => 'Female', 'dob' => '1988-08-22', 'desig' => 'Head of People Operations', 'dept' => 'People & Culture (HR)', 'branch' => $prayagrajId, 'role' => 'hr_admin', 'mgr' => 1, 'doj' => '2020-06-15', 'ctc' => 2400000, 'gross' => 200000, 'basic' => 100000, 'pan' => 'BPZPN5678K', 'uan' => '100904567891'],
                ['code' => 'EMP-003', 'first' => 'Ananya', 'last' => 'Deshmukh', 'email' => 'ananya.deshmukh@pinweb.in', 'phone' => '+91 98450 33445', 'gender' => 'Female', 'dob' => '1990-11-03', 'desig' => 'Lead Payroll Specialist', 'dept' => 'Finance & Payroll', 'branch' => $prayagrajId, 'role' => 'payroll_admin', 'mgr' => 1, 'doj' => '2021-01-10', 'ctc' => 1800000, 'gross' => 150000, 'basic' => 75000, 'pan' => 'CKLPD9012M', 'uan' => '100904567892'],
                ['code' => 'EMP-004', 'first' => 'Vikram', 'last' => 'Malhotra', 'email' => 'vikram.malhotra@pinweb.in', 'phone' => '+91 98710 55667', 'gender' => 'Male', 'dob' => '1986-03-30', 'desig' => 'Engineering Manager', 'dept' => 'Engineering & Technology', 'branch' => $prayagrajId, 'role' => 'manager', 'mgr' => 1, 'doj' => '2020-03-01', 'ctc' => 2800000, 'gross' => 233333, 'basic' => 116666, 'pan' => 'DFGPM3456Q', 'uan' => '100904567893'],
                ['code' => 'EMP-005', 'first' => 'Aarav', 'last' => 'Patel', 'email' => 'aarav.patel@pinweb.in', 'phone' => '+91 97230 77889', 'gender' => 'Male', 'dob' => '1995-07-19', 'desig' => 'Senior Fullstack Engineer', 'dept' => 'Engineering & Technology', 'branch' => $kaushambiId, 'role' => 'employee', 'mgr' => 4, 'doj' => '2022-04-18', 'ctc' => 1600000, 'gross' => 133333, 'basic' => 66666, 'pan' => 'EMPPA7890R', 'uan' => '100904567894'],
                ['code' => 'EMP-006', 'first' => 'Sneha', 'last' => 'Reddy', 'email' => 'sneha.reddy@pinweb.in', 'phone' => '+91 94401 99001', 'gender' => 'Female', 'dob' => '1997-01-25', 'desig' => 'Mobile & Cloud Engineer', 'dept' => 'Engineering & Technology', 'branch' => $kaushambiId, 'role' => 'employee', 'mgr' => 4, 'doj' => '2023-07-03', 'ctc' => 1400000, 'gross' => 116666, 'basic' => 58333, 'pan' => 'FGHSR1234T', 'uan' => '100904567895'],
                ['code' => 'EMP-007', 'first' => 'Rohan', 'last' => 'Verma', 'email' => 'rohan.verma@pinweb.in', 'phone' => '+91 98190 22334', 'gender' => 'Male', 'dob' => '1992-09-12', 'desig' => 'DevOps & Security Engineer', 'dept' => 'Engineering & Technology', 'branch' => $kaushambiId, 'role' => 'employee', 'mgr' => 4, 'doj' => '2021-08-16', 'ctc' => 1500000, 'gross' => 125000, 'basic' => 62500, 'pan' => 'GHKRV5678W', 'uan' => '100904567896'],
                ['code' => 'EMP-008', 'first' => 'Meera', 'last' => 'Iyer', 'email' => 'meera.iyer@pinweb.in', 'phone' => '+91 98440 66778', 'gender' => 'Female', 'dob' => '1994-04-05', 'desig' => 'Senior Talent Acquisition Partner', 'dept' => 'People & Culture (HR)', 'branch' => $kaushambiId, 'role' => 'recruiter', 'mgr' => 2, 'doj' => '2022-11-01', 'ctc' => 1200000, 'gross' => 100000, 'basic' => 50000, 'pan' => 'HJIMI9012Y', 'uan' => '100904567897'],
                ['code' => 'EMP-009', 'first' => 'Siddharth', 'last' => 'Mehta', 'email' => 'siddharth.mehta@pinweb.in', 'phone' => '+91 98210 44556', 'gender' => 'Male', 'dob' => '1996-12-18', 'desig' => 'QA Automation Lead', 'dept' => 'Engineering & Technology', 'branch' => $prayagrajId, 'role' => 'employee', 'mgr' => 4, 'doj' => '2024-02-12', 'ctc' => 1100000, 'gross' => 91666, 'basic' => 45833, 'pan' => 'JKLSM3456Z', 'uan' => '100904567898'],
                ['code' => 'EMP-010', 'first' => 'Neha', 'last' => 'Kapoor', 'email' => 'neha.kapoor@pinweb.in', 'phone' => '+91 98100 88990', 'gender' => 'Female', 'dob' => '1991-06-08', 'desig' => 'Internal Auditor & Compliance Officer', 'dept' => 'People & Culture (HR)', 'branch' => $prayagrajId, 'role' => 'auditor', 'mgr' => 2, 'doj' => '2021-05-20', 'ctc' => 1300000, 'gross' => 108333, 'basic' => 54166, 'pan' => 'KLMNK7890A', 'uan' => '100904567899'],
            ];

            $empIdMap = [];
            foreach ($employeesData as $ed) {
                $eid = DB::table('employees')->insertGetId([
                    'employee_code' => $ed['code'],
                    'first_name' => $ed['first'],
                    'last_name' => $ed['last'],
                    'email' => $ed['email'],
                    'phone' => $ed['phone'],
                    'gender' => $ed['gender'],
                    'date_of_birth' => $ed['dob'],
                    'designation' => $ed['desig'],
                    'department' => $ed['dept'],
                    'branch_id' => $ed['branch'],
                    'reporting_manager_id' => null,
                    'date_of_joining' => $ed['doj'],
                    'employment_type' => 'Full-Time Permanent',
                    'probation_status' => 'Confirmed',
                    'status' => 'active',
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $empIdMap[$ed['code']] = $eid;

                // Statutory details
                DB::table('employee_statutory_details')->insert([
                    'employee_id' => $eid,
                    'pan' => $ed['pan'],
                    'uan' => $ed['uan'],
                    'pf_applicable' => true,
                    'pf_account_no' => 'UP/PRG/0054321/000/' . substr($ed['code'], 4),
                    'esi_applicable' => false,
                    'pt_state' => 'Uttar Pradesh',
                    'tax_regime' => 'New',
                    'ctc_annual' => $ed['ctc'],
                    'gross_monthly' => $ed['gross'],
                    'basic_monthly' => $ed['basic'],
                    'bank_name' => 'State Bank of India (Prayagraj)',
                    'account_number' => '50100' . rand(100000000, 999999999),
                    'ifsc_code' => 'SBIN0000123',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // User account for authentication
                DB::table('users')->insert([
                    'name' => "{$ed['first']} {$ed['last']}",
                    'email' => $ed['email'],
                    'password' => Hash::make('AdminPass@2026#Secure'),
                    'employee_id' => $eid,
                    'role' => $ed['role'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Leave balances
                DB::table('leave_balances')->insert([
                    'employee_id' => $eid,
                    'cl' => 12.00,
                    'sl' => 12.00,
                    'pl' => 15.00,
                    'comp_off' => 0.00,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 6. Penalty Policy Safeguard Baseline (Section 9 & 10)
            DB::table('project_penalty_policies')->insert([
                'name' => 'Standard Delivery & Deadline Policy (India Baseline)',
                'grace_days' => 2,
                'penalty_type' => 'non_monetary',
                'rule_config' => json_encode([
                    'allow_monetary' => false,
                    'escalation_notice' => true,
                    'requires_manager_review' => true
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 7. Seed Initial Projects (M17 Delivery Tracking)
            $p1 = DB::table('projects')->insertGetId([
                'project_code' => 'PRJ-2026-001',
                'name' => 'Customer Billing & Payment Gateway V2',
                'description' => 'Architect and implement multi-currency Razorpay & Stripe integration with automated recurring invoicing and GST compliance.',
                'deliverable' => 'Production-ready payment gateway module with 100% unit test coverage, webhook idempotency, and GST tax invoice generation.',
                'priority' => 'High',
                'status' => 'in_progress',
                'created_by' => 'Priya Nair (HR Admin)',
                'created_at' => now()->subDays(18),
                'updated_at' => now(),
            ]);
            DB::table('project_assignments')->insert([
                'project_id' => $p1,
                'employee_id' => $empIdMap['EMP-005'], // Aarav Patel
                'assigned_by' => 'Priya Nair',
                'start_date' => '2026-11-01',
                'due_date' => '2026-11-20',
                'active_due_date' => '2026-11-20',
                'progress_percent' => 75,
                'status' => 'in_progress',
                'created_at' => now()->subDays(18),
                'updated_at' => now(),
            ]);

            $p2 = DB::table('projects')->insertGetId([
                'project_code' => 'PRJ-2026-002',
                'name' => 'NextGen Mobile REST API Suite',
                'description' => 'Develop optimized RESTful endpoints for iOS and Android apps with biometric token refresh and push notifications via FCM.',
                'deliverable' => 'Swagger / OpenAPI 3.0 documented endpoints, response time <150ms p95, and push notification microservice.',
                'priority' => 'Urgent',
                'status' => 'overdue',
                'created_by' => 'Priya Nair (HR Admin)',
                'created_at' => now()->subDays(30),
                'updated_at' => now(),
            ]);
            DB::table('project_assignments')->insert([
                'project_id' => $p2,
                'employee_id' => $empIdMap['EMP-006'], // Sneha Reddy
                'assigned_by' => 'Priya Nair',
                'start_date' => '2026-10-15',
                'due_date' => '2026-11-10',
                'active_due_date' => '2026-11-10',
                'progress_percent' => 85,
                'status' => 'overdue',
                'created_at' => now()->subDays(30),
                'updated_at' => now(),
            ]);

            $p3 = DB::table('projects')->insertGetId([
                'project_code' => 'PRJ-2026-003',
                'name' => 'Multi-Factor Security & Audit Log Streamer',
                'description' => 'Implement TOTP 2FA, IP allowlisting for admin portals, and streaming sensitive database read audits.',
                'deliverable' => 'RFC 6238 TOTP engine, rate limiting, and immutable audit log exporter with tamper verification.',
                'priority' => 'Medium',
                'status' => 'under_review',
                'created_by' => 'Priya Nair (HR Admin)',
                'created_at' => now()->subDays(15),
                'updated_at' => now(),
            ]);
            DB::table('project_assignments')->insert([
                'project_id' => $p3,
                'employee_id' => $empIdMap['EMP-009'], // Siddharth Mehta
                'assigned_by' => 'Priya Nair',
                'start_date' => '2026-11-05',
                'due_date' => '2026-11-15',
                'active_due_date' => '2026-11-15',
                'progress_percent' => 100,
                'status' => 'under_review',
                'submission_note' => 'Completed all requirements ahead of deadline! Tested TOTP with Google Authenticator.',
                'created_at' => now()->subDays(15),
                'updated_at' => now(),
            ]);

            // 8. Seed Initial Attendance Records
            $todayDate = date('Y-m-d');
            DB::table('attendance_records')->insert([
                ['employee_id' => $empIdMap['EMP-005'], 'date' => $todayDate, 'punch_in' => '09:15 AM', 'punch_out' => null, 'status' => 'Present', 'work_hours' => 6.5, 'ip_address' => '103.24.12.55', 'location' => 'Kaushambi Branch', 'created_at' => now(), 'updated_at' => now()],
                ['employee_id' => $empIdMap['EMP-006'], 'date' => $todayDate, 'punch_in' => '09:30 AM', 'punch_out' => null, 'status' => 'Present', 'work_hours' => 6.2, 'ip_address' => '183.82.100.12', 'location' => 'Kaushambi Branch', 'created_at' => now(), 'updated_at' => now()],
                ['employee_id' => $empIdMap['EMP-004'], 'date' => $todayDate, 'punch_in' => '08:55 AM', 'punch_out' => null, 'status' => 'Present', 'work_hours' => 7.0, 'ip_address' => '103.24.12.10', 'location' => 'Prayagraj Branch', 'created_at' => now(), 'updated_at' => now()],
                ['employee_id' => $empIdMap['EMP-002'], 'date' => $todayDate, 'punch_in' => '09:10 AM', 'punch_out' => null, 'status' => 'Present', 'work_hours' => 6.7, 'ip_address' => '122.161.45.8', 'location' => 'Prayagraj Branch', 'created_at' => now(), 'updated_at' => now()],
            ]);

            // 9. Seed Initial Leave Requests
            DB::table('leave_requests')->insert([
                ['employee_id' => $empIdMap['EMP-005'], 'leave_type' => 'Privilege Leave (PL)', 'from_date' => '2026-11-26', 'to_date' => '2026-11-28', 'days' => 3.0, 'reason' => 'Family function in Prayagraj.', 'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['employee_id' => $empIdMap['EMP-006'], 'leave_type' => 'Casual Leave (CL)', 'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'days' => 1.0, 'reason' => 'Personal banking and passport verification appointment.', 'status' => 'approved', 'reviewed_by' => 'Vikram Malhotra', 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            // 10. Seed Initial Payroll Runs
            DB::table('payroll_runs')->insert([
                'period_year' => 2026,
                'period_month' => 10,
                'scope_key' => 'company',
                'month_name' => 'October 2026',
                'status' => 'locked',
                'total_employees' => 10,
                'gross_payout_inr' => 1541664.00,
                'total_deductions_inr' => 218540.00,
                'net_payout_inr' => 1323124.00,
                'approved_by' => 'Ananya Deshmukh',
                'approved_at' => '2026-10-31 18:00:00',
                'payout_date' => '2026-11-01',
                'created_at' => '2026-10-28',
                'updated_at' => now(),
            ]);

            // 11. Audit log of installation
            DB::table('audit_logs')->insert([
                'action' => 'HRMS_BOOTSTRAP',
                'actor' => 'System Console (hrms:install)',
                'module' => 'Platform',
                'description' => 'Initialized Pinweb Pvt. Ltd. company master, 2 branches (Prayagraj & Kaushambi), 10 employees, statutory configs, and projects',
                'ip' => '127.0.0.1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->info("✓ Company master created: Pinweb Pvt. Ltd.");
        $this->info("✓ Branches created: Prayagraj Branch, Kaushambi Branch");
        $this->info("✓ Departments & Designations configured");
        $this->info("✓ 10 Employees seeded with statutory PF/ESI/PT and user accounts");
        $this->info("✓ M17 Projects, attendance records, leaves & payroll baseline seeded");
        $this->info("✓ M17 Penalty Safeguard policy active (Non-monetary default)");
        $this->info("✓ Audit log entry committed to audit_logs table");
        $this->info("====================================================================");
        $this->info("HRMS Bootstrap successfully completed!");

        return Command::SUCCESS;
    }
}
