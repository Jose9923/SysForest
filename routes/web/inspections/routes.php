<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Inspections\TreeInspectionController;

Route::get('/inspections', [TreeInspectionController::class, 'index'])->name('inspections.index');
Route::get('/trees/{tree}/inspections/create', [TreeInspectionController::class, 'create'])->whereUuid('tree')->name('trees.inspections.create');
Route::post('/trees/{tree}/inspections', [TreeInspectionController::class, 'store'])->whereUuid('tree')->name('trees.inspections.store');
Route::get('/inspections/{inspection}', [TreeInspectionController::class, 'show'])->whereUuid('inspection')->name('inspections.show');
Route::get('/inspections/{inspection}/edit', [TreeInspectionController::class, 'edit'])->whereUuid('inspection')->name('inspections.edit');
Route::put('/inspections/{inspection}', [TreeInspectionController::class, 'update'])->whereUuid('inspection')->name('inspections.update');
