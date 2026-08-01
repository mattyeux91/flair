<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Messaging;

use Flair\Kernel\Core\Messaging\DecisionRequest;
use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Messaging\Intent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "L'erreur la plus couteuse serait de tout appeler « evenement »"
 * (docs/16-evenements-et-cascades.md §1). Ces tests encodent la regle en
 * assertion executable plutot qu'en commentaire : les trois types ne se
 * recouvrent jamais.
 *
 * Le message et les types exclus transitent par un data provider (types
 * generiques `object`/`class-string` au niveau du test) plutot que d'etre
 * construits en dur dans le corps du test : sans ca, PHPStan prouve
 * statiquement qu'une classe `final` qui n'implemente qu'une seule des trois
 * interfaces ne peut jamais satisfaire les deux autres, et signale
 * l'assertion comme deja tranchee (`staticMethod.alreadyNarrowedType`) - un
 * faux positif ici, puisque le test existe justement pour attraper une
 * future violation de cette regle, pas pour verifier l'etat actuel du code.
 */
final class TaxonomyTest extends TestCase
{
    /** @return iterable<string, array{0: object, 1: class-string, 2: list<class-string>}> */
    public static function messages(): iterable
    {
        yield 'DomainEvent' => [
            new TaxonomyTestEvent(),
            DomainEvent::class,
            [DecisionRequest::class, Intent::class],
        ];
        yield 'DecisionRequest' => [
            new TaxonomyTestDecisionRequest(),
            DecisionRequest::class,
            [DomainEvent::class, Intent::class],
        ];
        yield 'Intent' => [
            new TaxonomyTestIntent(),
            Intent::class,
            [DomainEvent::class, DecisionRequest::class],
        ];
    }

    /**
     * @param class-string $expectedType
     * @param list<class-string> $excludedTypes
     */
    #[DataProvider('messages')]
    public function testAMessageSatisfiesOnlyItsOwnType(object $message, string $expectedType, array $excludedTypes): void
    {
        self::assertInstanceOf($expectedType, $message);

        foreach ($excludedTypes as $excludedType) {
            self::assertNotInstanceOf($excludedType, $message);
        }
    }
}

final class TaxonomyTestEvent implements DomainEvent
{
}

final class TaxonomyTestDecisionRequest implements DecisionRequest
{
}

final class TaxonomyTestIntent implements Intent
{
}
