<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->string('author_name')->nullable()->after('user_id');
            $table->string('author_avatar')->nullable()->after('author_name');
            $table->string('image_path')->nullable()->after('body');
            $table->timestamp('read_at')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->dropColumn(['author_name', 'author_avatar', 'image_path', 'read_at']);
        });
    }
};
