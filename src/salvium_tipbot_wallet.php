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

    public function transfer(array $destinations, int $mixin = 11, int $unlockTime = 0, bool $getTxKey = false, bool $doNotRelay = false): array|false {
        $params = [
            'destinations' => $destinations,
            'mixin' => $mixin,
            'unlock_time' => $unlockTime,
            'get_tx_key' => $getTxKey,
            'do_not_relay' => $doNotRelay
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
