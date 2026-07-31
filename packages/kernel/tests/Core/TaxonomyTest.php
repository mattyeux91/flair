<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core;

use Flair\Kernel\Core\DecisionRequest;
use Flair\Kernel\Core\DomainEvent;
use Flair\Kernel\Core\Intent;
use PHPUnit\Framework\TestCase;

/**
 * "L'erreur la plus couteuse serait de tout appeler « evenement »"
 * (docs/16-evenements-et-cascades.md §1). Ces tests encodent la regle en
 * assertion executable plutot qu'en commentaire : les trois types ne se
 * recouvrent jamais.
 */
final class TaxonomyTest extends TestCase
{
    public function testADomainEventSatisfiesOnlyItsOwnType(): void
    {
        $event = new TaxonomyTestEvent();

        self::assertInstanceOf(DomainEvent::class, $event);
        self::assertNotInstanceOf(DecisionRequest::class, $event);
        self::assertNotInstanceOf(Intent::class, $event);
    }

    public function testADecisionRequestSatisfiesOnlyItsOwnType(): void
    {
        $request = new TaxonomyTestDecisionRequest();

        self::assertInstanceOf(DecisionRequest::class, $request);
        self::assertNotInstanceOf(DomainEvent::class, $request);
        self::assertNotInstanceOf(Intent::class, $request);
    }

    public function testAnIntentSatisfiesOnlyItsOwnType(): void
    {
        $intent = new TaxonomyTestIntent();

        self::assertInstanceOf(Intent::class, $intent);
        self::assertNotInstanceOf(DomainEvent::class, $intent);
        self::assertNotInstanceOf(DecisionRequest::class, $intent);
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
