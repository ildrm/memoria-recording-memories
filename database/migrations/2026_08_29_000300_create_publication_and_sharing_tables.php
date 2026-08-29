<?php

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SharePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_user_id');
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('server_url', 2048)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('connected_at');
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['user_id', 'provider', 'provider_user_id'], 'social_accounts_owner_provider_uid_unique');
            $table->index(['user_id', 'provider', 'revoked_at'], 'social_accounts_owner_provider_idx');
        });

        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_entry_id')->nullable()->constrained('entries')->nullOnDelete();
            $table->string('title');
            $table->string('slug', 180);
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('status', 30)->default(PublicationStatus::Draft->value);
            $table->boolean('comments_enabled')->default(false);
            $table->boolean('reactions_enabled')->default(false);
            $table->boolean('search_engine_indexing')->default(false);
            $table->timestampTz('privacy_reviewed_at')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('unpublished_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'status', 'published_at'], 'publications_owner_status_date_idx');
            $table->index(['status', 'published_at'], 'publications_public_status_date_idx');
            $table->index(['status', 'scheduled_at'], 'publications_schedule_idx');
        });

        Schema::create('publication_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('disk', 80)->default('local');
            $table->string('path', 2048);
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('metadata_stripped')->default(false);
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['disk', 'path']);
            $table->index(['publication_id', 'sort_order']);
            $table->index(['publication_id', 'is_featured']);
        });

        Schema::create('publication_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('status', 30);
            $table->json('settings')->nullable();
            $table->string('reason', 80)->nullable();
            $table->timestampsTz();

            $table->unique(['publication_id', 'version']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('publication_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_key', 160);
            $table->string('type', 30)->default(PublicationTargetType::Website->value);
            $table->string('provider', 40)->nullable();
            $table->string('status', 30)->default(PublicationTargetStatus::Pending->value);
            $table->text('content_override')->nullable();
            $table->json('settings')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['publication_id', 'target_key']);
            $table->index(['status', 'scheduled_at'], 'publication_targets_schedule_idx');
            $table->index(['user_id', 'status', 'created_at'], 'publication_targets_owner_status_idx');
        });

        Schema::create('entry_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shared_with_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 30)->default(SharePermission::View->value);
            $table->boolean('include_attachments')->default(false);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['entry_id', 'shared_with_user_id']);
            $table->index(['shared_with_user_id', 'revoked_at', 'expires_at'], 'entry_shares_recipient_active_idx');
            $table->index(['shared_by_user_id', 'created_at']);
        });

        Schema::create('share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('publication_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('password_hash')->nullable();
            $table->boolean('include_attachments')->default(false);
            $table->boolean('track_views')->default(false);
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedBigInteger('max_views')->nullable();
            $table->timestampTz('last_accessed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['expires_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('entry_shares');
        Schema::dropIfExists('publication_targets');
        Schema::dropIfExists('publication_versions');
        Schema::dropIfExists('publication_media');
        Schema::dropIfExists('publications');
        Schema::dropIfExists('social_accounts');
    }
};
