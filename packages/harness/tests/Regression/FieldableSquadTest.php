<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Regression;

use Flair\Harness\Population\PopulationSpec;
use Flair\Harness\Simulation\StepRunner;
use Flair\Harness\Support\WorldInspector;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Position;
use Flair\Worldgen\WorldFactory;
use PHPUnit\Framework\TestCase;

/**
 * Mecanise l'hypothese sur laquelle repose la composition du onze de
 * `Football\MatchSystem` : **tout club peut aligner une equipe**.
 *
 * Le systeme de match remplit les places manquantes a une note plancher, ce qui
 * est une garantie de bonne definition et non une regle du jeu - le forfait
 * reglementaire n'est pas modelise (pas de pyramide ou releguer, pas de
 * mecanisme de defaillance de club, docs/14- §7 hors perimetre). Cette
 * degenerescence ne doit donc jamais s'observer, et si le monde se met un jour
 * a la produire il faut l'apprendre **deliberement**, par ce test, plutot que
 * de la decouvrir comme un artefact silencieux au milieu d'une mesure
 * d'equilibre.
 *
 * ## Ce qui est garanti, et ce qui ne l'est pas
 *
 * 1. **Onze joueurs au minimum, en permanence** - une vraie garantie, verifiee
 *    comme telle. Mesure : effectif minimum 14 sur vingt saisons.
 * 2. **Un gardien par club** n'est **pas** garanti, et ce test ne pretend pas
 *    le contraire. Mesure sur douze saisons : 0,49 % des club-semaines sans
 *    gardien, deux clubs sur dix-huit concernes, disette la plus longue
 *    28 semaines.
 *
 * La cause du second point est structurelle et **hors de portee du lot des
 * postes** : il n'existe aucun canal d'approvisionnement fiable pour un poste
 * donne. Un gardien part a la retraite en cours de saison, et le monde n'a
 * qu'**un seul jour administratif par an** (`ContractBalance::$renewalDayOfYear`,
 * documente comme provisoire) pour reagir - encore faut-il qu'un gardien
 * existe ce jour-la dans le vivier des joueurs sans club, ou que le centre de
 * formation en produise un, ce que sa cohorte stochastique (1,2 recrue par club
 * et par an) ne peut pas promettre. Le marche des transferts (docs/14- §5,
 * lot suivant) est ce qui fermera le trou : un club qui a besoin d'un gardien
 * en achete un.
 *
 * Le seuil est donc un **garde-fou contre l'aggravation**, pas une affirmation
 * de solution - meme esprit que les bornes larges de
 * `CalibrationRegressionTest`. S'il saute, c'est que l'approvisionnement s'est
 * degrade, et c'est ca qu'on veut apprendre.
 *
 * L'effectif est verifie **chaque annee simulee** et non seulement a la fin :
 * une composition peut se degrader puis se retablir, et c'est le creux qui
 * compte.
 */
final class FieldableSquadTest extends TestCase
{
    private const YEARS = 20;
    private const MINIMUM_SQUAD = 11;

    /**
     * Part maximale de club-annees tolerees sans gardien. Mesure : 0,49 % des
     * club-semaines ; 5 % laisse de la marge au bruit inter-graines tout en
     * attrapant une vraie degradation de l'approvisionnement.
     */
    private const MAX_KEEPERLESS_SHARE = 0.05;

    public function testEveryClubCanAlwaysFieldEleven(): void
    {
        $spec = new PopulationSpec(playerCount: 500, years: self::YEARS, seed: 42, clubCount: 18);
        $world = new WorldState();
        (new WorldFactory())->populate($world, $spec->world());
        $runner = new StepRunner($world, new Ruleset('regression'), $spec->seed);

        $clubYears = 0;
        $keeperless = 0;

        for ($year = 1; $year <= self::YEARS; $year++) {
            $runner->advance(365);

            foreach (WorldInspector::squadsByPosition($world) as $clubId => $squad) {
                self::assertGreaterThanOrEqual(
                    self::MINIMUM_SQUAD,
                    $squad['total'],
                    "Le club {$clubId} ne peut plus aligner onze joueurs a l'annee {$year}",
                );

                $clubYears++;

                if ($squad[Position::Goalkeeper->value] === 0) {
                    $keeperless++;
                }
            }
        }

        self::assertLessThanOrEqual(
            self::MAX_KEEPERLESS_SHARE,
            $keeperless / $clubYears,
            "L'approvisionnement en gardiens s'est degrade : {$keeperless} club-annees sans gardien sur {$clubYears}",
        );
    }

}
