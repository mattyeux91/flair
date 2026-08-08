<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * L'emploi d'une personne **non-joueur** par un club : vit sur l'entite
 * personne, pointe vers l'entite club - meme forme que `SquadMembership` et
 * `Contract`, et pour la meme raison (aucune coherence bidirectionnelle a
 * maintenir, docs/12-modele-du-monde.md §1).
 *
 * **Un type distinct de `SquadMembership`, jamais une reutilisation** (docs/12-
 * §4, question 2 tranchee) : un scout n'est pas un membre d'effectif et ne doit
 * apparaitre dans aucun des parcours qui iterent l'effectif
 * (`Football\TrainingSystem`, `Football\MatchSystem`,
 * `Harness\Tests\Regression\SquadIntegrityTest`). Deux composants distincts
 * rendent cette separation structurelle plutot que vigilante.
 *
 * Le role, lui, n'est pas ici : c'est la **presence d'un composant de role**
 * (`Scout`) qui dit ce que la personne fait pour ce club - ECS strict, aucun
 * sous-type (docs/12- §1). Une meme personne pourra un jour porter
 * `Employment` + un autre role sans qu'aucun de ces fichiers change.
 *
 * Pas de salaire ni de date d'embauche : rien ne les consomme. Le staff est
 * hors du grand livre monetaire pour l'instant - l'ajouter demanderait de
 * rouvrir `MonetaryConservationTest`, et aucun mecanisme n'embauche ni ne
 * licencie encore (gouvernance de club, hors Phase 2).
 *
 * Seme au genesis (`Harness\Population\StaffFactory`), jamais ecrit par un
 * systeme - meme precedent que `Facilities`/`Finances`.
 */
final readonly class Employment
{
    public function __construct(public int $clubId)
    {
    }
}
