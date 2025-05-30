<?php
// salvium_tipbot.php
use Salvium\SalviumTipBotDB;
use Salvium\SalviumWallet;

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/salvium_tipbot_db.php';
require_once __DIR__ . '/src/salvium_tipbot_wallet.php';
require_once __DIR__ . '/src/salvium_tipbot_common.php';
require_once __DIR__ . '/src/salvium_tipbot_commands.php';

$db = new SalviumTipBotDB($config);
$wallet = new SalviumWallet(
    $config['SALVIUM_RPC_HOST'],
    $config['SALVIUM_RPC_PORT'],
    $config['SALVIUM_RPC_USERNAME'],
    $config['SALVIUM_RPC_PASSWORD']
);

$update = json_decode(file_get_contents("php://input"), true);
if (!$update || !isset($update['message'])) exit;

$message = $update['message'];
//$args = explode(' ', trim($message['text'] ?? ''));
//$command = strtolower($args[0] ?? '');


$text = trim($message['text'] ?? '');
if (!str_starts_with($text, '/')) exit; // Only react to messages that start with '/'

$args = explode(' ', $text);
$command = strtolower($args[0]);

// Strip optional @BotUsername (Telegram adds this in groups)
if (str_contains($command, '@')) {
    $command = explode('@', $command)[0];
}


$context = [
    'chat_id' => $message['chat']['id'],
    'chat_name' => $message['chat']['title'] ?? $message['chat']['first_name'] ?? '',
    'chat_type' => $message['chat']['type'],
    'username' => $message['from']['username'] ?? '',
    'user_id' => (int) $message['from']['id'],
    'raw' => $message['text'] ?? '',
];

$handler = new SalviumTipBotCommands($db, $wallet, $config);
$handler->handle($command, $args, $context);
?>
