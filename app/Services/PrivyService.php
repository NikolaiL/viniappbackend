<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PrivyService
{
    private string $appId;
    private string $appSecret;
    private string $authId;
    private string $authSecret;
    private string $baseUrl = 'https://api.privy.io/v1';

    public function __construct()
    {
        $this->appId = env('PRIVY_APP_ID');
        $this->appSecret = env('PRIVY_APP_SECRET');
        $this->authId = env('PRIVY_AUTH_ID');
        $this->authSecret = env('PRIVY_AUTH_SECRET');

        if (empty($this->appId) || empty($this->appSecret)) {
            throw new \Exception('PRIVY_APP_ID and PRIVY_APP_SECRET must be set in .env file');
        }

        if (empty($this->authId) || empty($this->authSecret)) {
            throw new \Exception('PRIVY_AUTH_ID and PRIVY_AUTH_SECRET must be set in .env file');
        }
    }

    /**
     * Generate a P-256 ECDSA keypair and return the public key in base64 DER format
     * 
     * @return array ['private_key' => string, 'public_key' => string (base64 DER)]
     */
    private function generateP256Keypair(): array
    {
        // Generate P-256 (prime256v1) ECDSA keypair
        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $resource = openssl_pkey_new($config);
        if (!$resource) {
            throw new \Exception('Failed to generate P-256 keypair: ' . openssl_error_string());
        }

        // Export private key in PEM format
        $privateKeyPem = '';
        if (!openssl_pkey_export($resource, $privateKeyPem)) {
            throw new \Exception('Failed to export private key: ' . openssl_error_string());
        }

        // Remove PEM headers and footers from private key
        $privateKeyClean = preg_replace('/-----BEGIN PRIVATE KEY-----|-----END PRIVATE KEY-----|\s/', '', $privateKeyPem);

        // Get public key details
        $details = openssl_pkey_get_details($resource);
        if (!$details || !isset($details['key'])) {
            throw new \Exception('Failed to get public key details: ' . openssl_error_string());
        }

        $publicKeyPem = $details['key'];

        // Convert public key from PEM to DER format
        // Remove PEM headers and decode from base64
        $publicKeyPem = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s/', '', $publicKeyPem);
        $publicKeyDer = base64_decode($publicKeyPem);

        // Encode to base64 for Privy API
        $publicKeyBase64Der = base64_encode($publicKeyDer);

        return [
            'private_key' => $privateKeyClean,
            'public_key' => $publicKeyBase64Der,
        ];
    }

    /**
     * Create an authorization key for a msg.sender
     * First generates a P-256 keypair, then creates a key quorum with it
     * Returns both the key quorum ID and private key for database storage
     * 
     * @param string $msgSender The msg.sender address
     * @return array ['key_quorum_id' => string, 'private_key' => string] Key quorum ID and private key
     */
    public function createAuthorizationKeyForSender(string $msgSender): array
    {
        try {
            // Step 1: Generate P-256 keypair
            $keypair = $this->generateP256Keypair();
            $publicKeyBase64Der = $keypair['public_key'];
            $privateKey = $keypair['private_key'];
            
            // Note: In production, you should securely store the private key
            // Privy does not store it and cannot help recover it
            Log::info('Generated P-256 keypair for msg.sender', [
                'msg_sender' => $msgSender,
                // Do not log the private key in production!
            ]);

            // Step 2: Create a key quorum with the public key
            // POST to https://api.privy.io/v1/key_quorums
            $keyQuorumData = [
                'public_keys' => [$publicKeyBase64Der],
                'display_name' => "{$msgSender}",
                'authorization_threshold' => 1, // Require 1 signature (just this key)
            ];

            $response = Http::withBasicAuth($this->appId, $this->appSecret)
                ->withHeaders([
                    'privy-app-id' => $this->appId,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/key_quorums", $keyQuorumData);

            if (!$response->successful()) {
                Log::error('Failed to create key quorum', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'msg_sender' => $msgSender,
                ]);
                throw new \Exception('Failed to create key quorum: ' . $response->body());
            }

            $keyQuorumResponse = $response->json();
            
            // The key quorum ID is what we use as signer_id
            $keyQuorumId = $keyQuorumResponse['id'] ?? null;
            
            if (!$keyQuorumId) {
                throw new \Exception('Key quorum created but no ID returned in response');
            }

            Log::info('Created key quorum for msg.sender', [
                'msg_sender' => $msgSender,
                'key_quorum_id' => $keyQuorumId,
            ]);

            return [
                'key_quorum_id' => $keyQuorumId,
                'private_key' => $privateKey,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create authorization key for msg.sender', [
                'msg_sender' => $msgSender,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to create authorization key for msg.sender: ' . $e->getMessage());
        }
    }

    /**
     * Create a wallet owned by an authorization key with an additional signer for msg.sender
     * 
     * @param string $msgSender The msg.sender address from the transaction request
     * @param string $chainType Chain type (e.g., 'ethereum')
     * @return array Wallet data including address, key_quorum_id, and private_key
     */
    public function createWalletWithAuthorizationKeyOwner(string $msgSender, string $chainType = 'ethereum'): array
    {
        // Create authorization key for msg.sender to use as additional signer
        $authorizationKeyData = $this->createAuthorizationKeyForSender($msgSender);
        $additionalSignerId = $authorizationKeyData['key_quorum_id'];
        $privateKey = $authorizationKeyData['private_key'];

        // Create wallet with authorization key as owner
        // PRIVY_AUTH_ID is the key quorum ID of the authorization key that will own the wallet
        // Try using owner_id first (if PRIVY_AUTH_ID is a key quorum ID)
        $requestData = [
            'owner_id' => $this->authId, // Use the authorization key's key quorum ID as owner
            'chain_type' => $chainType,
            'additional_signers' => [
                [
                    'signer_id' => $additionalSignerId,
                ],
            ],
        ];

        // Try authenticating with PRIVY_AUTH_ID and PRIVY_AUTH_SECRET first
        // since the wallet is owned by this authorization key
        $response = Http::withBasicAuth($this->authId, $this->authSecret)
            ->withHeaders([
                'privy-app-id' => $this->appId,
                'Content-Type' => 'application/json',
            ])
            ->post("{$this->baseUrl}/wallets", $requestData);

        // If that doesn't work, try with PRIVY_APP_ID and PRIVY_APP_SECRET
        if (!$response->successful() && ($response->status() === 401 || $response->status() === 403)) {
            $response = Http::withBasicAuth($this->appId, $this->appSecret)
                ->withHeaders([
                    'privy-app-id' => $this->appId,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/wallets", $requestData);
        }

        // If owner_id doesn't work, try using owner with public_key
        if (!$response->successful() && $response->status() === 400) {
            $requestDataAlternative = [
                'owner' => [
                    'public_key' => $this->authId, // Try as public key if owner_id failed
                ],
                'chain_type' => $chainType,
                'additional_signers' => [
                    [
                        'signer_id' => $additionalSignerId,
                    ],
                ],
            ];

            // Try with AUTH credentials first
            $response = Http::withBasicAuth($this->authId, $this->authSecret)
                ->withHeaders([
                    'privy-app-id' => $this->appId,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/wallets", $requestDataAlternative);

            // If that fails, try with APP credentials
            if (!$response->successful() && ($response->status() === 401 || $response->status() === 403)) {
                $response = Http::withBasicAuth($this->appId, $this->appSecret)
                    ->withHeaders([
                        'privy-app-id' => $this->appId,
                        'Content-Type' => 'application/json',
                    ])
                    ->post("{$this->baseUrl}/wallets", $requestDataAlternative);
            }

            if ($response->successful()) {
                $walletData = $response->json();
                // Include the key quorum ID and private key in the response
                $walletData['key_quorum_id'] = $additionalSignerId;
                $walletData['private_key'] = $privateKey;
                return $walletData;
            }
        }

        if (!$response->successful()) {
            Log::error('Privy wallet creation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'request' => $requestData,
            ]);
            throw new \Exception('Failed to create wallet: ' . $response->body());
        }

        $walletData = $response->json();
        // Include the key quorum ID and private key in the response
        $walletData['key_quorum_id'] = $additionalSignerId;
        $walletData['private_key'] = $privateKey;
        return $walletData;
    }
}

