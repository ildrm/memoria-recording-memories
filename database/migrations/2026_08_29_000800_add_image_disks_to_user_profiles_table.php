<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('avatar_disk', 80)->nullable()->after('avatar_path');
            $table->string('cover_image_disk', 80)->nullable()->after('cover_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn(['avatar_disk', 'cover_image_disk']);
        });
    }
};
