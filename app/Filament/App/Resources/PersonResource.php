<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PersonResource\Pages;
use App\Models\Person;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PersonResource extends OwnedResource
{
    protected static ?string $model = Person::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Organize';

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute = 'display_name';

    protected static ?string $navigationLabel = 'People';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Person details'))
                    ->description(__('People are private labels for your own memories. Notes are visible only to you.'))
                    ->schema([
                        TextInput::make('display_name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('nickname')
                            ->label(__('Nickname'))
                            ->maxLength(255),
                        TextInput::make('relationship')
                            ->label(__('Relationship'))
                            ->placeholder(__('Friend, parent, colleague…'))
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->label(__('Private notes'))
                            ->rows(4)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('Person'))
                    ->description(fn (Person $record): ?string => $record->nickname ? __('Also :nickname', ['nickname' => $record->nickname]) : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('relationship')
                    ->label(__('Relationship'))
                    ->placeholder(__('Not specified'))
                    ->searchable(),
                TextColumn::make('entries_count')
                    ->label(__('Memories together'))
                    ->counts('entries')
                    ->badge()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Last changed'))
                    ->since()
                    ->dateTimeTooltip()
                    ->toggleable(),
            ])
            ->defaultSort('display_name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading(__('No people yet'))
            ->emptyStateDescription(__('Add the people who appear in your memories.'))
            ->emptyStateIcon(Heroicon::OutlinedUserGroup);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPeople::route('/'),
            'create' => Pages\CreatePerson::route('/create'),
            'edit' => Pages\EditPerson::route('/{record}/edit'),
        ];
    }
}
