<?php

declare(strict_types=1);

namespace Flair\Host\Store;

use RuntimeException;

/**
 * Une ligne de l'event log porte une cle de type qui ne designe pas un Fait.
 *
 * Ne devrait jamais arriver : `Core\Snapshot\TypeRegistry` impose des cles
 * uniques toutes familles confondues, donc une cle d'evenement ne peut pas
 * designer un composant. Mais un event log est **la verite du passe**
 * (docs/13- §5), et rendre un objet dont on a seulement promis le type serait
 * transformer une base derivee en bug silencieux plus loin.
 *
 * Meme raison que `UnexpectedColumn` : ce qu'on ne sait pas lire, on le dit.
 */
final class UnexpectedEventType extends RuntimeException
{
    public function __construct(string $key, string $found)
    {
        parent::__construct(
            "La cle de type \"{$key}\" de l'event log designe {$found}, qui n'est pas un Fait.",
        );
    }
}
