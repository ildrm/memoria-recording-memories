<?php

use App\Actions\AssignUserRole;
use App\Actions\RemoveUserRole;
use App\Actions\UpdateReportModeration;
use App\Enums\ReportStatus;
use App\Enums\RoleName;
use App\Filament\Admin\Resources\ReportResource\Pages\EditReport;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\AuditEvent;
use App\Models\Report;
use App\Models\User;
use App\Services\EligibleReportAssignees;
use App\Services\SystemHealthSnapshot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('only a super administrator can assign and remove roles through confirmed admin actions', function (): void {
    $superAdministrator = User::factory()->superAdministrator()->create();
    $subject = User::factory()->create();

    $this->actingAs($superAdministrator->refresh());

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertActionExists(
            TestAction::make('assignRole')->table($subject),
            fn (Action $action): bool => $action->isConfirmationRequired(),
        )
        ->callAction(
            TestAction::make('assignRole')->table($subject),
            data: ['role' => RoleName::Moderator->value],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($subject->hasRole(RoleName::Moderator))->toBeTrue();

    Livewire::test(ListUsers::class)
        ->assertActionExists(
            TestAction::make('removeRole')->table($subject),
            fn (Action $action): bool => $action->isConfirmationRequired(),
        )
        ->callAction(
            TestAction::make('removeRole')->table($subject),
            data: ['role' => RoleName::Moderator->value],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($subject->hasRole(RoleName::Moderator))->toBeFalse()
        ->and(AuditEvent::query()
            ->where('event', 'admin.user.role_assigned')
            ->where('actor_user_id', $superAdministrator->getKey())
            ->where('auditable_id', $subject->getKey())
            ->exists())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('event', 'admin.user.role_removed')
            ->where('actor_user_id', $superAdministrator->getKey())
            ->where('auditable_id', $subject->getKey())
            ->exists())->toBeTrue();
});

test('administrator role permissions do not grant role management', function (): void {
    $administrator = User::factory()->withRole(RoleName::Administrator)->create();
    $subject = User::factory()->create();

    $this->actingAs($administrator->refresh());

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertActionHidden(TestAction::make('assignRole')->table($subject))
        ->assertActionHidden(TestAction::make('removeRole')->table($subject));

    expect(fn () => app(AssignUserRole::class)->handle(
        subject: $subject,
        roleName: RoleName::Moderator,
        actor: $administrator,
    ))->toThrow(AuthorizationException::class);

    expect($subject->hasRole(RoleName::Moderator))->toBeFalse();
});

test('the last active super administrator role cannot be removed', function (): void {
    $activeSuperAdministrator = User::factory()->superAdministrator()->create();
    User::factory()->disabled()->superAdministrator()->create();

    expect(fn () => app(RemoveUserRole::class)->handle(
        subject: $activeSuperAdministrator,
        roleName: RoleName::SuperAdministrator,
        actor: $activeSuperAdministrator,
    ))->toThrow(ValidationException::class, 'Keep at least one active super administrator');

    expect($activeSuperAdministrator->hasRole(RoleName::SuperAdministrator))->toBeTrue()
        ->and(AuditEvent::query()->where('event', 'admin.user.role_removed')->exists())->toBeFalse();
});

test('role actions are idempotent and audit only material changes', function (): void {
    $superAdministrator = User::factory()->superAdministrator()->create();
    $subject = User::factory()->create();
    $action = app(AssignUserRole::class);

    expect($action->handle($subject, RoleName::Moderator, $superAdministrator))->toBeTrue()
        ->and($action->handle($subject, RoleName::Moderator, $superAdministrator))->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'admin.user.role_assigned')->count())->toBe(1);
});

