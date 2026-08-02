<?php

declare(strict_types=1);

namespace Flair\Harness\Report;

use Flair\Harness\Metrics\AggregateResult;
use Flair\Harness\Metrics\CompetitiveBalance;
use Flair\Harness\Metrics\CompetitiveBalanceResult;
use Flair\Harness\Metrics\EventGraphResult;

/**
 * Rendu console d'un AggregateResult - tables et barres ASCII, meme esprit
 * que kernel/bin/demo.php. Pas de dependance a une lib de rendu.
 */
final class TextReport
{
    private const CATEGORY_ORDER = ['physical', 'technical', 'mental'];
    private const CATEGORY_LABELS = ['physical' => 'Physique', 'technical' => 'Technique', 'mental' => 'Mental'];

    public function render(AggregateResult $result): string
    {
        $output = '';

        foreach (self::CATEGORY_ORDER as $category) {
            $output .= $this->renderCurve($category, $result);
        }

        $output .= $this->renderPopulationByYear($result->populationByYear);
        $output .= $this->renderHistogram($result->finalAgeHistogram, 'Pyramide des ages (derniere annee simulee)', 'aucun joueur actif observe');
        $output .= $this->renderHistogram($result->retirementAgeHistogram, 'Distribution des ages de retraite', 'aucune retraite observee');

        $output .= $this->renderHistogram($result->goalsPerMatchHistogram, 'Distribution des buts par match', 'aucun match observe');
        $output .= $this->renderMatchResultDistribution($result->matchResultDistribution);
        $output .= $this->renderScorelineFrequency($result->scorelineFrequency);

        $lastSeason = $this->lastSeason($result->seasonHistory);
        $seasonLabel = $lastSeason !== null ? "saison {$lastSeason['season']}" : 'derniere saison';
        $output .= $this->renderStandings($lastSeason['standings'] ?? [], "Classement ({$seasonLabel})");
        $output .= $this->renderRecentMatches($lastSeason['matches'] ?? [], "Matchs de la {$seasonLabel}");

        $output .= $this->renderCompetitiveBalance(CompetitiveBalance::analyze($result->seasonHistory, $result->cumulativeIncomeByClub));

        return $output;
    }

    public function renderComparison(AggregateResult $baseline, AggregateResult $modified): string
    {
        $output = "=== Comparaison a graines appariees (baseline vs modifie) ===\n\n";

        foreach (self::CATEGORY_ORDER as $category) {
            $output .= $this->renderCurveDelta($category, $baseline, $modified);
        }

        $output .= $this->renderPopulationByYearDelta($baseline->populationByYear, $modified->populationByYear);

        $output .= "-- Pyramide des ages, baseline --\n";
        $output .= $this->renderHistogram($baseline->finalAgeHistogram, 'Pyramide des ages (derniere annee simulee)', 'aucun joueur actif observe');
        $output .= "-- Pyramide des ages, modifie --\n";
        $output .= $this->renderHistogram($modified->finalAgeHistogram, 'Pyramide des ages (derniere annee simulee)', 'aucun joueur actif observe');

        $output .= "-- Ages de retraite (baseline) --\n";
        $output .= $this->renderHistogram($baseline->retirementAgeHistogram, 'Distribution des ages de retraite', 'aucune retraite observee');
        $output .= "-- Ages de retraite (modifie) --\n";
        $output .= $this->renderHistogram($modified->retirementAgeHistogram, 'Distribution des ages de retraite', 'aucune retraite observee');

        $output .= $this->renderGoalsPerMatchDelta($baseline->goalsPerMatchHistogram, $modified->goalsPerMatchHistogram);
        $output .= $this->renderMatchResultDistributionDelta($baseline->matchResultDistribution, $modified->matchResultDistribution);

        $baselineSeason = $this->lastSeason($baseline->seasonHistory);
        $modifiedSeason = $this->lastSeason($modified->seasonHistory);
        $output .= $this->renderStandings($baselineSeason['standings'] ?? [], 'Classement, baseline' . ($baselineSeason !== null ? " (saison {$baselineSeason['season']})" : ''));
        $output .= $this->renderStandings($modifiedSeason['standings'] ?? [], 'Classement, modifie' . ($modifiedSeason !== null ? " (saison {$modifiedSeason['season']})" : ''));
        $output .= $this->renderRecentMatches($baselineSeason['matches'] ?? [], 'Matchs recents, baseline' . ($baselineSeason !== null ? " (saison {$baselineSeason['season']})" : ''));
        $output .= $this->renderRecentMatches($modifiedSeason['matches'] ?? [], 'Matchs recents, modifie' . ($modifiedSeason !== null ? " (saison {$modifiedSeason['season']})" : ''));

        $output .= "-- Equilibre competitif, baseline --\n";
        $output .= $this->renderCompetitiveBalance(CompetitiveBalance::analyze($baseline->seasonHistory, $baseline->cumulativeIncomeByClub));
        $output .= "-- Equilibre competitif, modifie --\n";
        $output .= $this->renderCompetitiveBalance(CompetitiveBalance::analyze($modified->seasonHistory, $modified->cumulativeIncomeByClub));

        return $output;
    }

