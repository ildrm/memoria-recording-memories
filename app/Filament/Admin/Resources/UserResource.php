<?php

namespace App\Filament\Admin\Resources;

use App\Actions\AssignUserRole;
use App\Actions\RemoveUserRole;
use App\Enums\RoleName;
use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\AuditRecorder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Account'))
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('roles.display_name')
                    ->label(__('Roles'))
                    ->badge()
                    ->placeholder(__('User')),
                IconColumn::make('email_verified_at')
                    ->label(__('Verified'))
                    ->boolean(fn (mixed $state): bool => filled($state)),
                TextColumn::make('last_login_at')
                    ->label(__('Last sign-in'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(__('Never'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Joined'))
                    ->date()
                    ->sortable(),
                TextColumn::make('disabled_at')
                    ->label(__('Access'))
                    ->formatStateUsing(fn (mixed $state): string => filled($state) ? __('Disabled') : __('Active'))
                    ->badge()
                    ->color(fn (mixed $state): string => filled($state) ? 'danger' : 'success'),
            ])
            ->filters([
                TernaryFilter::make('disabled_at')
                    ->label(__('Disabled accounts'))
                    ->nullable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('assignRole')
                    ->label(__('Assign role'))
                    ->icon(Heroicon::OutlinedKey)
                    ->color('primary')
                    ->authorize('manageRoles')
                    ->visible(fn (): bool => Filament::auth()->user() instanceof User
                        && Filament::auth()->user()->isSuperAdministrator())
                    ->schema([
                        Select::make('role')
                            ->label(__('Role to assign'))
                            ->options(fn (User $record): array => self::assignableRoleOptions($record))
                            ->required()
                            ->native(false),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(__('Assign administrative role'))
                    ->modalDescription(fn (User $record): string => __('Grant a role to :name. Roles can expose public-content moderation or operational account controls, but never private diary content.', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('Assign selected role'))
                    ->action(function (array $data, User $record): void {
                        $actor = Filament::auth()->user();
                        abort_unless($actor instanceof User, 403);

                        $changed = app(AssignUserRole::class)->handle(
                            subject: $record,
                            roleName: self::roleFromActionData($data),
                            actor: $actor,
                            request: request(),
                        );

                        Notification::make()
                            ->title($changed ? __('Role assigned') : __('Role was already assigned'))
                            ->body($changed ? __('The change was recorded in the administrative audit trail.') : __('No account permissions changed.'))
                            ->color($changed ? 'success' : 'gray')
                            ->send();
                    }),
                Action::make('removeRole')
                    ->label(__('Remove role'))
                    ->icon(Heroicon::OutlinedKey)
                    ->color('danger')
                    ->authorize('manageRoles')
                    ->visible(fn (User $record): bool => Filament::auth()->user() instanceof User
                        && Filament::auth()->user()->isSuperAdministrator()
                        && $record->roles()->whereIn('name', collect(RoleName::cases())->pluck('value'))->exists())
                    ->schema([
                        Select::make('role')
                            ->label(__('Role to remove'))
                            ->options(fn (User $record): array => self::removableRoleOptions($record))
                            ->helperText(__('The final active super-administrator role cannot be removed.'))
                            ->required()
                            ->native(false),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(__('Remove administrative role'))
                    ->modalDescription(fn (User $record): string => __('Remove a role from :name. Their access changes immediately, and the action is recorded.', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('Remove selected role'))
                    ->action(function (array $data, User $record): void {
                        $actor = Filament::auth()->user();
                        abort_unless($actor instanceof User, 403);

                        $changed = app(RemoveUserRole::class)->handle(
                            subject: $record,
                            roleName: self::roleFromActionData($data),
                            actor: $actor,
                            request: request(),
                        );

                        Notification::make()
                            ->title($changed ? __('Role removed') : __('Role was already absent'))
                            ->body($changed ? __('The change was recorded in the administrative audit trail.') : __('No account permissions changed.'))
                            ->color($changed ? 'success' : 'gray')
                            ->send();
                    }),
                Action::make('disable')
                    ->label(__('Disable access'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('disable')
                    ->visible(fn (User $record): bool => $record->disabled_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(__('This immediately blocks both the diary and admin panels. It does not delete the account or its data.'))
                    ->action(function (User $record): void {
                        $actor = Filament::auth()->user();
                        abort_unless($actor instanceof User, 403);

                        DB::transaction(function () use ($actor, $record): void {
                            $record->disable();
                            app(AuditRecorder::class)->record(
                                event: 'admin.user.disabled',
                                actor: $actor,
                                auditable: $record,
                                metadata: ['target_user_id' => $record->getKey()],
                                request: request(),
                            );
                        });

                        Notification::make()->success()->title(__('Account disabled'))->send();
                    }),
                Action::make('enable')
                    ->label(__('Restore access'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->authorize('update')
                    ->visible(fn (User $record): bool => $record->disabled_at !== null)
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $actor = Filament::auth()->user();
                        abort_unless($actor instanceof User, 403);

                        DB::transaction(function () use ($actor, $record): void {
                            $record->forceFill(['disabled_at' => null])->save();
                            app(AuditRecorder::class)->record(
                                event: 'admin.user.enabled',
                                actor: $actor,
                                auditable: $record,
                                metadata: ['target_user_id' => $record->getKey()],
                                request: request(),
                            );
                        });

                        Notification::make()->success()->title(__('Account access restored'))->send();
                    }),
            ])
            ->emptyStateHeading(__('No accounts found'))
            ->emptyStateDescription(__('Accounts will appear here after registration.'))
            ->emptyStateIcon(Heroicon::OutlinedUsers);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
        ];
    }

    /** @return array<string, string> */
    private static function assignableRoleOptions(User $record): array
    {
        $assignedRoles = $record->roles()->pluck('name')->all();

        return collect(RoleName::cases())
            ->reject(fn (RoleName $roleName): bool => in_array($roleName->value, $assignedRoles, true))
            ->mapWithKeys(fn (RoleName $roleName): array => [$roleName->value => $roleName->label()])
            ->all();
    }

    /** @return array<string, string> */
    private static function removableRoleOptions(User $record): array
    {
        $assignedRoles = $record->roles()->pluck('name')->all();

        return collect(RoleName::cases())
            ->filter(fn (RoleName $roleName): bool => in_array($roleName->value, $assignedRoles, true))
            ->mapWithKeys(fn (RoleName $roleName): array => [$roleName->value => $roleName->label()])
            ->all();
    }

    /** @param array<string, mixed> $data */
    private static function roleFromActionData(array $data): RoleName
    {
        $roleName = RoleName::tryFrom((string) ($data['role'] ?? ''));

        if (! $roleName instanceof RoleName) {
            throw ValidationException::withMessages([
                'role' => [__('Select a valid system role.')],
            ]);
        }

        return $roleName;
    }
}
