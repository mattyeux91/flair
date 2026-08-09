<?php

declare(strict_types=1);

namespace Flair\Api\Read\View;

/**
 * Un joueur tel qu'une fiche de club le montre.
 *
 * ## Ce DTO est le contrat, et il ne bougera pas quand la perception arrivera
 *
 * `$quality` est aujourd'hui la **verite** : la note au meilleur poste rendue
 * par `Football\Support\WageModel::quality()`, un entier sur 1-100. Le jour ou
 * un client de jeu lira ces fiches, la meme case portera une **estimation**,
 * rendue par `Football\Support\PerceptionModel::estimate()` - qui rend un
 * `int` sur exactement la meme echelle (verifie, pas suppose). Le DTO ne
 * change donc pas de forme : seul le remplisseur change, dans
 * `Read\ClubSheetReader::qualityOf()`.
 *
 * C'est aussi pourquoi il n'y a **pas** de champ « attributs detailles » ici.
 * Exposer `PlayerPhysicalSkills`/`Technical`/`Mental` tels quels donnerait un
 * DTO dont la moitie des champs n'auraient aucun equivalent percu, et il
 * faudrait alors deux formes au lieu d'une (docs/12- §4).
 */
final readonly class SquadPlayerView
{
    public function __construct(
        public int $id,
        public string $name,
        /** Age en annees simulees, arrondi au dixieme - le noyau ne connait que des jours (docs/13- §1). */
        public float $age,
        /** Poste **derive** des competences du moment, jamais stocke (`PositionModel::bestPosition()`). */
        public string $position,
        /** Archetype de developpement, lui **fixe a la naissance** (`PlayerPotentials::$archetype`). */
        public string $archetype,
        /** Note au meilleur poste, 1-100. Verite aujourd'hui, estimation demain - voir le docblock de classe. */
        public int $quality,
        /** Plafond de la composition, pas de chaque competence (lot des postes, 2026-08-04). */
        public int $ceiling,
        public int $wagePerWeekCents,
        /** Echeance de contrat en jours simules, comparable a `LoadedWorld::$tick`. */
        public int $contractExpiresOnDay,
    ) {
    }
}
