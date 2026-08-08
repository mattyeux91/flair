<?php

declare(strict_types=1);

namespace Flair\Harness\Population;

use Flair\Worldgen\WorldSpec;

/**
 * Parametres d'un run de harness : **un monde a engendrer, et pendant combien
 * d'annees le faire tourner**. Regroupes pour eviter que la liste de
 * parametres positionnels ne grossisse a chaque nouveau levier sur les points
 * d'appel qui en ont besoin (public/index.php, bin/aggregate.php,
 * bin/sandbox.php, Comparison\PairedSeedComparison).
 *
 * ## Pourquoi cette classe survit a l'extraction de `worldgen`
 *
 * Tous les champs sauf `years` decrivent la **forme du monde**, et vivent
 * desormais dans `Worldgen\WorldSpec` - un generateur de monde n'a que faire
 * d'une duree de simulation. Mais `years` n'a nulle part ailleurs ou aller :
 * c'est une question d'appelant, pas une propriete du monde.
 *
 * Cette classe reste donc l'assemblage des deux, et **conserve deliberement sa
 * signature a plat** plutot que d'exiger un `WorldSpec` construit a la main :
 * une vingtaine de sites de construction ecrivent
 * `new PopulationSpec(playerCount: …, years: …, seed: …)`, et les faire tous
 * basculer vers un objet imbrique aurait ete du bruit pur pour zero gain de
 * lisibilite. `world()` fait la conversion au moment ou `Worldgen\WorldFactory`
 * en a besoin.
 *
 * Le detail de la frontiere Ruleset / forme du monde - et pourquoi un levier
 * de generation loge dans le `Ruleset` serait silencieusement inoperant sous
 * `--set` - est documente sur `Worldgen\WorldSpec`, seul endroit ou il doit
 * l'etre.
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

    public function world(): WorldSpec
    {
        return new WorldSpec(
            playerCount: $this->playerCount,
            seed: $this->seed,
            clubCount: $this->clubCount,
            facilitiesQuality: $this->facilitiesQuality,
            startingBalanceCents: $this->startingBalanceCents,
            scoutJudgementMean: $this->scoutJudgementMean,
            scoutJudgementSpread: $this->scoutJudgementSpread,
            boardPatienceMean: $this->boardPatienceMean,
            boardPatienceSpread: $this->boardPatienceSpread,
        );
    }
}
