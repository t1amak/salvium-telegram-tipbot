<?php
use Salvium\SalviumTipBotDB;
use Salvium\SalviumWallet;

class SalviumTipBotCommands {
    private SalviumTipBotDB $db;
    private SalviumWallet $wallet;

    public function __construct(SalviumTipBotDB $db, SalviumWallet $wallet) {
        $this->db = $db;
        $this->wallet = $wallet;
    }

    public function handle(string $command, array $args, array $context): void {
        $method = 'cmd_' . ltrim($command, '/');
        if (method_exists($this, $method)) {
            $response = $this->$method($args, $context);
        } else {
            //$response = "Unknown command.";
            $response = "";
        }

        sendMessage($context['chat_id'], $response);
        $this->db->logMessage($context['chat_id'], $context['chat_name'], $context['username'], $context['raw'], $response);
    }

    private function cmd_start(array $args, array $ctx): string {
        if (!empty($ctx['username'])) {
            $this->db->updateUsername($ctx['user_id'], $ctx['username']);
        }
        return "Welcome to the Salvium Tip Bot! Use /deposit to get started.";
    }

    private function cmd_deposit(array $args, array $ctx): string {
        $user = $this->db->getUserByTelegramId($ctx['user_id']);
        if (!$user) {
            $sub = $this->wallet->getNewSubaddress();
            if (!$sub) return "Error generating subaddress.";
            $this->db->createUser($ctx['user_id'], $sub);
            if (!empty($ctx['username'])) {
                $this->db->updateUsername($ctx['user_id'], $ctx['username']);
            }
            return "Your deposit address: $sub";
        }
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
        $sender = $this->db->getUserByTelegramId($ctx['user_id']);
        $recipient = $this->db->getUserByUsername(ltrim($targetUsername, '@'));

        if (!$sender || $sender['tip_balance'] < $amount) return "Insufficient funds or invalid sender.";
        if (!$recipient) return "Recipient not found. Ask them to run /start first.";

        $this->db->updateUserTipBalance($sender['id'], $amount, 'subtract');
        $this->db->addTip($sender['id'], $recipient['id'], $amount, $ctx['chat_id']);
        sendMessage($recipient['telegram_user_id'], "You received a tip of {$amount} SAL! Use /balance to check.");

        return "Tipped {$targetUsername} {$amount} SAL successfully!";
    }
}
?>