    private function renderCurve(string $category, AggregateResult $result): string
    {
        $label = self::CATEGORY_LABELS[$category] ?? $category;
        $curve = $result->curves[$category] ?? [];

        $output = "-- {$label} (valeur moyenne des attributs, par age) --\n";
        $output .= sprintf("%5s  %6s  %6s  %6s  %6s  %5s\n", 'age', 'p10', 'p50', 'moy', 'p90', 'n');

        foreach ($curve as $age => $stats) {
            $output .= sprintf(
                "%5d  %6.1f  %6.1f  %6.1f  %6.1f  %5d\n",
                $age,
                $stats['p10'],
                $stats['p50'],
                $stats['mean'],
                $stats['p90'],
                $stats['count'],
            );
        }

        return $output . "\n";
    }

    /**
     * Diffe la moyenne transversale des deux runs. La comparaison portait
     * avant sur une "courbe corrigee" par methode delta, retiree parce
     * qu'elle produisait des niveaux impossibles des que la population a
     * cesse d'etre une cohorte fermee (cf. docblock AggregateResult).
     *
     * L'appariement des graines fait le gros du travail que la correction
     * pretendait faire : baseline et modifie partagent la meme population de
     * depart et les memes flux RNG, donc a chaque age les deux colonnes
     * portent sur des populations comparables et le `delta` isole l'effet du
     * parametre teste. `n` est affiche pour que les ages faiblement peuples
     * (au-dela de ~34 ans) se lisent comme du bruit, pas comme un signal.
     */
    private function renderCurveDelta(string $category, AggregateResult $baseline, AggregateResult $modified): string
    {
        $label = self::CATEGORY_LABELS[$category] ?? $category;
        $baselineCurve = $baseline->curves[$category] ?? [];
        $modifiedCurve = $modified->curves[$category] ?? [];
        $ages = array_unique([...array_keys($baselineCurve), ...array_keys($modifiedCurve)]);
        sort($ages);

        $output = "-- {$label} : delta de niveau moyen (modifie - baseline), par age --\n";
        $output .= sprintf("%5s  %8s  %8s  %8s  %7s  %7s\n", 'age', 'baseline', 'modifie', 'delta', 'n(base)', 'n(mod)');

        foreach ($ages as $age) {
            $baselineStats = $baselineCurve[$age] ?? null;
            $modifiedStats = $modifiedCurve[$age] ?? null;
            if ($baselineStats === null || $modifiedStats === null) {
                continue;
            }

            $output .= sprintf(
                "%5d  %8.1f  %8.1f  %+8.1f  %7d  %7d\n",
                $age,
                $baselineStats['mean'],
                $modifiedStats['mean'],
                $modifiedStats['mean'] - $baselineStats['mean'],
                $baselineStats['count'],
                $modifiedStats['count'],
            );
        }

        return $output . "\n";
    }

    /** @param array<int, int> $histogram age -> effectif */
    private function renderHistogram(array $histogram, string $title, string $emptyMessage): string
    {
        if ($histogram === []) {
            return "{$title} : {$emptyMessage}.\n\n";
        }

        $maxCount = max($histogram);
        $output = "{$title} :\n";

        foreach ($histogram as $age => $count) {
            $barLength = $maxCount > 0 ? (int) round(($count / $maxCount) * 40) : 0;
            $output .= sprintf("%3d ans | %-40s %d\n", $age, str_repeat('#', $barLength), $count);
        }

        return $output . "\n";
    }

    /** @param array<int, int> $populationByYear annee -> effectif actif */
    private function renderPopulationByYear(array $populationByYear): string
    {
        if ($populationByYear === []) {
            return "Effectif actif par annee : aucune donnee.\n\n";
        }

        $output = "-- Effectif actif par annee --\n";
        $output .= sprintf("%6s  %6s\n", 'annee', 'effectif');

        foreach ($populationByYear as $year => $count) {
            $output .= sprintf("%6d  %6d\n", $year, $count);
        }

        return $output . "\n";
    }

