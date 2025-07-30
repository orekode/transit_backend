<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use kornrunner\Keccak;
use kornrunner\Secp256k1;
use Exception;

class RewardService
{
    private $client;
    private $contractAddress;
    private $fromAddress;
    private $privateKey;
    private $secp256k1;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => env('VECHAIN_NODE_URL', 'https://sync-testnet.vechain.org'),
            'timeout' => 10,
            'connect_timeout' => 5,
            'http_errors' => false,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->contractAddress = env('CONTRACT_ADDRESS');
        $this->fromAddress = env('WALLET_ADDRESS');
        $this->privateKey = $this->fetchPrivateKeyFromVault();
        $this->secp256k1 = new Secp256k1();
    }

    /**
     * Trigger the rewardUser smart contract function
     */
    public function triggerSmartContract(string $userAddress, int $distance): string
    {
        try {
            // Validate inputs
            if (!$this->isValidAddress($userAddress)) {
                throw new Exception('Invalid user address');
            }
            if ($distance < 0) {
                throw new Exception('Distance must be non-negative');
            }

            // Load and cache ABI
            $abi = Cache::remember('contract_abi', 3600, fn() => json_decode(
                file_get_contents(base_path('contract.json')),
                true
            ));

            $rewardUserAbi = array_filter($abi, fn($item) => $item['type'] === 'function' && $item['name'] === 'submitDistance');
            $rewardUserAbi = reset($rewardUserAbi);

            if (!$rewardUserAbi) {
                throw new Exception('submitDistance function not found in ABI');
            }

            Log::info('ABI for submitDistance', [$rewardUserAbi]);

            // Encode function call
            $encodedData = $this->encodeFunctionCall($rewardUserAbi, [$userAddress, $distance, 1]);

            // Prepare transaction clauses
            $clauses = [
                [
                    'to' => $this->contractAddress,
                    'value' => '0x0',
                    'data' => $encodedData,
                ],
            ];

            // Estimate gas
            $gas = $this->estimateGas($clauses);
            if (!$gas) {
                throw new Exception('Failed to estimate gas');
            }

            // Build transaction
            $txBody = [
                'chainTag' => hexdec('0x4a'), // Testnet chainTag (0x9a for mainnet)
                'blockRef' => $this->getBlockRef(),
                'expiration' => 32,
                'clauses' => $clauses,
                'gas' => $gas,
                'gasPriceCoef' => 128,
                'dependsOn' => null,
                'nonce' => random_int(0, PHP_INT_MAX),
                'reserved' => [],
            ];

            // Sign transaction
            $rawTx = $this->signTransaction($txBody);

            // Send transaction
            $txId = $this->sendTransaction($rawTx);

            // Verify transaction
            $this->verifyTransaction($txId);

            Log::info('Smart contract triggered successfully', [
                'txId' => $txId,
                'userAddress' => $userAddress,
                'distance' => $distance,
                'gas' => $gas,
            ]);

            return $txId;
        } catch (Exception $e) {
            Log::error('Failed to trigger smart contract', [
                'error' => $e->getMessage(),
                'userAddress' => $userAddress,
                'distance' => $distance,
            ]);
            throw new Exception('Failed to trigger smart contract: ' . $e->getMessage());
        }
    }

    /**
     * Validate VeChain address
     */
    private function isValidAddress(string $address): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }

    /**
     * Encode function call data for the smart contract
     */
    private function encodeFunctionCall(array $abi, array $parameters): string
    {
        $functionSignature = 'submitDistance(address,uint256,uint256)';
        $functionSelector = substr(Keccak::hash($functionSignature, 256), 0, 8);
        $encodedParams = '';

        foreach ($parameters as $index => $param) {
            $type = $abi['inputs'][$index]['type'];
            if ($type === 'address') {
                $encodedParams .= str_pad(substr($param, 2), 64, '0', STR_PAD_LEFT);
            } elseif ($type === 'uint256') {
                $encodedParams .= str_pad(dechex($param), 64, '0', STR_PAD_LEFT);
            }
        }

        return '0x' . $functionSelector . $encodedParams;
    }

    /**
     * Estimate gas for the transaction
     */
    private function estimateGas(array $clauses): int
    {
        try {
            if (empty($clauses)) {
                throw new Exception('Clauses array cannot be empty');
            }
            $response = $this->client->post('/accounts/*', [
                'json' => ['clauses' => $clauses],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            if (!is_array($body)) {
                throw new Exception('Invalid response format');
            }
            $totalGas = array_sum(array_column($body, 'gasUsed'));
            Log::info('Total gas estimated', ['gas' => $totalGas]);
            return $totalGas ?: throw new Exception('Gas estimation failed: No gas used');
        } catch (RequestException $e) {
            Log::error('Gas estimation failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response',
            ]);
            throw new Exception('Gas estimation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get block reference from the latest block
     */
    private function getBlockRef(): string
    {
        return Cache::remember('vechain_block_ref', 60, function () {
            try {
                $response = $this->client->get('/blocks/best');
                $block = json_decode($response->getBody()->getContents(), true);
                if (!isset($block['id'])) {
                    throw new Exception('Invalid block response');
                }
                return substr($block['id'], 0, 18);
            } catch (RequestException $e) {
                Log::error('Failed to fetch block reference', ['error' => $e->getMessage()]);
                throw new Exception('Failed to fetch block reference');
            }
        });
    }

    /**
     * RLP encode a value (string, integer, or array)
     */
    private function rlpEncode($input): string
    {
        if (is_array($input)) {
            $encoded = [];
            foreach ($input as $item) {
                $encoded[] = $this->rlpEncode($item);
            }
            $payload = implode('', $encoded);
            $length = strlen($payload) / 2;
            if ($length < 56) {
                return dechex(192 + $length) . $payload;
            }
            $lengthHex = dechex($length);
            $lengthHex = strlen($lengthHex) % 2 === 0 ? $lengthHex : '0' . $lengthHex;
            return dechex(247 + strlen($lengthHex) / 2) . $lengthHex . $payload;
        } elseif (is_string($input)) {
            if (substr($input, 0, 2) === '0x') {
                $input = substr($input, 2);
            }
            if (empty($input)) {
                return '80';
            }
            $length = strlen($input) / 2;
            if ($length === 1 && hexdec($input) < 128) {
                return $input;
            }
            if ($length < 56) {
                return dechex(128 + $length) . $input;
            }
            $lengthHex = dechex($length);
            $lengthHex = strlen($lengthHex) % 2 === 0 ? $lengthHex : '0' . $lengthHex;
            return dechex(183 + strlen($lengthHex) / 2) . $lengthHex . $input;
        } elseif (is_int($input) || (is_string($input) && ctype_xdigit($input))) {
            $value = is_string($input) ? hexdec($input) : $input;
            if ($value === 0) {
                return '80';
            }
            $hex = dechex($value);
            $hex = strlen($hex) % 2 === 0 ? $hex : '0' . $hex;
            if ($value < 128) {
                return $hex;
            }
            return $this->rlpEncode($hex);
        }
        throw new Exception('Unsupported RLP input type: ' . gettype($input));
    }

    /**
     * Simulate GMP right shift
     */
    private function gmp_shr($gmp, int $bits)
    {
        return gmp_div_q($gmp, gmp_pow(gmp_init(2), $bits));
    }

    /**
     * Blake2b-256 hashing implementation
     */
    private function blake2b256(string $data): string
    {
        // Blake2b constants
        $IV = [
            gmp_init('0x6a09e667f3bcc908'), gmp_init('0xbb67ae8584caa73b'),
            gmp_init('0x3c6ef372fe94f82b'), gmp_init('0xa54ff53a5f1d36f1'),
            gmp_init('0x510e527fade682d1'), gmp_init('0x9b05688c2b3e6c1f'),
            gmp_init('0x1f83d9abfb41bd6b'), gmp_init('0x5be0cd19137e2179'),
        ];
        $SIGMA = [
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            [14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3],
            [11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4],
            [7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8],
            [9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13],
            [2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9],
            [12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11],
            [13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10],
            [6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5],
            [10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0],
            [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            [14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3],
        ];

        $h = $IV;
        $h[0] = gmp_xor($h[0], gmp_init('0x01010020')); // 256-bit output

        // Convert message to 64-bit words as GMP objects
        $hexData = bin2hex($data);
        $m = str_split($hexData, 16); // Split into 64-bit words (16 hex chars = 8 bytes)
        $m = array_map(function ($hex) {
            return gmp_init($hex, 16);
        }, $m);
        Log::info('Blake2b message blocks', ['m' => array_map('gmp_strval', $m)]);

        $t = strlen($data);
        $f0 = $t <= 128 ? $t : 0;

        $v = array_fill(0, 16, gmp_init(0));
        $block = array_fill(0, 16, gmp_init(0));

        // Compression function
        $G = function ($v, $a, $b, $c, $d, $m, $s0, $s1) {
            $sum1 = gmp_add($v[$b], $m[$s0]);
            $v[$a] = gmp_add($v[$a], $sum1);
            $v[$d] = gmp_and(gmp_xor($this->gmp_shr(gmp_xor($v[$d], $v[$a]), 32), 0xffffffffffffffff), 0xffffffffffffffff);
            $v[$c] = gmp_add($v[$c], $v[$d]);
            $v[$b] = gmp_and(gmp_xor($this->gmp_shr(gmp_xor($v[$b], $v[$c]), 24), 0xffffffffffffffff), 0xffffffffffffffff);
            $sum2 = gmp_add($v[$b], $m[$s1]);
            $v[$a] = gmp_add($v[$a], $sum2);
            $v[$d] = gmp_and(gmp_xor($this->gmp_shr(gmp_xor($v[$d], $v[$a]), 16), 0xffffffffffffffff), 0xffffffffffffffff);
            $v[$c] = gmp_add($v[$c], $v[$d]);
            $v[$b] = gmp_and(gmp_xor($this->gmp_shr(gmp_xor($v[$b], $v[$c]), 63), 0xffffffffffffffff), 0xffffffffffffffff);
            return $v;
        };

        for ($offset = 0; $offset < count($m); $offset += 16) {
            $v = array_merge($h, array_fill(8, 8, gmp_init(0)));
            $v[8] = $IV[0];
            $v[9] = $IV[1];
            $v[10] = $IV[2];
            $v[11] = $IV[3];
            $v[12] = gmp_xor($IV[4], gmp_init($offset === 0 ? $t : 0));
            $v[13] = gmp_xor($IV[5], gmp_init($offset === 0 ? $f0 : 0));
            $v[14] = $IV[6];
            $v[15] = $IV[7];

            for ($i = 0; $i < count($m) - $offset && $i < 16; $i++) {
                $block[$i] = $m[$offset + $i];
            }

            for ($round = 0; $round < 12; $round++) {
                $s = $SIGMA[$round % 10];
                $v = $G($v, 0, 4, 8, 12, $block, $s[0], $s[1]);
                $v = $G($v, 1, 5, 9, 13, $block, $s[2], $s[3]);
                $v = $G($v, 2, 6, 10, 14, $block, $s[4], $s[5]);
                $v = $G($v, 3, 7, 11, 15, $block, $s[6], $s[7]);
                $v = $G($v, 0, 5, 10, 15, $block, $s[8], $s[9]);
                $v = $G($v, 1, 6, 11, 12, $block, $s[10], $s[11]);
                $v = $G($v, 2, 7, 8, 13, $block, $s[12], $s[13]);
                $v = $G($v, 3, 4, 9, 14, $block, $s[14], $s[15]);
            }

            for ($i = 0; $i < 8; $i++) {
                $h[$i] = gmp_xor($h[$i], gmp_xor($v[$i], $v[$i + 8]));
            }
        }

        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= str_pad(gmp_strval($h[$i], 16), 16, '0', STR_PAD_LEFT);
        }
        Log::info('Blake2b hash', ['hash' => $out]);
        return $out;
    }

    /**
     * Sign the transaction using ECDSA with Blake2b
     */
    private function signTransaction(array $txBody): string
    {
        try {
            // Prepare transaction fields for RLP encoding
            $encodedFields = [
                $txBody['chainTag'],
                $txBody['blockRef'],
                $txBody['expiration'],
                array_map(function ($clause) {
                    return [
                        $clause['to'] ? substr($clause['to'], 2) : '',
                        $clause['value'],
                        $clause['data'] ? substr($clause['data'], 2) : '',
                    ];
                }, $txBody['clauses']),
                $txBody['gasPriceCoef'],
                $txBody['gas'],
                $txBody['dependsOn'] ? substr($txBody['dependsOn'], 2) : '',
                $txBody['nonce'],
                $txBody['reserved'],
            ];

            Log::info('Transaction fields for RLP encoding', ['fields' => $encodedFields]);

            // RLP encode the transaction body
            $rlpEncoded = $this->rlpEncode($encodedFields);

            // Compute Blake2b-256 hash
            $messageHash = $this->blake2b256(hex2bin($rlpEncoded));

            // Sign with secp256k1
            $privateKey = substr($this->privateKey, 2);
            $signature = $this->secp256k1->sign($messageHash, $privateKey);

            // Extract r, s, v from signature
            $sigHex = $signature->toHex();
            $r = substr($sigHex, 0, 64);
            $s = substr($sigHex, 64, 64);
            $v = dechex((hexdec(substr($sigHex, -2)) % 2));

            // RLP encode with signature
            $encodedWithSig = $this->rlpEncode(array_merge($encodedFields, [
                ['0x' . $r, '0x' . $s, '0x' . $v]
            ]));

            $rawTx = '0x' . $encodedWithSig;
            Log::info('Signed transaction', ['rawTx' => $rawTx]);
            return $rawTx;
        } catch (Exception $e) {
            Log::error('Transaction signing failed', ['error' => $e->getMessage()]);
            throw new Exception('Transaction signing failed: ' . $e->getMessage());
        }
    }

    /**
     * Send the signed transaction to the VeChain node
     */
    private function sendTransaction(string $rawTx): string
    {
        try {
            Log::info('Sending transaction', ['rawTx' => $rawTx]);
            $response = $this->client->post('/transactions', [
                'json' => ['raw' => $rawTx],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() !== 200 || !isset($body['id'])) {
                throw new Exception('Transaction ID not returned: ' . ($body['error'] ?? 'Unknown error'));
            }

            Log::info('Transaction response', ['body' => $body]);
            return $body['id'];
        } catch (RequestException $e) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response',
                'status_code' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
            ];
            Log::error('Transaction sending failed', $errorDetails);
            throw new Exception('Transaction sending failed: ' . $e->getMessage());
        }
    }

    /**
     * Verify transaction by checking its receipt
     */
    private function verifyTransaction(string $txId): void
    {
        try {
            for ($i = 0; $i < 10; $i++) {
                sleep(3);
                $response = $this->client->get("/transactions/{$txId}/receipt");
                if ($response->getStatusCode() === 200) {
                    $receipt = json_decode($response->getBody()->getContents(), true);
                    if ($receipt && (!isset($receipt['reverted']) || !$receipt['reverted'])) {
                        Log::info('Transaction verified', ['txId' => $txId, 'receipt' => $receipt]);
                        return;
                    }
                    throw new Exception('Transaction reverted');
                }
            }
            throw new Exception('Transaction receipt not found');
        } catch (RequestException $e) {
            Log::error('Transaction verification failed', [
                'txId' => $txId,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response',
            ]);
            throw new Exception('Transaction verification failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch private key from a secure vault
     */
    private function fetchPrivateKeyFromVault(): string
    {
        $privateKey = env('WALLET_PRIVATE_KEY');
        if (!$privateKey || substr($privateKey, 0, 2) !== '0x' || strlen($privateKey) !== 66) {
            throw new Exception('Invalid private key');
        }
        return $privateKey;
    }
}