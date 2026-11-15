<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_policies_are_published_acknowledged_versioned_and_archived(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $effectiveDate = now()->subDay()->toDateString();

        Sanctum::actingAs($hrAdmin);
        $policy = $this->postJson('/api/v1/documents/policies', [
            'title' => 'Information Security Policy',
            'category' => 'Information Security',
            'version' => '1.0',
            'effective_date' => $effectiveDate,
            'mandatory' => true,
            'content' => 'Use approved devices and report security incidents.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data');

        $hrExecutive = User::factory()->create([
            'name' => 'Policy HR Executive',
            'email' => 'policy.executive@example.com',
            'role' => 'hr_executive',
        ]);
        Sanctum::actingAs($hrExecutive);
        $this->getJson('/api/v1/documents/policies')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/documents/policies', [
            'title' => 'Unauthorized policy',
            'category' => 'Other',
            'version' => '1.0',
            'effective_date' => $effectiveDate,
            'mandatory' => true,
            'content' => 'This must not publish.',
        ])->assertForbidden();
        $this->postJson("/api/v1/documents/policies/{$policy['id']}/archive", [])
            ->assertForbidden();

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/documents/policies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acknowledged_by_current_user', false)
            ->assertJsonPath('data.0.visible_employee_count', 1);
        $this->postJson("/api/v1/documents/policies/{$policy['id']}/acknowledge", [])
            ->assertCreated()
            ->assertJsonPath('data.policy_version', '1.0');
        $this->postJson("/api/v1/documents/policies/{$policy['id']}/acknowledge", [])
            ->assertStatus(409);
        $this->getJson('/api/v1/documents/policies')
            ->assertJsonPath('data.0.acknowledged_by_current_user', true)
            ->assertJsonPath('data.0.acknowledged_count', 1);

        Sanctum::actingAs($hrAdmin);
        $this->postJson("/api/v1/documents/policies/{$policy['id']}/archive", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'POLICY_ACKNOWLEDGED',
            'module' => 'Documents',
        ]);

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/documents/policies')->assertJsonCount(0, 'data');
    }

    public function test_employee_documents_can_be_verified_with_audited_decisions(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $hrExecutive = User::factory()->create([
            'name' => 'Documents HR Executive',
            'email' => 'documents.executive@example.com',
            'role' => 'hr_executive',
        ]);
        $employee = User::where('role', 'employee')->firstOrFail();
        $documentId = DB::table('hrms_uploads')->insertGetId([
            'module' => 'employee_documents',
            'employee_id' => $employee->employee_id,
            'uploaded_by_user_id' => $employee->id,
            'document_type' => 'Identity document',
            'original_filename' => 'identity.pdf',
            'cloudinary_public_id' => (string) Str::uuid(),
            'cloudinary_resource_type' => 'raw',
            'cloudinary_delivery_type' => 'authenticated',
            'cloudinary_format' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($hrExecutive);
        $this->postJson("/api/v1/employee-documents/{$documentId}/review", [
            'decision' => 'verified',
        ])->assertForbidden();
        $this->getJson('/api/v1/employee-documents')->assertForbidden();
        $this->getJson('/api/v1/uploads?module=employee_documents')->assertForbidden();

        Sanctum::actingAs($hrAdmin);
        $this->getJson('/api/v1/employee-documents')
            ->assertOk()
            ->assertJsonPath('data.0.reviewed_by_name', null);
        $this->postJson("/api/v1/employee-documents/{$documentId}/review", [
            'decision' => 'rejected',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('review_note');
        $this->postJson("/api/v1/employee-documents/{$documentId}/review", [
            'decision' => 'verified',
            'review_note' => 'Identity document checked.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.reviewed_by_name', $hrAdmin->name);
        $rejectedDocumentId = DB::table('hrms_uploads')->insertGetId([
            'module' => 'employee_documents',
            'employee_id' => $employee->employee_id,
            'uploaded_by_user_id' => $employee->id,
            'document_type' => 'Address document',
            'original_filename' => 'address.pdf',
            'cloudinary_public_id' => (string) Str::uuid(),
            'cloudinary_resource_type' => 'raw',
            'cloudinary_delivery_type' => 'authenticated',
            'cloudinary_format' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson("/api/v1/employee-documents/{$rejectedDocumentId}/review", [
            'decision' => 'rejected',
            'review_note' => 'Please upload a legible scan.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'Please upload a legible scan.');

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/employee-documents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['status' => 'verified', 'review_note' => 'Identity document checked.'])
            ->assertJsonFragment(['status' => 'rejected', 'review_note' => 'Please upload a legible scan.']);
        $this->postJson("/api/v1/employee-documents/{$documentId}/review", [
            'decision' => 'verified',
        ])->assertForbidden();
    }

    public function test_hr_letter_drafts_are_scoped_and_exclude_salary_data(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();

        Sanctum::actingAs($hrAdmin);
        $experience = $this->postJson('/api/v1/hr-letters', [
            'employee_id' => $employee->employee_id,
            'letter_type' => 'experience',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.snapshot.employee_code', DB::table('employees')
                ->where('id', $employee->employee_id)
                ->value('employee_code'));
        $this->assertStringStartsWith('HRL-', $experience->json('data.letter_number'));
        $experience->assertJsonMissingPath('data.snapshot.gross_monthly');
        $experience->assertJsonMissingPath('data.snapshot.bank_name');
        $this->postJson('/api/v1/hr-letters', [
            'employee_id' => $employee->employee_id,
            'letter_type' => 'relieving',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('letter_type');

        DB::table('employee_lifecycle_cases')->insert([
            'employee_id' => $employee->employee_id,
            'case_type' => 'offboarding',
            'notice_date' => '2026-10-01',
            'effective_date' => '2026-11-01',
            'target_completion_date' => '2026-11-01',
            'status' => 'completed',
            'created_by_user_id' => $hrAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/hr-letters', [
            'employee_id' => $employee->employee_id,
            'letter_type' => 'relieving',
        ])->assertCreated()
            ->assertJsonPath('data.snapshot.last_working_date', '2026-11-01');
        $this->postJson('/api/v1/hr-letters', [
            'employee_id' => $manager->employee_id,
            'letter_type' => 'employment_verification',
        ])->assertCreated();

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/hr-letters')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['employee_code' => DB::table('employees')
                ->where('id', $manager->employee_id)
                ->value('employee_code')]);
        $this->postJson('/api/v1/hr-letters', [])->assertForbidden();
    }
}