    /**
     * @param array<int, int> $baseline annee -> effectif actif
     * @param array<int, int> $modified annee -> effectif actif
     */
    private function renderPopulationByYearDelta(array $baseline, array $modified): string
    {
        $years = array_unique([...array_keys($baseline), ...array_keys($modified)]);
        sort($years);

        $output = "-- Effectif actif par annee : baseline vs modifie --\n";
        $output .= sprintf("%6s  %8s  %8s  %8s\n", 'annee', 'baseline', 'modifie', 'delta');

        foreach ($years as $year) {
            $baselineCount = $baseline[$year] ?? null;
            $modifiedCount = $modified[$year] ?? null;
            if ($baselineCount === null || $modifiedCount === null) {
                continue;
            }

            $output .= sprintf("%6d  %8d  %8d  %+8d\n", $year, $baselineCount, $modifiedCount, $modifiedCount - $baselineCount);
        }

        return $output . "\n";
    }

    /** @param array{homeWin: int, draw: int, awayWin: int} $distribution */
    private function renderMatchResultDistribution(array $distribution): string
    {
        $total = $distribution['homeWin'] + $distribution['draw'] + $distribution['awayWin'];
        if ($total === 0) {
            return "Repartition des resultats : aucun match observe.\n\n";
        }

        $output = "-- Repartition des resultats --\n";
        foreach (['homeWin' => 'Domicile', 'draw' => 'Nul', 'awayWin' => 'Exterieur'] as $key => $label) {
            $count = $distribution[$key];
            $output .= sprintf("%-10s  %6d  %5.1f%%\n", $label, $count, $count / $total * 100);
        }

        return $output . "\n";
    }

    /**
     * @param array{homeWin: int, draw: int, awayWin: int} $baseline
     * @param array{homeWin: int, draw: int, awayWin: int} $modified
     */
    private function renderMatchResultDistributionDelta(array $baseline, array $modified): string
    {
        $baselineTotal = $baseline['homeWin'] + $baseline['draw'] + $baseline['awayWin'];
        $modifiedTotal = $modified['homeWin'] + $modified['draw'] + $modified['awayWin'];
        if ($baselineTotal === 0 || $modifiedTotal === 0) {
            return "Repartition des resultats : donnees insuffisantes.\n\n";
        }

        $output = "-- Repartition des resultats : baseline vs modifie --\n";
        $output .= sprintf("%-10s  %8s  %8s  %8s\n", '', 'baseline', 'modifie', 'delta');

        foreach (['homeWin' => 'Domicile', 'draw' => 'Nul', 'awayWin' => 'Exterieur'] as $key => $label) {
            $baselinePct = $baseline[$key] / $baselineTotal * 100;
            $modifiedPct = $modified[$key] / $modifiedTotal * 100;
            $output .= sprintf("%-10s  %7.1f%%  %7.1f%%  %+7.1f%%\n", $label, $baselinePct, $modifiedPct, $modifiedPct - $baselinePct);
        }

        return $output . "\n";
    }

    /** @param array<string, int> $histogram "buts_domicile-buts_exterieur" (ou 'autre') -> nombre de matchs */
    private function renderScorelineFrequency(array $histogram): string
    {
        if ($histogram === []) {
            return "Scores exacts : aucun match observe.\n\n";
        }

        $frequency = $histogram;
        arsort($frequency);

        $output = "-- Scores exacts (tries par frequence) --\n";
        foreach ($frequency as $score => $count) {
            $output .= sprintf("%-8s  %6d\n", $score, $count);
        }

        return $output . "\n";
    }

    /**
     * @param list<array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}> $seasonHistory
     * @return array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}|null
     */
    private function lastSeason(array $seasonHistory): ?array
    {
        return $seasonHistory === [] ? null : $seasonHistory[array_key_last($seasonHistory)];
    }

    /**
     * @param list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}> $standings deja trie par Sampler
     */
    private function renderStandings(array $standings, string $title = 'Classement (derniere saison)'): string
    {
        if ($standings === []) {
            return "{$title} : aucune donnee.\n\n";
        }

        $output = "-- {$title} --\n";
        $output .= sprintf("%-24s  %3s  %3s  %3s  %3s  %5s  %5s  %4s\n", 'club', 'J', 'G', 'N', 'P', 'BP', 'BC', 'Pts');

        foreach ($standings as $row) {
            $output .= sprintf(
                "%-24s  %3d  %3d  %3d  %3d  %5d  %5d  %4d\n",
                $row['clubName'],
                $row['played'],
                $row['won'],
                $row['drawn'],
                $row['lost'],
                $row['goalsFor'],
                $row['goalsAgainst'],
                $row['points'],
            );
        }

        return $output . "\n";
    }

