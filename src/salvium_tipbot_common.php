<?php
function sendMessage(int $chatId, string $text, array $options = []): void {
    global $config;
    $url = "https://api.telegram.org/bot{$config['TELEGRAM_BOT_TOKEN']}/sendMessage";
    $payload = array_merge(['chat_id' => $chatId, 'text' => $text], $options);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    if (!empty($config['IPV4_ONLY'])) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    curl_exec($ch);
    curl_close($ch);
}

function isValidSalviumAddress(string $address): bool {
    // Accepts standard and subaddress prefixes for Salvium (e.g. SaLvd, SaLvs)
    return preg_match('/^SaLv[a-zA-Z0-9]{95}$/', $address) === 1;
}

?>
