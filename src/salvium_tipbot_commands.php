<?php
use Salvium\SalviumTipBotDB;
use Salvium\SalviumWallet;

class SalviumTipBotCommands {
    private SalviumTipBotDB $db;
    private SalviumWallet $wallet;
    private array $config;
    private array $commandAccess = [
        'start'    => ['private'],
        'deposit'  => ['private'],
        'claim'  => ['private'],
        'withdraw' => ['private'],
        'balance'  => ['private'],
        'tip'      => ['private', 'group', 'supergroup'],
    ];

    public function __construct(SalviumTipBotDB $db, SalviumWallet $wallet, array $config) {
        $this->db = $db;
        $this->wallet = $wallet;
        $this->config = $config;
    }

    public function handle(string $command, array $args, array $context): void {
        $method = 'cmd_' . ltrim($command, '/');

        $cmdKey = ltrim($command, '/');
        $allowedChats = $this->commandAccess[$cmdKey] ?? [];

        if (!in_array($context['chat_type'], $allowedChats)) {
            return; // not allowed
        }

        if (method_exists($this, $method)) {
            $response = $this->$method($args, $context);
        } else {
            $response = ""; // Optional fallback
        }

        if ($response) {
            sendMessage($context['chat_id'], $response);
        }

        $this->db->logMessage($context['chat_id'], $context['chat_name'], $context['username'], $context['raw'], $response);
    }

    private function cmd_start(array $args, array $ctx): string {
        $this->db->ensureUserExists(
            $ctx['user_id'],
            $ctx['username'] ?? null,
            fn() => $this->wallet->getNewSubaddress(),
            false
        );

        return "👋 Welcome to the Salvium Tip Bot!\n\n"
            . "You can use the following commands:\n"
            . "/deposit – View your deposit address\n"
            . "/balance – Check your current balance\n"
            . "/withdraw <address> <amount> – Withdraw your SAL\n"
            . "/tip <username> <amount> – Tip another user\n"
            . "/claim – Claim tips sent to your username (if you haven’t used the bot before)\n\n"
            . "ℹ️ All commands except /tip must be used in a private chat with the bot.";
    }

    private function cmd_deposit(array $args, array $ctx): string {
        $user = $this->db->ensureUserExists(
            $ctx['user_id'],
            $ctx['username'] ?? null,
            fn() => $this->wallet->getNewSubaddress(),
            false
        );

        return "Your deposit address: {$user['salvium_subaddress']}";
    }


    private function cmd_balance(array $args, array $ctx): string {
        $user = $this->db->getUserByTelegramId($ctx['user_id']);
        return $user ? "Your balance: {$user['tip_balance']} SAL" : "No account found. Use /deposit first.";
    }

    private function cmd_withdraw(array $args, array $ctx): string {
        if (count($args) < 3) return "Usage: /withdraw <address> <amount>";

        list(, $address, $amount) = $args;
        $amount = (float) $amount;

        if ($amount < $this->config['MIN_WITHDRAWAL_AMOUNT']) {
            return "Withdrawal amount too low. Minimum is {$this->config['MIN_WITHDRAWAL_AMOUNT']} SAL.";
        }

        $user = $this->db->getUserByTelegramId($ctx['user_id']);

        if (!$user || $user['tip_balance'] < $amount) return "Insufficient balance or invalid account.";
        if (!isValidSalviumAddress($address)) return "Invalid SAL address format.";

        $this->db->updateUserTipBalance($user['id'], $amount, 'subtract');
        $this->db->logWithdrawal($user['id'], $address, $amount);
        return "Withdrawal request submitted. Processing soon.";
    }


    private function cmd_tip(array $args, array $ctx): string {
        if (count($args) < 3) return "Usage: /tip <username> <amount>";

        list(, $targetUsername, $amount) = $args;
        $amount = (float)$amount;

        if ($amount < $this->config['MIN_TIP_AMOUNT']) {
            return "Tip {$amount} for {$targetUsername} too small. Minimum tip is {$this->config['MIN_TIP_AMOUNT']} SAL.";
        }

        $sender = $this->db->getUserByTelegramId($ctx['user_id']);
        if (!$sender || $sender['tip_balance'] < $amount) {
            return "Insufficient funds or invalid sender.";
        }

        $cleanUsername = ltrim($targetUsername, '@');

        $recipient = $this->db->ensureUserExists(
            0,
            $cleanUsername,
            fn() => $this->wallet->getNewSubaddress(),
            true
        );

        if (!$recipient) {
            return "Failed to ensure recipient exists.";
        }

        $this->db->updateUserTipBalance($sender['id'], $amount, 'subtract');
        $this->db->addTip($sender['id'], $recipient['id'], $amount, $ctx['chat_id']);

        if (!empty($recipient['telegram_user_id']) && $recipient['telegram_user_id'] > 0 && $recipient['telegram_user_id'] !== (100_000 + (crc32($cleanUsername) % 900_000))) {
            sendMessage($recipient['telegram_user_id'], "You received a tip of {$amount} SAL! Use /balance to check.");
        } else {
            sendMessage($ctx['chat_id'], "Hey @$cleanUsername, you just got a tip from @$ctx[username]!");
            sendGif(
                $ctx['chat_id'],
                'CgACAgQAAxkBAAOQaDjcu6ftEKHp3ZCCKX8p6hTkqxEAAtYaAAL4YMlR4yZwk_GMuWg2BA',
                "DM me and run /claim to receive it."
            );
        }

        return "Tipped {$targetUsername} {$amount} SAL successfully!";
    }


    private function cmd_claim(array $args, array $ctx): string {
        $user = $this->db->getUserByUsername($ctx['username']);

        if (!$user || $user['telegram_user_id'] > 1_000_000) {
            return "Nothing to claim or already claimed.";
        }

        $this->db->upgradeTelegramUserId($user['telegram_user_id'], $ctx['user_id']);

        return "Welcome @{$ctx['username']}, your account has been activated. You can now check your balance and receive tips!";
    }

}
?>
