<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HelpdeskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ticket_replies_status_lifecycle_and_sla_are_persisted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00:00'));
        $employee = User::where('role', 'employee')->firstOrFail();
        $admin = User::where('role', 'super_admin')->firstOrFail();
        Sanctum::actingAs($employee);

        $ticket = $this->postJson('/api/v1/helpdesk/tickets', [
            'title' => 'VPN access request',
            'description' => 'The company VPN rejects my credentials.',
            'category' => 'IT Support',
            'priority' => 'Urgent',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'Open')
            ->assertJsonPath('data.assigned_to', 'IT Support')
            ->assertJsonPath('data.sla_status', 'Due soon')
            ->assertJsonPath('data.sla_hours_left', 2)
            ->json('data');
        $this->assertDatabaseHas('helpdesk_tickets', [
            'id' => $ticket['id'],
            'description' => 'The company VPN rejects my credentials.',
            'created_by_user_id' => $employee->id,
            'sla_hours_left' => 2,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-14 13:15:00'));
        $this->getJson('/api/v1/helpdesk/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.sla_status', 'Overdue')
            ->assertJsonPath('data.0.sla_hours_overdue', 2);
        $this->patchJson("/api/v1/helpdesk/tickets/{$ticket['id']}/status", [
            'status' => 'In Progress',
        ])->assertForbidden();
        $this->postJson("/api/v1/helpdesk/tickets/{$ticket['id']}/messages", [
            'body' => 'I have restarted the VPN client.',
        ])->assertOk()
            ->assertJsonPath('data.messages.0.body', 'I have restarted the VPN client.');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/helpdesk/tickets/{$ticket['id']}/status", [
            'status' => 'In Progress',
        ])->assertOk()
            ->assertJsonPath('data.status', 'In Progress');
        $this->patchJson("/api/v1/helpdesk/tickets/{$ticket['id']}/status", [
            'status' => 'Resolved',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Resolved')
            ->assertJsonPath('data.sla_status', 'Breached');

        Sanctum::actingAs($employee);
        $this->patchJson("/api/v1/helpdesk/tickets/{$ticket['id']}/status", [
            'status' => 'Closed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Closed');
        $this->postJson("/api/v1/helpdesk/tickets/{$ticket['id']}/messages", [
            'body' => 'Follow-up after closure.',
        ])->assertStatus(409);
    }

    public function test_hr_ticket_access_and_management_stay_within_hr_scope(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $hrExecutive = User::factory()->create(['role' => 'hr_executive']);
        $employee = User::where('role', 'employee')->firstOrFail();

        Sanctum::actingAs($admin);
        $payrollTicket = $this->postJson('/api/v1/helpdesk/tickets', [
            'title' => 'Payroll issue',
            'category' => 'Payroll',
            'priority' => 'High',
        ])->assertCreated()->json('data');

        Sanctum::actingAs($hrAdmin);
        $hrTicket = $this->postJson('/api/v1/helpdesk/tickets', [
            'title' => 'Benefits question',
            'category' => 'HR Query',
            'priority' => 'Medium',
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/helpdesk/tickets', [
            'title' => 'Out-of-scope IT request',
            'category' => 'IT Support',
            'priority' => 'Low',
        ])->assertForbidden();
        $this->patchJson("/api/v1/helpdesk/tickets/{$hrTicket['id']}/status", [
            'status' => 'In Progress',
        ])->assertOk();
        $this->getJson('/api/v1/helpdesk/tickets')
            ->assertOk()
            ->assertJsonMissing(['id' => $payrollTicket['id']]);

        Sanctum::actingAs($hrExecutive);
        $this->patchJson("/api/v1/helpdesk/tickets/{$hrTicket['id']}/status", [
            'status' => 'Resolved',
        ])->assertForbidden();
        $this->getJson('/api/v1/helpdesk/tickets')->assertOk();

        Sanctum::actingAs($employee);
        $this->getJson("/api/v1/helpdesk/tickets/{$hrTicket['id']}")->assertNotFound();
    }
}
