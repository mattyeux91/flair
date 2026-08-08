<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * La patience du conseil d'administration d'un club dans une négociation de
 * transfert (`Football\TransferSystem`, docs/17-marche-transferts.md point 2
 * réouvert) : un club plus ou moins prompt à rompre plutôt qu'à continuer de
 * négocier, sur la même échelle absolue 1-100 que `Scout::$judgement` et
 * `PlayerPotentials::$ceiling` (docs/12- §5).
 *
 * Un composant à part, jamais un champ de `Football\Components\Club` (dont le
 * docblock exclut déjà explicitement `BoardExpectations` de son périmètre) :
 * `Club` a quatorze sites d'appel qui n'ont rien à voir avec le marché des
 * transferts, `TransferSystem` seul lit celui-ci.
 *
 * Semé au genesis (`Harness\Population\ClubFactory::disperseBoardPatience()`),
 * jamais écrit par un système - même précédent que `Scout`/`Finances`/
 * `Facilities`. Un club sans ce composant est lu comme parfaitement neutre
 * (`Ruleset\TransferBalance::$patienceReference`), pas comme impatient par
 * défaut - contrairement à `PerceptionBalance::$unstaffedJudgement`, aucune
 * affirmation de football ne justifie qu'un conseil non renseigné soit
 * mauvais plutôt que quelconque.
 */
final readonly class BoardPatience
{
    public function __construct(
        public int $level,
    ) {
    }
}
