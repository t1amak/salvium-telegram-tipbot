<?php
// src/salvium_tipbot_wallet.php
namespace Salvium;

class SalviumWallet {
    private string $host;
    private int $port;
    private ?string $username;
    private ?string $password;

    public function __construct(string $host, int $port, ?string $username = null, ?string $password = null) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    private function _callRpc(string $method, array $params = []): array|false {
        $url = "http://{$this->host}:{$this->port}/json_rpc";
        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => '0',
            'method' => $method,
            'params' => $params
        ]);

        $headers = ['Content-Type: application/json'];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($this->username && $this->password) {
            $options[CURLOPT_USERPWD] = "{$this->username}:{$this->password}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log('RPC Curl Error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        $decoded = json_decode($response, true);
        return $decoded['result'] ?? false;
    }

    public function getWalletBalance(): array|false {
        return $this->_callRpc('get_balance');
    }

    public function getNewSubaddress(int $accountIndex = 0, ?string $label = null): string|false {
        $params = ['account_index' => $accountIndex];
        if ($label) $params['label'] = $label;
        $result = $this->_callRpc('create_address', $params);
        return $result['address'] ?? false;
    }

    public function getAddresses(): array|false {
        $result = $this->_callRpc('get_address');
        return $result['addresses'] ?? false;
    }

    public function transfer(array $destinations, int $accountIndex = 0, array $subaddrIndices = [0], int $priority = 0, int $ringSize = 16, bool $getTxKey = true): array|false {
        $params = [
            'destinations' => array_map(function ($dest) {
                return [
                    'address' => $dest['address'],
                    'amount' => (int)($dest['amount']), // already in atomic units
                    'asset_type' => 'SAL1'
                ];
            }, $destinations),
            'source_asset' => 'SAL1',
            'dest_asset' => 'SAL1',
            'tx_type' => 3,
            'account_index' => $accountIndex,
    //        'subaddr_indices' => $subaddrIndices,
            'priority' => $priority,
            'ring_size' => $ringSize,
            'get_tx_key' => $getTxKey
        ];

        return $this->_callRpc('transfer', $params);
    }

    public function getTransfers(string $inOrOut = 'in', bool $pending = false, bool $failed = false): array|false {
        $params = [
            $inOrOut => true,
            'pending' => $pending,
            'failed' => $failed
        ];
        $result = $this->_callRpc('get_transfers', $params);
        return $result[$inOrOut] ?? false;
    }

    public function getPayments(string $paymentId): array|false {
        $result = $this->_callRpc('get_payments', ['payment_id' => $paymentId]);
        return $result['payments'] ?? false;
    }

    public function getHeight(): int|false {
        $result = $this->_callRpc('get_height');
        return $result['height'] ?? false;
    }
}
?>
