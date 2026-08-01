<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core;

use Flair\Kernel\Core\Ruleset;
use PHPUnit\Framework\TestCase;

final class RulesetTest extends TestCase
{
    public function testVersionIsExposedAsGiven(): void
    {
        $ruleset = new Ruleset('2026.1.0');

        self::assertSame('2026.1.0', $ruleset->version);
    }
}
