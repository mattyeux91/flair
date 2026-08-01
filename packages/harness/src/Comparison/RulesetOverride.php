<?php

declare(strict_types=1);

namespace Flair\Harness\Comparison;

use Flair\Kernel\Core\Ruleset\AgingBalance;
use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\Ruleset;

/**
 * Construit un Ruleset qui ne differe d'un autre que par un seul champ
 * d'AgingBalance - utilise par le CLI et la page web pour la comparaison a
 * graines appariees. Explicitement enumere plutot que via reflection, dans
 * le meme esprit que le reste du noyau (aucune magie/reflection nulle part).
 */
final class RulesetOverride
{
    /** @var list<string> */
    public const AGING_FIELDS = [
        'retirementEligibleAge',
        'retirementAgeWeight',
        'retirementFragilityWeight',
        'growthPrimeAgeThreshold',
        'growthPlateauFactor',
        'declineRatePerYear',
        'physicalDeclineMultiplier',
        'technicalDeclineMultiplier',
        'mentalDeclineMultiplier',
    ];

    public static function agingField(Ruleset $base, string $field, float $value): Ruleset
    {
        if (!\in_array($field, self::AGING_FIELDS, strict: true)) {
            throw new \InvalidArgumentException("Champ AgingBalance inconnu : {$field}");
        }

        $aging = $base->balance->aging;

        return new Ruleset($base->version, new Balance(
            developmentRate: $base->balance->developmentRate,
            aging: new AgingBalance(
                retirementEligibleAge: $field === 'retirementEligibleAge' ? $value : $aging->retirementEligibleAge,
                retirementAgeWeight: $field === 'retirementAgeWeight' ? $value : $aging->retirementAgeWeight,
                retirementFragilityWeight: $field === 'retirementFragilityWeight' ? $value : $aging->retirementFragilityWeight,
                growthPrimeAgeThreshold: $field === 'growthPrimeAgeThreshold' ? $value : $aging->growthPrimeAgeThreshold,
                growthPlateauFactor: $field === 'growthPlateauFactor' ? $value : $aging->growthPlateauFactor,
                declineRatePerYear: $field === 'declineRatePerYear' ? $value : $aging->declineRatePerYear,
                physicalDeclineMultiplier: $field === 'physicalDeclineMultiplier' ? $value : $aging->physicalDeclineMultiplier,
                technicalDeclineMultiplier: $field === 'technicalDeclineMultiplier' ? $value : $aging->technicalDeclineMultiplier,
                mentalDeclineMultiplier: $field === 'mentalDeclineMultiplier' ? $value : $aging->mentalDeclineMultiplier,
            ),
        ));
    }
}
