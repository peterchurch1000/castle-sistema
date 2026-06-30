<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SalesM2Controller;
use App\Http\Controllers\OperacionesController;

Route::get('/', [DashboardController::class, 'index']);
Route::get('/ventas', [DashboardController::class, 'index']);
Route::post('/refresh', [DashboardController::class, 'refresh']);
Route::post('/refresh-activities', [DashboardController::class, 'refreshActivities']);
Route::post('/refresh-quotas', [DashboardController::class, 'refreshQuotas']);

Route::get('/operaciones', [OperacionesController::class, 'index']);

Route::get('/ventas-m2', [SalesM2Controller::class, 'index']);
Route::post('/refresh-ventas-m2', [SalesM2Controller::class, 'refresh']);
