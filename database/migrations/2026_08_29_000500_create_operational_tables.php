<?php

use App\Enums\ExportStatus;
use App\Enums\ReminderFrequency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('format', 30)->default('zip');
            $table->string('status', 30)->default(ExportStatus::Pending->value);
            $table->json('options')->nullable();
            $table->string('disk', 80)->nullable();
            $table->string('path', 2048)->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status', 'created_at'], 'exports_owner_status_idx');
            $table->index(['status', 'created_at']);
            $table->index(['expires_at', 'status']);
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('frequency', 30)->default(ReminderFrequency::Daily->value);
            $table->time('local_time');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->json('channels')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['is_enabled', 'next_run_at']);
            $table->index(['user_id', 'is_enabled']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('auditable');
            $table->string('event', 120);
            $table->char('ip_address_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('exports');
    }
};
