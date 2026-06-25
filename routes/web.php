<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\CabinetController;
use App\Http\Controllers\HouseController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MapLayerController;
use App\Http\Controllers\OdfController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RouteController;
use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/mapa')->name('dashboard');
Route::get('/mapa', [MapController::class, 'map'])->name('map.dashboard');
Route::redirect('/mapa/editor', '/')->name('map.index');
Route::post('/mapa/plan', [MapController::class, 'storePlan'])->name('map.plan.store');
Route::post('/mapa/draft', [MapController::class, 'storeDraft'])->name('map.draft.store');
Route::post('/mapa/sugestije', [CabinetController::class, 'storeSuggestedCabinets'])->name('map.suggestions.store');
Route::get('/izvjestaji', [ReportController::class, 'reports'])->name('reports.index');
Route::post('/izvjestaji/projekti/{project}/stavke-priloga', [ReportController::class, 'storeAppendixItem'])->name('reports.appendix-items.store');
Route::delete('/izvjestaji/stavke-priloga/{item}', [ReportController::class, 'deleteAppendixItem'])->name('reports.appendix-items.delete');
Route::get('/izvjestaji/projekti/{project}/prilog-3', [ReportController::class, 'projectAppendix'])->name('reports.project-appendix');
Route::get('/splitteri', [ReportController::class, 'splitters'])->name('splitters.index');
Route::get('/fiber-sema', [ReportController::class, 'fiberSchema'])->name('fiber-schema.index');
Route::get('/projekti/{project}/fiber-sema/pdf', [ReportController::class, 'fiberSchemaPdf'])->name('projects.fiber-schema-pdf');
Route::get('/krakovi', [BranchController::class, 'branches'])->name('branches.index');
Route::post('/krakovi', [BranchController::class, 'storeBranch'])->name('branches.store');
Route::patch('/krakovi/reorder', [BranchController::class, 'reorderBranches'])->name('branches.reorder');
Route::match(['put', 'patch'], '/krakovi/{id}', [BranchController::class, 'updateBranch'])->name('branches.update');
Route::delete('/krakovi/{id}', [BranchController::class, 'deleteBranch'])->name('branches.delete');
Route::get('/provjera-projekta', [ProjectController::class, 'projectCheck'])->name('project-check.index');
Route::get('/postavke', [ProjectController::class, 'settings'])->name('settings.index');
Route::get('/postavke/backup', [ProjectController::class, 'backup'])->name('settings.backup');

Route::get('/projekti', [ProjectController::class, 'projects'])->name('projects.index');
Route::post('/projekti', [ProjectController::class, 'storeProject'])->name('projects.store');
Route::get('/projekti/{project}/pregled', [ProjectController::class, 'showProject'])->name('projects.show');
Route::match(['put', 'patch'], '/projekti/{id}', [ProjectController::class, 'updateProject'])->name('projects.update');
Route::post('/projekti/{project}/odo-plan/preview', [ProjectController::class, 'previewOdoPlan'])->name('projects.odo-plan.preview');
Route::post('/projekti/{project}/odo-plan/confirm', [ProjectController::class, 'confirmOdoPlan'])->name('projects.odo-plan.confirm');
Route::get('/projekti/{project}/validacija', [ProjectController::class, 'validateProject'])->name('projects.validation');
Route::post('/projekti/{project}/drop-trase/popuni', [ProjectController::class, 'createMissingDropRoutes'])->name('projects.drop-routes.fill');
Route::get('/projekti/{project}/geojson', [ProjectController::class, 'exportGeoJson'])->name('projects.geojson');
Route::post('/projekti/{project}/dxf', [ProjectController::class, 'exportDxf'])->name('projects.dxf');
Route::get('/projekti/{project}/fiber-schema-dxf', [ProjectController::class, 'exportFiberSchema'])->name('projects.fiber-schema-dxf');
Route::get('/projekti/{project}/print', [ProjectController::class, 'printProject'])->name('projects.print');
Route::delete('/projekti/{id}', [ProjectController::class, 'deleteProject'])->name('projects.delete');

