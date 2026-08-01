<?php

declare(strict_types=1);

namespace Flair\Harness\Comparison;

use Flair\Kernel\Core\Ruleset\Balance;
use Flair\Kernel\Core\Ruleset\PlayerDevelopmentBalance;
use Flair\Kernel\Core\Ruleset\RetirementBalance;
use Flair\Kernel\Core\Ruleset\Ruleset;

/**
 * Construit un Ruleset qui ne differe d'un autre que par un seul champ de
 * calibration du vieillissement (`RetirementBalance` ou
 * `PlayerDevelopmentBalance`) - utilise par le CLI et la page web pour la
 * comparaison a graines appariees. Explicitement enumere plutot que via
 * reflection, dans le meme esprit que le reste du noyau (aucune
 * magie/reflection nulle part).
 *
 * `AGING_FIELDS` reste un seul groupe cote CLI/web (le contrat
 * `--compare-field=retirementEligibleAge` ne change pas) meme si les 9
 * champs vivent desormais dans deux classes distinctes cote noyau -
 * `RETIREMENT_FIELDS`/`PLAYER_DEVELOPMENT_FIELDS` servent uniquement a
 * router en interne vers le bon sous-objet de `Balance`.
 */
final class RulesetOverride
{
    /** @var list<string> */
    private const array RETIREMENT_FIELDS = [
        'retirementEligibleAge',
        'retirementAgeWeight',
        'retirementFragilityWeight',
    ];

    /** @var list<string> */
    private const array PLAYER_DEVELOPMENT_FIELDS = [
        'growthPrimeAgeThreshold',
        'growthPlateauFactor',
        'declineRatePerYear',
        'physicalDeclineMultiplier',
        'technicalDeclineMultiplier',
        'mentalDeclineMultiplier',
    ];

    /** @var list<string> */
    public const array AGING_FIELDS = [
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
        if (\in_array($field, self::RETIREMENT_FIELDS, strict: true)) {
            return self::withRetirementField($base, $field, $value);
        }

        if (\in_array($field, self::PLAYER_DEVELOPMENT_FIELDS, strict: true)) {
            return self::withPlayerDevelopmentField($base, $field, $value);
        }

        throw new \InvalidArgumentException("Champ de vieillissement inconnu : {$field}");
    }

    private static function withRetirementField(Ruleset $base, string $field, float $value): Ruleset
    {
        $retirement = $base->balance->retirement;

        return new Ruleset($base->version, new Balance(
            developmentRate: $base->balance->developmentRate,
            trainingRate: $base->balance->trainingRate,
            retirement: new RetirementBalance(
                retirementEligibleAge: $field === 'retirementEligibleAge' ? $value : $retirement->retirementEligibleAge,
                retirementAgeWeight: $field === 'retirementAgeWeight' ? $value : $retirement->retirementAgeWeight,
                retirementFragilityWeight: $field === 'retirementFragilityWeight' ? $value : $retirement->retirementFragilityWeight,
            ),
            playerDevelopment: $base->balance->playerDevelopment,
        ));
    }

    private static function withPlayerDevelopmentField(Ruleset $base, string $field, float $value): Ruleset
    {
        $development = $base->balance->playerDevelopment;

        return new Ruleset($base->version, new Balance(
            developmentRate: $base->balance->developmentRate,
            trainingRate: $base->balance->trainingRate,
            retirement: $base->balance->retirement,
            playerDevelopment: new PlayerDevelopmentBalance(
                growthPrimeAgeThreshold: $field === 'growthPrimeAgeThreshold' ? $value : $development->growthPrimeAgeThreshold,
                growthPlateauFactor: $field === 'growthPlateauFactor' ? $value : $development->growthPlateauFactor,
                declineRatePerYear: $field === 'declineRatePerYear' ? $value : $development->declineRatePerYear,
                physicalDeclineMultiplier: $field === 'physicalDeclineMultiplier' ? $value : $development->physicalDeclineMultiplier,
                technicalDeclineMultiplier: $field === 'technicalDeclineMultiplier' ? $value : $development->technicalDeclineMultiplier,
                mentalDeclineMultiplier: $field === 'mentalDeclineMultiplier' ? $value : $development->mentalDeclineMultiplier,
            ),
        ));
    }
}