    private function renderCompetitiveBalance(CompetitiveBalanceResult $balance): string
    {
        if ($balance->seasonsMeasured === 0) {
            return "Equilibre competitif : aucune saison achevee observee.\n\n";
        }

        $output = "-- Equilibre competitif ({$balance->seasonsMeasured} saison(s) mesuree(s)) --\n";
        $output .= sprintf("%-24s  %6s\n", 'club', 'titres');
        $titlesByClub = $balance->titlesByClub;
        arsort($titlesByClub);
        foreach ($titlesByClub as $clubName => $titles) {
            $output .= sprintf("%-24s  %6d\n", $clubName, $titles);
        }

        $output .= sprintf("Champions distincts : %d\n", $balance->distinctChampions);
        $output .= sprintf("Gini des titres : %.3f (0 = egalite parfaite, 1 = monopole)\n", $balance->giniOfTitles);
        $output .= sprintf("Gini des revenus : %.3f (0 = tous le meme revenu, 1 = un club encaisse tout)\n", $balance->giniOfRevenues);
        $output .= $balance->topFiveTurnoverRate !== null
            ? sprintf("Rotation du top 5 : %.1f%%\n", $balance->topFiveTurnoverRate * 100)
            : "Rotation du top 5 : donnees insuffisantes (moins de 2 saisons)\n";

        return $output . "\n";
    }

    /**
     * @param list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}> $matches
     */
    private function renderRecentMatches(array $matches, string $title = 'Matchs de la derniere saison'): string
    {
        if ($matches === []) {
            return "{$title} : aucune donnee.\n\n";
        }

        $output = "-- {$title} --\n";
        foreach ($matches as $match) {
            $output .= sprintf(
                "J%-3d  %-20s %d - %d %s\n",
                $match['matchday'],
                $match['homeClub'],
                $match['homeGoals'],
                $match['awayGoals'],
                $match['awayClub'],
            );
        }

        return $output . "\n";
    }

    /**
     * Rendu opt-in (docs/16- §6) - appele explicitement par bin/aggregate.php
     * quand --event-graph est fourni, jamais depuis render()/renderComparison()
     * puisque AggregateResult ne porte pas cette donnee (EventGraphCollector
     * est un collecteur separe, pas branche par defaut dans Sampler::run()).
     */
    public function renderEventGraph(EventGraphResult $eventGraph): string
    {
        $output = "-- Graphe d'evenements (volume par type, tout le run) --\n";
        if ($eventGraph->totalEvents === 0) {
            return $output . "aucun evenement observe.\n\n";
        }

        $volumeByType = $eventGraph->volumeByType;
        arsort($volumeByType);
        foreach ($volumeByType as $type => $count) {
            $output .= sprintf("%-60s  %8d\n", $type, $count);
        }
        $output .= sprintf("%-60s  %8d\n", 'total', $eventGraph->totalEvents);
        $output .= "\n";

        $output .= "-- Backlog du Scheduler par annee --\n";
        $output .= sprintf("%6s  %8s\n", 'annee', 'backlog');
        foreach ($eventGraph->schedulerBacklogByYear as $entry) {
            $output .= sprintf("%6d  %8d\n", $entry['year'], $entry['schedulerBacklog']);
        }

        return $output . "\n";
    }

    /**
     * @param array<int, int> $baselineHistogram buts totaux -> nombre de matchs
     * @param array<int, int> $modifiedHistogram buts totaux -> nombre de matchs
     */
    private function renderGoalsPerMatchDelta(array $baselineHistogram, array $modifiedHistogram): string
    {
        $baselineMean = $this->weightedMean($baselineHistogram);
        $modifiedMean = $this->weightedMean($modifiedHistogram);
        if ($baselineMean === null || $modifiedMean === null) {
            return "Buts par match (moyenne) : donnees insuffisantes.\n\n";
        }

        $output = "-- Buts par match (moyenne) : baseline vs modifie --\n";
        $output .= sprintf("baseline=%.2f  modifie=%.2f  delta=%+.2f\n", $baselineMean, $modifiedMean, $modifiedMean - $baselineMean);

        return $output . "\n";
    }

    /** @param array<int, int> $histogram valeur -> effectif */
    private function weightedMean(array $histogram): ?float
    {
        $total = array_sum($histogram);
        if ($total === 0) {
            return null;
        }

        $weighted = 0;
        foreach ($histogram as $value => $count) {
            $weighted += $value * $count;
        }

        return $weighted / $total;
    }
}
