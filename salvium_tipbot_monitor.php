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
    // Skip if amount too low
    if ($withdrawal['amount'] < $config['MIN_WITHDRAWAL_AMOUNT']) {
        $db->updateWithdrawalStatus($withdrawal['id'], 'failed');
        sendMessage($withdrawal['user_id'], "Withdrawal amount too low. Minimum is {$config['MIN_WITHDRAWAL_AMOUNT']} SAL.");
        continue;
    }

    $amountToSend = $withdrawal['amount'] - $config['WITHDRAWAL_FEE'];
    $result = $wallet->transfer([
        [
            'address' => $withdrawal['address'],
            'amount' => (int)($amountToSend * 1e8)
        ]
    ]);

    if ($result && isset($result['tx_hash'])) {
        $db->updateWithdrawalTxid($withdrawal['id'], $result['tx_hash']);
        $db->updateWithdrawalStatus($withdrawal['id'], 'sent');
        sendMessage($withdrawal['user_id'], "Withdrawal of {$amountToSend} SAL sent. Fee: {$config['WITHDRAWAL_FEE']} SAL. TxID: {$result['tx_hash']}");
    } else {
        // Refund full amount including fee
        $db->updateWithdrawalStatus($withdrawal['id'], 'failed');
        $db->updateUserTipBalance($withdrawal['user_id'], $withdrawal['amount'], 'add');
        sendMessage($withdrawal['user_id'], "Withdrawal failed. {$withdrawal['amount']} SAL returned to your balance. Please try again later.");
    }
}


// Handle pending tips
$users = [];
$tips = $db->getAllPendingTips();
foreach ($tips as $tip) {

    if ($tip['amount'] < $config['MIN_TIP_AMOUNT']) continue; // skip small tips

    $recipientId = $tip['recipient_user_id'];
    if (!isset($users[$recipientId])) {
        $users[$recipientId] = $db->getUserByTelegramId($recipientId);
    }
    $db->updateUserTipBalance($recipientId, $tip['amount'], 'add');
    $db->markTipsAsCredited([$tip['id']]);
    sendMessage($recipientId, "You received a credited tip of {$tip['amount']} SAL. Use /balance to check.");
}

?>
