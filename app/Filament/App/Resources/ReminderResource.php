<?php

namespace App\Filament\App\Resources;

use App\Enums\ReminderFrequency;
use App\Filament\App\Resources\ReminderResource\Pages;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserPreference;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class ReminderResource extends OwnedResource
{
    protected static ?string $model = Reminder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Writing reminder'))
                    ->description(__('Choose a gentle prompt. Missing a reminder never affects your diary.'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('Evening reflection'))
                            ->required()
                            ->maxLength(255),
                        Select::make('frequency')
                            ->label(__('Frequency'))
                            ->options(self::frequencyOptions())
                            ->default(ReminderFrequency::Daily->value)
                            ->live()
                            ->native(false)
                            ->required(),
                        TimePicker::make('local_time')
                            ->label(__('Local time'))
                            ->timezone(fn (Get $get): string => self::selectedTimezone($get('timezone')))
                            ->seconds(false)
                            ->required(),
                        Select::make('day_of_week')
                            ->label(__('Day of week'))
                            ->options([
                                1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'),
                                5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday'),
                            ])
                            ->required(fn (Get $get): bool => $get('frequency') === ReminderFrequency::Weekly->value)
                            ->visible(fn (Get $get): bool => $get('frequency') === ReminderFrequency::Weekly->value)
                            ->native(false),
                        TextInput::make('day_of_month')
                            ->label(__('Day of month'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn (Get $get): bool => $get('frequency') === ReminderFrequency::Monthly->value)
                            ->visible(fn (Get $get): bool => $get('frequency') === ReminderFrequency::Monthly->value),
                        TextInput::make('interval_days')
                            ->label(__('Repeat every'))
                            ->suffix(__('days'))
                            ->helperText(__('The first reminder uses the next selected local time; later reminders repeat after this many local calendar days.'))
                            ->numeric()
                            ->minValue(2)
                            ->maxValue(365)
                            ->required(fn (Get $get): bool => $get('frequency') === ReminderFrequency::Custom->value)
                            ->visible(fn (Get $get): bool => $get('frequency') === ReminderFrequency::Custom->value),
                        Select::make('timezone')
                            ->label(__('Timezone'))
                            ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->default(fn (): string => self::defaultTimezone())
                            ->required(),
                        CheckboxList::make('channels')
                            ->label(__('Send by'))
                            ->options([
                                'mail' => __('Email'),
                                'database' => __('In-app notification'),
                            ])
                            ->default(['database'])
                            ->required(),
                        Toggle::make('is_enabled')
                            ->label(__('Reminder enabled'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Reminder'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('frequency')
                    ->label(__('Frequency'))
                    ->formatStateUsing(fn (ReminderFrequency|string $state): string => $state instanceof ReminderFrequency ? $state->label() : Str::headline($state))
                    ->badge(),
                TextColumn::make('local_time')
                    ->label(__('Time')),
                TextColumn::make('timezone')
                    ->label(__('Timezone'))
                    ->toggleable(),
                IconColumn::make('is_enabled')
                    ->label(__('Enabled'))
                    ->boolean(),
                TextColumn::make('next_run_at')
                    ->label(__('Next reminder'))
                    ->dateTime()
                    ->placeholder(__('Calculated after save')),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No writing reminders'))
            ->emptyStateDescription(__('Create a gentle prompt if a regular writing rhythm would help.'))
            ->emptyStateIcon(Heroicon::OutlinedBell);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReminders::route('/'),
            'create' => Pages\CreateReminder::route('/create'),
            'edit' => Pages\EditReminder::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function frequencyOptions(): array
    {
        return collect(ReminderFrequency::cases())
            ->mapWithKeys(fn (ReminderFrequency $frequency): array => [$frequency->value => $frequency->label()])
            ->all();
    }

    private static function defaultTimezone(): string
    {
        $user = Filament::auth()->user();
        if ($user instanceof User) {
            $timezone = UserPreference::query()
                ->whereBelongsTo($user)
                ->value('timezone');
            if (is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                return $timezone;
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    private static function selectedTimezone(mixed $timezone): string
    {
        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : self::defaultTimezone();
    }
}
