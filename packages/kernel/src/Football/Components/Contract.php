<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Obligation salariale minimale d'un joueur : vit sur l'entite **joueur**,
 * pointe vers l'entite club (`$clubId`) - meme forme que `SquadMembership`.
 * Pas de `expiresOn`/`releaseClause`/`agentId` (docs/12-modele-du-monde.md
 * §6 les prevoit) : ce lot n'a ni negociation ni marche des transferts pour
 * les consommer, meme precedent de catalogue reduit que `Club` (docs/15-
 * roadmap.md §4).
 *
 * Cree par `Football\YouthIntakeSystem` (joueur promu) et
 * `Harness\Population\PopulationFactory` (joueur du genesis), retire par
 * `Football\RetirementSystem` - un joueur retraite n'a plus de contrat, donc
 * plus de salaire, meme si `SquadMembership` persiste.
 */
final readonly class Contract
{
    public function __construct(
        public int $clubId,
        public int $wagePerWeekCents,
    ) {
    }
}
