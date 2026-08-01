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

    /** @return list<class-string> composants mutes en place via ComponentStore::set() */
    public function writes(): array;

    /** @return list<class-string> composants retires via ComponentStore::remove() (archetype-strip) - distinct de writes(), qui ne couvre que set() */
    public function removes(): array;

    /**
     * @return list<class-string> composants poses via ComponentStore::set() sur une entite **creee par ce systeme dans ce tick** (createEntity())
     *
     * Distinct de writes() pour la meme raison qui a fait separer removes() :
     * un writer de valeur et un createur d'entite peuvent coexister sur un
     * meme composant sans violer l'invariant "un seul writer" (docs/13- §2),
     * parce qu'ils ne touchent jamais la meme entite - le createur ne pose
     * ses composants que sur une entite qui n'existait pas quand le writer
     * a itere. Fusionner les deux dans writes() obligerait a vider
     * l'invariant de sa substance ; ne rien declarer ferait mentir une
     * declaration censee etre verifiee mecaniquement
     * (Football\PipelineInvariantsTest), ce qui reduirait tout le mecanisme
     * reads/writes a du theatre.
     */
    public function creates(): array;

    /** @return list<class-string> types d'evenements ecoutes - vide si purement periodique */
    public function subscribesTo(): array;

    /** Reactif - appele une fois par evenement pertinent, dans l'ordre de la file */
    public function handle(DomainEvent $event, SystemContext $ctx): void;

    /** Periodique - appele une fois par tick, apres les handle() du systeme */
    public function update(SystemContext $ctx): void;
}
