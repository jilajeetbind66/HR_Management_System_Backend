<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->string('client_name');
                $table->string('phone', 25)->nullable();
                $table->string('email')->nullable();
                $table->string('project_name');
                $table->string('service_type')->nullable(); // e.g., Website Building, App Development, Digital Marketing (free text)
                $table->date('start_date')->nullable();
                $table->date('deadline')->nullable();
                $table->decimal('total_amount', 12, 2)->default(0.00); // total kitne payment me baat hui hai
                $table->decimal('paid_amount', 12, 2)->default(0.00); // kitna payment ho gya
                $table->decimal('remaining_amount', 12, 2)->default(0.00); // kitna baki hai
                $table->string('payment_status', 30)->default('Unpaid'); // Unpaid, Partially Paid, Paid
                $table->string('project_status', 30)->default('In Progress'); // Upcoming, In Progress, Completed, On Hold
                $table->text('remarks')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('client_name');
                $table->index('phone');
                $table->index('payment_status');
                $table->index('project_status');
                $table->index('start_date');
                $table->index('deadline');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
