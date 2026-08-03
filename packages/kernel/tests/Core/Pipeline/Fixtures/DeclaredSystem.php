<?php

declare(strict_types=1);

namespace Flair\Kernel\Tests\Core\Pipeline\Fixtures;

use Flair\Kernel\Core\Messaging\DomainEvent;
use Flair\Kernel\Core\Pipeline\System;
use Flair\Kernel\Core\Pipeline\SystemContext;

/**
 * Systeme de test dont les quatre declarations sont fournies a la
 * construction : permet d'exercer chaque combinaison de droits sans
 * dependre d'un systeme du domaine football, dont les declarations sont
 * fixees par le jeu.
 */
final class DeclaredSystem implements System
{
    /**
     * @param list<class-string> $reads
     * @param list<class-string> $writes
     * @param list<class-string> $creates
     * @param list<class-string> $removes
     */
    public function __construct(
        private string $id = 'declared-test-system',
        private array $reads = [],
        private array $writes = [],
        private array $creates = [],
        private array $removes = [],
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function reads(): array
    {
        return $this->reads;
    }

    public function writes(): array
    {
        return $this->writes;
    }

    public function creates(): array
    {
        return $this->creates;
    }

    public function removes(): array
    {
        return $this->removes;
    }

    public function subscribesTo(): array
    {
        return [];
    }

    public function handle(DomainEvent $event, SystemContext $ctx): void
    {
    }

    public function update(SystemContext $ctx): void
    {
    }
}
