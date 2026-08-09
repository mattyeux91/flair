<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Flair\Api\Read\View\WorldSummaryView;
use Flair\Api\Read\WorldListReader;
use Flair\Api\Read\WorldReader;
use Flair\Api\Read\WorldSummaryReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * L'index des mondes, et le resume d'un monde.
 *
 * ## Deux presentations, une seule source
 *
 * `show()` rend du HTML, `showJson()` rend du JSON, et tous deux passent par
 * `summary()` - la meme methode, le meme DTO. C'est ce qui rend tenable la
 * decision d'avoir **un seul paquet** qui sert l'admin et l'API (docs/11- §7,
 * ecart assume) : ce n'est pas la separation en paquets qui empeche les deux
 * presentations de diverger, c'est qu'elles n'ont qu'une source. Un test le
 * verifie chiffre par chiffre plutot que de faire confiance a cette phrase.
 *
 * Aucune requete n'est faite ici : un controleur ne lit ni la base ni un
 * snapshot, il assemble. Toute lecture vit dans `Flair\Api\Read\`.
 */
final class WorldController extends Controller
{
    public function __construct(
        private readonly WorldListReader $worlds,
        private readonly WorldReader $reader,
        private readonly WorldSummaryReader $summaries,
    ) {
    }

    public function index(): View
    {
        return view('worlds.index', ['worlds' => $this->worlds->read()]);
    }

    public function indexJson(): JsonResponse
    {
        return response()->json($this->worlds->read());
    }

    public function show(string $world): View
    {
        return view('worlds.show', ['world' => $this->summary($world)]);
    }

    public function showJson(string $world): JsonResponse
    {
        return response()->json($this->summary($world));
    }

    private function summary(string $worldId): WorldSummaryView
    {
        $world = $this->reader->load($worldId);

        if ($world === null) {
            abort(404, "Monde \"{$worldId}\" inconnu, ou sans snapshot pour le reconstituer.");
        }

        return $this->summaries->read($world);
    }
}
