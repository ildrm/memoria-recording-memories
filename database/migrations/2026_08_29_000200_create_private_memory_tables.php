<?php

use App\Enums\AttachmentScanStatus;
use App\Enums\EntryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('cover_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'archived_at', 'sort_order'], 'journals_owner_archive_order_idx');
        });

        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('mood', 30)->nullable();
            $table->string('custom_mood', 80)->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedTinyInteger('importance')->default(0);
            $table->string('status', 20)->default(EntryStatus::Draft->value);
            $table->boolean('is_favorite')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('last_saved_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'occurred_at'], 'entries_owner_occurred_idx');
            $table->index(['user_id', 'journal_id', 'occurred_at'], 'entries_owner_journal_date_idx');
            $table->index(['user_id', 'is_favorite', 'occurred_at'], 'entries_owner_favorite_date_idx');
            $table->index(['user_id', 'archived_at', 'occurred_at'], 'entries_owner_archive_date_idx');
            $table->index(['user_id', 'status', 'updated_at'], 'entries_owner_status_updated_idx');
            $table->index(['user_id', 'deleted_at'], 'entries_owner_trash_idx');
        });

        Schema::create('entry_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('mood', 30)->nullable();
            $table->string('custom_mood', 80)->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedTinyInteger('importance')->default(0);
            $table->json('metadata')->nullable();
            $table->string('reason', 80)->nullable();
            $table->timestampsTz();

            $table->unique(['entry_id', 'version']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->string('color', 20)->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'normalized_name']);
        });

        Schema::create('entry_tag', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('attached_at')->useCurrent();

            $table->primary(['entry_id', 'tag_id']);
            $table->index(['tag_id', 'entry_id']);
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('nickname')->nullable();
            $table->text('notes')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('relationship')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'display_name']);
        });

        Schema::create('entry_person', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_context')->nullable();
            $table->timestampTz('attached_at')->useCurrent();

            $table->primary(['entry_id', 'person_id']);
            $table->index(['person_id', 'entry_id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disk', 80)->default('local');
            $table->string('path', 2048);
            $table->string('original_name');
            $table->string('download_name')->nullable();
            $table->string('mime_type', 150);
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('media_type', 30);
            $table->char('sha256', 64);
            $table->string('scan_status', 30)->default(AttachmentScanStatus::Pending->value);
            $table->timestampTz('scanned_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['disk', 'path']);
            $table->index(['user_id', 'media_type', 'created_at'], 'attachments_owner_type_date_idx');
            $table->index(['entry_id', 'created_at']);
            $table->index(['scan_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('entry_person');
        Schema::dropIfExists('people');
        Schema::dropIfExists('entry_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('entry_versions');
        Schema::dropIfExists('entries');
        Schema::dropIfExists('journals');
    }
};
