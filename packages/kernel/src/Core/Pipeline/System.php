<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

use Flair\Kernel\Core\Messaging\DomainEvent;

/**
 * Contrat d'un systeme du pipeline (docs/13-moteur-de-simulation.md §2).
 * Reactif via handle(), periodique via update(), ou les deux.
 */
interface System
{
    public function id(): string;

    /** @return list<class-string> */
    public function reads(): array;

    /** @return list<class-string> */
    public function writes(): array;

    /** @return list<class-string> types d'evenements ecoutes - vide si purement periodique */
    public function subscribesTo(): array;

    /** Reactif - appele une fois par evenement pertinent, dans l'ordre de la file */
    public function handle(DomainEvent $event, SystemContext $ctx): void;

    /** Periodique - appele une fois par tick, apres les handle() du systeme */
    public function update(SystemContext $ctx): void;
}
