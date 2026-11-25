<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('client_payments')) {
            Schema::create('client_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->date('payment_date');
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 50)->default('Bank Transfer'); // Bank Transfer, UPI, Cash, Cheque, Card
                $table->string('transaction_reference', 100)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['client_id', 'payment_date']);
                $table->index('payment_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_payments');
    }
};
