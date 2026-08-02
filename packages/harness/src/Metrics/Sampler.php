<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Pipeline\Pipeline;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Club;
use Flair\Kernel\Football\Components\Fixture;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Standings;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;
use Flair\Kernel\Football\Systems\CalendarSystem;
use Flair\Kernel\Football\Systems\CompetitionSystem;
use Flair\Kernel\Football\Systems\MatchSystem;
use Flair\Kernel\Football\Systems\PlayerDevelopmentSystem;
use Flair\Kernel\Football\Systems\RetirementSystem;
use Flair\Kernel\Football\Systems\TrainingSystem;
use Flair\Kernel\Football\Systems\YouthIntakeSystem;

/**
 * Fait tourner la simulation du noyau sur une population deja construite,
 * et releve un SkillSample par joueur actif et par categorie a chaque fin
 * d'annee simulee (pas a chaque tick - inutile pour des courbes annuelles,
 * couteux en memoire). Ne reimplemente aucune logique de vieillissement :
 * observe seulement ce que le pipeline football ecrit
 * (Flair\Kernel\Football\Systems\YouthIntakeSystem,
 * Flair\Kernel\Football\Systems\TrainingSystem,
 * Flair\Kernel\Football\Systems\RetirementSystem et
 * Flair\Kernel\Football\Systems\PlayerDevelopmentSystem).
 *
 * **La population echantillonnee n'est pas figee.** `$playerIds` n'est que
 * le point de depart : `YouthIntakeSystem` cree de nouveaux joueurs en
 * cours de run (si le monde contient des clubs - cf.
 * Population\ClubFactory/PopulationFactory, qui les cree desormais), et ce
 * Sampler les suit des leur promotion via `YouthPlayerPromoted`, symetrique
 * du suivi deja en place pour `PlayerRetired`. Sans ce suivi, les joueurs
 * promus en cours de route seraient invisibles des courbes - mesurer une
 * cohorte fermee alors que le monde n'en est plus une.
 *
 * **Calendrier/match/classement** (`CalendarSystem`/`MatchSystem`/
 * `CompetitionSystem`) rejoignent desormais le pipeline. Ils ne lisent ni
 * n'ecrivent aucun composant joueur, et leur flux RNG est isole par
 * `systemId` (`Rng::forStream`, docs/13- §4.1) - mais **ne pas en conclure
 * que les metriques joueur sont rigoureusement inchangees en toute
 * circonstance**. `CalendarSystem` cree des entites `Fixture` sur le meme
 * `EntityIdAllocator` partage que `YouthIntakeSystem` cree des joueurs : un
 * joueur promu en cours de run n'a donc pas le meme id selon que le
 * calendrier tourne ou non, et `RetirementSystem`/`PlayerDevelopmentSystem`
 * clent leur RNG par cet id (`$ctx->rng($entityId)`) - la trajectoire d'un
 * joueur promu peut reellement diverger entre les deux configurations,
 * verifie empiriquement en ecrivant `SamplerTest`. Seule la **population
 * initiale** (creee avant que le moindre systeme ne tourne, ids stables
 * quel que soit le pipeline) est garantie inchangee - c'est ce que
 * `SamplerTest::testAddingMatchSimulationDoesNotChangeTheInitialPopulationOutcomes()`
 * verifie. Sans consequence sur `Comparison\PairedSeedComparison` : baseline
 * et modifie y partagent toujours le meme pipeline, donc le meme ordre
 * d'allocation d'ids.
 *
 * Ces trois systemes n'ont d'ailleurs rien a faire si
 * `Population\PopulationFactory` n'a pas cree de `Competition`
 * (clubCount = 0) : `CalendarSystem` ne trouve alors aucune competition a
 * planifier, et aucune entite n'est creee en plus des joueurs - dans ce cas
 * precis (le seul couvert par `testNoMatchesAreSimulatedWithoutClubs`),
 * rien ne change nulle part, promus compris.
 *
 * ## Historique des saisons, capture sur `SeasonStarted`
 *
 * `Football\CompetitionSystem` remet `Standings` a vide sur `SeasonStarted`,
 * mais avec un tick de retard structurel (canal 2 : un evenement emis via
 * `ctx->emit()` n'est traite qu'au tick suivant, docs/13- §2) - au moment ou
 * ce Sampler observe `SeasonStarted` dans `$result->events`, `Standings`
 * porte donc encore integralement le classement final de la saison qui vient
 * de se terminer. `seasonHistory` capture cet instantane a **chaque**
 * `SeasonStarted` (pas seulement en fin de run) : la toute derniere saison
 * d'un run ne peut structurellement jamais y figurer, puisqu'elle "demarre"
 * toujours pile au tout dernier tick simule sans qu'aucun de ses matchs
 * n'ait eu le temps d'etre joue (`$years` annees se terminent toujours sur
 * un multiple de 365 jours, exactement le declencheur par defaut de
 * `CalendarSystem`) - une saison a zero match n'a de toute facon rien a
 * montrer.
 */
