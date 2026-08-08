<?php

declare(strict_types=1);

namespace Flair\Host;

use Flair\Host\Database\Database;

/**
 * Le verrou mono-writer : **un seul processus fait avancer un monde donne**
 * (docs/13- §8). Indispensable avec un declenchement par cron, ou deux
 * executions peuvent se chevaucher des qu'un tick prend plus longtemps que
 * prevu.
 *
 * `pg_try_advisory_xact_lock` et non `pg_advisory_lock`, pour deux raisons qui
 * comptent toutes les deux :
 *
 * - **`try`** : le second processus repart immediatement au lieu d'attendre.
 *   Un tick en retard ne doit pas faire s'empiler des processus qui feront
 *   tous le meme travail en file indienne ; le monde n'attend personne.
 * - **`xact`** : le verrou est lie a la transaction et libere au commit comme
 *   au rollback, **y compris si le processus meurt**. Un verrou de session
 *   survivrait a un crash et bloquerait le monde jusqu'a expiration de la
 *   connexion - exactement le mode de panne que la Phase 3 doit exclure.
 *
 * La cle est un couple d'entiers 32 bits : un `CLASS_ID` fixe qui identifie
 * « un monde Flair », et `hashtext(worldId)`. Deux mondes dont les noms
 * entrent en collision sur ce hash se bloqueraient mutuellement - genant,
 * jamais incorrect, et sans consequence tant qu'un serveur ne fait pas tourner
 * des milliers de mondes.
 */
final class WorldLock
{
    private const int CLASS_ID = 0x464C4149; // "FLAI"

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Tente de prendre le verrou pour la transaction en cours. **Doit etre
     * appele a l'interieur d'une transaction** : hors transaction, PostgreSQL
     * le libere immediatement et la protection ne vaut rien.
     */
    public function tryAcquire(string $worldId): bool
    {
        $rows = $this->database->connection()->select(
            'select pg_try_advisory_xact_lock(?, hashtext(?)) as acquired',
            [self::CLASS_ID, $worldId],
        );

        $first = $rows[0] ?? null;

        return is_object($first) && ($first->acquired ?? false) === true;
    }
}
