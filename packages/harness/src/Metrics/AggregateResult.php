<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Sortie agregee d'une simulation : courbes de competence par categorie
 * (bucketees par age entier, avec bande de percentiles), distribution des
 * ages de retraite, effectif actif par annee et pyramide des ages de la
 * derniere annee simulee. Independant du format de sortie -
 * Report/TextReport et Report/JsonSerializer rendent tous les deux la meme
 * structure.
 *
 * `curves` est une **coupe transversale** : la moyenne des joueurs vivants a
 * chaque age, a un instant donne. C'est la seule vue publiee, et c'est
 * volontaire.
 *
 * Une "courbe corrigee" par methode delta (deltas individuels d'annee en
 * annee, chaines depuis une ancre jeune) a existe ici pour corriger le biais
 * de survie aux ages avances. Elle a ete retiree : la methode suppose une
 * **cohorte fermee**, hypothese cassee des que Football\YouthIntakeSystem a
 * commence a injecter des joueurs en continu. Mesure a l'appui, elle
 * culminait a ~81 points de competence pour un `ceiling` moyen de ~65 -
 * arithmetiquement impossible, puisque Football\PlayerDevelopmentSystem fait
 * converger une competence vers son `ceiling` sans jamais le depasser. Le
 * biais qu'elle corrigeait (remontee illusoire en fin de courbe) ne se
 * manifeste d'ailleurs pas au calibrage actuel : `curves` decroit
 * monotonement de 24 a 35 ans.
 *
 * Une coupe transversale ne peut pas mentir de cette facon - elle est bornee
 * par le `ceiling` mecaniquement. Contrepartie a connaitre en la lisant :
 * elle melange les recrues fraiches aux joueurs installes, d'ou un creux a
 * l'age d'arrivee des promotions. Ce n'est pas une erreur, c'est la
 * composition reelle de la population.
 *
 * `populationByYear`/`finalAgeHistogram` sont calcules par Sampler (le seul
 * a suivre les promotions en cours de run) et simplement transportes ici -
 * contrairement aux courbes, ils ne se derivent pas de `$samples` seul, qui
 * ne porte pas trace des joueurs retraites ou promus.
 */
final readonly class AggregateResult
{
    /**
     * @param array<string, array<int, array{mean: float, p10: float, p50: float, p90: float, count: int}>> $curves
     *   categorie -> age -> statistiques, trie par age croissant
     * @param array<int, int> $retirementAgeHistogram age -> effectif, trie par age croissant
     * @param array<int, int> $populationByYear annee simulee -> effectif actif en fin d'annee
     * @param array<int, int> $finalAgeHistogram age -> effectif, population active de la derniere annee simulee
     */
    public function __construct(
        public array $curves,
        public array $retirementAgeHistogram,
        public array $populationByYear,
        public array $finalAgeHistogram,
    ) {
    }

    /**
     * @param list<SkillSample> $samples
     * @param list<int> $retirementAges
     * @param array<int, int> $populationByYear
     * @param array<int, int> $finalAgeHistogram
     */
    public static function fromSamples(array $samples, array $retirementAges, array $populationByYear, array $finalAgeHistogram): self
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

        return new self(
            $curves,
            Stats::histogram($retirementAges, bucketWidth: 1),
            $populationByYear,
            $finalAgeHistogram,
        );
    }
}