test('report assignees are limited to active moderation staff in queries and updates', function (): void {
    $moderator = User::factory()->withRole(RoleName::Moderator)->create();
    $administrator = User::factory()->withRole(RoleName::Administrator)->create();
    $superAdministrator = User::factory()->superAdministrator()->create();
    $ordinaryUser = User::factory()->create();
    $disabledModerator = User::factory()->disabled()->withRole(RoleName::Moderator)->create();
    $report = Report::factory()->create();

    $eligibleIds = app(EligibleReportAssignees::class)
        ->constrain(User::query())
        ->pluck('id');

    expect($eligibleIds)
        ->toContain($moderator->getKey(), $administrator->getKey(), $superAdministrator->getKey())
        ->not->toContain($ordinaryUser->getKey(), $disabledModerator->getKey());

    $updated = app(UpdateReportModeration::class)->handle(
        report: $report,
        actor: $moderator,
        status: ReportStatus::InReview,
        assigneeUserId: (int) $administrator->getKey(),
        moderationNotes: 'Reviewed without opening any private diary content.',
        resolution: null,
    );

    expect($updated->assigned_to_user_id)->toBe($administrator->getKey())
        ->and($updated->status)->toBe(ReportStatus::InReview)
        ->and(AuditEvent::query()
            ->where('event', 'admin.report.updated')
            ->where('actor_user_id', $moderator->getKey())
            ->where('auditable_id', $report->getKey())
            ->exists())->toBeTrue();

    foreach ([$ordinaryUser, $disabledModerator] as $ineligibleAssignee) {
        expect(fn () => app(UpdateReportModeration::class)->handle(
            report: $report,
            actor: $moderator,
            status: ReportStatus::InReview,
            assigneeUserId: (int) $ineligibleAssignee->getKey(),
            moderationNotes: null,
            resolution: null,
        ))->toThrow(ValidationException::class, 'Choose an active moderator or administrator');
    }

    expect($report->refresh()->assigned_to_user_id)->toBe($administrator->getKey());
});

test('report assignment validation is enforced when the Filament form is tampered with', function (): void {
    $moderator = User::factory()->withRole(RoleName::Moderator)->create();
    $ordinaryUser = User::factory()->create();
    $report = Report::factory()->create();

    $this->actingAs($moderator->refresh());

    Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
        ->fillForm([
            'status' => ReportStatus::InReview->value,
            'assigned_to_user_id' => $ordinaryUser->getKey(),
            'moderation_notes' => null,
            'resolution' => null,
        ])
        ->call('save')
        ->assertHasFormErrors(['assigned_to_user_id']);

    expect($report->refresh()->assigned_to_user_id)->toBeNull();
});

test('system health is super-admin only and exposes bounded counts without job contents', function (): void {
    $superAdministrator = User::factory()->superAdministrator()->create();
    $administrator = User::factory()->withRole(RoleName::Administrator)->create();
    $sensitivePayload = 'private-payload-'.Str::random(20);
    $sensitiveException = 'internal-exception-'.Str::random(20);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'fictional-connection',
        'queue' => 'fictional-queue',
        'payload' => $sensitivePayload,
        'exception' => $sensitiveException,
        'failed_at' => now(),
    ]);

    $this->actingAs($superAdministrator->refresh())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSee('A privacy-safe operational snapshot')
        ->assertSee('1 failed job record')
        ->assertDontSee($sensitivePayload)
        ->assertDontSee($sensitiveException)
        ->assertDontSee('fictional-connection')
        ->assertDontSee('fictional-queue');

    $this->actingAs($administrator->refresh())
        ->get('/admin/system-health')
        ->assertForbidden();

    expect(fn () => app(SystemHealthSnapshot::class)->for($administrator))
        ->toThrow(AuthorizationException::class);
});

test('system health degrades safely when failed-job storage is absent', function (): void {
    $superAdministrator = User::factory()->superAdministrator()->create();
    config()->set('queue.failed.table', 'missing_failed_jobs_for_health_check');

    $snapshot = app(SystemHealthSnapshot::class)->for($superAdministrator);

    expect($snapshot['counts']['failed_jobs'])->toBeNull()
        ->and($snapshot['checks']['failed_jobs']['state'])->toBe('Count unavailable')
        ->and($snapshot['checks']['database']['status'])->toBe('success')
        ->and($snapshot['checks']['cache']['status'])->toBe('success');
});
