<?php

use App\Services\PrivyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

Route::get('/', function () {
    return view('welcome');
});

Route::get('hello', function () {
    return 'Hello World';
});

Route::get('test/create-wallet', function (Request $request, PrivyService $privyService) {
    try {
        // Get address from query parameter
        $address = $request->query('address');
        
        if (!$address) {
            return response()->json([
                'error' => 'address query parameter is required',
                'example' => '/test/create-wallet?address=0x1234567890abcdef...',
            ], 400);
        }

        // Create wallet with authorization key owner and msg.sender as additional signer
        $wallet = $privyService->createWalletWithAuthorizationKeyOwner($address, 'ethereum');

        return response()->json([
            'success' => true,
            'wallet' => $wallet,
        ]);
    } catch (\Exception $e) {
        Log::error('Test wallet creation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'address' => $request->query('address'),
        ]);

        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});


 
