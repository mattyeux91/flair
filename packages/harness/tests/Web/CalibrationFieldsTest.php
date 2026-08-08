<?php

declare(strict_types=1);

namespace Flair\Harness\Tests\Web;

use Flair\Harness\Comparison\RulesetOverride;
use Flair\Harness\Web\CalibrationFields;
use Flair\Kernel\Core\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

/**
 * **Le test qui manquait, et dont l'absence se voyait a l'ecran.**
 *
 * Le formulaire de calibration decrivait 43 champs quand
 * `RulesetOverride::ALL_FIELDS` en compte 82 : Finances, Installations,
 * Contrats, Marche des transferts et Inflation rendaient cinq `<details>`
 * **vides**, ajoutes lot apres lot sans que personne ne branche l'interface.
 * Rien ne pouvait le signaler - la liste vivait dans `public/index.php`, hors
 * PHPStan et hors tests.
 *
 * La correspondance est exigee **dans les deux sens** : un champ ajoute au
 * `Ruleset` mais pas a l'interface est une case manquante, un champ decrit
 * mais retire du `Ruleset` est un formulaire qui ment.
 */
final class CalibrationFieldsTest extends TestCase
{
    public function testEveryOverridableFieldIsDescribedExactlyOnce(): void
    {
        $described = array_map(
            static fn (CalibrationFields $meta): string => $meta->field,
            CalibrationFields::all(new Ruleset('test')),
        );

        $duplicates = array_keys(array_filter(array_count_values($described), static fn (int $n): bool => $n > 1));
        self::assertSame([], $duplicates, 'Champs decrits deux fois : ' . implode(', ', $duplicates));

        $missing = array_diff(RulesetOverride::ALL_FIELDS, $described);
        self::assertSame([], array_values($missing), sprintf(
            "Champs calibrables absents du formulaire : %s.\n"
            . "Ils rendraient un groupe vide a l'ecran, sans erreur.",
            implode(', ', $missing),
        ));

        $unknown = array_diff($described, RulesetOverride::ALL_FIELDS);
        self::assertSame([], array_values($unknown), sprintf(
            'Champs decrits mais inconnus de RulesetOverride : %s.',
            implode(', ', $unknown),
        ));
    }

    /**
     * Chaque groupe declare par `RulesetOverride` doit avoir au moins un champ.
     * C'est la forme la plus directe du defaut d'origine : un `<details>` qu'on
     * deplie et qui ne contient rien.
     */
    public function testNoDeclaredGroupRendersEmpty(): void
    {
        $grouped = CalibrationFields::grouped(new Ruleset('test'));

        foreach (CalibrationFields::groupLabels() as $label) {
            self::assertArrayHasKey($label, $grouped, "Le groupe « {$label} » n'a aucun champ : il s'afficherait vide.");
            self::assertNotSame([], $grouped[$label]);
        }

        self::assertSame(
            CalibrationFields::groupLabels(),
            array_keys($grouped),
            'Les groupes doivent sortir dans l\'ordre canonique de RulesetOverride, sans groupe invente.',
        );
    }

    /**
     * Les defauts affiches sont ceux du `Ruleset` passe, pas des constantes
     * recopiees : un champ dont la valeur par defaut bougerait dans le kernel
     * doit bouger dans le formulaire sans qu'on y touche.
     */
    public function testDefaultsComeFromTheRulesetAndNotFromCopies(): void
    {
        $baseline = new Ruleset('harness');
        $fields = [];

        foreach (CalibrationFields::all($baseline) as $meta) {
            $fields[$meta->field] = $meta->default;
        }

        self::assertSame($baseline->balance->retirement->retirementEligibleAge, $fields['retirementEligibleAge']);
        self::assertSame($baseline->balance->finance->meritShare, $fields['meritShare']);
        self::assertSame($baseline->balance->contract->targetSquadSize, $fields['targetSquadSize']);
        self::assertSame($baseline->balance->transfer->maxRounds, $fields['maxRounds']);
        self::assertSame($baseline->balance->inflation->marketInflationTarget, $fields['marketInflationTarget']);
    }

    /**
     * Les seules bornes qui comptent sont celles des champs qui servent de
     * borne de boucle dans le kernel : sans elles, une saisie demesuree
     * declenche des millions de tirages RNG et un timeout serveur - constate
     * en usage reel.
     */
    public function testTheTwoLoopBoundFieldsCarryTheirBounds(): void
    {
        $bounded = [];

        foreach (CalibrationFields::all(new Ruleset('test')) as $meta) {
            if ($meta->min !== null || $meta->max !== null) {
                $bounded[$meta->field] = $meta->boundsAttribute();
            }
        }

        self::assertSame(['baseIntakePerClub', 'talentSkew'], array_keys($bounded));
        self::assertStringContainsString('min="1"', $bounded['talentSkew']);
        self::assertStringContainsString('max="50"', $bounded['talentSkew']);
    }
}
