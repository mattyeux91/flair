<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Solde monetaire d'un club, en centimes (docs/14-algorithmes.md §6). Seul
 * writer `Football\FinanceSystem`. Seede au genesis par
 * `Harness\Population\ClubFactory`, jamais cree par un `System` du noyau -
 * meme relation que `Facilities` avec `TrainingSystem` (etat externe, lu et
 * ici mute par le pipeline plutot que produit par lui).
 *
 * Un solde negatif est possible et n'est pas en soi un bug de ce lot :
 * l'invariant verifie la conservation globale de la masse monetaire, pas la
 * solvabilite par club (hors perimetre, cf. `docs/15-roadmap.md` §4 Phase 2).
 */
final readonly class Finances
{
    public function __construct(
        public int $balanceCents,
    ) {
    }
}