final class Sampler
{
    private const int TICKS_PER_YEAR = 365;

    /**
     * Au-dela de ce nombre de buts d'un cote, le score exact rejoint la cle
     * `'autre'` de `$scorelineFrequency` plutot que sa propre entree -
     * evite qu'un score aberrant (l'exposant de Dixon-Coles mal calibre)
     * ne fasse exploser le nombre de cles distinctes.
     */
    private const int SCORELINE_GOAL_CAP = 6;

    /** @param list<int> $playerIds */
    public function run(WorldState $world, array $playerIds, int $years, int $worldSeed, Ruleset $ruleset): AggregateResult
    {
        $simulation = new Simulation(new Pipeline([
            new YouthIntakeSystem(),
            new TrainingSystem(),
            new RetirementSystem(),
            new PlayerDevelopmentSystem(),
            new CalendarSystem(),
            new MatchSystem(),
            new CompetitionSystem(),
        ]));

        $clubNames = $this->clubNames($world);

        /** @var list<SkillSample> $samples */
        $samples = [];
        /** @var list<int> $retirementAges */
        $retirementAges = [];
        /** @var array<int, true> $retired */
        $retired = [];
        /** @var array<int, true> $known joueurs initiaux + promus, jamais purge (la soustraction de $retired se fait a la lecture) */
        $known = array_fill_keys($playerIds, true);
        /** @var array<int, int> $populationByYear annee -> effectif actif en fin d'annee */
        $populationByYear = [];
        /** @var list<int> $finalAges ages (arrondis) des actifs a la derniere annee simulee, pour l'histogramme de pyramide */
        $finalAges = [];
        /** @var list<int> $matchGoalsTotals buts totaux (domicile + exterieur) de chaque match joue */
        $matchGoalsTotals = [];
        /** @var array{homeWin: int, draw: int, awayWin: int} $matchResultDistribution */
        $matchResultDistribution = ['homeWin' => 0, 'draw' => 0, 'awayWin' => 0];
        /** @var array<string, int> $scorelineFrequency "buts_domicile-buts_exterieur" (ou 'autre') -> nombre de matchs */
        $scorelineFrequency = [];
        /** @var list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}> $currentSeasonMatches vide a chaque SeasonStarted - ne porte donc que la saison en cours */
        $currentSeasonMatches = [];
        /** @var list<array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}> $seasonHistory une entree par saison achevee (cf. docblock de classe) */
        $seasonHistory = [];
        $seasonsStarted = 0;

        for ($year = 1; $year <= $years; $year++) {
            for ($day = 1; $day <= self::TICKS_PER_YEAR; $day++) {
                $tick = ($year - 1) * self::TICKS_PER_YEAR + $day;
                $result = $simulation->step($world, new TickContext(
                    tick: $tick,
                    seed: $worldSeed,
                    intents: [],
                    ruleset: $ruleset,
                ));

                foreach ($result->events as $event) {
                    if ($event instanceof PlayerRetired && !isset($retired[$event->playerId])) {
                        $retired[$event->playerId] = true;
                        $retirementAges[] = $event->ageYears;
                    }

                    if ($event instanceof YouthPlayerPromoted) {
                        $known[$event->playerId] = true;
                    }

                    if ($event instanceof SeasonStarted) {
                        $seasonsStarted++;
                        if ($seasonsStarted > 1) {
                            $seasonHistory[] = [
                                'season' => $seasonsStarted - 1,
                                'standings' => $this->standingsSnapshot($world, $event->competitionId, $clubNames),
                                'matches' => $currentSeasonMatches,
                            ];
                        }
                        $currentSeasonMatches = [];
                    }

                    if ($event instanceof MatchPlayed) {
                        $matchGoalsTotals[] = $event->homeGoals + $event->awayGoals;
                        $matchResultDistribution = $this->tallyResult($matchResultDistribution, $event->homeGoals, $event->awayGoals);
                        $scorelineKey = $this->scorelineKey($event->homeGoals, $event->awayGoals);
                        $scorelineFrequency[$scorelineKey] = ($scorelineFrequency[$scorelineKey] ?? 0) + 1;
                        // MatchPlayed ne porte pas matchday (self-suffisant sur les identifiants
                        // de match, pas sur la position au calendrier) - lu sur Fixture, cree par
                        // CalendarSystem sur la meme entite (fixtureId).
                        $matchday = $world->components(Fixture::class)->get($event->fixtureId)?->matchday ?? 0;
                        $currentSeasonMatches[] = [
                            'matchday' => $matchday,
                            'homeClub' => $clubNames[$event->homeClubId] ?? "Club #{$event->homeClubId}",
                            'awayClub' => $clubNames[$event->awayClubId] ?? "Club #{$event->awayClubId}",
                            'homeGoals' => $event->homeGoals,
                            'awayGoals' => $event->awayGoals,
                        ];
                    }
                }
            }

            /** @var list<int> $activePlayerIds */
            $activePlayerIds = array_keys(array_diff_key($known, $retired));
            $populationByYear[$year] = \count($activePlayerIds);

            $ages = $this->sampleYearEnd($world, $activePlayerIds, $year, $samples);
            if ($year === $years) {
                $finalAges = $ages;
            }
        }

        return AggregateResult::fromSamples(
            $samples,
            $retirementAges,
            $populationByYear,
            Stats::histogram($finalAges, bucketWidth: 1),
            Stats::histogram($matchGoalsTotals, bucketWidth: 1),
            $matchResultDistribution,
            $scorelineFrequency,
            $seasonHistory,
        );
    }

