<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            $table->dropUnique('users_email_unique');
            $table->string('email')->nullable()->change();
            $table->unique('email');
            $table->string('username')->nullable()->unique();
        });

        Schema::table('teachers', function (Blueprint $table): void {
            $table->string('profile_photo_path')->nullable();
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->string('roll')->nullable();
            $table->string('profile_photo_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Cannot roll back nullable user email while username-only accounts exist.');
        }

        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn(['roll', 'profile_photo_path']);
        });

        Schema::table('teachers', function (Blueprint $table): void {
            $table->dropColumn('profile_photo_path');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_username_unique');
            $table->dropUnique('users_email_unique');
            $table->string('email')->nullable(false)->change();
            $table->unique('email');
            $table->dropColumn('username');
        });
    }
};
