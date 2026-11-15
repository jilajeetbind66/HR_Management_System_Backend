<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_claims_require_receipts_and_follow_manager_review_to_manual_payment(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);

        Sanctum::actingAs($employee);
        $claim = $this->postJson('/api/v1/expense-claims', [
            'category' => 'Travel',
            'amount' => 1250.50,
            'incurred_on' => '2027-01-12',
            'description' => 'Travel to client office.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data');

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonCount(1, 'data.claims');
        $this->postJson("/api/v1/expense-claims/{$claim['id']}/review", [
            'decision' => 'approved',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('receipts');

        $receiptId = DB::table('hrms_uploads')->insertGetId([
            'module' => 'expense_receipts',
            'employee_id' => $employee->employee_id,
            'record_id' => $claim['id'],
            'uploaded_by_user_id' => $employee->id,
            'original_filename' => 'travel-receipt.jpg',
            'cloudinary_public_id' => (string) Str::uuid(),
            'cloudinary_resource_type' => 'image',
            'cloudinary_delivery_type' => 'authenticated',
            'cloudinary_format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v1/uploads?module=expense_receipts&record_id={$claim['id']}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $receiptId);
        Config::set('services.cloudinary.cloud_name', null);
        Config::set('services.cloudinary.api_key', null);
        Config::set('services.cloudinary.api_secret', null);
        $this->post('/api/v1/uploads', [
            'module' => 'expense_receipts',
            'employee_id' => $employee->employee_id,
            'record_id' => $claim['id'],
            'file' => UploadedFile::fake()->create('manager-upload.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
        $this->getJson("/api/v1/uploads/{$receiptId}/download")->assertStatus(503);

        $this->postJson("/api/v1/expense-claims/{$claim['id']}/review", [
            'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/expense-claims/{$claim['id']}/pay", [])->assertForbidden();

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/v1/expense-claims/{$claim['id']}/pay", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('expense_claims', [
            'id' => $claim['id'],
            'status' => 'paid',
            'paid_by_user_id' => $superAdmin->id,
        ]);
    }

    public function test_receipt_uploads_must_belong_to_an_existing_employee_claim(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($employee);
        $claim = $this->postJson('/api/v1/expense-claims', [
            'category' => 'Meals',
            'amount' => 250,
            'incurred_on' => '2027-01-12',
            'description' => 'Client meeting meal.',
        ])->assertCreated()->json('data');

        Config::set('services.cloudinary.cloud_name', null);
        Config::set('services.cloudinary.api_key', null);
        Config::set('services.cloudinary.api_secret', null);

        $file = UploadedFile::fake()->create('meal-receipt.pdf', 10, 'application/pdf');
        $this->post('/api/v1/uploads', [
            'module' => 'expense_receipts',
            'employee_id' => $employee->employee_id,
            'file' => $file,
        ])->assertForbidden();

        $this->post('/api/v1/uploads', [
            'module' => 'expense_receipts',
            'employee_id' => $employee->employee_id,
            'record_id' => $claim['id'],
            'file' => UploadedFile::fake()->create('meal-receipt.pdf', 10, 'application/pdf'),
        ])->assertStatus(503)
            ->assertJsonPath('code', 'CLOUDINARY_NOT_CONFIGURED');
    }

    public function test_loan_requests_are_manager_reviewed_without_payroll_deductions(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);

        Sanctum::actingAs($hrAdmin);
        $this->getJson('/api/v1/expenses')->assertForbidden();

        Sanctum::actingAs($employee);
        $rejectedLoan = $this->postJson('/api/v1/loan-requests', [
            'requested_amount' => 5000,
            'purpose' => 'Unexpected travel costs.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data');
        $approvedLoan = $this->postJson('/api/v1/loan-requests', [
            'requested_amount' => 10000,
            'purpose' => 'Emergency salary advance request.',
        ])->assertCreated()->json('data');

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonCount(2, 'data.loans');
        $this->postJson("/api/v1/loan-requests/{$rejectedLoan['id']}/review", [
            'decision' => 'rejected',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('decision_note');
        $this->postJson("/api/v1/loan-requests/{$rejectedLoan['id']}/review", [
            'decision' => 'rejected',
            'decision_note' => 'Please provide additional details.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/loan-requests/{$approvedLoan['id']}/review", [
            'decision' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('loan_requests', [
            'id' => $approvedLoan['id'],
            'status' => 'approved',
            'requested_amount' => 10000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOAN_REQUEST_REVIEWED',
            'module' => 'Expenses',
        ]);
    }
}