    /**
     * @param list<int> $activePlayerIds joueurs actifs (retraites deja exclus par l'appelant)
     * @param list<SkillSample> $samples
     * @return list<int> ages (annees, arrondis) des joueurs effectivement echantillonnes
     */
    private function sampleYearEnd(WorldState $world, array $activePlayerIds, int $year, array &$samples): array
    {
        $now = new SimDate($year * self::TICKS_PER_YEAR);
        $ages = [];

        foreach ($activePlayerIds as $playerId) {
            $person = $world->components(Person::class)->get($playerId);
            if ($person === null) {
                continue;
            }

            $ageYears = $now->yearsSince($person->birthDate);
            $ages[] = (int) round($ageYears);

            $physical = $world->components(PlayerPhysicalSkills::class)->get($playerId);
            if ($physical !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'physical', $this->average([
                    $physical->pace, $physical->stamina, $physical->strength, $physical->reflexes,
                ]));
            }

            $technical = $world->components(PlayerTechnicalSkills::class)->get($playerId);
            if ($technical !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'technical', $this->average([
                    $technical->technique, $technical->passing, $technical->finishing,
                    $technical->defending, $technical->positioning, $technical->handling, $technical->distribution,
                ]));
            }

            $mental = $world->components(PlayerMentalSkills::class)->get($playerId);
            if ($mental !== null) {
                $samples[] = new SkillSample($playerId, $ageYears, 'mental', $this->average([
                    $mental->vision, $mental->composure, $mental->leadership, $mental->discipline, $mental->command,
                ]));
            }
        }

        return $ages;
    }

    /** @return array<int, string> clubId -> nom */
    private function clubNames(WorldState $world): array
    {
        $names = [];
        foreach ($world->components(Club::class)->entities() as $clubId) {
            $names[$clubId] = $world->components(Club::class)->get($clubId)?->name ?? "Club #{$clubId}";
        }

        return $names;
    }

    /**
     * @param array{homeWin: int, draw: int, awayWin: int} $distribution
     * @return array{homeWin: int, draw: int, awayWin: int}
     */
    private function tallyResult(array $distribution, int $homeGoals, int $awayGoals): array
    {
        if ($homeGoals > $awayGoals) {
            $distribution['homeWin']++;
        } elseif ($homeGoals < $awayGoals) {
            $distribution['awayWin']++;
        } else {
            $distribution['draw']++;
        }

        return $distribution;
    }

    private function scorelineKey(int $homeGoals, int $awayGoals): string
    {
        if ($homeGoals > self::SCORELINE_GOAL_CAP || $awayGoals > self::SCORELINE_GOAL_CAP) {
            return 'autre';
        }

        return "{$homeGoals}-{$awayGoals}";
    }

    /**
     * Instantane de `Standings` pour une competition donnee, appele depuis
     * la boucle d'evenements au moment precis ou il porte encore le
     * classement final de la saison qui vient de se terminer (cf. docblock
     * de classe - le reset par `Football\CompetitionSystem` n'arrive que
     * le tick suivant). `$competitionId` vient directement de l'evenement
     * `SeasonStarted` qui declenche l'appel, jamais requete separement.
     *
     * @param array<int, string> $clubNames
     * @return list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>
     */
    private function standingsSnapshot(WorldState $world, int $competitionId, array $clubNames): array
    {
        $standings = $world->components(Standings::class)->get($competitionId);
        if ($standings === null) {
            return [];
        }

        $rows = [];
        foreach ($standings->entries as $entry) {
            $rows[] = [
                'clubId' => $entry->clubId,
                'clubName' => $clubNames[$entry->clubId] ?? "Club #{$entry->clubId}",
                'played' => $entry->played,
                'won' => $entry->won,
                'drawn' => $entry->drawn,
                'lost' => $entry->lost,
                'goalsFor' => $entry->goalsFor,
                'goalsAgainst' => $entry->goalsAgainst,
                'points' => $entry->points,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['points'] <=> $a['points']
            ?: ($b['goalsFor'] - $b['goalsAgainst']) <=> ($a['goalsFor'] - $a['goalsAgainst']));

        return $rows;
    }

    /** @param list<int> $values */
    private function average(array $values): float
    {
        return array_sum($values) / \count($values);
    }
}
