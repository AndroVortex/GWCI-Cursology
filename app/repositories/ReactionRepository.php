<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\StoredProcedures;
use App\Models\Dto\ReactionSummary;
use App\Models\Entities\Reaction;

/** Catálogo de reacciones y reacciones a opiniones y mensajes, exclusivamente mediante SP. */
final class ReactionRepository
{
    use CallsProcedures;

    public function __construct(private readonly StoredProcedures $procedures)
    {
    }

    /** @return list<Reaction> */
    public function list(bool $onlyActive): array
    {
        return array_map(
            static fn (array $row): Reaction => Reaction::fromRow($row),
            $this->procedures->rows('sp_reaction_list', [$onlyActive]),
        );
    }

    public function create(string $adminId, string $emoji, string $name): Reaction
    {
        return Reaction::fromRow($this->requiredRow('sp_reaction_create', [$adminId, $emoji, $name]));
    }

    public function update(string $adminId, string $reactionId, string $emoji, string $name): Reaction
    {
        return Reaction::fromRow($this->requiredRow('sp_reaction_update', [$adminId, $reactionId, $emoji, $name]));
    }

    public function setActive(string $adminId, string $reactionId, bool $active): Reaction
    {
        return Reaction::fromRow($this->requiredRow('sp_reaction_set_active', [$adminId, $reactionId, $active]));
    }

    /** @param ?string $reactionId null quita la reacción */
    public function reactToOpinion(string $userId, string $opinionId, ?string $reactionId): ReactionSummary
    {
        return ReactionSummary::fromRows($this->resultSets('sp_opinion_react', [$userId, $opinionId, $reactionId])[0] ?? []);
    }

    /** @param ?string $reactionId null quita la reacción */
    public function reactToMessage(string $userId, string $messageId, ?string $reactionId): ReactionSummary
    {
        return ReactionSummary::fromRows($this->resultSets('sp_message_react', [$userId, $messageId, $reactionId])[0] ?? []);
    }
}