Route::get('/odf', [OdfController::class, 'odfs'])->name('odfs.index');
Route::post('/odf', [OdfController::class, 'storeOdf'])->name('odfs.store');
Route::match(['put', 'patch'], '/odf/{id}', [OdfController::class, 'updateOdf'])->name('odfs.update');
Route::patch('/odf/{id}/pozicija', [OdfController::class, 'updateOdfPosition'])->name('odfs.position.update');
Route::delete('/odf/{id}', [OdfController::class, 'deleteOdf'])->name('odfs.delete');

Route::get('/ormarici', [CabinetController::class, 'cabinets'])->name('cabinets.index');
Route::post('/ormarici', [CabinetController::class, 'storeCabinet'])->name('cabinets.store');
Route::match(['put', 'patch'], '/ormarici/{id}', [CabinetController::class, 'updateCabinet'])->name('cabinets.update');
Route::patch('/ormarici/{id}/pozicija', [CabinetController::class, 'updateCabinetPosition'])->name('cabinets.position.update');
Route::delete('/ormarici/{id}', [CabinetController::class, 'deleteCabinet'])->name('cabinets.delete');

Route::get('/kuce', [HouseController::class, 'houses'])->name('houses.index');
Route::post('/kuce', [HouseController::class, 'storeHouse'])->name('houses.store');
Route::match(['put', 'patch'], '/kuce/{id}', [HouseController::class, 'updateHouse'])->name('houses.update');
Route::patch('/kuce/{id}/pozicija', [HouseController::class, 'updateHousePosition'])->name('houses.position.update');
Route::post('/ormarici/{id}/povezi-kuce', [HouseController::class, 'connectCabinetHouses'])->name('cabinets.houses.connect');
Route::delete('/kuce/{id}', [HouseController::class, 'deleteHouse'])->name('houses.delete');

Route::get('/trase', [RouteController::class, 'routes'])->name('routes.index');
Route::post('/trase', [RouteController::class, 'storeRoute'])->name('routes.store');
Route::match(['put', 'patch'], '/trase/{id}', [RouteController::class, 'updateRoute'])->name('routes.update');
Route::patch('/trase/{id}/geometrija', [RouteController::class, 'updateRouteGeometry'])->name('routes.geometry.update');
Route::post('/trase/{id}/split', [RouteController::class, 'splitRoute'])->name('routes.split');
Route::post('/trase/{id}/join', [RouteController::class, 'joinRoutes'])->name('routes.join.multiple');
Route::post('/trase/{id}/join/{otherId}', [RouteController::class, 'joinRoutes'])->name('routes.join');
Route::post('/trase/dxf', [RouteController::class, 'importDxf'])->name('routes.dxf.import');
Route::delete('/trase/{id}', [RouteController::class, 'deleteRoute'])->name('routes.delete');

Route::post('/mapa/dxf-layer', [MapLayerController::class, 'upload'])->name('map.dxf-layer.upload');

Route::get('/api/notifications', function () {
    $unlinkedHouses = House::whereNull('cabinet_id')->count();
    $unlinkedCabinets = Cabinet::whereNull('odf_id')->count();
    $incompleteRoutes = NetworkRoute::where('route_type', '!=', 'trench')->where(function ($q) {
        $q->whereNull('microduct_type')->orWhereNull('fiber_count');
    })->count();
    $items = [];
    if ($unlinkedHouses) {
        $items[] = "$unlinkedHouses kuca nema dodijeljeni ODO.";
    }
    if ($unlinkedCabinets) {
        $items[] = "$unlinkedCabinets ODO ormarica nema povezani ODF.";
    }
    if ($incompleteRoutes) {
        $items[] = "$incompleteRoutes trasa nema kompletne tehnicke podatke.";
    }

    return response()->json(['count' => count($items), 'items' => $items]);
})->name('api.notifications');
