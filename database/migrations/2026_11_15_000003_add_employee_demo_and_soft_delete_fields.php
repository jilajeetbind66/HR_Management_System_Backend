<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamp('deleted_at')->nullable()->index();
        });

        DB::table('employees')
            ->where(function ($query) {
                $query->where('employee_code', 'like', 'DEMO-%')
                    ->orWhereRaw('LOWER(email) LIKE ?', ['demo.%@pinweb.in']);
            })
            ->update(['is_demo' => true]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('employees_deleted_at_index');
            $table->dropIndex('employees_is_demo_index');
            $table->dropColumn(['deleted_at', 'is_demo']);
        });
    }
};
