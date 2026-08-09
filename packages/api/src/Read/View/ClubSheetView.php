<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Un club, a l'instant du dernier snapshot.
 *
 * L'effectif est groupe par poste **derive** (`PositionModel::bestPosition()`),
 * dans l'ordre GK / DEF / MID / ATT, et trie par note decroissante a
 * l'interieur de chaque groupe : c'est la lecture qu'on veut d'une fiche, et
 * c'est aussi celle qui rend visible d'un coup d'oeil le trou d'effectif a un
 * poste - le mecanisme dont tout le marche des transferts depend (docs/14- §5).
 */
final readonly class ClubSheetView
{
    /**
     * @param array<string, list<SquadPlayerView>> $squadByPosition clefs = valeurs de `Position`
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $balanceCents,
        /** Qualite des installations, 0,5 a 2,0 (`Facilities`). */
        public float $facilitiesQuality,
        public int $seasonIncomeCents,
        /** Patience du conseil du club vendeur, lue par `TransferSystem`. */
        public int $boardPatience,
        public ?ScoutView $scout,
        public ?StandingsRowView $standing,
        public array $squadByPosition,
        public int $squadSize,
        public int $wageBillPerWeekCents,
    ) {
    }
}
