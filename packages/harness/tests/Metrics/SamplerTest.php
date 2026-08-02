<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Metrics;

use Flair\Harness\Metrics\Sampler;
use Flair\Harness\Population\PopulationFactory;
use Flair\Harness\Population\PopulationSpec;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;
use PHPUnit\Framework\TestCase;

final class SamplerTest extends TestCase
{
    /**
     * Sans clubs, YouthIntakeSystem n'a personne a promouvoir (cf. docblock
     * ClubFactory) : la population ne peut que decliner par retraite. Avec
     * des clubs, elle doit croitre au-dela de ce plancher - la preuve que
     * Sampler suit bien les promotions en cours de run (cf. son docblock),
     * pas seulement que YouthIntakeSystem tourne quelque part dans le
     * kernel.
     */
    public function testPopulationGrowsThroughYouthIntakeWhenClubsExist(): void
    {
        $ruleset = new Ruleset('test');
        $years = 15;

        $withClubs = $this->finalPopulation(new PopulationSpec(playerCount: 40, years: $years, seed: 99, clubCount: 6), $ruleset);
        $withoutClubs = $this->finalPopulation(new PopulationSpec(playerCount: 40, years: $years, seed: 99, clubCount: 0), $ruleset);

        self::assertGreaterThan($withoutClubs, $withClubs);
    }

    public function testFinalAgeHistogramCountsMatchTheFinalYearPopulation(): void
    {
        $ruleset = new Ruleset('test');
        $spec = new PopulationSpec(playerCount: 30, years: 10, seed: 7, clubCount: 4);

        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);

