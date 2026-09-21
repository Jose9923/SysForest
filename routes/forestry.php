<?php

declare(strict_types=1);

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

// La fotografía se entrega únicamente después de comprobar sesión, tenant y propietario.
foreach (['trees' => 'tree', 'inspections' => 'inspection', 'maintenances' => 'maintenance'] as $owners => $parameter) {
    Route::get('/'.$owners.'/{'.$parameter.'}/evidences', [C\EvidenceController::class, 'index'])->whereUuid($parameter)->name($owners.'.evidences.index');
    Route::get('/'.$owners.'/{'.$parameter.'}/evidences/create', [C\EvidenceController::class, 'create'])->whereUuid($parameter)->name($owners.'.evidences.create');
    Route::post('/'.$owners.'/{'.$parameter.'}/evidences', [C\EvidenceController::class, 'store'])->whereUuid($parameter)->middleware('throttle:30,1')->name($owners.'.evidences.store');
}
Route::get('/evidences/{evidence}', [C\EvidenceController::class, 'show'])->whereUuid('evidence')->name('evidences.show');
Route::put('/evidences/{evidence}', [C\EvidenceController::class, 'update'])->whereUuid('evidence')->name('evidences.update');
Route::delete('/evidences/{evidence}', [C\EvidenceController::class, 'destroy'])->whereUuid('evidence')->name('evidences.void');
Route::post('/evidences/{evidence}/restore', [C\EvidenceController::class, 'restore'])->whereUuid('evidence')->name('evidences.restore');
Route::get('/evidences/{evidence}/view', [C\EvidenceController::class, 'view'])->whereUuid('evidence')->name('evidences.view');
Route::get('/evidences/{evidence}/download', [C\EvidenceController::class, 'download'])->whereUuid('evidence')->name('evidences.download');


// Las rutas reciben UUID como texto: el Service resuelve primero por tenant y luego aplica Policy.
Route::get('/inspections', [C\TreeInspectionController::class, 'index'])->name('inspections.index');
Route::get('/trees/{tree}/inspections/create', [C\TreeInspectionController::class, 'create'])->whereUuid('tree')->name('trees.inspections.create');
Route::post('/trees/{tree}/inspections', [C\TreeInspectionController::class, 'store'])->whereUuid('tree')->name('trees.inspections.store');
Route::get('/inspections/{inspection}', [C\TreeInspectionController::class, 'show'])->whereUuid('inspection')->name('inspections.show');
Route::get('/inspections/{inspection}/edit', [C\TreeInspectionController::class, 'edit'])->whereUuid('inspection')->name('inspections.edit');
Route::put('/inspections/{inspection}', [C\TreeInspectionController::class, 'update'])->whereUuid('inspection')->name('inspections.update');
Route::post('/dendrometry/preview', C\DendrometryPreviewController::class)->middleware('throttle:60,1')->name('dendrometry.preview');
Route::get('/trees', [C\TreeController::class, 'index'])->name('trees.index');
Route::get('/trees/create', [C\TreeController::class, 'create'])->name('trees.create');
Route::post('/trees', [C\TreeController::class, 'store'])->name('trees.store');
Route::get('/trees/{tree}', [C\TreeController::class, 'show'])->whereUuid('tree')->name('trees.show');
Route::get('/trees/{tree}/edit', [C\TreeController::class, 'edit'])->whereUuid('tree')->name('trees.edit');
Route::put('/trees/{tree}', [C\TreeController::class, 'update'])->whereUuid('tree')->name('trees.update');
Route::patch('/trees/{tree}/status', C\TreeStatusController::class)->whereUuid('tree')->name('trees.status');
Route::patch('/trees/{tree}/location', C\TreeLocationController::class)->whereUuid('tree')->name('trees.location');
Route::delete('/trees/{tree}', C\TreeVoidController::class)->whereUuid('tree')->name('trees.void');
Route::post('/trees/{tree}/restore', C\TreeRestoreController::class)->whereUuid('tree')->name('trees.restore');
Route::post('/trees/{tree}/inventories', C\TreeInventoryController::class)->whereUuid('tree')->name('trees.inventories');
Route::post('/coordinates/preview', C\CoordinatePreviewController::class)->middleware('throttle:60,1')->name('coordinates.preview');
Route::get('/inventories', [C\InventoryController::class, 'index'])->name('inventories.index');
Route::get('/inventories/create', [C\InventoryController::class, 'create'])->name('inventories.create');
Route::post('/inventories', [C\InventoryController::class, 'store'])->name('inventories.store');
Route::get('/inventories/{inventory}', [C\InventoryController::class, 'show'])->whereUuid('inventory')->name('inventories.show');
Route::get('/inventories/{inventory}/edit', [C\InventoryController::class, 'edit'])->whereUuid('inventory')->name('inventories.edit');
Route::put('/inventories/{inventory}', [C\InventoryController::class, 'update'])->whereUuid('inventory')->name('inventories.update');

// Las órdenes de trabajo pertenecen al árbol y conservan sus estados e historial.
Route::get('/maintenances', [C\MaintenanceController::class, 'index'])->name('maintenances.index');
Route::get('/trees/{tree}/maintenances/create', [C\MaintenanceController::class, 'create'])->whereUuid('tree')->name('trees.maintenances.create');
Route::post('/trees/{tree}/maintenances', [C\MaintenanceController::class, 'store'])->whereUuid('tree')->name('trees.maintenances.store');
Route::get('/maintenances/{maintenance}', [C\MaintenanceController::class, 'show'])->whereUuid('maintenance')->name('maintenances.show');
Route::get('/maintenances/{maintenance}/edit', [C\MaintenanceController::class, 'edit'])->whereUuid('maintenance')->name('maintenances.edit');
Route::put('/maintenances/{maintenance}', [C\MaintenanceController::class, 'update'])->whereUuid('maintenance')->name('maintenances.update');
Route::patch('/maintenances/{maintenance}/status', [C\MaintenanceController::class, 'transition'])->whereUuid('maintenance')->name('maintenances.status');
Route::delete('/maintenances/{maintenance}', [C\MaintenanceController::class, 'destroy'])->whereUuid('maintenance')->name('maintenances.void');
Route::post('/maintenances/{maintenance}/restore', [C\MaintenanceController::class, 'restore'])->whereUuid('maintenance')->name('maintenances.restore');
