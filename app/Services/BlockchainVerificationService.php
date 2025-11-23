<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlockchainVerificationService
{
    private ?string $chainRpcUrl;
    private ?string $contractAddress;
    private ?string $expectedMethod;

    public function __construct()
    {
        $chain = env('VINI_RPC_URL');
        $this->contractAddress = env('VINI_CONTRACT');
        $this->expectedMethod = env('VINI_METHOD');

        if (empty($chain)) {
            throw new \Exception('VINI_RPC_URL must be set in .env file');
        }

        if (empty($this->contractAddress)) {
            throw new \Exception('VINI_CONTRACT must be set in .env file');
        }

        if (empty($this->expectedMethod)) {
            throw new \Exception('VINI_METHOD must be set in .env file');
        }

        // Normalize contract address (remove 0x prefix for comparison, but keep original for RPC)
        $this->contractAddress = strtolower($this->contractAddress);

        // Normalize method selector (should be 4 bytes = 8 hex chars, with or without 0x prefix)
        $this->expectedMethod = strtolower(trim($this->expectedMethod));
        if (!str_starts_with($this->expectedMethod, '0x')) {
            $this->expectedMethod = '0x' . $this->expectedMethod;
        }

        // Determine RPC URL based on chain configuration
        // VINI_CHAIN can be either:
        // 1. A direct RPC URL (e.g., https://eth-mainnet.g.alchemy.com/v2/YOUR_KEY)
        // 2. A chain identifier that we map to a public RPC
        $this->chainRpcUrl = $this->resolveRpcUrl($chain);
    }

    /**
     * Resolve RPC URL from chain identifier
     * 
     * @param string $chain Chain identifier or RPC URL
     * @return string RPC URL
     */
    private function resolveRpcUrl(string $chain): string
    {
        // If it's already a URL, use it directly
        if (filter_var($chain, FILTER_VALIDATE_URL)) {
            return $chain;
        }

        // Map common chain IDs to public RPC endpoints
        $chainRpcMap = [
            '1' => 'https://eth.llamarpc.com', // Ethereum Mainnet
            '11155111' => 'https://sepolia.infura.io/v3/9aa3d95b3bc440fa88ea12eaa4456161', // Sepolia
            '8453' => 'https://base.llamarpc.com', // Base Mainnet
            '84532' => 'https://sepolia.base.org', // Base Sepolia
            '137' => 'https://polygon.llamarpc.com', // Polygon Mainnet
            '80001' => 'https://rpc-mumbai.maticvigil.com', // Mumbai Testnet
            '42161' => 'https://arb1.arbitrum.io/rpc', // Arbitrum One
            '421614' => 'https://sepolia-rollup.arbitrum.io/rpc', // Arbitrum Sepolia
        ];

        // Check if it's a numeric chain ID
        if (isset($chainRpcMap[$chain])) {
            return $chainRpcMap[$chain];
        }

        // If VINI_CHAIN_RPC_URL is set, use that
        $customRpc = env('VINI_CHAIN_RPC_URL');
        if (!empty($customRpc)) {
            return $customRpc;
        }

        throw new \Exception("Unable to resolve RPC URL for chain: {$chain}. Set VINI_CHAIN_RPC_URL in .env or use a supported chain ID.");
    }

    /**
     * Verify that a transaction hash exists on the blockchain and is to the correct contract
     * 
     * @param string $transactionHash The transaction hash to verify
     * @return array ['valid' => bool, 'transaction' => array|null, 'error' => string|null]
     */
    public function verifyTransaction(string $transactionHash): array
    {
        try {
            // Normalize transaction hash
            $txHash = strtolower($transactionHash);
            if (!str_starts_with($txHash, '0x')) {
                $txHash = '0x' . $txHash;
            }

            // Get transaction receipt (this confirms the transaction was mined)
            $receipt = $this->getTransactionReceipt($txHash);
            
            if (!$receipt) {
                return [
                    'valid' => false,
                    'transaction' => null,
                    'error' => 'Transaction not found on blockchain',
                ];
            }

            // Check if transaction was successful
            if (isset($receipt['status']) && $receipt['status'] !== '0x1' && $receipt['status'] !== '1') {
                return [
                    'valid' => false,
                    'transaction' => $receipt,
                    'error' => 'Transaction failed on blockchain',
                ];
            }

            // Verify the contract address matches
            $toAddress = strtolower(trim($receipt['to'] ?? ''));
            if (empty($toAddress)) {
                return [
                    'valid' => false,
                    'transaction' => $receipt,
                    'error' => 'Transaction receipt does not contain a "to" address',
                ];
            }

            // Normalize expected contract address
            $expectedContract = strtolower(trim($this->contractAddress));
            if (!str_starts_with($expectedContract, '0x')) {
                $expectedContract = '0x' . $expectedContract;
            }

            // Normalize to address
            if (!str_starts_with($toAddress, '0x')) {
                $toAddress = '0x' . $toAddress;
            }

            if ($toAddress !== $expectedContract) {
                return [
                    'valid' => false,
                    'transaction' => $receipt,
                    'error' => "Transaction is not to the expected contract. Expected: {$expectedContract}, Got: {$toAddress}",
                ];
            }

            // Get full transaction details
            $transaction = $this->getTransactionByHash($txHash);

            if (!$transaction) {
                return [
                    'valid' => false,
                    'transaction' => null,
                    'error' => 'Failed to retrieve transaction details',
                ];
            }

            // Verify the method selector matches
            $input = $transaction['input'] ?? '';
            if (empty($input) || $input === '0x') {
                return [
                    'valid' => false,
                    'transaction' => $transaction,
                    'error' => 'Transaction has no input data',
                ];
            }

            // Extract method selector (first 4 bytes = 8 hex characters after 0x)
            $input = strtolower($input);
            if (strlen($input) < 10) { // 0x + 8 chars = 10 minimum
                return [
                    'valid' => false,
                    'transaction' => $transaction,
                    'error' => 'Transaction input data is too short to contain a method selector',
                ];
            }

            $methodSelector = substr($input, 0, 10); // 0x + 8 hex chars = 10 characters

            if ($methodSelector !== $this->expectedMethod) {
                return [
                    'valid' => false,
                    'transaction' => $transaction,
                    'error' => "Transaction method does not match. Expected: {$this->expectedMethod}, Got: {$methodSelector}",
                ];
            }

            return [
                'valid' => true,
                'transaction' => $transaction,
                'receipt' => $receipt,
                'method' => $methodSelector,
            ];

        } catch (\Exception $e) {
            Log::error('Blockchain verification failed', [
                'transaction_hash' => $transactionHash,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'valid' => false,
                'transaction' => null,
                'error' => 'Failed to verify transaction: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get transaction receipt using JSON-RPC
     * 
     * @param string $transactionHash
     * @return array|null
     */
    private function getTransactionReceipt(string $transactionHash): ?array
    {
        $response = Http::timeout(10)
            ->post($this->chainRpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_getTransactionReceipt',
                'params' => [$transactionHash],
                'id' => 1,
            ]);

        if (!$response->successful()) {
            throw new \Exception('RPC request failed: ' . $response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            // Transaction not found or other error
            if (isset($data['error']['code']) && $data['error']['code'] === -32000) {
                return null; // Transaction not found
            }
            throw new \Exception('RPC error: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data['result'] ?? null;
    }

    /**
     * Get transaction details using JSON-RPC
     * 
     * @param string $transactionHash
     * @return array|null
     */
    private function getTransactionByHash(string $transactionHash): ?array
    {
        $response = Http::timeout(10)
            ->post($this->chainRpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_getTransactionByHash',
                'params' => [$transactionHash],
                'id' => 1,
            ]);

        if (!$response->successful()) {
            throw new \Exception('RPC request failed: ' . $response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception('RPC error: ' . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data['result'] ?? null;
    }
}

