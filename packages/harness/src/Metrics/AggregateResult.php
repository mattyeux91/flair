<?php

declare(strict_types=1);

namespace Flair\Harness\Metrics;

/**
 * Sortie agregee d'une simulation : courbes de competence par categorie
 * (bucketees par age entier, avec bande de percentiles), distribution des
 * ages de retraite, effectif actif par annee, pyramide des ages de la
 * derniere annee simulee, et - depuis que le pipeline joue des matchs -
 * distribution des scores et historique des saisons (classement + matchs).
 * Independant du format de sortie - Report/TextReport et
 * Report/JsonSerializer rendent tous les deux la meme structure.
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
 * ne porte pas trace des joueurs retraites ou promus. Meme chose pour les
 * quatre champs match/classement ci-dessous : Sampler les calcule en
 * observant `Football\Events\MatchPlayed`/`SeasonStarted` et l'etat de
 * `Football\Components\Standings` au fil du run, aucun ne se derive de
 * `$samples`.
 *
 * `cumulativeIncomeByClub` suit la meme logique de transport : Sampler cumule
 * `Football\Components\SeasonIncome` une fois par annee simulee, et
 * CompetitiveBalance en tire le Gini des revenus. Un **flux** cumule, pas le
 * stock `Finances` - voir le docblock de `SeasonIncome` pour pourquoi un
 * solde de club ne peut pas servir a ce calcul.
 *
 * `goalsPerMatchHistogram`/`matchResultDistribution`/`scorelineFrequency`
 * agregent **tout le run** (plus stable statistiquement qu'une seule
 * saison), contrairement a `seasonHistory` qui detaille chaque saison
 * individuellement (classement final + matchs joues) - c'est ce second champ
 * qui permet de naviguer saison par saison plutot que de ne voir que la
 * derniere.
 */
final readonly class AggregateResult
{
    /**
     * @param array<string, array<int, array{mean: float, p10: float, p50: float, p90: float, count: int}>> $curves
     *   categorie -> age -> statistiques, trie par age croissant
     * @param array<int, int> $retirementAgeHistogram age -> effectif, trie par age croissant
     * @param array<int, int> $populationByYear annee simulee -> effectif actif en fin d'annee
     * @param array<int, int> $finalAgeHistogram age -> effectif, population active de la derniere annee simulee
     * @param array<int, int> $goalsPerMatchHistogram buts totaux (domicile + exterieur) d'un match -> nombre de matchs
     * @param array{homeWin: int, draw: int, awayWin: int} $matchResultDistribution
     * @param array<string, int> $scorelineFrequency "buts_domicile-buts_exterieur" (ou 'autre') -> nombre de matchs
     * @param list<array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}> $seasonHistory une entree par saison achevee, dans l'ordre chronologique (jamais la toute derniere saison d'un run, qui ne joue structurellement aucun match - cf. docblock de Sampler)
     * @param array<string, int> $cumulativeIncomeByClub nom de club -> total des revenus de saison percus sur le run
     * @param array<string, float> $finalFacilitiesByClub nom de club -> qualite d'installations en fin de run
     */
    public function __construct(
        public array $curves,
        public array $retirementAgeHistogram,
        public array $populationByYear,
        public array $finalAgeHistogram,
        public array $goalsPerMatchHistogram = [],
        public array $matchResultDistribution = ['homeWin' => 0, 'draw' => 0, 'awayWin' => 0],
        public array $scorelineFrequency = [],
        public array $seasonHistory = [],
        public array $cumulativeIncomeByClub = [],
        public array $finalFacilitiesByClub = [],
    ) {
    }

    /**
     * @param list<SkillSample> $samples
     * @param list<int> $retirementAges
     * @param array<int, int> $populationByYear
     * @param array<int, int> $finalAgeHistogram
     * @param array<int, int> $goalsPerMatchHistogram
     * @param array{homeWin: int, draw: int, awayWin: int} $matchResultDistribution
     * @param array<string, int> $scorelineFrequency
     * @param list<array{season: int, standings: list<array{clubId: int, clubName: string, played: int, won: int, drawn: int, lost: int, goalsFor: int, goalsAgainst: int, points: int}>, matches: list<array{matchday: int, homeClub: string, awayClub: string, homeGoals: int, awayGoals: int}>}> $seasonHistory
     * @param array<string, int> $cumulativeIncomeByClub
     * @param array<string, float> $finalFacilitiesByClub
     */
    public static function fromSamples(
        array $samples,
        array $retirementAges,
        array $populationByYear,
        array $finalAgeHistogram,
        array $goalsPerMatchHistogram = [],
        array $matchResultDistribution = ['homeWin' => 0, 'draw' => 0, 'awayWin' => 0],
        array $scorelineFrequency = [],
        array $seasonHistory = [],
        array $cumulativeIncomeByClub = [],
        array $finalFacilitiesByClub = [],
    ): self {
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
            $goalsPerMatchHistogram,
            $matchResultDistribution,
            $scorelineFrequency,
            $seasonHistory,
            $cumulativeIncomeByClub,
            $finalFacilitiesByClub,
        );
    }
}
