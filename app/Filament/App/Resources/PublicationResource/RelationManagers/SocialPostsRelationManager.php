<?php

namespace App\Filament\App\Resources\PublicationResource\RelationManagers;

use App\Filament\App\Resources\SocialPostResource;
use App\Models\Publication;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SocialPostsRelationManager extends RelationManager
{
    protected static string $relationship = 'socialPosts';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Social delivery';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedPaperAirplane;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Publication
            && $ownerRecord->isOwnedBy($user)
            && $user->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return SocialPostResource::table($table)
            ->heading(__('Social delivery for this public version'))
            ->description(__('Website visibility and each exact external account have independent states. A published website does not mean every social delivery succeeded.'));
    }
}
