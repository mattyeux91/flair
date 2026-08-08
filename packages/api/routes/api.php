<?php

declare(strict_types=1);

use App\Http\Controllers\ClubController;
use App\Http\Controllers\WorldController;
use Illuminate\Support\Facades\Route;

/*
 * Les memes DTO, en JSON. Ce n'est pas un bonus : c'est ce qui rend **testable**
 * la discipline « une seule couche de lecture ». Un test compare chiffre par
 * chiffre ce que rend une page a ce que rend sa route JSON ; sans ces routes il
 * faudrait croire sur parole que l'admin et l'API lisent la meme chose.
 *
 * C'est aussi la surface que `game-web` consommera (docs/11- §7, HTTP
 * uniquement). Le jour venu, elle devra passer par un point de vue - voir
 * `Flair\Api\Read\ClubSheetReader::qualityOf()`.
 */
Route::get('/worlds', [WorldController::class, 'indexJson']);
Route::get('/worlds/{world}', [WorldController::class, 'showJson']);
Route::get('/worlds/{world}/clubs/{club}', [ClubController::class, 'showJson'])->whereNumber('club');
Route::get('/worlds/{world}/clubs/{club}/history', [ClubController::class, 'historyJson'])->whereNumber('club');
