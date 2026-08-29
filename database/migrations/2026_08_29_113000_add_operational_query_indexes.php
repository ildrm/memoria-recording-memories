<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exports', function (Blueprint $table): void {
            $table->dropIndex(['expires_at', 'status']);
            $table->index(
                ['status', 'expires_at'],
                'exports_expiration_due_idx',
            );
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'is_enabled']);
            $table->index(
                ['user_id', 'is_enabled', 'next_run_at'],
                'reminders_owner_due_idx',
            );
        });

        Schema::table('publication_targets', function (Blueprint $table): void {
            $table->index(
                ['publication_id', 'type', 'status', 'user_id'],
                'publication_targets_public_lookup_idx',
            );
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->index(
                ['publication_id', 'parent_id', 'status', 'created_at'],
                'comments_public_thread_lookup_idx',
            );
        });

        Schema::table('stored_file_deletions', function (Blueprint $table): void {
            $table->dropIndex('stored_file_deletions_pending_idx');
            $table->index(
                ['completed_at', 'failed_at', 'last_attempted_at', 'id'],
                'stored_file_deletions_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('stored_file_deletions', function (Blueprint $table): void {
            $table->dropIndex('stored_file_deletions_due_idx');
            $table->index(
                ['completed_at', 'failed_at', 'created_at'],
                'stored_file_deletions_pending_idx',
            );
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex('comments_public_thread_lookup_idx');
        });

        Schema::table('publication_targets', function (Blueprint $table): void {
            $table->dropIndex('publication_targets_public_lookup_idx');
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropIndex('reminders_owner_due_idx');
            $table->index(['user_id', 'is_enabled']);
        });

        Schema::table('exports', function (Blueprint $table): void {
            $table->dropIndex('exports_expiration_due_idx');
            $table->index(['expires_at', 'status']);
        });
    }
};
