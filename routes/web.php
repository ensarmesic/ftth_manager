<?php

use App\Http\Controllers\FtthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FtthController::class, 'dashboard'])->name('dashboard');
Route::get('/mapa', [FtthController::class, 'map'])->name('map.dashboard');
Route::redirect('/mapa/editor', '/')->name('map.index');
Route::post('/mapa/plan', [FtthController::class, 'storePlan'])->name('map.plan.store');
Route::post('/mapa/draft', [FtthController::class, 'storeDraft'])->name('map.draft.store');
Route::post('/mapa/sugestije', [FtthController::class, 'storeSuggestedCabinets'])->name('map.suggestions.store');
Route::get('/izvjestaji', [FtthController::class, 'reports'])->name('reports.index');
Route::get('/splitteri', [FtthController::class, 'splitters'])->name('splitters.index');
Route::get('/fiber-sema', [FtthController::class, 'fiberSchema'])->name('fiber-schema.index');
Route::get('/provjera-projekta', [FtthController::class, 'projectCheck'])->name('project-check.index');
Route::get('/postavke', [FtthController::class, 'settings'])->name('settings.index');

Route::get('/projekti', [FtthController::class, 'projects'])->name('projects.index');
Route::post('/projekti', [FtthController::class, 'storeProject'])->name('projects.store');
Route::match(['put', 'patch'], '/projekti/{id}', [FtthController::class, 'updateProject'])->name('projects.update');
Route::post('/projekti/{project}/odo-plan/preview', [FtthController::class, 'previewOdoPlan'])->name('projects.odo-plan.preview');
Route::post('/projekti/{project}/odo-plan/confirm', [FtthController::class, 'confirmOdoPlan'])->name('projects.odo-plan.confirm');
Route::get('/projekti/{project}/validacija', [FtthController::class, 'validateProject'])->name('projects.validation');
Route::delete('/projekti/{id}', [FtthController::class, 'deleteProject'])->name('projects.delete');

Route::get('/odf', [FtthController::class, 'odfs'])->name('odfs.index');
Route::post('/odf', [FtthController::class, 'storeOdf'])->name('odfs.store');
Route::match(['put', 'patch'], '/odf/{id}', [FtthController::class, 'updateOdf'])->name('odfs.update');
Route::patch('/odf/{id}/pozicija', [FtthController::class, 'updateOdfPosition'])->name('odfs.position.update');
Route::delete('/odf/{id}', [FtthController::class, 'deleteOdf'])->name('odfs.delete');

Route::get('/ormarici', [FtthController::class, 'cabinets'])->name('cabinets.index');
Route::post('/ormarici', [FtthController::class, 'storeCabinet'])->name('cabinets.store');
Route::match(['put', 'patch'], '/ormarici/{id}', [FtthController::class, 'updateCabinet'])->name('cabinets.update');
Route::patch('/ormarici/{id}/pozicija', [FtthController::class, 'updateCabinetPosition'])->name('cabinets.position.update');
Route::delete('/ormarici/{id}', [FtthController::class, 'deleteCabinet'])->name('cabinets.delete');

Route::get('/kuce', [FtthController::class, 'houses'])->name('houses.index');
Route::post('/kuce', [FtthController::class, 'storeHouse'])->name('houses.store');
Route::match(['put', 'patch'], '/kuce/{id}', [FtthController::class, 'updateHouse'])->name('houses.update');
Route::patch('/kuce/{id}/pozicija', [FtthController::class, 'updateHousePosition'])->name('houses.position.update');
Route::post('/ormarici/{id}/povezi-kuce', [FtthController::class, 'connectCabinetHouses'])->name('cabinets.houses.connect');
Route::delete('/kuce/{id}', [FtthController::class, 'deleteHouse'])->name('houses.delete');

Route::get('/korisnici', [FtthController::class, 'subscribers'])->name('subscribers.index');
Route::post('/korisnici', [FtthController::class, 'storeSubscriber'])->name('subscribers.store');
Route::match(['put', 'patch'], '/korisnici/{id}', [FtthController::class, 'updateSubscriber'])->name('subscribers.update');
Route::delete('/korisnici/{id}', [FtthController::class, 'deleteSubscriber'])->name('subscribers.delete');

Route::get('/trase', [FtthController::class, 'routes'])->name('routes.index');
Route::post('/trase', [FtthController::class, 'storeRoute'])->name('routes.store');
Route::match(['put', 'patch'], '/trase/{id}', [FtthController::class, 'updateRoute'])->name('routes.update');
Route::patch('/trase/{id}/geometrija', [FtthController::class, 'updateRouteGeometry'])->name('routes.geometry.update');
Route::post('/trase/{id}/join/{otherId}', [FtthController::class, 'joinRoutes'])->name('routes.join');
Route::post('/trase/dxf', [FtthController::class, 'importDxf'])->name('routes.dxf.import');
Route::delete('/trase/{id}', [FtthController::class, 'deleteRoute'])->name('routes.delete');

Route::get('/materijali', [FtthController::class, 'materials'])->name('materials.index');
Route::post('/materijali', [FtthController::class, 'storeMaterial'])->name('materials.store');
Route::match(['put', 'patch'], '/materijali/{id}', [FtthController::class, 'updateMaterial'])->name('materials.update');
Route::post('/materijali/obracun/{project}', [FtthController::class, 'calculateMaterials'])->name('materials.calculate');
Route::delete('/materijali/{id}', [FtthController::class, 'deleteMaterial'])->name('materials.delete');
