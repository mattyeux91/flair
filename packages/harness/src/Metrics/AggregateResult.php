<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Sortie agregee d'une simulation : courbes de competence par categorie
 * (bucketees par age entier, avec bande de percentiles) et distribution des
 * ages de retraite. Independant du format de sortie - Report/TextReport et
 * Report/JsonSerializer rendent tous les deux la meme structure.
 *
 * `curves` est une coupe transversale brute - biaisee par survie aux ages
 * avances (cf. DeltaCurveBuilder). `deltaCurves`/`chainedCurves` sont la
 * correction : deltas individuels d'annee en annee, chaines depuis une
 * ancre jeune. Les deux vues sont gardees, pas une seule - `curves` reste
 * utile aux ages jeunes bien peuples et pour montrer visuellement le biais.
 */
final readonly class AggregateResult
{
    /**
     * @param array<string, array<int, array{mean: float, p10: float, p50: float, p90: float, count: int}>> $curves
     *   categorie -> age -> statistiques, trie par age croissant
     * @param array<string, array<int, array{meanDelta: float, count: int}>> $deltaCurves
     *   categorie -> age de depart de la transition -> delta annuel moyen
     * @param array<string, array<int, float>> $chainedCurves
     *   categorie -> age -> niveau reconstruit par chainage des deltas moyens
     * @param array<int, int> $retirementAgeHistogram age -> effectif, trie par age croissant
     */
    public function __construct(
        public array $curves,
        public array $deltaCurves,
        public array $chainedCurves,
        public array $retirementAgeHistogram,
    ) {
    }

    /**
     * @param list<SkillSample> $samples
     * @param list<int> $retirementAges
     */
    public static function fromSamples(array $samples, array $retirementAges): self
    {
        $byCategoryThenAge = [];
        foreach ($samples as $sample) {
            $age = (int) round($sample->ageYears);
            $byCategoryThenAge[$sample->category][$age][] = $sample->value;
        }

        $curves = [];
        foreach ($byCategoryThenAge as $category => $byAge) {
            ksort($byAge);
            foreach ($byAge as $age => $values) {
                $curves[$category][$age] = [
                    'mean' => Stats::mean($values),
                    'p10' => Stats::percentile($values, 10.0),
                    'p50' => Stats::percentile($values, 50.0),
                    'p90' => Stats::percentile($values, 90.0),
                    'count' => \count($values),
                ];
            }
        }

        $delta = DeltaCurveBuilder::build($samples);

        return new self(
            $curves,
            $delta['deltaCurves'],
            $delta['chainedCurves'],
            Stats::histogram($retirementAges, bucketWidth: 1),
        );
    }
}
