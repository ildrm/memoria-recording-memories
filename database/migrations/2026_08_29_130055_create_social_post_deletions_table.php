<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->char('deletion_key', 64)->unique();
            $table->char('remote_post_hash', 64);
            $table->longText('encrypted_remote_post_id')->nullable();
            $table->longText('encrypted_credentials')->nullable();
            $table->string('reason', 120);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->index(['completed_at', 'failed_at', 'last_attempted_at'], 'social_post_deletions_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_deletions');
    }
};
