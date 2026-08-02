<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Generation;

use Flair\Kernel\Core\Ruleset\MatchBalance;
use Flair\Kernel\Core\Support\Rng;

/**
 * Le moteur de match L0 (docs/14- §1) : Poisson bivarie avec la correction
 * de Dixon-Coles sur les scores faibles. Pure comme `PlayerFactory` : aucun
 * acces au monde, `(Rng, ratings, MatchBalance)` vers un `MatchScore` que
 * l'appelant (`Football\MatchSystem`) ecrit lui-meme.
 *
 * Pas encore derriere l'interface `MatchEngine` de docs/14- §1 : elle n'a de
 * sens qu'avec au moins une deuxieme implementation (L1), qui n'existe pas -
 * l'introduire maintenant serait une abstraction sans deuxieme utilisateur.
 *
 * ## Tirage par grille plutot que par rejet
 *
 * La construction Dixon-Coles reponderee un produit de deux Poisson
 * independantes par `τ(x, y, λ, μ, ρ)` sur les scores 0-0/1-0/0-1/1-1
 * (docs/14- §1) - ce n'est plus une loi normalisee. Plutot qu'un tirage par
 * rejet (boucle non bornee, desagreable pour le determinisme et les tests),
 * la masse est calculee sur une grille finie `[0, maxSimulatedGoals]²`,
 * normalisee, puis un seul tirage uniforme (`Rng::nextUint32()`) est
 * invertit contre la fonction de repartition cumulee. Borne, un seul appel
 * RNG par match, et directement une traduction de la formule documentee.
 *
 * ## `exp()` : voir `MatchBalance` pour la justification complete de la
 * premiere fonction transcendante du noyau. Un seul appel par λ (recurrence
 * ensuite), jamais dans une boucle.
 */
final class PoissonMatchEngine
{
    public function play(
        Rng $rng,
        float $attackHome,
        float $defenseHome,
        float $attackAway,
        float $defenseAway,
        MatchBalance $balance,
    ): MatchScore {
        $lambdaHome = exp(($attackHome - $defenseAway) / $balance->strengthScale + $balance->homeAdvantage);
        $lambdaAway = exp(($attackAway - $defenseHome) / $balance->strengthScale);

        $grid = $this->weightedGrid($lambdaHome, $lambdaAway, $balance->lowScoreCorrelation, $balance->maxSimulatedGoals);
        $total = 0.0;
        foreach ($grid as $row) {
            $total += array_sum($row);
        }

        $target = $this->unitInterval($rng) * $total;
        $cumulative = 0.0;

        foreach ($grid as $homeGoals => $row) {
            foreach ($row as $awayGoals => $weight) {
                $cumulative += $weight;
                if ($cumulative >= $target) {
                    return new MatchScore($homeGoals, $awayGoals);
                }
            }
        }

        // Filet de securite : arrondis flottants, $target tombe pile sur $total.
        return new MatchScore($balance->maxSimulatedGoals, $balance->maxSimulatedGoals);
    }

    /** @return array<int, array<int, float>> keye par [buts domicile][buts exterieur] */
    private function weightedGrid(float $lambdaHome, float $lambdaAway, float $rho, int $maxGoals): array
    {
        $homePmf = $this->poissonPmf($lambdaHome, $maxGoals);
        $awayPmf = $this->poissonPmf($lambdaAway, $maxGoals);

        $grid = [];
        for ($x = 0; $x <= $maxGoals; $x++) {
            for ($y = 0; $y <= $maxGoals; $y++) {
                $weight = $homePmf[$x] * $awayPmf[$y] * $this->tau($x, $y, $lambdaHome, $lambdaAway, $rho);
                // Garde-fou : un rho mal calibre pourrait rendre tau negatif
                // sur les scores faibles. Une masse negative n'a pas de sens
                // pour un tirage - meme esprit que TrainingSystem qui clampe
                // son resultat plutot que de faire confiance a l'appelant.
                $grid[$x][$y] = max(0.0, $weight);
            }
        }

        return $grid;
    }

    /**
     * pmf de Poisson par recurrence (`p(0) = exp(-λ)`, `p(k) = p(k-1) * λ / k`)
     * plutot que `exp(-λ) * λ^k / k!` calcule terme a terme - un seul appel
     * `exp()` par λ, pas de factorielle qui deborde pour k grand.
     *
     * @return array<int, float> indice = nombre de buts, 0 a $maxGoals
     */
    private function poissonPmf(float $lambda, int $maxGoals): array
    {
        $pmf = [exp(-$lambda)];

        for ($k = 1; $k <= $maxGoals; $k++) {
            $pmf[$k] = $pmf[$k - 1] * $lambda / $k;
        }

        return $pmf;
    }

    /**
     * Correction de Dixon-Coles (docs/14- §1, docs/10- - Dixon & Coles 1997) :
     * corrige la sous-estimation des matchs nuls serres par un Poisson
     * independant, sur les quatre scores faibles seulement.
     */
    private function tau(int $x, int $y, float $lambda, float $mu, float $rho): float
    {
        return match (true) {
            $x === 0 && $y === 0 => 1.0 - $lambda * $mu * $rho,
            $x === 0 && $y === 1 => 1.0 + $lambda * $rho,
            $x === 1 && $y === 0 => 1.0 + $mu * $rho,
            $x === 1 && $y === 1 => 1.0 - $rho,
            default => 1.0,
        };
    }

    private function unitInterval(Rng $rng): float
    {
        return $rng->nextUint32() / 0xFFFFFFFF;
    }
}
