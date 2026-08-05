<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Football\Systems;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\MatchBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\MatchResult;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\SquadMembership;
use Flair\Kernel\Football\Events\FixtureKickoff;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Systems\MatchSystem;
use PHPUnit\Framework\TestCase;

final class MatchSystemTest extends TestCase
{
    private const WORLD_SEED = 20260802;
    private const COMPETITION_ID = 999;

    public function testAFixtureKickoffProducesAConsistentMatchResultAndFact(): void
    {
        $world = new WorldState();
        $home = $this->addClubWithSkill($world, 50);
        $away = $this->addClubWithSkill($world, 50);
        $fixture = $this->scheduleKickoff($world, $home, $away, matchday: 0, atTick: 10);

        $this->runTick($world, tick: 10, balance: new MatchBalance());

        $result = $world->components(MatchResult::class)->get($fixture);
        self::assertNotNull($result);
        self::assertSame(self::COMPETITION_ID, $result->competitionId);
        self::assertSame($home, $result->homeClubId);
        self::assertSame($away, $result->awayClubId);
        self::assertGreaterThanOrEqual(0, $result->homeGoals);
        self::assertGreaterThanOrEqual(0, $result->awayGoals);

        $events = $world->outQueue()->pending();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(MatchPlayed::class, $event);
        self::assertSame($result->homeGoals, $event->homeGoals);
        self::assertSame($result->awayGoals, $event->awayGoals);
    }

    public function testIsDeterministicForAGivenSeed(): void
    {
        $signature = function (): string {
            $world = new WorldState();
            $home = $this->addClubWithSkill($world, 60);
            $away = $this->addClubWithSkill($world, 45);
            $fixture = $this->scheduleKickoff($world, $home, $away, matchday: 0, atTick: 10);

            $this->runTick($world, tick: 10, balance: new MatchBalance());

            $result = $world->components(MatchResult::class)->get($fixture);
            self::assertNotNull($result);

            return "{$result->homeGoals}:{$result->awayGoals}";
        };

        self::assertSame($signature(), $signature());
    }

    public function testAStrongerSquadScoresMoreOnAverage(): void
    {
        $world = new WorldState();
        $strong = $this->addClubWithSkill($world, 80);
        $weak = $this->addClubWithSkill($world, 30);

        $strongGoals = 0;
        $weakGoals = 0;
        $matches = 300;

        for ($matchday = 0; $matchday < $matches; $matchday++) {
            $fixture = $this->scheduleKickoff($world, $strong, $weak, $matchday, atTick: 10);
            $this->runTick($world, tick: 10, balance: new MatchBalance(homeAdvantage: 0.0));

            $result = $world->components(MatchResult::class)->get($fixture);
            self::assertNotNull($result);
            $strongGoals += $result->homeGoals;
            $weakGoals += $result->awayGoals;
        }

        self::assertGreaterThan($weakGoals, $strongGoals);
    }

    public function testAClubWithoutAnySquadReceivesANeutralRating(): void
    {
        $world = new WorldState();
        $home = $world->createEntity();
        $world->components(Club::class)->set($home, new Club('Sans effectif'));
        $away = $this->addClubWithSkill($world, 50);
        $fixture = $this->scheduleKickoff($world, $home, $away, matchday: 0, atTick: 10);

        $this->runTick($world, tick: 10, balance: new MatchBalance());

        self::assertNotNull($world->components(MatchResult::class)->get($fixture));
    }

    /**
     * **Le bug corrige par ce lot, en test.** Ajouter un douzieme joueur faible
     * a un effectif de onze ne change plus rien : il ne prend aucune place. Sous
     * l'ancienne moyenne d'effectif il aurait **degrade** la note du club, donc
     * donne une valeur marginale negative a tout joueur de rotation - une
     * incitation inversee sous le futur marche des transferts.
     */
    public function testExtraPlayersBeyondTheElevenChangeNothing(): void
    {
        // La fixture est creee **avant** les joueurs supplementaires : son
        // `EntityId` alimente le flux RNG du match (`SystemContext::rng()`), donc
        // le decaler ferait varier le score pour une raison sans rapport avec ce
        // qu'on mesure.
        $score = function (int $extraPlayers): string {
            $world = new WorldState();
            $home = $this->addClubWithSkill($world, 70);
            $away = $this->addClubWithSkill($world, 55);
            $fixture = $this->scheduleKickoff($world, $home, $away, matchday: 0, atTick: 10);

            for ($i = 0; $i < $extraPlayers; $i++) {
                $this->addPlayer($world, $home, 5);
            }

            $this->runTick($world, tick: 10, balance: new MatchBalance());
            $result = $world->components(MatchResult::class)->get($fixture);
            self::assertNotNull($result);

            return "{$result->homeGoals}:{$result->awayGoals}";
        };

        // Un douzieme joueur faible : sous l'ancienne moyenne d'effectif il
        // aurait degrade la note du club.
        self::assertSame($score(0), $score(1));

        // Onze de plus : le garde-fou contre la composition de **deux** onze
        // distincts, qui rendrait un gros effectif mecaniquement meilleur.
        self::assertSame($score(0), $score(11));
    }

