<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Evidences\EvidenceController;

foreach (['trees' => 'tree', 'inspections' => 'inspection', 'maintenances' => 'maintenance'] as $owners => $parameter) {
    Route::get('/'.$owners.'/{'.$parameter.'}/evidences', [EvidenceController::class, 'index'])->whereUuid($parameter)->name($owners.'.evidences.index');
    Route::get('/'.$owners.'/{'.$parameter.'}/evidences/create', [EvidenceController::class, 'create'])->whereUuid($parameter)->name($owners.'.evidences.create');
    Route::post('/'.$owners.'/{'.$parameter.'}/evidences', [EvidenceController::class, 'store'])->whereUuid($parameter)->middleware('throttle:30,1')->name($owners.'.evidences.store');
}
Route::get('/evidences/{evidence}', [EvidenceController::class, 'show'])->whereUuid('evidence')->name('evidences.show');
Route::put('/evidences/{evidence}', [EvidenceController::class, 'update'])->whereUuid('evidence')->name('evidences.update');
Route::delete('/evidences/{evidence}', [EvidenceController::class, 'destroy'])->whereUuid('evidence')->name('evidences.void');
Route::post('/evidences/{evidence}/restore', [EvidenceController::class, 'restore'])->whereUuid('evidence')->name('evidences.restore');
Route::get('/evidences/{evidence}/view', [EvidenceController::class, 'view'])->whereUuid('evidence')->name('evidences.view');
Route::get('/evidences/{evidence}/download', [EvidenceController::class, 'download'])->whereUuid('evidence')->name('evidences.download');
