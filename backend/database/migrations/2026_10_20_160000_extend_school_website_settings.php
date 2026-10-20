<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_website_settings', function (Blueprint $table): void {
            $table->string('meta_description', 320)->nullable();
            $table->string('instagram_url', 2048)->nullable();
            $table->string('font_family', 80)->default('system');
        });
    }

    public function down(): void
    {
        Schema::table('school_website_settings', function (Blueprint $table): void {
            $table->dropColumn(['meta_description', 'instagram_url', 'font_family']);
        });
    }
};
