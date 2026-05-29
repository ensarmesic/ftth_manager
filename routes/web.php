<?php

use App\Http\Controllers\FtthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FtthController::class, 'dashboard'])->name('dashboard');
Route::get('/mapa', [FtthController::class, 'map'])->name('map.index');
Route::post('/mapa/plan', [FtthController::class, 'storePlan'])->name('map.plan.store');
Route::post('/mapa/draft', [FtthController::class, 'storeDraft'])->name('map.draft.store');
Route::post('/mapa/sugestije', [FtthController::class, 'storeSuggestedCabinets'])->name('map.suggestions.store');
Route::get('/izvjestaji', [FtthController::class, 'reports'])->name('reports.index');

Route::get('/projekti', [FtthController::class, 'projects'])->name('projects.index');
Route::post('/projekti', [FtthController::class, 'storeProject'])->name('projects.store');
Route::delete('/projekti/{id}', [FtthController::class, 'deleteProject'])->name('projects.delete');

Route::get('/odf', [FtthController::class, 'odfs'])->name('odfs.index');
Route::post('/odf', [FtthController::class, 'storeOdf'])->name('odfs.store');
Route::delete('/odf/{id}', [FtthController::class, 'deleteOdf'])->name('odfs.delete');

Route::get('/ormarici', [FtthController::class, 'cabinets'])->name('cabinets.index');
Route::post('/ormarici', [FtthController::class, 'storeCabinet'])->name('cabinets.store');
Route::delete('/ormarici/{id}', [FtthController::class, 'deleteCabinet'])->name('cabinets.delete');

Route::post('/kuce', [FtthController::class, 'storeHouse'])->name('houses.store');
Route::delete('/kuce/{id}', [FtthController::class, 'deleteHouse'])->name('houses.delete');

Route::get('/korisnici', [FtthController::class, 'subscribers'])->name('subscribers.index');
Route::post('/korisnici', [FtthController::class, 'storeSubscriber'])->name('subscribers.store');
Route::delete('/korisnici/{id}', [FtthController::class, 'deleteSubscriber'])->name('subscribers.delete');

Route::get('/trase', [FtthController::class, 'routes'])->name('routes.index');
Route::post('/trase', [FtthController::class, 'storeRoute'])->name('routes.store');
Route::delete('/trase/{id}', [FtthController::class, 'deleteRoute'])->name('routes.delete');

Route::get('/materijali', [FtthController::class, 'materials'])->name('materials.index');
Route::post('/materijali', [FtthController::class, 'storeMaterial'])->name('materials.store');
Route::delete('/materijali/{id}', [FtthController::class, 'deleteMaterial'])->name('materials.delete');
