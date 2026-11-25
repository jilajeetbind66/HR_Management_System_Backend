<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_messages')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                if (!Schema::hasColumn('chat_messages', 'is_edited')) {
                    $table->boolean('is_edited')->default(false)->after('message');
                }
                if (!Schema::hasColumn('chat_messages', 'edited_at')) {
                    $table->timestamp('edited_at')->nullable()->after('is_edited');
                }
                if (!Schema::hasColumn('chat_messages', 'is_deleted')) {
                    $table->boolean('is_deleted')->default(false)->after('edited_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chat_messages')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn(['is_edited', 'edited_at', 'is_deleted']);
            });
        }
    }
};
