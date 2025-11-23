<?php

namespace App\Http\Controllers;

use App\Models\Viniapp;
use App\Services\PrivyService;
use App\Services\BlockchainVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ViniappController extends Controller
{
    protected PrivyService $privyService;
    protected BlockchainVerificationService $blockchainVerificationService;

    public function __construct(
        PrivyService $privyService,
        BlockchainVerificationService $blockchainVerificationService
    ) {
        $this->privyService = $privyService;
        $this->blockchainVerificationService = $blockchainVerificationService;
    }

    public function createNewViniapp(Request $request)
    {
        $transactionHash = $request->input('transaction_hash');
        
        if (!$transactionHash) {
            return response()->json([
                'error' => 'transaction_hash is required in the request',
            ], 400);
        }

        if (Viniapp::where('transaction_hash', $transactionHash)->exists()) {
            return response()->json([
                'error' => 'Transaction hash already exists',
            ], 400);
        }

        // Verify transaction exists on blockchain and is to the correct contract
        $verification = $this->blockchainVerificationService->verifyTransaction($transactionHash);
        
        if (!$verification['valid']) {
            return response()->json([
                'error' => 'Transaction verification failed',
                'message' => $verification['error'] ?? 'Transaction is not valid',
            ], 400);
        }

        $msgSender = $request->input('msg_sender');
        
        if (!$msgSender) {
            return response()->json([
                'error' => 'msg_sender is required in the request',
            ], 400);
        }

        $slug = Str::slug($request->input('name'));
        while (Viniapp::where('slug', $slug)->exists()) {
            $slug = $slug . '-' . Str::random(5);
        }
        
        try {
            // Create the viniapp
            $viniapp = Viniapp::create(
                [
                    'transaction_hash' => $request->input('transaction_hash'),
                    'name' => $request->input('name'),
                    'slug' => $slug,
                    'prompt' => $request->input('prompt'),
                    'logo_image' => $request->input('logo_image'),
                    'created_by' => $msgSender,
                    'owned_by' => $msgSender,
                ]
            );

            // Generate a wallet address for the viniapp using privy
            // Wallet is owned by authorization key (PRIVY_AUTH_ID/AUTH_SECRET)
            // Additional signer is created for msg.sender
            $walletData = $this->privyService->createWalletWithAuthorizationKeyOwner(
                $msgSender,
                'ethereum'
            );

            // Save the wallet address to the viniapp
            $viniapp->wallet_address = $walletData['address'] ?? null;
            $viniapp->save();

            return response()->json([
                'viniapp' => $viniapp,
                'wallet' => $walletData,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create viniapp with wallet', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to create viniapp with wallet',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify a transaction hash on the blockchain
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyHash(Request $request)
    {
        $transactionHash = $request->input('transaction_hash') ?? $request->query('transaction_hash');
        
        if (!$transactionHash) {
            return response()->json([
                'error' => 'transaction_hash is required',
                'example' => '/api/verify-hash?transaction_hash=0x...',
            ], 400);
        }

        try {
            $verification = $this->blockchainVerificationService->verifyTransaction($transactionHash);
            
            if ($verification['valid']) {
                return response()->json([
                    'valid' => true,
                    'transaction_hash' => $transactionHash,
                    'transaction' => $verification['transaction'] ?? null,
                    'receipt' => $verification['receipt'] ?? null,
                    'message' => 'Transaction verified successfully',
                ], 200);
            } else {
                return response()->json([
                    'valid' => false,
                    'transaction_hash' => $transactionHash,
                    'error' => $verification['error'] ?? 'Transaction verification failed',
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Hash verification endpoint failed', [
                'transaction_hash' => $transactionHash,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'valid' => false,
                'transaction_hash' => $transactionHash,
                'error' => 'Failed to verify transaction: ' . $e->getMessage(),
            ], 500);
        }
    }
}
