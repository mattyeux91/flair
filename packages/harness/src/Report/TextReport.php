<?php

declare(strict_types=1);

namespace Flair\Harness\Report;

use Flair\Harness\Metrics\AggregateResult;

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
            $output .= $this->renderChainedCurve($category, $result);
        }

        $output .= $this->renderPopulationByYear($result->populationByYear);
        $output .= $this->renderHistogram($result->finalAgeHistogram, 'Pyramide des ages (derniere annee simulee)', 'aucun joueur actif observe');
        $output .= $this->renderHistogram($result->retirementAgeHistogram, 'Distribution des ages de retraite', 'aucune retraite observee');

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
     * Courbe corrigee (methode delta) : niveau reconstruit par chainage des
     * deltas individuels moyens, immunise contre le biais de survie qui
     * affecte renderCurve() aux ages avances (cf. docblock DeltaCurveBuilder).
     * S'arrete naturellement ou les transitions manquent.
     */
    private function renderChainedCurve(string $category, AggregateResult $result): string
    {
        $label = self::CATEGORY_LABELS[$category] ?? $category;
        $chained = $result->chainedCurves[$category] ?? [];
        $deltas = $result->deltaCurves[$category] ?? [];

        $output = "-- {$label} (courbe corrigee, methode delta) --\n";
        $output .= sprintf("%5s  %8s  %5s\n", 'age', 'niveau', 'n');

        foreach ($chained as $age => $value) {
            $count = $deltas[$age]['count'] ?? null;
            $output .= sprintf("%5d  %8.1f  %5s\n", $age, $value, $count !== null ? (string) $count : '-');
        }

        return $output . "\n";
    }

    /**
     * Diffe les courbes corrigees (chainedCurves), pas la moyenne brute :
     * comparer les moyennes brutes melangerait l'effet du parametre teste
     * avec un eventuel changement de qui survit jusqu'a quel age.
     */
    private function renderCurveDelta(string $category, AggregateResult $baseline, AggregateResult $modified): string
    {
        $label = self::CATEGORY_LABELS[$category] ?? $category;
        $baselineChained = $baseline->chainedCurves[$category] ?? [];
        $modifiedChained = $modified->chainedCurves[$category] ?? [];
        $ages = array_unique([...array_keys($baselineChained), ...array_keys($modifiedChained)]);
        sort($ages);

        $output = "-- {$label} : delta de niveau corrige (modifie - baseline), par age --\n";
        $output .= sprintf("%5s  %8s  %8s  %8s\n", 'age', 'baseline', 'modifie', 'delta');

        foreach ($ages as $age) {
            $baselineValue = $baselineChained[$age] ?? null;
            $modifiedValue = $modifiedChained[$age] ?? null;
            if ($baselineValue === null || $modifiedValue === null) {
                continue;
            }

            $output .= sprintf("%5d  %8.1f  %8.1f  %+8.1f\n", $age, $baselineValue, $modifiedValue, $modifiedValue - $baselineValue);
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
}