    /**
     * Le corollaire : la place vide, elle, coute. Un effectif de dix joueurs
     * laisse une place au plancher de l'echelle, donc le onzieme joueur vaut
     * strictement quelque chose - c'est ce qui rend la valeur marginale
     * correctement signee sur tout l'intervalle 1..11.
     */
    public function testTheEleventhPlayerIsWorthSomething(): void
    {
        $world = new WorldState();
        $full = $this->addClubWithSkill($world, 70, squadSize: 11);
        $short = $this->addClubWithSkill($world, 70, squadSize: 10);

        $homeGoals = 0;
        $awayGoals = 0;

        for ($matchday = 0; $matchday < 200; $matchday++) {
            $fixture = $this->scheduleKickoff($world, $full, $short, $matchday, atTick: 10);
            $this->runTick($world, tick: 10, balance: new MatchBalance(homeAdvantage: 0.0));
            $result = $world->components(MatchResult::class)->get($fixture);
            self::assertNotNull($result);
            $homeGoals += $result->homeGoals;
            $awayGoals += $result->awayGoals;
        }

        self::assertGreaterThan($awayGoals, $homeGoals);
    }

    /**
     * Un excellent gardien ameliore la note defensive de son club - impossible
     * avant ce lot, ou `reflexes` etait compte comme competence defensive de
     * **tous** les joueurs de champ et ou `handling`/`command`/`distribution`
     * n'etaient lus par personne.
     */
    public function testAnExcellentGoalkeeperImprovesTheDefence(): void
    {
        $world = new WorldState();
        $withKeeper = $this->addClubWithSkill($world, 50, squadSize: 10);
        $this->addGoalkeeper($world, $withKeeper, 95);
        $withoutKeeper = $this->addClubWithSkill($world, 50, squadSize: 11);

        $concededWith = 0;
        $concededWithout = 0;

        for ($matchday = 0; $matchday < 200; $matchday++) {
            $a = $this->scheduleKickoff($world, $withKeeper, $withoutKeeper, $matchday, atTick: 10);
            $this->runTick($world, tick: 10, balance: new MatchBalance(homeAdvantage: 0.0));
            $result = $world->components(MatchResult::class)->get($a);
            self::assertNotNull($result);
            $concededWith += $result->awayGoals;
            $concededWithout += $result->homeGoals;
        }

        self::assertLessThan($concededWithout, $concededWith);
    }

    /** Un gardien specialise : fort sur son profil, faible partout ailleurs. */
    private function addGoalkeeper(WorldState $world, int $club, int $skill): int
    {
        $player = $world->createEntity();
        $world->components(SquadMembership::class)->set($player, new SquadMembership($club));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills(10, 50, 10, $skill));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills(10, 10, 10, 10, $skill, $skill, $skill));
        $world->components(PlayerMentalSkills::class)->set($player, new PlayerMentalSkills(10, $skill, 50, 50, $skill));

        return $player;
    }

    /**
     * Un club dote d'un effectif **complet** (onze joueurs par defaut), tous a
     * la meme competence uniforme.
     *
     * Onze et non un seul : depuis que le systeme compose un onze par poste, un
     * club a un joueur voit ce joueur occuper la place de gardien - qui ne pese
     * rien en attaque. Un tel club ne marque jamais, ce qui ne teste plus rien.
     */
    private function addClubWithSkill(WorldState $world, int $skill, int $squadSize = 11): int
    {
        $club = $world->createEntity();
        $world->components(Club::class)->set($club, new Club("Club {$skill}"));

        for ($i = 0; $i < $squadSize; $i++) {
            $this->addPlayer($world, $club, $skill);
        }

        return $club;
    }

    /** Un joueur uniforme : il note exactement `$skill` a chacun des quatre postes. */
    private function addPlayer(WorldState $world, int $club, int $skill): int
    {
        $player = $world->createEntity();
        $world->components(SquadMembership::class)->set($player, new SquadMembership($club));
        $world->components(PlayerPhysicalSkills::class)->set($player, new PlayerPhysicalSkills($skill, $skill, $skill, $skill));
        $world->components(PlayerTechnicalSkills::class)->set($player, new PlayerTechnicalSkills($skill, $skill, $skill, $skill, $skill, $skill, $skill));
        $world->components(PlayerMentalSkills::class)->set($player, new PlayerMentalSkills($skill, $skill, $skill, $skill, $skill));

        return $player;
    }

    private function scheduleKickoff(WorldState $world, int $home, int $away, int $matchday, int $atTick): int
    {
        $fixture = $world->createEntity();
        $world->scheduler()->schedule(
            new FixtureKickoff($fixture, self::COMPETITION_ID, $home, $away, $matchday),
            atTick: $atTick,
            systemIndex: 0,
            entityId: $fixture,
            seq: 0,
        );

        return $fixture;
    }

    private function runTick(WorldState $world, int $tick, MatchBalance $balance): void
    {
        $pipeline = new Pipeline([new MatchSystem()]);
        $ruleset = new Ruleset('test', balance: new Balance(match: $balance));

        $pipeline->tick($world, $tick, self::WORLD_SEED, $ruleset, []);
    }
}
