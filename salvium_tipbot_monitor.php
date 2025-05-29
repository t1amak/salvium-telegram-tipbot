<?php
// salvium_tipbot_monitor.php
use Salvium\SalviumTipBotDB;
use Salvium\SalviumWallet;

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/salvium_tipbot_db.php';
require_once __DIR__ . '/src/salvium_tipbot_wallet.php';
require_once __DIR__ . '/src/salvium_tipbot_common.php';

$db = new SalviumTipBotDB($config);
$wallet = new SalviumWallet(
    $config['SALVIUM_RPC_HOST'],
    $config['SALVIUM_RPC_PORT'],
    $config['SALVIUM_RPC_USERNAME'],
    $config['SALVIUM_RPC_PASSWORD']
);

// Handle incoming transfers
$incoming = $wallet->getTransfers('in');
if ($incoming) {
    foreach ($incoming as $tx) {
        if ($db->isTxidLogged($tx['txid'])) continue;

        $subaddress = $tx['address'];
        $user = $db->getUserBySubaddress($subaddress);
        if (!$user) continue;

        $amount = $tx['amount'] / 1e8; // Convert atomic to major units (1 SAL = 1e8 atomic)
        $db->logDeposit($user['id'], $tx['txid'], $amount, $tx['height']);
        $db->updateUserTipBalance($user['id'], $amount, 'add');

        sendMessage($user['telegram_user_id'], "Deposit received: {$amount} SAL added to your balance.");
    }
}

// Handle pending withdrawals
$withdrawals = $db->getPendingWithdrawals();
foreach ($withdrawals as $withdrawal) {
    $result = $wallet->transfer([
        [
            'address' => $withdrawal['address'],
            'amount' => (int)($withdrawal['amount'] * 1e8) // major to atomic
        ]
    ]);

    if ($result && isset($result['tx_hash'])) {
        $db->updateWithdrawalTxid($withdrawal['id'], $result['tx_hash']);
        $db->updateWithdrawalStatus($withdrawal['id'], 'sent');
        sendMessage($withdrawal['user_id'], "Withdrawal of {$withdrawal['amount']} SAL sent. TxID: {$result['tx_hash']}");
    } else {
        $db->updateWithdrawalStatus($withdrawal['id'], 'failed');
        sendMessage($withdrawal['user_id'], "Withdrawal failed. Please try again later or contact support.");
    }
}

// Handle pending tips
$users = [];
$tips = $db->getAllPendingTips();
foreach ($tips as $tip) {
    $recipientId = $tip['recipient_user_id'];
    if (!isset($users[$recipientId])) {
        $users[$recipientId] = $db->getUserByTelegramId($recipientId);
    }
    $db->updateUserTipBalance($recipientId, $tip['amount'], 'add');
    $db->markTipsAsCredited([$tip['id']]);
    sendMessage($recipientId, "You received a credited tip of {$tip['amount']} SAL. Use /balance to check.");
}

?>
