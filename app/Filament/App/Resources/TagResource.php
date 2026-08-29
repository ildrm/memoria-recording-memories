<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\TagResource\Pages;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TagResource extends OwnedResource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Organize';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Tag details'))
                    ->description(__('Tags help you find related memories. Tags are never visible publicly unless you add them to a publication.'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('Japan, firsts, gratitude…'))
                            ->required()
                            ->maxLength(80),
                        ColorPicker::make('color')
                            ->label(__('Color')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')
                    ->label(__('Tag'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entries_count')
                    ->label(__('Memories'))
                    ->counts('entries')
                    ->badge()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Last changed'))
                    ->since()
                    ->dateTimeTooltip()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading(__('No tags yet'))
            ->emptyStateDescription(__('Add a tag while writing, or create one here.'))
            ->emptyStateIcon(Heroicon::OutlinedTag);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}
