<?php

use App\Http\Controllers\FtthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FtthController::class, 'dashboard'])->name('dashboard');
Route::get('/mapa', [FtthController::class, 'map'])->name('map.index');
Route::post('/mapa/plan', [FtthController::class, 'storePlan'])->name('map.plan.store');
Route::post('/mapa/draft', [FtthController::class, 'storeDraft'])->name('map.draft.store');
Route::get('/izvjestaji', [FtthController::class, 'reports'])->name('reports.index');
Route::get('/projekti', [FtthController::class, 'projects'])->name('projects.index');
Route::post('/projekti', [FtthController::class, 'storeProject'])->name('projects.store');
Route::get('/odf', [FtthController::class, 'odfs'])->name('odfs.index');
Route::post('/odf', [FtthController::class, 'storeOdf'])->name('odfs.store');
Route::get('/ormarici', [FtthController::class, 'cabinets'])->name('cabinets.index');
Route::post('/ormarici', [FtthController::class, 'storeCabinet'])->name('cabinets.store');
Route::post('/kuce', [FtthController::class, 'storeHouse'])->name('houses.store');
Route::get('/korisnici', [FtthController::class, 'subscribers'])->name('subscribers.index');
Route::post('/korisnici', [FtthController::class, 'storeSubscriber'])->name('subscribers.store');
Route::get('/trase', [FtthController::class, 'routes'])->name('routes.index');
Route::post('/trase', [FtthController::class, 'storeRoute'])->name('routes.store');
Route::get('/materijali', [FtthController::class, 'materials'])->name('materials.index');
Route::post('/materijali', [FtthController::class, 'storeMaterial'])->name('materials.store');
