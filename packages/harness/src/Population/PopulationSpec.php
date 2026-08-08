<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

/**
 * Parametres d'un run de harness, regroupes pour eviter que la liste de
 * parametres positionnels ne grossisse a chaque nouveau levier sur les
 * trois points d'appel qui en ont besoin (public/index.php,
 * bin/aggregate.php, Comparison\PairedSeedComparison).
 *
 * `clubCount`/`facilitiesQuality` pilotent uniquement la generation de
 * clubs synthetiques (Population\ClubFactory) - une qualite d'installations
 * uniforme sur tous les clubs, premier jet volontairement simple (pas de
 * variance entre clubs dans ce lot, cf. docblock ClubFactory).
 * `startingBalanceCents` suit le meme principe (Phase 2) : un solde initial
 * uniforme, seede par ClubFactory, pas un levier de Ruleset - c'est un
 * parametre de generation du monde, pas un levier d'equilibrage du jeu.
 *
 * `scoutJudgementMean`/`scoutJudgementSpread` sont ici pour la meme raison, et
 * elle a une consequence pratique a connaitre : le `Ruleset` dit **de combien un
 * jugement donne se trompe** (`PerceptionBalance`), le monde dit **qui sont les
 * scouts**. Comme `Comparison\PairedSeedComparison` ne rejoue jamais la
 * generation - c'est le principe des graines appariees, meme population des deux
 * cotes - un levier de generation loge dans le `Ruleset` serait silencieusement
 * inoperant sous `--set`. Faire varier la dispersion du staff demande donc deux
 * runs, pas une comparaison appariee.
 *
 * `boardPatienceMean`/`boardPatienceSpread` suivent exactement la meme regle,
 * pour la meme raison (docs/17-marche-transferts.md point 2 reouvert) :
 * `Ruleset\TransferBalance` dit comment la patience module la probabilite de
 * rupture, le monde dit quelle patience chaque club a.
 */
final readonly class PopulationSpec
{
    public function __construct(
        public int $playerCount,
        public int $years,
        public int $seed,
        public int $clubCount = 18,
        public float $facilitiesQuality = 1.0,
        public int $startingBalanceCents = 10_000_000,
        public int $scoutJudgementMean = 50,
        public int $scoutJudgementSpread = 25,
        public int $boardPatienceMean = 50,
        public int $boardPatienceSpread = 25,
    ) {
    }
}
