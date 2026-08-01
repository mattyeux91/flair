<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Ruleset;

/**
 * Les leviers d'equilibrage du monde (docs/12-modele-du-monde.md §6, la cle
 * JSON "balance" : trainingRate, injuryBaseHazard, marketInflationTarget...).
 *
 * `developmentRate` est le premier - multiplicateur global sur la
 * progression naturelle (vieillissement, docs/14- §2), lu par
 * Football\AgingSystem. Les suivants rejoindront cette classe au fur et a
 * mesure qu'un systeme les lira reellement, jamais par anticipation.
 */
final readonly class Balance
{
    public function __construct(public float $developmentRate = 1.0)
    {
    }
}
