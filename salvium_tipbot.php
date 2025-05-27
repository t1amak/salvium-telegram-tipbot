<?php

// salvium_tipbot.php
use Salvium\SalviumTipBotDB;
use Salvium\SalviumWallet;

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/salvium_tipbot_db.php';
require_once __DIR__ . '/src/salvium_tipbot_wallet.php';

$db = new SalviumTipBotDB($config);
$wallet = new SalviumWallet(
    $config['SALVIUM_RPC_HOST'],
    $config['SALVIUM_RPC_PORT'],
    $config['SALVIUM_RPC_USERNAME'],
    $config['SALVIUM_RPC_PASSWORD']
);

function sendMessage(int $chatId, string $text, array $options = []): void {
    global $config;
    $payload = array_merge(['chat_id' => $chatId, 'text' => $text], $options);
    file_get_contents("https://api.telegram.org/bot{$config['TELEGRAM_BOT_TOKEN']}/sendMessage?" . http_build_query($payload));
}

$update = json_decode(file_get_contents("php://input"), true);
if (!$update || !isset($update['message'])) exit;

$message = $update['message'];
$chatId = $message['chat']['id'];
$userId = $message['from']['id'];
$username = $message['from']['username'] ?? '';
$text = trim($message['text'] ?? '');
$args = explode(' ', $text);
$command = strtolower($args[0] ?? '');

switch ($command) {
    case '/start':
        sendMessage($chatId, "Welcome to the Salvium Tip Bot! Use /deposit to get started.");
        break;

    case '/deposit':
        $user = $db->getUserByTelegramId($userId);
        if (!$user) {
            $subaddress = $wallet->getNewSubaddress();
            if (!$subaddress) {
                sendMessage($chatId, "Error generating subaddress. Try again later.");
                exit;
            }
            $db->createUser($userId, $subaddress);
            $user = ['salvium_subaddress' => $subaddress];
        }
        sendMessage($chatId, "Your Salvium deposit address is: {$user['salvium_subaddress']}");
        break;

    case '/balance':
        $user = $db->getUserByTelegramId($userId);
        if (!$user) {
            sendMessage($chatId, "You don't have an account yet. Use /deposit to create one.");
            break;
        }
        sendMessage($chatId, "Your balance: {$user['tip_balance']} XSL.");
        break;

    case '/withdraw':
        if (count($args) < 3) {
            sendMessage($chatId, "Usage: /withdraw <address> <amount>");
            break;
        }
        list(, $address, $amount) = $args;
        $amount = (float)$amount;
        $user = $db->getUserByTelegramId($userId);
        if (!$user || $user['tip_balance'] < $amount) {
            sendMessage($chatId, "Insufficient balance or invalid account.");
            break;
        }
        if (!preg_match('/^4[0-9AB][1-9A-HJ-NP-Za-km-z]{93}$/', $address)) {
            sendMessage($chatId, "Invalid address format.");
            break;
        }
        $db->updateUserTipBalance($user['id'], $amount, 'subtract');
        $db->logWithdrawal($user['id'], $address, $amount);
        sendMessage($chatId, "Withdrawal request submitted. Processing soon.");
        break;

    default:
        if (str_starts_with($command, '/tip')) {
            if (count($args) < 3) {
                sendMessage($chatId, "Usage: /tip <username> <amount>");
                break;
            }
            list(, $targetUsername, $amount) = $args;
            $amount = (float)$amount;
            $sender = $db->getUserByTelegramId($userId);
            $recipient = $db->getUserByTelegramId(ltrim($targetUsername, '@'));
            if (!$sender || $sender['tip_balance'] < $amount) {
                sendMessage($chatId, "Insufficient funds or invalid sender.");
                break;
            }
            if (!$recipient) {
                sendMessage($chatId, "Recipient not found. Ask them to run /start first.");
                break;
            }
            $db->updateUserTipBalance($sender['id'], $amount, 'subtract');
            $db->addTip($sender['id'], $recipient['id'], $amount, $chatId);
            sendMessage($chatId, "Tipped {$targetUsername} {$amount} XSL successfully!");
            sendMessage($recipient['telegram_user_id'], "You received a tip of {$amount} XSL! Use /balance to check.");
        } else {
            sendMessage($chatId, "Unknown command.");
        }
        break;
}
?>
