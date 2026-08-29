<?php

use App\Enums\CommentStatus;
use App\Enums\ReportStatus;
use App\Enums\SocialPostStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publication_target_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('status', 30)->default(SocialPostStatus::Pending->value);
            $table->char('idempotency_key', 64)->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->text('content')->nullable();
            $table->string('remote_post_id')->nullable();
            $table->string('remote_url', 2048)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['social_account_id', 'remote_post_id']);
            $table->index(['status', 'scheduled_at'], 'social_posts_schedule_idx');
            $table->index(['user_id', 'status', 'created_at'], 'social_posts_owner_status_idx');
            $table->index(['publication_id', 'provider', 'status'], 'social_posts_publication_provider_idx');
        });

        Schema::create('social_post_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('error_class')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('message');
            $table->boolean('is_retryable')->default(false);
            $table->json('context')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['social_post_id', 'occurred_at']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('status', 30)->default(CommentStatus::Pending->value);
            $table->char('ip_hash', 64)->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['publication_id', 'status', 'created_at'], 'comments_publication_status_idx');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->timestampsTz();

            $table->unique(['publication_id', 'user_id', 'type']);
            $table->index(['publication_id', 'type']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 80);
            $table->text('details')->nullable();
            $table->string('status', 30)->default(ReportStatus::Open->value);
            $table->text('resolution')->nullable();
            $table->text('moderation_notes')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_to_user_id', 'status', 'created_at'], 'reports_assignee_status_idx');
            $table->index(['reporter_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('social_post_failures');
        Schema::dropIfExists('social_posts');
    }
};