        self::assertSame($result->populationByYear[$spec->years], array_sum($result->finalAgeHistogram));
    }

    public function testMatchesArePlayedAndTrackedWhenClubsExist(): void
    {
        $ruleset = new Ruleset('test');
        $spec = new PopulationSpec(playerCount: 200, years: 5, seed: 42, clubCount: 8);

        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);

        self::assertNotSame([], $result->goalsPerMatchHistogram);
        self::assertGreaterThan(
            0,
            $result->matchResultDistribution['homeWin'] + $result->matchResultDistribution['draw'] + $result->matchResultDistribution['awayWin'],
        );
        self::assertNotSame([], $result->scorelineFrequency);
        self::assertNotSame([], $result->seasonHistory);
        self::assertNotSame([], $result->seasonHistory[0]['standings']);
        self::assertNotSame([], $result->seasonHistory[0]['matches']);
    }

    /**
     * Invariant desormais propre (cf. docblock de Sampler) : une saison est
     * capturee a chaque `SeasonStarted` sauf le tout premier (rien a
     * capturer avant la premiere saison jouee) - sur un run de `$years`
     * annees, il y a exactement `$years` occurrences de `SeasonStarted`
     * (une par annee, `seasonStartDayOfYear` par defaut a 0), donc
     * `$years - 1` saisons dans l'historique. La derniere saison "demarree"
     * ne joue structurellement jamais aucun match (cf. docblock de classe)
     * et n'y figure donc jamais.
     */
    public function testSeasonHistoryHasOneEntryPerCompletedSeason(): void
    {
        $ruleset = new Ruleset('test');
        $years = 8;
        $spec = new PopulationSpec(playerCount: 200, years: $years, seed: 42, clubCount: 6);

        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);

        self::assertCount($years - 1, $result->seasonHistory);
        self::assertSame(range(1, $years - 1), array_column($result->seasonHistory, 'season'));
    }

    public function testNoMatchesAreSimulatedWithoutClubs(): void
    {
        $ruleset = new Ruleset('test');
        $spec = new PopulationSpec(playerCount: 50, years: 5, seed: 42, clubCount: 0);

        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);

        self::assertSame([], $result->goalsPerMatchHistogram);
        self::assertSame(['homeWin' => 0, 'draw' => 0, 'awayWin' => 0], $result->matchResultDistribution);
        self::assertSame([], $result->scorelineFrequency);
        self::assertSame([], $result->seasonHistory);
    }

    /**
     * Garde-fou architectural, mais plus etroit que "rien ne change" :
     * `CalendarSystem` cree des entites `Fixture` sur le meme
     * `EntityIdAllocator` partage que `YouthIntakeSystem` cree des joueurs.
     * Un joueur **promu en cours de run** n'a donc pas le meme id selon que
     * le calendrier tourne ou non - or `RetirementSystem`/
     * `PlayerDevelopmentSystem` clent leur RNG par l'id du joueur
     * (`$ctx->rng($entityId)`), pas par un attribut stable. Consequence
     * verifiee empiriquement (ce test a d'abord echoue en le decouvrant) :
     * la trajectoire d'un joueur promu peut reellement diverger entre les
     * deux pipelines, pas juste changer d'etiquette.
     *
     * Ce qui reste rigoureusement garanti, et ce que ce test verifie : la
     * **population initiale** (creee par `PopulationFactory` avant que le
     * moindre systeme ne tourne, donc avec des ids stables quel que soit le
     * pipeline) n'est affectee par aucune valeur ni RNG lie a
     * Calendar/Match/CompetitionSystem, qui ne lisent ni n'ecrivent aucun
     * composant joueur.
     *
     * Sans consequence sur `Comparison\PairedSeedComparison` : baseline et
     * modifie y partagent toujours exactement le meme pipeline (7
     * systemes), donc le meme ordre d'allocation d'ids d'un bout a l'autre -
     * ce garde-fou ne visait que la migration "avant/apres ce lot".
     */
    public function testAddingMatchSimulationDoesNotChangeTheInitialPopulationOutcomes(): void
    {
        $spec = new PopulationSpec(playerCount: 60, years: 12, seed: 2026, clubCount: 8);
        $ruleset = new Ruleset('test');

        $withoutMatches = $this->initialPopulationSkillSignature($spec, $ruleset, includeMatchSystems: false);
        $withMatches = $this->initialPopulationSkillSignature($spec, $ruleset, includeMatchSystems: true);

        self::assertNotSame('', $withoutMatches);
        self::assertSame($withoutMatches, $withMatches);
    }

    private function finalPopulation(PopulationSpec $spec, Ruleset $ruleset): int
    {
        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);
        $result = (new Sampler())->run($world, $playerIds, $spec->years, $spec->seed, $ruleset);

        return $result->populationByYear[$spec->years] ?? 0;
    }

    private function initialPopulationSkillSignature(PopulationSpec $spec, Ruleset $ruleset, bool $includeMatchSystems): string
    {
        $world = new WorldState();
        $playerIds = (new PopulationFactory())->populate($world, $spec);

        $systems = [
            new YouthIntakeSystem(),
            new TrainingSystem(),
            new RetirementSystem(),
            new PlayerDevelopmentSystem(),
        ];
        if ($includeMatchSystems) {
            $systems[] = new CalendarSystem();
            $systems[] = new MatchSystem();
            $systems[] = new CompetitionSystem();
        }

        $simulation = new Simulation(new Pipeline($systems));
        for ($tick = 1; $tick <= $spec->years * 365; $tick++) {
            $simulation->step($world, new TickContext(tick: $tick, seed: $spec->seed, intents: [], ruleset: $ruleset));
        }

        // Uniquement $playerIds (la population initiale, ids stables quel
        // que soit le pipeline) - jamais les joueurs promus en cours de run,
        // dont l'id (et donc le flux RNG de developpement/retraite) depend
        // de l'ordre d'allocation, lui-meme different des que Calendar/Match/
        // CompetitionSystem rejoignent le pipeline (cf. docblock du test).
        $parts = [];
        foreach ($playerIds as $playerId) {
            $technical = $world->components(PlayerTechnicalSkills::class)->get($playerId);
            $physical = $world->components(PlayerPhysicalSkills::class)->get($playerId);
            $mental = $world->components(PlayerMentalSkills::class)->get($playerId);
            $parts[] = sprintf(
                '%d:%d:%d:%d',
                $playerId,
                $technical?->technique ?? -1,
                $physical?->pace ?? -1,
                $mental?->vision ?? -1,
            );
        }

        return implode('|', $parts);
    }
}
