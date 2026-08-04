<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use LogicException;

/**
 * Les dependances de composants entre systemes forment un cycle : aucun
 * ordre d'execution ne peut les satisfaire toutes (docs/13- §2).
 *
 * `LogicException` et pas une situation de monde : c'est un defaut de
 * conception du pipeline, detecte au montage, jamais en cours de partie.
 *
 * La correction n'est jamais de relacher le tri, c'est de **casser le cycle
 * par un evenement** : le systeme en aval cesse de lire le composant dans le
 * meme tick et reagit au Fait emis, traite au tick suivant (docs/13- §2,
 * canal 2). C'est exactement la reponse deja retenue pour le couple
 * decision/application `ContractSystem`/`SquadSystem` et pour
 * `ClubInvestedInFacilities`.
 */
final class PipelineCycleException extends LogicException
{
    /** @param list<string> $remaining ids des systemes restes bloques, ordre d'entree */
    public static function among(array $remaining, string $from, string $to, string $component): self
    {
        return new self(sprintf(
            'Cycle de dependances entre systemes : %s. Par exemple "%s" ecrit %s que "%s" lit, '
            . 'et une chaine de dependances ramene de "%s" a "%s". Casse le cycle avec un evenement '
            . '(docs/13- §2, canal 2) : le lecteur reagit au Fait au tick suivant au lieu de lire '
            . 'le composant dans le meme tick.',
            implode(', ', $remaining),
            $from,
            $component,
            $to,
            $to,
            $from,
        ));
    }
}
