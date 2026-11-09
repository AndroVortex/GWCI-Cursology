<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Dto\ReactionSummary;
use App\Models\Entities\Reaction;

/** JSON de las reacciones: el resumen de un elemento, el catálogo del selector y el del admin. */
final class ReactionPresenter
{
    /** @return array{items: list<array{id: string, emoji: string, name: string, total: int, mine: bool}>, mine: ?string} */
    public static function summary(ReactionSummary $summary): array
    {
        return [
            'items' => array_map(static fn (array $count): array => [
                'id' => $count['reactionId'],
                'emoji' => $count['emoji'],
                'name' => $count['name'],
                'total' => $count['total'],
                'mine' => $count['reactionId'] === $summary->mine,
            ], $summary->counts),
            'mine' => $summary->mine,
        ];
    }

    /**
     * Las reacciones activas para el selector.
     *
     * @param list<Reaction> $reactions
     * @return list<array{id: string, emoji: string, name: string}>
     */
    public static function catalog(array $reactions): array
    {
        return array_map(static fn (Reaction $reaction): array => [
            'id' => $reaction->id(),
            'emoji' => $reaction->emoji(),
            'name' => $reaction->reactionName(),
        ], $reactions);
    }

    /** @return array<string, mixed> */
    public static function admin(Reaction $reaction): array
    {
        return [
            'id' => $reaction->id(),
            'emoji' => $reaction->emoji(),
            'name' => $reaction->reactionName(),
            'active' => $reaction->active(),
            'updatedAt' => $reaction->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * El catálogo activo para incrustarlo en la página (lo lee reactions.js). Escapa <, >, & y
     * comillas para que el JSON no pueda cerrar la etiqueta <script>.
     *
     * @param list<Reaction> $reactions
     */
    public static function catalogJson(array $reactions): string
    {
        return json_encode(self::catalog($reactions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
