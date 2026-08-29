<?php

namespace App\Filament\Admin\Resources;

use App\Enums\CommentStatus;
use App\Filament\Admin\Resources\CommentResource\Pages;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Public content';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Public comments';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->hasPermissionTo('comments.moderate');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('body')
                    ->label(__('Comment'))
                    ->limit(110)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('publication.title')
                    ->label(__('Public story'))
                    ->limit(45)
                    ->searchable(),
                TextColumn::make('author.name')
                    ->label(__('Author'))
                    ->placeholder(__('Deleted account')),
                TextColumn::make('status')
                    ->formatStateUsing(fn (CommentStatus|string $state): string => $state instanceof CommentStatus ? $state->label() : Str::headline($state))
                    ->badge()
                    ->color(fn (CommentStatus|string $state): string => match ($state instanceof CommentStatus ? $state : CommentStatus::tryFrom($state)) {
                        CommentStatus::Approved => 'success',
                        CommentStatus::Pending => 'warning',
                        CommentStatus::Rejected, CommentStatus::Spam => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Received'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                self::moderationAction('approve', CommentStatus::Approved, __('Approve'), 'success', Heroicon::OutlinedCheckCircle),
                self::moderationAction('reject', CommentStatus::Rejected, __('Reject'), 'danger', Heroicon::OutlinedXCircle),
                self::moderationAction('spam', CommentStatus::Spam, __('Mark as spam'), 'gray', Heroicon::OutlinedShieldExclamation),
            ])
            ->emptyStateHeading(__('No public comments to moderate'))
            ->emptyStateDescription(__('Comments appear only for explicitly public stories.'))
            ->emptyStateIcon(Heroicon::OutlinedChatBubbleLeftRight);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('publication_id', Publication::query()->websitePublished()->select('id'))
            ->with(['publication:id,title,status,published_at', 'author:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComments::route('/'),
        ];
    }

    private static function moderationAction(
        string $name,
        CommentStatus $status,
        string $label,
        string $color,
        Heroicon $icon,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->authorize('moderate')
            ->visible(fn (Comment $record): bool => $record->status !== $status)
            ->requiresConfirmation()
            ->action(function (Comment $record) use ($status): void {
                $user = Filament::auth()->user();
                abort_unless($user instanceof User, 403);

                DB::transaction(function () use ($record, $status, $user): void {
                    $record->forceFill([
                        'status' => $status,
                        'moderated_by_user_id' => $user->getKey(),
                        'moderated_at' => now(),
                    ])->save();
                    app(AuditRecorder::class)->record(
                        event: 'admin.comment.moderated',
                        actor: $user,
                        auditable: $record,
                        metadata: [
                            'publication_id' => $record->publication_id,
                            'status' => $status->value,
                        ],
                        request: request(),
                    );
                });

                Notification::make()->success()->title(__('Comment moderation updated'))->send();
            });
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(CommentStatus::cases())
            ->mapWithKeys(fn (CommentStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
