<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->string('author_avatar')->nullable()->after('author_name');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->dropColumn('author_avatar');
        });
    }
};
