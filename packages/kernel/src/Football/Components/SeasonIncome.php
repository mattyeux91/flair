<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Revenu credite a un club pour la saison en cours, en centimes - la part de
 * l'enveloppe des droits TV que `Football\FinanceSystem` lui a versee au
 * dernier `Football\Events\SeasonConcluded`. Seul writer
 * `Football\FinanceSystem`, ecrit dans la meme boucle que le credit
 * correspondant sur `Finances`.
 *
 * Un **flux**, la ou `Finances` est un **stock** : c'est ce flux qui porte
 * l'inegalite de revenus que docs/14-algorithmes.md §7 demande de mesurer
 * ("Gini des revenus < seuil"). `Finances::$balanceCents` ne peut pas servir
 * a ce calcul : un solde derive vers le negatif au calibrage actuel (revenu
 * de saison legerement inferieur a la masse salariale annuelle), et un Gini
 * sur des valeurs negatives n'a pas de sens.
 *
 * Pas de composant "revenu cumule" en face : le cumul est une agregation de
 * l'observateur (`Harness\Metrics\Sampler`), pas un etat du monde.
 */
final readonly class SeasonIncome
{
    public function __construct(
        public int $cents,
    ) {
    }
}
