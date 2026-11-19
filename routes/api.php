<?php

use App\Http\Controllers\Api\V1\AnnouncementsController;
use App\Http\Controllers\Api\V1\AssetsController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceImportController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentsController;
use App\Http\Controllers\Api\V1\EmailChangeController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeImportController;
use App\Http\Controllers\Api\V1\EmployeeLifecycleController;
use App\Http\Controllers\Api\V1\EmployeeStaffingRequestController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\HelpdeskController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OperationalRecordController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\PayrollComponentController;
use App\Http\Controllers\Api\V1\PayrollStatutoryController;
use App\Http\Controllers\Api\V1\PerformanceController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReportsController;
use App\Http\Controllers\Api\V1\TrainingController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\UserManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Section 11 & Section 9 Architecture
| Base path: /api/v1
| Envelope: { data, meta? }
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Health endpoint
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'version' => '1.2',
            'company' => 'Pinweb Pvt. Ltd.',
            'jurisdiction' => 'India (Single Company / Multi-Branch)',
            'timestamp' => now()->toISOString(),
        ]);
    });

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/complete-account-setup', [AuthController::class, 'completeAccountSetup'])
        ->middleware('throttle:10,1');
    Route::post('/auth/email-change-confirm', [EmailChangeController::class, 'confirm'])
        ->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'current']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
            ->whereNumber('id');

        Route::post('/auth/email-change-request', [EmailChangeController::class, 'requestOwnEmailChange'])
            ->middleware('throttle:5,1');
        Route::get('/employee-documents', [UploadController::class, 'employeeDocuments']);
        Route::get('/uploads', [UploadController::class, 'index']);
        Route::post('/uploads', [UploadController::class, 'store']);
        Route::get('/uploads/{id}/download', [UploadController::class, 'download']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        // M02 Organization
        Route::prefix('organization')->middleware('role:super_admin,hr_admin,hr_executive,manager,auditor,recruiter')->group(function () {
            Route::get('/company', [OrganizationController::class, 'company']);
            Route::get('/branches', [OrganizationController::class, 'branches']);
            Route::get('/departments', [OrganizationController::class, 'departments']);
            Route::get('/designations', [OrganizationController::class, 'designations']);
            Route::get('/holidays', [OrganizationController::class, 'holidays']);
        });

        // M03 Employees
        Route::get('/employees', [EmployeeController::class, 'index'])
            ->middleware('role:super_admin,hr_admin,hr_executive,manager,auditor');
        Route::post('/employees', [EmployeeController::class, 'store'])
            ->middleware('role:super_admin,hr_admin');
        Route::post('/employees/import/preview', [EmployeeImportController::class, 'preview'])
            ->middleware('role:super_admin,hr_admin');
        Route::post('/employees/import', [EmployeeImportController::class, 'import'])
            ->middleware('role:super_admin,hr_admin');
        Route::get('/employees/{employeeId}/archive-impact', [EmployeeController::class, 'archiveImpact'])
            ->whereNumber('employeeId')
            ->middleware('role:super_admin,hr_admin');
        Route::delete('/employees/{employeeId}', [EmployeeController::class, 'destroy'])
            ->whereNumber('employeeId')
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/employees/{employeeId}/hierarchy', [EmployeeController::class, 'updateHierarchy'])
            ->whereNumber('employeeId')
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/employees/{employeeId}/compensation', [EmployeeController::class, 'updateCompensation'])
            ->whereNumber('employeeId')
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/employees/{employeeId}/lead-access', [EmployeeController::class, 'updateLeadAccess'])
            ->whereNumber('employeeId')
            ->middleware('role:super_admin,hr_admin');
        Route::get('/employee-staffing-requests', [EmployeeStaffingRequestController::class, 'index'])
            ->middleware('role:manager,super_admin,hr_admin,hr_executive');
        Route::post('/employee-staffing-requests', [EmployeeStaffingRequestController::class, 'store'])
            ->middleware('role:manager');
        Route::patch('/employee-staffing-requests/{requestId}/review', [EmployeeStaffingRequestController::class, 'review'])
            ->whereNumber('requestId')
            ->middleware('role:super_admin,hr_admin,hr_executive');

        // M10 Employee Lifecycle & Clearances
        Route::get('/lifecycle/cases', [EmployeeLifecycleController::class, 'index'])
            ->middleware('role:super_admin,hr_admin,hr_executive');
        Route::post('/lifecycle/cases', [EmployeeLifecycleController::class, 'store'])
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/lifecycle/tasks/{id}', [EmployeeLifecycleController::class, 'updateTask'])
            ->middleware('role:super_admin,hr_admin');

        // M13 Training & Certification
        Route::get('/training', [TrainingController::class, 'index'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,super_admin');
        Route::post('/training/courses', [TrainingController::class, 'storeCourse'])
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/training/courses/{id}', [TrainingController::class, 'updateCourse'])
            ->whereNumber('id')
            ->middleware('role:super_admin,hr_admin');
        Route::delete('/training/courses/{id}', [TrainingController::class, 'deleteCourse'])
            ->whereNumber('id')
            ->middleware('role:super_admin,hr_admin');
        Route::post('/training/courses/{id}/enrollments', [TrainingController::class, 'assignEmployees'])
            ->whereNumber('id')
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/training/enrollments/{id}/progress', [TrainingController::class, 'updateProgress'])
            ->middleware('role:employee,manager');
        Route::post('/training/enrollments/{id}/review', [TrainingController::class, 'reviewCompletion'])
            ->middleware('role:manager,hr_admin,super_admin');

        // M12 Performance & OKRs
        Route::get('/performance', [PerformanceController::class, 'index'])
            ->middleware('role:employee,manager,super_admin');
        Route::post('/performance/cycles', [PerformanceController::class, 'storeCycle'])
            ->middleware('role:super_admin');
        Route::post('/performance/cycles/{cycleId}/close', [PerformanceController::class, 'closeCycle'])
            ->middleware('role:super_admin');
        Route::post('/performance/cycles/{cycleId}/objectives', [PerformanceController::class, 'storeObjective'])
            ->middleware('role:employee,manager');
        Route::post('/performance/cycles/{cycleId}/submit-objectives', [PerformanceController::class, 'submitCycleObjectives'])
            ->middleware('role:employee,manager');
        Route::patch('/performance/objectives/{objectiveId}', [PerformanceController::class, 'updateObjective'])
            ->middleware('role:employee,manager');
        Route::delete('/performance/objectives/{objectiveId}', [PerformanceController::class, 'destroyObjective'])
            ->middleware('role:employee,manager');
        Route::post('/performance/objectives/{objectiveId}/review', [PerformanceController::class, 'reviewObjective'])
            ->middleware('role:manager,super_admin');
        Route::patch('/performance/objectives/{objectiveId}/self-review', [PerformanceController::class, 'submitSelfReview'])
            ->middleware('role:employee,manager');
        Route::patch('/performance/objectives/{objectiveId}/manager-review', [PerformanceController::class, 'submitManagerReview'])
            ->middleware('role:manager,super_admin');
        Route::patch('/performance/key-results/{keyResultId}/progress', [PerformanceController::class, 'updateKeyResultProgress'])
            ->middleware('role:employee,manager');

        // M08 Expenses and Loan Requests
        Route::get('/expenses', [ExpenseController::class, 'index'])
            ->middleware('role:employee,manager,super_admin');
        Route::post('/expense-claims', [ExpenseController::class, 'storeClaim'])
            ->middleware('role:employee,manager,super_admin');
        Route::post('/expense-claims/{id}/review', [ExpenseController::class, 'reviewClaim'])
            ->middleware('role:manager,super_admin');
        Route::post('/expense-claims/{id}/pay', [ExpenseController::class, 'markClaimPaid'])
            ->middleware('role:super_admin');
        Route::post('/loan-requests', [ExpenseController::class, 'storeLoanRequest'])
            ->middleware('role:employee,manager,super_admin');
        Route::post('/loan-requests/{id}/review', [ExpenseController::class, 'reviewLoanRequest'])
            ->middleware('role:manager,super_admin');

        // M16 Documents, Policies, and HR Letter Drafts
        Route::get('/documents/policies', [DocumentsController::class, 'policies'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,super_admin');
        Route::post('/documents/policies', [DocumentsController::class, 'storePolicy'])
            ->middleware('role:hr_admin,super_admin');
        Route::post('/documents/policies/{id}/acknowledge', [DocumentsController::class, 'acknowledgePolicy'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,super_admin');
        Route::post('/documents/policies/{id}/archive', [DocumentsController::class, 'archivePolicy'])
            ->middleware('role:hr_admin,super_admin');
        Route::get('/hr-letters', [DocumentsController::class, 'letters'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,super_admin');
        Route::post('/hr-letters', [DocumentsController::class, 'createLetter'])
            ->middleware('role:hr_admin,super_admin');
        Route::post('/employee-documents/{id}/review', [DocumentsController::class, 'reviewEmployeeDocument'])
            ->middleware('role:hr_admin,super_admin');

        // M05 Attendance
        Route::get('/attendance/imports', [AttendanceImportController::class, 'index'])
            ->middleware('role:super_admin,hr_admin,hr_executive');
        Route::post('/attendance/imports/{uploadId}/preview', [AttendanceImportController::class, 'preview'])
            ->middleware('role:super_admin,hr_admin');
        Route::post('/attendance/imports/{uploadId}/process', [AttendanceImportController::class, 'process'])
            ->middleware('role:super_admin,hr_admin');
        Route::get('/attendance', [AttendanceController::class, 'index'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::post('/attendance/punch', [AttendanceController::class, 'punch'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::get('/attendance/regularizations', [AttendanceController::class, 'regularizations'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,super_admin');
        Route::post('/attendance/regularizations', [AttendanceController::class, 'requestRegularization'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,super_admin');
        Route::post('/attendance/regularizations/{id}/review', [AttendanceController::class, 'reviewRegularization'])
            ->middleware('role:manager,hr_admin,hr_executive,super_admin');

        // M06 Leaves
        Route::get('/leaves', [LeaveController::class, 'index'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::get('/leaves/balances', [LeaveController::class, 'balances'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::post('/leaves/apply', [LeaveController::class, 'apply'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::post('/leaves/{id}/review', [LeaveController::class, 'review'])
            ->middleware('role:manager,hr_admin,hr_executive,super_admin');
        Route::post('/leaves/{id}/cancel', [LeaveController::class, 'cancel'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');

        // M07 Payroll (India Statutory)
        Route::get('/payroll/payslips/mine', [PayrollController::class, 'mine']);
        Route::get('/payroll/runs', [PayrollController::class, 'index'])
            ->middleware('role:super_admin,payroll_admin,auditor');
        Route::get('/payroll/components', [PayrollComponentController::class, 'index'])
            ->middleware('role:super_admin,hr_admin,payroll_admin');
        Route::post('/payroll/components', [PayrollComponentController::class, 'store'])
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/payroll/components/{id}', [PayrollComponentController::class, 'update'])
            ->whereNumber('id')
            ->middleware('role:super_admin,hr_admin');
        Route::get('/payroll/statutory-config', [PayrollStatutoryController::class, 'index'])
            ->middleware('role:super_admin,hr_admin,payroll_admin');
        Route::post('/payroll/statutory-config/contributions', [PayrollStatutoryController::class, 'storeContributionConfig'])
            ->middleware('role:super_admin,hr_admin');
        Route::post('/payroll/statutory-config/professional-tax', [PayrollStatutoryController::class, 'storeProfessionalTaxRules'])
            ->middleware('role:super_admin,hr_admin');
        Route::post('/payroll/statutory-config/income-tax', [PayrollStatutoryController::class, 'storeIncomeTaxRules'])
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/payroll/statutory-config/branches/{branchId}/professional-tax', [PayrollStatutoryController::class, 'updateBranchProfessionalTax'])
            ->whereNumber('branchId')
            ->middleware('role:super_admin,hr_admin');
        Route::get('/payroll/tax-declarations', [PayrollStatutoryController::class, 'declarations'])
            ->middleware('role:super_admin,hr_admin,payroll_admin');
        Route::put('/payroll/tax-declarations/{employeeId}', [PayrollStatutoryController::class, 'updateDeclaration'])
            ->whereNumber('employeeId')
            ->middleware('role:super_admin,hr_admin');
        Route::post('/payroll/runs', [PayrollController::class, 'generate'])
            ->middleware('role:super_admin,payroll_admin');
        Route::post('/payroll/runs/{id}/verify', [PayrollController::class, 'verify'])
            ->middleware('role:super_admin,payroll_admin');
        Route::post('/payroll/runs/{id}/publish', [PayrollController::class, 'publish'])
            ->middleware('role:super_admin,payroll_admin');
        Route::post('/payroll/runs/{id}/lock', [PayrollController::class, 'lock'])
            ->middleware('role:super_admin,payroll_admin');
        Route::get('/payroll/runs/{runId}/payslips', [PayrollController::class, 'payslips'])
            ->middleware('role:super_admin,payroll_admin');
        Route::patch('/payroll/runs/{runId}/payslips/{payslipId}/deductions', [PayrollController::class, 'updatePayslipDeductions'])
            ->middleware('role:super_admin,payroll_admin');

        Route::get('/helpdesk/tickets', [HelpdeskController::class, 'index'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,super_admin');
        Route::post('/helpdesk/tickets', [HelpdeskController::class, 'store'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,super_admin');
        Route::get('/helpdesk/tickets/{ticketId}', [HelpdeskController::class, 'show'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,super_admin');
        Route::post('/helpdesk/tickets/{ticketId}/messages', [HelpdeskController::class, 'addMessage'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,super_admin');
        Route::patch('/helpdesk/tickets/{ticketId}/status', [HelpdeskController::class, 'updateStatus'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,super_admin');
        Route::get('/announcements', [AnnouncementsController::class, 'index'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,super_admin');
        Route::post('/announcements', [AnnouncementsController::class, 'store'])
            ->middleware('role:super_admin,hr_admin');
        Route::post('/announcements/{announcementId}/read', [AnnouncementsController::class, 'markRead'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,super_admin');
        Route::get('/assets', [AssetsController::class, 'index'])
            ->middleware('role:super_admin');
        Route::post('/assets', [AssetsController::class, 'store'])
            ->middleware('role:super_admin');
        Route::post('/assets/{assetId}/assign', [AssetsController::class, 'assign'])
            ->middleware('role:super_admin');
        Route::post('/assets/{assetId}/return', [AssetsController::class, 'returnAsset'])
            ->middleware('role:super_admin');
        Route::patch('/assets/{assetId}/status', [AssetsController::class, 'updateStatus'])
            ->middleware('role:super_admin');

        Route::prefix('recruitment')->middleware('role:super_admin,hr_admin,hr_executive,recruiter')->group(function () {
            Route::get('/', [OperationalRecordController::class, 'recruitment']);
            Route::post('/jobs', [OperationalRecordController::class, 'createRecruitmentJob'])
                ->middleware('role:super_admin,hr_admin,recruiter');
            Route::patch('/jobs/{id}/status', [OperationalRecordController::class, 'updateRecruitmentJobStatus'])
                ->middleware('role:super_admin,hr_admin,recruiter');
            Route::post('/candidates', [OperationalRecordController::class, 'createCandidate'])
                ->middleware('role:super_admin,hr_admin,recruiter');
            Route::patch('/candidates/{id}/stage', [OperationalRecordController::class, 'updateCandidateStage'])
                ->middleware('role:super_admin,hr_admin,recruiter');
            Route::patch('/candidates/{id}/evaluation', [OperationalRecordController::class, 'evaluateCandidate'])
                ->middleware('role:super_admin,hr_admin,recruiter');
            Route::post('/candidates/{id}/convert', [OperationalRecordController::class, 'convertCandidate'])
                ->middleware('role:super_admin,hr_admin,recruiter');
        });

        // M17 Projects & Deadlines (Architecture Section 9 Baseline)
        Route::get('/projects/mine', [ProjectController::class, 'mine'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::get('/projects', [ProjectController::class, 'index'])
            ->middleware('role:manager,hr_admin,hr_executive,super_admin');
        Route::get('/project-penalty-policy', [ProjectController::class, 'penaltyPolicy'])
            ->middleware('role:manager,hr_admin,hr_executive,super_admin');
        Route::post('/projects', [ProjectController::class, 'store'])
            ->middleware('role:manager,hr_admin,hr_executive,super_admin');
        Route::post('/project-assignments/{id}/progress', [ProjectController::class, 'updateProgress'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::post('/project-assignments/{id}/extension-requests', [ProjectController::class, 'requestExtension'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::post('/project-extension-requests/{id}/approve', [ProjectController::class, 'approveExtension'])
            ->middleware('role:manager,hr_admin,hr_executive,super_admin');
        Route::post('/project-assignments/{id}/submit', [ProjectController::class, 'submitCompletion'])
            ->middleware('role:employee,manager,hr_admin,hr_executive,recruiter,payroll_admin,auditor,super_admin');
        Route::post('/project-assignments/{id}/accept', [ProjectController::class, 'acceptCompletion'])
            ->middleware('role:manager,hr_admin,hr_executive,super_admin');
        Route::post('/project-assignments/{id}/penalties', [ProjectController::class, 'assessPenalty'])
            ->middleware('role:hr_admin,hr_executive,super_admin');
        Route::post('/project-penalties/{id}/waive', [ProjectController::class, 'waivePenalty'])
            ->middleware('role:manager,super_admin');

        // M00 Platform & Audit Logs
        Route::get('/platform/audit-logs', [PlatformController::class, 'auditLogs'])
            ->middleware('role:super_admin,auditor');
        Route::get('/platform/scheduler-runs', [PlatformController::class, 'schedulerRuns'])
            ->middleware('role:super_admin,auditor');

        // M16 Reports & Analytics
        Route::get('/reports/summary', [ReportsController::class, 'index'])
            ->middleware('role:super_admin,hr_admin,hr_executive,manager,payroll_admin,auditor');
        Route::get('/reports/exports/{report}', [ReportsController::class, 'export'])
            ->where('report', 'employee_master|pf_source_register|pt_source_register|tds_source_register|training_compliance')
            ->middleware('role:super_admin,hr_admin,hr_executive,manager,payroll_admin,auditor');

        // M17 Lead Management Module
        Route::get('/leads/assignees', [LeadController::class, 'assignees']);
        Route::get('/leads', [LeadController::class, 'index']);
        Route::post('/leads', [LeadController::class, 'store']);
        Route::get('/leads/{id}', [LeadController::class, 'show'])
            ->whereNumber('id');
        Route::put('/leads/{id}', [LeadController::class, 'update'])
            ->whereNumber('id');
        Route::delete('/leads/{id}', [LeadController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('role:super_admin,hr_admin');
        Route::patch('/leads/{id}/progress', [LeadController::class, 'updateProgress'])
            ->whereNumber('id');

        // M00 Account credential administration
        Route::prefix('admin')->middleware('role:super_admin')->group(function () {
            Route::get('/users', [UserManagementController::class, 'index']);
            Route::patch('/users/{user}/password', [UserManagementController::class, 'resetPassword']);
            Route::patch('/users/{user}/email', [EmailChangeController::class, 'requestForUser'])
                ->middleware('throttle:5,1');
        });
    });
});
