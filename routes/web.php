<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SalesM2Controller;
use App\Http\Controllers\OperacionesController;
use App\Http\Controllers\QuoteController;

Route::get('/', [DashboardController::class, 'index']);
Route::get('/ventas', [DashboardController::class, 'index']);
Route::post('/refresh', [DashboardController::class, 'refresh']);
Route::post('/refresh-activities', [DashboardController::class, 'refreshActivities']);
Route::post('/refresh-quotas', [DashboardController::class, 'refreshQuotas']);

Route::get('/operaciones', [OperacionesController::class, 'index']);

Route::get('/ventas-m2', [SalesM2Controller::class, 'index']);
Route::post('/refresh-ventas-m2', [SalesM2Controller::class, 'refresh']);

Route::get('/cotizar', [QuoteController::class, 'create']);
Route::post('/cotizar', [QuoteController::class, 'store']);
Route::get('/cotizaciones', [QuoteController::class, 'index']);
Route::get('/cotizaciones/{quote}/archivo', [QuoteController::class, 'download']);

// --- Form-based login (replaces native Basic-auth popup; header auth still works) ---
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function (\Illuminate\Http\Request $request) {
    $data = $request->validate([
        'username' => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);
    $user = (string) config('dashboard.auth_user');
    $pass = (string) config('dashboard.auth_pass');
    if (hash_equals($user, $data['username']) && hash_equals($pass, $data['password'])) {
        $request->session()->regenerate();
        $request->session()->put('castle_authed', true);
        return redirect()->intended('/ventas');
    }
    return back()->withErrors(['password' => 'Usuario o contraseña incorrectos.'])->onlyInput('username');
});

Route::get('/logout', function (\Illuminate\Http\Request $request) {
    $request->session()->forget('castle_authed');
    $request->session()->regenerate();
    return redirect('/login');
});
