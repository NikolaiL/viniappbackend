<?php

use App\Http\Controllers\ViniappController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/verify-hash', [ViniappController::class, 'verifyHash']);
Route::post('/verify-hash', [ViniappController::class, 'verifyHash']);

Route::post('/create-viniapp', [ViniappController::class, 'createNewViniapp']);

Route::get('test-create-viniapp', function (ViniappController $viniappController) {
    $request = new Request();
    $request->setBody(json_encode([
        'transaction_hash' => '0x1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
        'msg_sender' => '0x1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
        'name' => 'Test Viniapp',
        'prompt' => 'This is a test prompt',
        'logo_image' => 'https://example.com/logo.png',
    ]));
    return $viniappController->createNewViniapp($request);
});
