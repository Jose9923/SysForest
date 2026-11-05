<?php

declare(strict_types=1);

use App\Http\Controllers\RiskController;
use Illuminate\Support\Facades\Route;

Route::get('/risk', [RiskController::class, 'index'])->name('risk.index');
Route::get('/risk/trees/{tree}/decision', [RiskController::class, 'decision'])->whereUuid('tree')->name('risk.trees.decision');
Route::patch('/risk/trees/{tree}/decision', [RiskController::class, 'updateDecision'])->whereUuid('tree')->name('risk.trees.decision.update');
Route::get('/risk/methodology', [RiskController::class, 'methodology'])->name('risk.methodology');
Route::post('/risk/methodologies', [RiskController::class, 'store'])->name('risk.store');
Route::post('/trees/first-inspection/risk-preview', [RiskController::class, 'previewRegistration'])->middleware('throttle:60,1')->name('risk.registration-preview');
Route::post('/trees/{tree}/risk-preview', [RiskController::class, 'preview'])->whereUuid('tree')->name('risk.preview');
