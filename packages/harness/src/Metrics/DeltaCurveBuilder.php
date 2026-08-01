<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Corrige le biais de survie des courbes agregees par coupe transversale
 * (AggregateResult::$curves) : la retraite retire du pool en priorite les
 * joueurs a forte fragilite, qui sont aussi ceux qui declinent le plus vite
 * (meme levier - PlayerPotentials::$fragility, pondere separement par
 * RetirementBalance et PlayerDevelopmentBalance). A un age avance,
 * la moyenne brute finit par ne representer qu'un petit sous-groupe de
 * survivants resistants, pas la population - c'est ce qui produit une
 * remontee illusoire en fin de courbe.
 *
 * Methode delta (standard en sabermetrie pour ce biais precis) : suivre
 * chaque joueur d'une annee sur l'autre et moyenner *son* delta individuel,
 * uniquement entre joueurs presents aux deux ages consecutifs. Un joueur qui
 * part en retraite arrete de contribuer de nouveaux deltas sans fausser
 * retroactivement la moyenne des survivants. On reconstruit ensuite une
 * courbe "corrigee" en chainant ces deltas moyens depuis un point d'ancrage
 * jeune (peu ou pas touche par la retraite).
 */
final class DeltaCurveBuilder
{
    /**
     * @param list<SkillSample> $samples
     * @return array{
     *     deltaCurves: array<string, array<int, array{meanDelta: float, count: int}>>,
     *     chainedCurves: array<string, array<int, float>>,
     * }
     */
    public static function build(array $samples): array
    {
        $byPlayerCategory = self::groupByPlayerAndCategory($samples);
        $deltasByCategory = self::computeDeltas($byPlayerCategory);

        $deltaCurves = [];
        foreach ($deltasByCategory as $category => $byAge) {
            ksort($byAge);
            foreach ($byAge as $age => $deltas) {
                $deltaCurves[$category][$age] = [
                    'meanDelta' => Stats::mean($deltas),
                    'count' => \count($deltas),
                ];
            }
        }

        return [
            'deltaCurves' => $deltaCurves,
            'chainedCurves' => self::chain($samples, $deltaCurves),
        ];
    }

    /**
     * @param list<SkillSample> $samples
     * @return array<string, array<int, list<array{age: float, value: float}>>> categorie -> playerId -> points tries par age
     */
    private static function groupByPlayerAndCategory(array $samples): array
    {
        $grouped = [];
        foreach ($samples as $sample) {
            $grouped[$sample->category][$sample->playerId][] = ['age' => $sample->ageYears, 'value' => $sample->value];
        }

        foreach ($grouped as $category => $byPlayer) {
            foreach ($byPlayer as $playerId => $points) {
                usort($points, static fn (array $a, array $b): int => $a['age'] <=> $b['age']);
                $grouped[$category][$playerId] = $points;
            }
        }

        return $grouped;
    }

    /**
     * @param array<string, array<int, list<array{age: float, value: float}>>> $byPlayerCategory
     * @return array<string, array<int, list<float>>> categorie -> age (bucket de depart) -> deltas
     */
    private static function computeDeltas(array $byPlayerCategory): array
    {
        $deltas = [];
        foreach ($byPlayerCategory as $category => $byPlayer) {
            foreach ($byPlayer as $points) {
                for ($i = 0; $i < \count($points) - 1; $i++) {
                    $ageBucket = (int) round($points[$i]['age']);
                    $delta = $points[$i + 1]['value'] - $points[$i]['value'];
                    $deltas[$category][$ageBucket][] = $delta;
                }
            }
        }

        return $deltas;
    }

    /**
     * @param list<SkillSample> $samples
     * @param array<string, array<int, array{meanDelta: float, count: int}>> $deltaCurves
     * @return array<string, array<int, float>>
     */
    private static function chain(array $samples, array $deltaCurves): array
    {
        $anchors = self::anchorAges($samples);

        $chained = [];
        foreach ($deltaCurves as $category => $byAge) {
            if (!isset($anchors[$category])) {
                continue;
            }

            [$anchorAge, $anchorValue] = $anchors[$category];
            $chained[$category][$anchorAge] = $anchorValue;

            $age = $anchorAge;
            while (isset($byAge[$age])) {
                $chained[$category][$age + 1] = $chained[$category][$age] + $byAge[$age]['meanDelta'];
                $age++;
            }
        }

        return $chained;
    }

    /**
     * Point d'ancrage par categorie : le plus jeune age observe, avec la
     * moyenne brute des valeurs a cet age (peu ou pas de retraite a cet
     * age, donc peu de biais de survie sur ce seul point).
     *
     * @param list<SkillSample> $samples
     * @return array<string, array{0: int, 1: float}>
     */
    private static function anchorAges(array $samples): array
    {
        $byCategoryAge = [];
        foreach ($samples as $sample) {
            $age = (int) round($sample->ageYears);
            $byCategoryAge[$sample->category][$age][] = $sample->value;
        }

        $anchors = [];
        foreach ($byCategoryAge as $category => $byAge) {
            $minAge = min(array_keys($byAge));
            $anchors[$category] = [$minAge, Stats::mean($byAge[$minAge])];
        }

        return $anchors;
    }
}
