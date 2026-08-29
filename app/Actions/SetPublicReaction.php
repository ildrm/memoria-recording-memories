<?php

namespace App\Actions;

use App\Enums\ReactionType;
use App\Models\Publication;
use App\Models\Reaction;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicPublicationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SetPublicReaction
{
    public function __construct(
        private readonly PublicPublicationGuard $publicationGuard,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(
        Publication $publication,
        User $actor,
        ReactionType $type,
        ?Request $request = null,
    ): Reaction {
        return DB::transaction(function () use ($publication, $actor, $type, $request): Reaction {
            $publication = $this->publicationGuard->resolve($publication, forUpdate: true);
            Gate::forUser($actor)->authorize('create', [Reaction::class, $publication]);

            $reaction = Reaction::query()
                ->whereBelongsTo($publication)
                ->whereBelongsTo($actor, 'owner')
                ->where('type', $type)
                ->first();

            if ($reaction !== null) {
                return $reaction;
            }

            $reaction = new Reaction;
            $reaction->forceFill([
                'publication_id' => $publication->getKey(),
                'user_id' => $actor->getKey(),
                'type' => $type,
            ]);
            $reaction->save();

            $this->auditRecorder->record(
                event: 'public_reaction.created',
                actor: $actor,
                auditable: $reaction,
                metadata: [
                    'publication_id' => $publication->getKey(),
                    'reaction_type' => $type->value,
                ],
                request: $request,
            );

            return $reaction;
        });
    }
}
