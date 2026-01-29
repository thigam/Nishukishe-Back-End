<?php

use Illuminate\Support\Facades\Route;
// use Illuminate\Http\Request;
// use App\Services\MpesaService;
use App\Http\Controllers\MpesaController;


// Route::post('/mpesa/confirmation', function (Request $request, MpesaService $service) {
//     $service->handleConfirmation($request->all());
//     return response()->json(['status' => 'success']);
// });

Route::post('/stk/push', [MpesaController::class, 'stkPush']);
Route::post('/mpesa/callback', [MpesaController::class, 'callback']);   // Public endpoint for Safaricom
Route::get('/stk/status/{checkoutRequestId}', [MpesaController::class, 'status']);
Route::post('/mpesaCallBack', [MpesaController::class, 'callback'])->name('mpesa.callback');


Route::get('/getToken', [MpesaController::class, 'generateToken']);

Route::post('/b2c/payment', [MpesaController::class,'b2cPayment'])->name('mpesa.b2c.payment');
Route::post('/b2c/callback', [MpesaController::class,'b2cCallback'])->name('mpesa.b2c.callback');
Route::post('/b2c/timeout', [MpesaController::class,'b2cTimeout'])->name('mpesa.b2c.timeout');
Route::post('/mpesa/cost', [MpesaController::class, 'showCost'])->name('mpesa.cost');

