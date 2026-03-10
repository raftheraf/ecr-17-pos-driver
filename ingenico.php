<?php
/**
 * ingenico.php - Invio importo al terminale POS (solo comando pagamento)
 * Protocollo: STX + payload + ETX + LRC (Nexi/Ingenico compatible).
 *
 * Configurazione: copia config.example.php in config.php oppure imposta POS_HOST e POS_PORT
 * (o le variabili d'ambiente omonime).
 *
 * Parametri GET:
 *   amount=2.55   Importo in EUR (default 1.00)
 *   tid=00000000  Terminal ID (8 cifre)
 *   crid=00000001 Cash Register ID (8 cifre)
 *   timeout=10    Timeout risposta secondi
 *   debug=1       Mostra errori PHP
 *   from=waiter   Risposta HTML per apertura da link (evita download, mostra messaggio e chiude)
 */

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}
if (!defined('POS_HOST')) {
    define('POS_HOST', getenv('POS_HOST') !== false ? getenv('POS_HOST') : '192.168.1.15');
}
if (!defined('POS_PORT')) {
    define('POS_PORT', (int) (getenv('POS_PORT') !== false ? getenv('POS_PORT') : 8000));
}

$from_waiter = isset($_REQUEST['from']) && $_REQUEST['from'] === 'waiter';

define('POS_DEBUG', getenv('POS_DEBUG') === '1' || (isset($_REQUEST['debug']) && $_REQUEST['debug'] === '1'));
if (POS_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', 0);
}

$POS_HOST = POS_HOST;
$POS_PORT = max(1, min(65535, (int) POS_PORT));
$timeout  = isset($_REQUEST['timeout']) ? max(1, min(60, (int)$_REQUEST['timeout'])) : 10;
if ($from_waiter) {
    $timeout = 0.1;
}

$amountRaw = isset($_REQUEST['amount']) ? (float)$_REQUEST['amount'] : 1.00;
$amount = round(max(0.01, min(99999.99, $amountRaw)), 2);
$amountCents = (int) round($amount * 100);

$tidRaw = isset($_REQUEST['tid']) ? preg_replace('/[^0-9]/', '', $_REQUEST['tid']) : '00000000';
$terminalId = str_pad(substr($tidRaw, 0, 8), 8, '0', STR_PAD_LEFT);
$cridRaw = isset($_REQUEST['crid']) ? preg_replace('/[^0-9]/', '', $_REQUEST['crid']) : '00000001';
$cashRegisterId = str_pad(substr($cridRaw, 0, 8), 8, '0', STR_PAD_LEFT);

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

function buildAckFrame() {
    return chr(0x06) . chr(0x03) . chr((0x7F ^ 0x06 ^ 0x03) & 0xFF);
}

$payload = buildPaymentRequest($terminalId, $cashRegisterId, $amountCents, '');
$frame = wrapStxEtxLrc($payload);

$fp = @fsockopen($POS_HOST, $POS_PORT, $errno, $errstr, 10);
if (!$fp) {
    if ($from_waiter) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>POS</title></head><body><p>ERRORE: [' . (int)$errno . '] ' . htmlspecialchars($errstr) . '</p><p><a href="#" onclick="window.close(); return false;">Chiudi finestra</a></p></body></html>';
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERRORE: [$errno] $errstr";
    }
    exit(1);
}

if (fwrite($fp, $frame) !== strlen($frame)) {
    fclose($fp);
    if ($from_waiter) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>POS</title></head><body><p>Errore invio frame.</p><p><a href="#" onclick="window.close(); return false;">Chiudi finestra</a></p></body></html>';
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Errore invio frame.";
    }
    exit(1);
}

stream_set_timeout($fp, $timeout);
$response = '';
$buf = '';
$ackFrame = buildAckFrame();
$lastDataAt = microtime(true);

while ((microtime(true) - $lastDataAt) < $timeout && strlen($response) < 8192) {
    $ch = fread($fp, 256);
    if ($ch === false || strlen($ch) === 0) {
        usleep(100000);
        continue;
    }
    $response .= $ch;
    $buf .= $ch;
    $lastDataAt = microtime(true);

    while (true) {
        $stxPos = strpos($buf, chr(0x02));
        if ($stxPos === false) {
            if (strlen($buf) > 4096) $buf = substr($buf, -128);
            break;
        }
        $etxPos = strpos($buf, chr(0x03), $stxPos + 1);
        if ($etxPos === false || ($etxPos + 1) >= strlen($buf)) break;
        @fwrite($fp, $ackFrame);
        $buf = substr($buf, $etxPos + 2);
    }
}

fclose($fp);

if ($from_waiter) {
    header('Content-Type: text/html; charset=utf-8');
    $amount_safe = htmlspecialchars(sprintf('%0.2f', $amount));
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>POS - Comando inviato</title></head><body style="font-family:sans-serif; padding:2em; text-align:center;">';
    echo '<p><strong>Comando inviato al POS</strong></p>';
    echo '<p>Importo: ' . $amount_safe . ' &euro;</p>';
    echo '<p id="countdown">La finestra si chiuderà tra 20 secondi...</p>';
    echo '<p><a href="#" onclick="window.close(); return false;">Chiudi finestra</a></p>';
    echo '<script>var s=20; var el=document.getElementById("countdown"); var t=setInterval(function(){ s--; if(s>1) el.textContent="La finestra si chiuderà tra "+s+" secondi..."; else if(s===1) el.textContent="La finestra si chiuderà tra 1 secondo..."; else { clearInterval(t); window.close(); } }, 1000);</script>';
    echo '</body></html>';
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK|" . $amount . "|" . $amountCents . "|" . strlen($response) . "\n";
    echo $response;
}
