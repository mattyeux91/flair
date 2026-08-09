<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Le recruteur qu'un club emploie - premier role non-joueur du monde (lot
 * perception, 2026-08-05).
 *
 * `$judgement` est ce qui determine a quel point ce club se trompe sur les
 * joueurs qu'il evalue (`PerceptionModel::sigma()`). Un club **sans**
 * recruteur n'est pas omniscient : c'est le pire observateur du monde. D'ou le
 * `null` de `ClubSheetView::$scout`, qui n'est pas une absence d'information
 * mais une information en soi.
 */
final readonly class ScoutView
{
    public function __construct(
        public int $id,
        public string $name,
        /** 1-100. Le monde de reference le tire entre 27 et 74. */
        public int $judgement,
    ) {
    }
}
