<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 80);
            $table->char('path_hash', 64);
            $table->longText('encrypted_path')->nullable();
            $table->string('reason', 120);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['disk', 'path_hash']);
            $table->index(['completed_at', 'failed_at', 'created_at'], 'stored_file_deletions_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_file_deletions');
    }
};
