<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Singletons;

/**
 * Total courant des injections et des puits monetaires du monde, en
 * centimes (docs/14-algorithmes.md §6 : "Σ injections − Σ puits = Δ masse
 * monetaire totale"). Premier singleton du domaine football - sibling de
 * `MarketInflation`/`SeasonPhase` anticipes par docs/12-modele-du-monde.md
 * §3bis.
 *
 * Mis a jour uniquement par `Football\FinanceSystem`, en meme temps que
 * chaque ecriture de `Finances` : sous-produit direct de la boucle qui
 * paie/encaisse, jamais recalcule independamment - c'est ce qui permet au
 * test de conservation du harness de detecter une divergence reelle plutot
 * que de rejouer sa propre copie de la logique metier.
 */
final readonly class MonetaryMass
{
    public function __construct(
        public int $totalInjectionsCents = 0,
        public int $totalSinksCents = 0,
    ) {
    }
}
