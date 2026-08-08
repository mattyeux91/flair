<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Snapshot;

use Attribute;

/**
 * Declare le type des elements d'une propriete `array`, pour que ValueCodec
 * sache quoi reconstruire au decodage.
 *
 * Necessaire parce que la reflexion PHP rend `array` sans son type
 * d'element : rien ne distingue `Standings::$entries`
 * (`array<int, StandingsEntry>`) de `SeasonConcluded::$finalRanking`
 * (`list<int>`). C'est la seule information que le systeme de types de PHP ne
 * porte pas et dont le codec a besoin.
 *
 * Consommee au **decodage** uniquement : encoder n'en a pas besoin, chaque
 * element est encode d'apres sa classe reelle. Le test de conformite
 * (Tests\Core\Snapshot\SnapshotConformanceTest) refuse toute propriete
 * `array` qui l'oublie - c'est ce qui rend l'oubli impossible plutot
 * qu'improbable.
 *
 * `$type` vaut un nom de classe (`StandingsEntry::class`) ou l'un des
 * scalaires `'int'`, `'float'`, `'string'`, `'bool'`.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class SnapshotArrayOf
{
    public function __construct(public string $type)
    {
    }
}
