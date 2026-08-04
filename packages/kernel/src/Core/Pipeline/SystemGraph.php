<?php

declare(strict_types=1);

namespace Flair\Kernel\Core\Pipeline;

/**
 * Deduit l'ordre d'execution d'un pipeline des declarations de ses systemes,
 * au lieu de le faire ecrire a la main (docs/13- §2).
 *
 * Une arete `A -> B` existe des que `B` lit un composant que `A` ecrit ou
 * retire : `B` doit donc passer apres `A`. Le tri topologique qui en decoule
 * rend une violation de dependance **impossible a ecrire** plutot que
 * simplement detectable apres coup, et transforme un cycle en exception au
 * montage plutot qu'en savoir tribal.
 *
 * Generique a dessein : ne connait que `System`, aucun domaine. Le tri est
 * applique par le registre du domaine (`Football\FootballPipeline`), pas par
 * `Pipeline`, qui reste un pur executeur - les tests unitaires construisent
 * legitimement des pipelines partiels dans un ordre precis, et les reordonner
 * dans leur dos serait une surprise.
 *
 * ## Departage des ex aequo : stable, jamais alphabetique
 *
 * Parmi les systemes prets, on prend celui qui vient en premier dans la liste
 * fournie. Deux consequences voulues :
 *
 * - **Le monde ne depend pas des noms.** Un departage lexicographique par
 *   `id()` ferait changer l'ordre - donc le monde - au moindre renommage ;
 *   docs/13- §4.5 met explicitement en garde contre ce piege.
 * - **La liste declaree garde son sens.** La ou aucune dependance ne tranche,
 *   un systeme reste ou l'auteur l'a mis. Ajouter un systeme revient donc a
 *   le deposer n'importe ou : les dependances le placent, le reste ne bouge
 *   pas.
 *
 * ## Ce que le graphe ne capture pas
 *
 * Uniquement les dependances **par composant**. Deux systemes abonnes au meme
 * evenement dont l'ordre relatif compte ne sont pas couverts : `subscribesTo()`
 * ne dit rien de l'ordre entre souscripteurs. Aujourd'hui `MatchSystem` passe
 * bien avant `CompetitionSystem`, mais grace a l'arete `MatchResult`, pas
 * grace a leur souscription commune a `FixtureKickoff` - coincidence heureuse,
 * pas couverture. Si un cas reel apparait un jour, il faudra une declaration
 * d'ordre explicite ; rien n'est ajoute par anticipation.
 */
final class SystemGraph
{
    /**
     * @param list<System> $systems ordre declare, utilise comme preference
     * @return list<System> ordre d'execution
     *
     * @throws PipelineCycleException
     */
    public static function sort(array $systems): array
    {
        $writers = self::writersByComponent($systems);

        /** @var array<int, array<int, true>> $successors index -> indices qui doivent passer apres */
        $successors = [];
        /** @var array<int, int> $inDegree */
        $inDegree = array_fill_keys(array_keys($systems), 0);
        /** @var array<int, array{int, string}> $cause index du lecteur -> [index de l'ecrivain, composant] */
        $cause = [];

        foreach ($systems as $readerIndex => $reader) {
            foreach ($reader->reads() as $component) {
                foreach ($writers[$component] ?? [] as $writerIndex) {
                    // Une arete reflexive ne contraint rien : un systeme qui
                    // lit ce qu'il ecrit (`FacilitiesSystem` sur `Facilities`,
                    // `CompetitionSystem` sur `Standings`) ne peut pas passer
                    // avant lui-meme.
                    if ($writerIndex === $readerIndex || isset($successors[$writerIndex][$readerIndex])) {
                        continue;
                    }

                    $successors[$writerIndex][$readerIndex] = true;
                    $inDegree[$readerIndex]++;
                    $cause[$readerIndex] ??= [$writerIndex, $component];
                }
            }
        }

        return self::kahn($systems, $successors, $inDegree, $cause);
    }

    /**
     * `creates()` est volontairement absent : un createur ne pose ses
     * composants que sur une entite qui n'existait pas quand le lecteur a
     * itere, il ne peut donc pas invalider une lecture deja faite. Cette
     * restriction n'est plus declarative depuis que `SystemContext` la
     * verifie a l'execution.
     *
     * @param list<System> $systems
     * @return array<class-string, list<int>>
     */
    private static function writersByComponent(array $systems): array
    {
        $writers = [];

        foreach ($systems as $index => $system) {
            foreach ([...$system->writes(), ...$system->removes()] as $component) {
                $writers[$component][] = $index;
            }
        }

        return $writers;
    }

    /**
     * @param list<System> $systems
     * @param array<int, array<int, true>> $successors
     * @param array<int, int> $inDegree
     * @param array<int, array{int, string}> $cause
     * @return list<System>
     */
    private static function kahn(array $systems, array $successors, array $inDegree, array $cause): array
    {
        $sorted = [];
        $placed = [];

        while (count($sorted) < count($systems)) {
            $next = null;

            // Premier systeme pret dans l'ordre declare : c'est ce simple
            // parcours croissant qui rend le tri stable.
            foreach (array_keys($systems) as $index) {
                if (!isset($placed[$index]) && $inDegree[$index] === 0) {
                    $next = $index;
                    break;
                }
            }

            if ($next === null) {
                throw self::cycle($systems, $inDegree, $placed, $cause);
            }

            $sorted[] = $systems[$next];
            $placed[$next] = true;

            foreach (array_keys($successors[$next] ?? []) as $successor) {
                $inDegree[$successor]--;
            }
        }

        return $sorted;
    }

    /**
     * @param list<System> $systems
     * @param array<int, int> $inDegree
     * @param array<int, true> $placed
     * @param array<int, array{int, string}> $cause
     */
    private static function cycle(array $systems, array $inDegree, array $placed, array $cause): PipelineCycleException
    {
        $remaining = [];
        foreach ($systems as $index => $system) {
            if (!isset($placed[$index])) {
                $remaining[] = $system->id();
            }
        }

        // Une arete concrete parmi celles qui bloquent, pour que le message
        // montre un exemple au lieu d'une liste abstraite.
        foreach (array_keys($systems) as $index) {
            if (isset($placed[$index]) || $inDegree[$index] === 0 || !isset($cause[$index])) {
                continue;
            }

            [$writerIndex, $component] = $cause[$index];

            return PipelineCycleException::among(
                $remaining,
                $systems[$writerIndex]->id(),
                $systems[$index]->id(),
                $component,
            );
        }

        return PipelineCycleException::among($remaining, '?', '?', '?');
    }
}
