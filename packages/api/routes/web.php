<?php

declare(strict_types=1);

use App\Http\Controllers\ClubController;
use App\Http\Controllers\WorldController;
use Illuminate\Support\Facades\Route;

/*
 * La surface d'administration : explorer un monde depuis un navigateur.
 *
 * Trois routes, et une seule est un vrai ecran - la fiche d'un club. Les deux
 * autres sont le minimum pour y arriver.
 *
 * `{world}` est un identifiant de monde (`worlds.id`), pas un entier : un monde
 * s'appelle `dix-ans`, `alpha`, `bench`. `{club}` est un `EntityId`, donc
 * borne aux chiffres pour qu'une URL malformee rende 404 au routage plutot
 * qu'un `(int)` silencieux dans le controleur.
 */
Route::get('/', [WorldController::class, 'index'])->name('worlds.index');
Route::get('/worlds/{world}', [WorldController::class, 'show'])->name('worlds.show');
Route::get('/worlds/{world}/clubs/{club}', [ClubController::class, 'show'])
    ->whereNumber('club')
    ->name('clubs.show');
Route::get('/worlds/{world}/clubs/{club}/history', [ClubController::class, 'history'])
    ->whereNumber('club')
    ->name('clubs.history');

/*
 * Le digest de retour d'absence (docs/14- §9). `?days=` ouvre ou resserre la
 * fenetre, 90 jours par defaut - l'enonce meme du critere de sortie de la
 * phase. Ce n'est pas un confort de developpement : la densite des Faits varie
 * d'un facteur ~30 selon l'endroit de la saison ou la fenetre tombe (mesure sur
 * le monde de reference : ~180 Faits au mois du mercato, ~1 sur le dernier mois
 * de l'intersaison), et un digest se juge sur une fenetre representative.
 */
Route::get('/worlds/{world}/clubs/{club}/digest', [ClubController::class, 'digest'])
    ->whereNumber('club')
    ->name('clubs.digest');
