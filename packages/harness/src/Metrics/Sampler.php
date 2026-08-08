<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

use Flair\Kernel\Football\FootballPipeline;
use Flair\Harness\Support\WorldInspector;
use Flair\Kernel\Core\Ecs\WorldState;
use Flair\Kernel\Core\Ruleset\Ruleset;
use Flair\Kernel\Core\Simulation\Simulation;
use Flair\Kernel\Core\Simulation\TickContext;
use Flair\Kernel\Core\Support\SimDate;
use Flair\Kernel\Football\Components\Contract;
use Flair\Kernel\Football\Components\Employment;
use Flair\Kernel\Football\Components\Facilities;
use Flair\Kernel\Football\Components\Fixture;
use Flair\Kernel\Football\Components\Person;
use Flair\Kernel\Football\Components\PlayerMentalSkills;
use Flair\Kernel\Football\Components\PlayerPhysicalSkills;
use Flair\Kernel\Football\Components\PlayerTechnicalSkills;
use Flair\Kernel\Football\Components\Scout;
use Flair\Kernel\Football\Components\SeasonIncome;
use Flair\Kernel\Football\Events\ContractSigned;
use Flair\Kernel\Football\Events\MatchPlayed;
use Flair\Kernel\Football\Events\PlayerRetired;
use Flair\Kernel\Football\Events\SeasonStarted;
use Flair\Kernel\Football\Events\YouthPlayerPromoted;

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
 * Worldgen\ClubFactory/WorldFactory, qui les cree desormais), et ce
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
 * `Worldgen\WorldFactory` n'a pas cree de `Competition`
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

    /** Meme convention que `Football\ContractSystem` : un contrat se signe a la semaine, se raisonne a l'annee. */
    private const int WEEKS_PER_YEAR = 52;

    /**
     * Au-dela de ce nombre de buts d'un cote, le score exact rejoint la cle
     * `'autre'` de `$scorelineFrequency` plutot que sa propre entree -
     * evite qu'un score aberrant (l'exposant de Dixon-Coles mal calibre)
     * ne fasse exploser le nombre de cles distinctes.
     */
    private const int SCORELINE_GOAL_CAP = 6;

    /** @param list<int> $playerIds */
    public function run(WorldState $world, array $playerIds, int $years, int $worldSeed, Ruleset $ruleset, ?EventGraphCollector $eventGraph = null): AggregateResult
    {
        $simulation = new Simulation(FootballPipeline::build());

        $clubNames = WorldInspector::clubNames($world);

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
        /** @var array<string, int> $cumulativeIncomeByClub nom de club -> total des revenus de saison percus sur le run */
        $cumulativeIncomeByClub = [];
        /** @var array<int, int> $transfersByYear annee -> nombre de joueurs ayant change de club */
        $transfersByYear = [];
        /** @var array<int, array{transfers: int, unattached: int, wageBillCents: int}> $marketByYear */
        $marketByYear = [];

        for ($year = 1; $year <= $years; $year++) {
            for ($day = 1; $day <= self::TICKS_PER_YEAR; $day++) {
                $tick = ($year - 1) * self::TICKS_PER_YEAR + $day;
                $result = $simulation->step($world, new TickContext(
                    tick: $tick,
                    seed: $worldSeed,
                    intents: [],
                    ruleset: $ruleset,
                ));

                $eventGraph?->tally($result->events);

                foreach ($result->events as $event) {
                    if ($event instanceof PlayerRetired && !isset($retired[$event->playerId])) {
                        $retired[$event->playerId] = true;
                        $retirementAges[] = $event->ageYears;
                    }

                    if ($event instanceof YouthPlayerPromoted) {
                        $known[$event->playerId] = true;
                    }

                    if ($event instanceof ContractSigned && $event->previousClubId !== $event->clubId) {
                        $transfersByYear[$year] = ($transfersByYear[$year] ?? 0) + 1;
                    }

                    if ($event instanceof SeasonStarted) {
                        $seasonsStarted++;
                        if ($seasonsStarted > 1) {
                            $seasonHistory[] = [
                                'season' => $seasonsStarted - 1,
                                'standings' => WorldInspector::standingsSnapshot($world, $event->competitionId, $clubNames),
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
                        $matchday = $world->components(Fixture::class)->get($event->fixtureId)->matchday ?? 0;
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
            $eventGraph?->recordQueueDepth($year, $world);
            $cumulativeIncomeByClub = $this->accumulateSeasonIncome($world, $clubNames, $cumulativeIncomeByClub);
            $marketByYear[$year] = $this->marketSnapshot($world, $transfersByYear[$year] ?? 0);

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
            $cumulativeIncomeByClub,
            $this->facilitiesSnapshot($world, $clubNames),
            $marketByYear,
            $this->wageBillSnapshot($world, $clubNames),
            $this->scoutJudgementSnapshot($world, $clubNames),
        );
    }

    /**
     * Le jugement du recruteur de chaque club. Seme au genesis et jamais ecrit
     * par un systeme, donc constant sur tout le run : un instantane final dit
     * tout, et c'est la seule grandeur du rapport qui soit une **cause** plutot
     * qu'un resultat.
     *
     * Rendu a cote du classement final, ou il se lit contre lui a l'oeil nu : le
     * club au mauvais recruteur doit finir plus bas. Aucune correlation n'est
     * calculee ici - la mesurer proprement (correlation de rang) appartient au
     * lot du marche des transferts, ou "payer cher achete-t-il de la
     * performance" sera la question centrale.
     *
     * @param array<int, string> $clubNames
     * @return array<string, int>
     */
    private function scoutJudgementSnapshot(WorldState $world, array $clubNames): array
    {
        $scouts = $world->components(Scout::class);
        $byClub = [];

        foreach ($scouts->entities() as $personId) {
            $employment = $world->components(Employment::class)->get($personId);
            $judgement = $scouts->get($personId)?->judgement;

            if ($employment === null || $judgement === null) {
                continue;
            }

            $name = $clubNames[$employment->clubId] ?? "Club #{$employment->clubId}";
            $byClub[$name] = max($byClub[$name] ?? 0, $judgement);
        }

        ksort($byClub);

        return $byClub;
    }

    /**
     * L'etat du marche du travail en fin d'annee simulee : combien de joueurs
     * ont change de club, combien n'en ont aucun, et ce que le monde s'est
     * engage a payer.
     *
     * Les trois chiffres qui disent si `Football\ContractSystem` fait ce
     * qu'il pretend. Le chomage est le **carburant** du marche tant qu'aucune
     * indemnite de transfert n'existe (docs/14- §5 hors perimetre Phase 2) :
     * sans joueurs libres, aucun club n'a de quoi se renforcer autrement
     * qu'en prolongeant les siens, et le monde se fige. Un chomage nul serait
     * donc un signal de panne, pas de bonne sante - et un chomage qui derive
     * a la hausse d'annee en annee, le signal inverse.
     *
     * La masse salariale est **annuelle et engagee**, pas versee : la somme
     * de ce que les contrats en cours couteront sur douze mois. C'est la
     * grandeur qui se compare a `SeasonIncome` et donc au budget de
     * `ContractBalance::$wageBudgetShare`.
     *
     * @return array{transfers: int, unattached: int, wageBillCents: int}
     */
    private function marketSnapshot(WorldState $world, int $transfers): array
    {
        $contracts = $world->components(Contract::class);
        $contracted = 0;
        $wageBillCents = 0;

        foreach ($contracts->entities() as $playerId) {
            $contract = $contracts->get($playerId);

            if ($contract === null) {
                continue;
            }

            $contracted++;
            $wageBillCents += $contract->wagePerWeekCents * self::WEEKS_PER_YEAR;
        }

        // Un joueur actif est une entite qui porte encore des competences :
        // Football\RetirementSystem les retire a la retraite, donc un retraite
        // n'est jamais compte comme chomeur.
        $active = \count($world->components(PlayerPhysicalSkills::class)->entities());

        return [
            'transfers' => $transfers,
            'unattached' => max(0, $active - $contracted),
            'wageBillCents' => $wageBillCents,
        ];
    }

    /**
     * Masse salariale annuelle engagee par chaque club en fin de run.
     *
     * Meme forme et meme raison d'etre que `facilitiesSnapshot()` : un
     * **stock** en fin de course, pas un flux a cumuler. C'est l'observable
     * de l'indexation du salaire sur la qualite - a salaire forfaitaire, ces
     * chiffres ne pourraient differer que par la taille des effectifs.
     *
     * @param array<int, string> $clubNames
     * @return array<string, int>
     */
    private function wageBillSnapshot(WorldState $world, array $clubNames): array
    {
        $contracts = $world->components(Contract::class);
        $byClub = [];

        foreach ($contracts->entities() as $playerId) {
            $contract = $contracts->get($playerId);

            if ($contract === null) {
                continue;
            }

            $name = $clubNames[$contract->clubId] ?? "Club #{$contract->clubId}";
            $byClub[$name] = ($byClub[$name] ?? 0) + $contract->wagePerWeekCents * self::WEEKS_PER_YEAR;
        }

        ksort($byClub);

        return $byClub;
    }

    /**
     * Qualite d'installations de chaque club en fin de run - l'observable
     * propre de la boucle "revenus -> installations -> joueurs -> resultats"
     * (docs/14- §7). Un instantane final suffit : contrairement aux revenus,
     * la qualite est un **stock** qui integre deja tout l'historique du club,
     * il n'y a rien a cumuler.
     *
     * @param array<int, string> $clubNames
     * @return array<string, float>
     */
    private function facilitiesSnapshot(WorldState $world, array $clubNames): array
    {
        $store = $world->components(Facilities::class);
        $byClub = [];

        foreach ($store->entities() as $clubId) {
            $byClub[$clubNames[$clubId] ?? "Club #{$clubId}"] = $store->get($clubId)->quality ?? 0.0;
        }

        ksort($byClub);

        return $byClub;
    }

    /**
     * Cumule le `SeasonIncome` de chaque club, une fois par annee simulee.
     *
     * Une lecture annuelle suffit a n'en manquer aucun et a n'en compter
     * aucun deux fois : `Football\CalendarSystem` ne demarre qu'une saison
     * par annee (`tick % 365 === seasonStartDayOfYear`), donc le composant ne
     * prend qu'une valeur par annee. Pas de suivi evenementiel ici :
     * `SeasonConcluded` est observable dans `$result->events`, mais le credit
     * correspondant n'est ecrit qu'au tick **suivant** (canal 2, docs/13-
     * §2) - le lire au moment de l'evenement donnerait la saison d'avant.
     *
     * Le cumul, plutot que la derniere saison seule : c'est la mesure du
     * "Gini des revenus" de docs/14- §7, et un instantane d'une seule saison
     * serait bien plus bruite. Cumuler impose en revanche de lire un
     * **flux** (`SeasonIncome`) et non le stock `Finances`, qui melange les
     * revenus aux salaires verses et derive vers le negatif.
     *
     * @param array<int, string> $clubNames
     * @param array<string, int> $cumulative
     * @return array<string, int>
     */
    private function accumulateSeasonIncome(WorldState $world, array $clubNames, array $cumulative): array
    {
        $store = $world->components(SeasonIncome::class);

        foreach ($store->entities() as $clubId) {
            $name = $clubNames[$clubId] ?? "Club #{$clubId}";
            $cumulative[$name] = ($cumulative[$name] ?? 0) + ($store->get($clubId)->cents ?? 0);
        }

        return $cumulative;
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

    /** @param list<int> $values */
    private function average(array $values): float
    {
        return array_sum($values) / \count($values);
    }
}
