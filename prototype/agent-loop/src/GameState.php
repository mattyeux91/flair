<?php

declare(strict_types=1);

/**
 * Ce qui survit d'une fenetre de mercato a l'autre - c'est ce qui permet au
 * feedback inter-fenetre de fonctionner (NegotiationWindow::generate() lit
 * $clientSatisfaction/$agentReputation pour moduler les offres suivantes).
 */
final class GameState
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(
        public float $agentReputation = 0.5,
        public float $clientSatisfaction = 0.6,
        public int $totalCommission = 0,
    ) {
    }

    public function addLog(string $entry): void
    {
        $this->log[] = $entry;
    }

    public function clampMeters(): void
    {
        $this->agentReputation = max(0.0, min(1.0, $this->agentReputation));
        $this->clientSatisfaction = max(0.0, min(1.0, $this->clientSatisfaction));
    }
}
