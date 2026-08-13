<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CabinetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\DropRouteMaintenanceController;
use App\Http\Controllers\GisController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HouseController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MapLayerController;
use App\Http\Controllers\MissingDropRouteController;
use App\Http\Controllers\OdfController;
use App\Http\Controllers\ProjectExportController;
use App\Http\Controllers\ProjectGeoJsonController;
use App\Http\Controllers\ProjectManagementController;
use App\Http\Controllers\ProjectMaterialController;
use App\Http\Controllers\ProjectPlanningController;
use App\Http\Controllers\ProjectPrintController;
use App\Http\Controllers\ProjectSettingsController;
use App\Http\Controllers\ProjectSnapshotController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SurveyPointController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/prijava', [LoginController::class, 'create'])->name('login');
    Route::post('/prijava', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/sistem/health', HealthController::class)->name('system.health');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/uputstvo/{document?}', DocumentationController::class)->name('documentation');
    Route::get('/mapa', [MapController::class, 'map'])->name('map.dashboard');
    Route::redirect('/mapa/editor', '/mapa')->name('map.index');
    Route::post('/mapa/plan', [MapController::class, 'storePlan'])->name('map.plan.store');
    Route::post('/mapa/draft', [MapController::class, 'storeDraft'])->name('map.draft.store');
    Route::get('/mapa/auto-route', [MapController::class, 'autoRoute'])->name('map.auto-route');
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
    Route::get('/provjera-projekta', [ProjectSettingsController::class, 'projectCheck'])->name('project-check.index');
    Route::get('/postavke', [ProjectSettingsController::class, 'settings'])->name('settings.index');
    Route::get('/postavke/backup', [ProjectSettingsController::class, 'backup'])->name('settings.backup');
    Route::post('/postavke/gis/import', [GisController::class, 'import'])->name('gis.import');
    Route::get('/postavke/gis/{project}/slojevi', [GisController::class, 'layers'])->name('gis.layers');
    Route::delete('/postavke/gis/{project}/slojevi/{type}', [GisController::class, 'destroyLayer'])->name('gis.layers.destroy');

    Route::get('/projekti', [ProjectManagementController::class, 'index'])->name('projects.index');
    Route::post('/projekti', [ProjectManagementController::class, 'store'])->name('projects.store');
    Route::get('/projekti/{project}/pregled', [ProjectManagementController::class, 'show'])->name('projects.show');
    Route::match(['put', 'patch'], '/projekti/{id}', [ProjectManagementController::class, 'update'])->name('projects.update');
    Route::post('/projekti/{project}/odo-plan/preview', [ProjectPlanningController::class, 'previewOdo'])->name('projects.odo-plan.preview');
    Route::get('/projekti/{project}/gis-plan/preview', [ProjectPlanningController::class, 'previewGis'])->name('projects.gis-plan.preview');
    Route::post('/projekti/{project}/gis-plan/confirm', [ProjectPlanningController::class, 'confirmGis'])->name('projects.gis-plan.confirm');
    Route::post('/projekti/{project}/odo-plan/confirm', [ProjectPlanningController::class, 'confirmOdo'])->name('projects.odo-plan.confirm');
    Route::get('/projekti/{project}/validacija', [ProjectPlanningController::class, 'validateProject'])->name('projects.validation');
    Route::get('/projekti/{project}/snapshoti', [ProjectSnapshotController::class, 'index'])->name('projects.snapshots.index');
    Route::post('/projekti/{project}/snapshoti', [ProjectSnapshotController::class, 'store'])->name('projects.snapshots.store');
    Route::post('/projekti/{project}/snapshoti/{snapshot}/vrati', [ProjectSnapshotController::class, 'restore'])->name('projects.snapshots.restore');
    Route::get('/projekti/{project}/snapshoti/{snapshot}/download', [ProjectSnapshotController::class, 'download'])->name('projects.snapshots.download');
    Route::post('/projekti/{project}/materijali/izracunaj', ProjectMaterialController::class)->name('materials.calculate');
    Route::post('/projekti/{project}/drop-trase/popuni', MissingDropRouteController::class)->name('projects.drop-routes.fill');
    Route::get('/projekti/{project}/drop-trase/audit', [DropRouteMaintenanceController::class, 'audit'])->name('projects.drop-routes.audit');
    Route::post('/projekti/{project}/drop-trase/popravi', [DropRouteMaintenanceController::class, 'repair'])->name('projects.drop-routes.repair');
    Route::post('/projekti/{project}/tacke/preview', [SurveyPointController::class, 'preview'])->name('projects.survey-points.preview');
    Route::post('/projekti/{project}/tacke/import', [SurveyPointController::class, 'import'])->name('projects.survey-points.import');
    Route::get('/projekti/{project}/tacke/importi', [SurveyPointController::class, 'imports'])->name('projects.survey-points.imports');
    Route::delete('/projekti/{project}/tacke/importi/{batch}', [SurveyPointController::class, 'destroyImport'])->where('batch', '[a-f0-9]{40}')->name('projects.survey-points.imports.destroy');
    Route::post('/projekti/{project}/teren/tacke', [SurveyPointController::class, 'storeFieldPoint'])->name('projects.field-points.store');
    Route::get('/projekti/{project}/teren/tacke/{point}/fotografija', [SurveyPointController::class, 'fieldPointPhoto'])->name('projects.field-points.photo');
    Route::delete('/projekti/{project}/tacke', [SurveyPointController::class, 'destroy'])->name('projects.survey-points.destroy');
    Route::get('/projekti/{project}/geojson', ProjectGeoJsonController::class)->name('projects.geojson');
    Route::post('/projekti/{project}/dxf', [ProjectExportController::class, 'exportDxf'])->name('projects.dxf');
    Route::get('/projekti/{project}/fiber-schema-dxf', [ProjectExportController::class, 'exportFiberSchema'])->name('projects.fiber-schema-dxf');
    Route::get('/projekti/{project}/backup', [ProjectExportController::class, 'backup'])->name('projects.backup');
    Route::post('/projekti/restore', [ProjectExportController::class, 'restore'])->name('projects.restore');
    Route::get('/projekti/{project}/print', ProjectPrintController::class)->name('projects.print');
    Route::delete('/projekti/{id}', [ProjectManagementController::class, 'destroy'])->name('projects.delete');

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

    Route::get('/api/notifications', [DashboardController::class, 'notifications'])->name('api.notifications');
    Route::post('/odjava', [LoginController::class, 'destroy'])->name('logout');
    Route::put('/postavke/lozinka', [PasswordController::class, 'update'])->name('password.update');
});
