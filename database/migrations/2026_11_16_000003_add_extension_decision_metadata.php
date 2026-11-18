<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extension_requests', function (Blueprint $table) {
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_extension_requests', function (Blueprint $table) {
            $table->dropColumn(['decided_by', 'decided_at']);
        });
    }
};
