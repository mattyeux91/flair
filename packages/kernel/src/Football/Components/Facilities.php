<?php

declare(strict_types=1);

namespace Flair\Kernel\Football\Components;

/**
 * Qualite des installations d'un club (docs/14- §2 : `h(entrainement)`),
 * portee par l'entite club. Exprimee directement sur l'echelle du
 * multiplicateur final `[0.5, 2.0]` (docs/14- §3) - pas d'echelle
 * intermediaire a mapper, meme choix que `PlayerPotentials::$fragility`
 * deja utilise directement sans indirection. `1.0` = installations
 * moyennes, `0.5` = mediocres, `2.0` = excellentes.
 *
 * Authore a la main pour l'instant (demo, tests) : aucun worldgen ne
 * genere encore cette valeur.
 */
final readonly class Facilities
{
    public function __construct(
        public float $quality,
    ) {
    }
}
