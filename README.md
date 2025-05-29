# Salvium Tip Bot

A PHP-based Telegram tip bot for the Salvium (Monero fork) cryptocurrency.

## Features
- Telegram tipping via subaddresses
- Wallet interaction via JSON-RPC
- Secure MySQL backend using PDO
- Deposit, withdraw, balance, and tipping commands
- Cron-compatible monitoring of deposits and withdrawals

## Installation
1. Clone the repo
2. Copy `config.sample.php` to `config.php` and fill in your credentials
3. Update `config.php` with your RPC, DB, and Telegram Bot credentials
4. Run `salvium_tipbot.php` as a Telegram webhook listener
5. Schedule `salvium_tipbot_monitor.php` using `cron` for periodic checks

## Requirements
- PHP 8.1+
- MySQL 5.7+/MariaDB
- Telegram Bot Token
- Salvium RPC Wallet node

## License
MIT
