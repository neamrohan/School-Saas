<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_website_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('website_name');
            $table->string('tagline')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->text('about')->nullable();
            $table->string('primary_color', 7)->default('#1d3552');
            $table->string('secondary_color', 7)->default('#eaf1f8');
            $table->string('facebook_url', 2048)->nullable();
            $table->string('youtube_url', 2048)->nullable();
            $table->text('footer_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_website_settings');
    }
};
