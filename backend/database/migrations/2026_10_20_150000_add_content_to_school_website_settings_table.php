<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_website_settings', function (Blueprint $table): void {
            $table->json('content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('school_website_settings', function (Blueprint $table): void {
            $table->dropColumn('content');
        });
    }
};
