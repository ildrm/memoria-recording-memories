<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\SystemHealthSnapshot;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'System health';

    protected string $view = 'filament.admin.pages.system-health';

    /**
     * @var array<string, array{label: string, state: string, status: 'success'|'warning'|'danger', description: string}>
     */
    public array $checks = [];

    /** @var array{pending_jobs: int|null, failed_jobs: int|null} */
    public array $counts = [
        'pending_jobs' => null,
        'failed_jobs' => null,
    ];

    public string $checkedAt = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->hasPermissionTo('system.manage');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->refreshChecks();
    }

    public function refreshChecks(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        $snapshot = app(SystemHealthSnapshot::class)->for($user);
        $this->checks = $snapshot['checks'];
        $this->counts = $snapshot['counts'];
        $this->checkedAt = $snapshot['checked_at'];
    }
}
