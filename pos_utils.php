<?php
/**
 * pos_utils.php
 * Shared utilities for ECR17 POS driver (PHP 5.5 compatible).
 */

// Build payment request payload (same as original)
function buildPaymentRequest($terminalId, $cashRegisterId, $amountCents, $receiptText) {
    $receiptText = (string) $receiptText;
    $msg  = str_pad(substr($terminalId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '0P';
    $msg .= str_pad(substr($cashRegisterId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '00000';
    $msg .= str_pad((string)$amountCents, 8, '0', STR_PAD_LEFT);
    $msg .= str_pad(substr($receiptText, 0, 128), 128, ' ', STR_PAD_LEFT);
    $msg .= '00000000';
    return $msg;
}

// Wrap payload with STX, ETX and calculate LRC
function wrapStxEtxLrc($payload) {
    $stx = chr(0x02);
    $etx = chr(0x03);
    $data = $stx . $payload . $etx;
    $lrc = 0x7F;
    for ($i = 0; $i < strlen($data); $i++) {
        $lrc ^= ord($data[$i]);
    }
    return $data . chr($lrc & 0xFF);
}

// Calculate LRC for a full STX..ETX string (used for response verification)
function calcLrcNexi($stxPayloadEtx) {
    $lrc = 0x7F;
    for ($i = 0; $i < strlen($stxPayloadEtx); $i++) {
        $lrc ^= ord($stxPayloadEtx[$i]);
    }
    return $lrc & 0xFF;
}

// Simple hex dump for debugging
function dumpHex($label, $data, $maxBytes) {
    $hex = bin2hex($data);
    $hexSpaced = trim(chunk_split($hex, 2, ' '));
    if (strlen($data) > $maxBytes) {
        $hexSpaced = substr($hexSpaced, 0, $maxBytes * 3) . ' ...';
    }
    echo $label . " (" . strlen($data) . " byte): " . $hexSpaced . "\n";
}

// Build ACK frame (used by both scripts)
function buildAckFrame() {
    return chr(0x06) . chr(0x03) . chr((0x7F ^ 0x06 ^ 0x03) & 0xFF);
}

// Load configuration (environment, config.php, optional mcp_config.json)
function loadConfig() {
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        require_once $configFile;
    }
    // Fallback to env vars if constants not defined
    if (!defined('POS_HOST')) {
        define('POS_HOST', getenv('POS_HOST') !== false ? getenv('POS_HOST') : '192.168.1.15');
    }
    if (!defined('POS_PORT')) {
        define('POS_PORT', (int) (getenv('POS_PORT') !== false ? getenv('POS_PORT') : 8000));
    }
    // Optional: load mcp_config.json if present (not required for core functionality)
    $jsonPath = __DIR__ . '/../mcp_config.json';
    if (file_exists($jsonPath)) {
        $json = @file_get_contents($jsonPath);
        $cfg = @json_decode($json, true);
        if (is_array($cfg)) {
            foreach ($cfg as $key => $value) {
                if (!defined($key)) {
                    define($key, $value);
                }
            }
        }
    }
}
?>
