<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->timestamps();
        });

        DB::table('legal_pages')->insert([
            [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'content' => '<p>This privacy policy has not been written yet.</p>',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms of Service',
                'content' => '<p>These terms of service have not been written yet.</p>',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }
};
