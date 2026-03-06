<?php

// https://developer.nexigroup.com/traditionalpos/en-EU/docs/communication-protocol/
// php5.5-Win32-VC11-x64

/**
 * Nexi Traditional POS - test file (LAN Integration)
 * https://developer.nexigroup.com/traditionalpos/en-EU/docs/communication-protocol/
 *
 * Regole Nexi:
 * - LRC = 0x7F XOR tutti i byte (STX...ETX)
 * - Frame: STX(0x02) + payload + ETX(0x03) + LRC
 * - ACK: 0x06 + 0x03 + LRC | NAK: 0x15 + 0x03 + LRC
 * - Progress update: 0x01 + 20 char + 0x04 (no conferma richiesta)
 *
 * Parametri:
 * ------------------------------------------------------------
 * ?amount=1.00        Pagamento (default 1.00)
 * ?status=1           Terminal status
 * ?totals=1           Terminal totals (comando 'T')
 * ?close=1            Close session (comando 'C')
 * ?reprint=1          Ristampa ultimo ticket (comando 'R')
 * ?ticket=1           Alias legacy di reprint
 * ?print=1            Reprint verso ECR (attesi frame 'S')
 * ?type=1             1=service ticket, 0=financial ticket
 * ?raw=1              Invia payload raw (senza STX/ETX/LRC)
 * ?tid=09253031       Terminal ID
 * ?crid=00000001      Cash Register ID (solo pagamento)
 * ?gt=1               Terminal totals: additional data message present (flag pos 19)
 * ?timeout=15         Timeout lettura risposta (secondi)
 * ------------------------------------------------------------
 */

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}
if (!defined('POS_HOST')) {
    define('POS_HOST', getenv('POS_HOST') !== false ? getenv('POS_HOST') : '192.168.1.206');
}
if (!defined('POS_PORT')) {
    define('POS_PORT', (int) (getenv('POS_PORT') !== false ? getenv('POS_PORT') : 8000));
}

// Modalità debug: mostra errori PHP solo se definito o con ?debug=1 (per test)
define('TEST_POS_DEBUG', getenv('TEST_POS_DEBUG') === '1' || (isset($_REQUEST['debug']) && $_REQUEST['debug'] === '1'));
if (TEST_POS_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}

$POS_HOST = POS_HOST;
$POS_PORT = max(1, min(65535, (int) POS_PORT));
$timeout  = isset($_REQUEST['timeout']) ? max(1, min(60, (int)$_REQUEST['timeout'])) : 10;

// Validazione importo: 0.01 - 99999.99 EUR, 2 decimali
$amountRaw = isset($_REQUEST['amount']) ? (float)$_REQUEST['amount'] : 1.00;
$amount = round(max(0.01, min(99999.99, $amountRaw)), 2);
$amountCents = (int) round($amount * 100);

// Validazione Terminal ID e Cash Register ID: solo cifre, max 8 caratteri
$tidRaw = isset($_REQUEST['tid']) ? preg_replace('/[^0-9]/', '', $_REQUEST['tid']) : '09253031';
$terminalId = str_pad(substr($tidRaw, 0, 8), 8, '0', STR_PAD_LEFT);
$cridRaw = isset($_REQUEST['crid']) ? preg_replace('/[^0-9]/', '', $_REQUEST['crid']) : '00000001';
$cashRegisterId = str_pad(substr($cridRaw, 0, 8), 8, '0', STR_PAD_LEFT);
$useRaw         = isset($_REQUEST['raw']) && $_REQUEST['raw'] === '1';
$statusRequest  = isset($_REQUEST['status']) && $_REQUEST['status'] === '1';
$totalsRequest  = isset($_REQUEST['totals']) && $_REQUEST['totals'] === '1';
$closeRequest   = isset($_REQUEST['close']) && $_REQUEST['close'] === '1';
$reprintRequest = isset($_REQUEST['reprint']) && $_REQUEST['reprint'] === '1';
$ticketRequest  = isset($_REQUEST['ticket']) && $_REQUEST['ticket'] === '1';
$printOnEcr     = isset($_REQUEST['print']) && $_REQUEST['print'] === '1' ? '1' : '0';
$ticketType     = isset($_REQUEST['type']) && $_REQUEST['type'] === '1' ? '1' : '0';
$gtPresent      = isset($_REQUEST['gt']) && $_REQUEST['gt'] === '1' ? '1' : '0';

function buildStatusRequest($terminalId) {
    return str_pad(substr($terminalId, 0, 8), 8, '0', STR_PAD_LEFT) . '0s';
}

// Reprint ticket (ECR -> POS): TerminalID(8) + '0' + 'R' + printOnEcr + ticketType + reserved(10x'0')
// Doc: https://developer.nexigroup.com/traditionalpos/en-EU/docs/reprint-ticket
function buildReprintTicketRequest($terminalId, $printOnEcr, $ticketType) {
    $msg  = str_pad(substr($terminalId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '0R' . $printOnEcr . $ticketType . str_repeat('0', 10);
    return $msg;
}

function buildPaymentRequest($terminalId, $cashRegisterId, $amountCents, $receiptText) {
    $msg  = str_pad(substr($terminalId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '0P';
    $msg .= str_pad(substr($cashRegisterId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '00000';  // pos 19-23: add.data(1) + reserved(2) + cardPresent(1) + payType(1)
    $msg .= str_pad((string)$amountCents, 8, '0', STR_PAD_LEFT);
    $msg .= str_pad(substr($receiptText, 0, 128), 128, ' ', STR_PAD_LEFT);
    $msg .= '00000000';
    return $msg;
}

// Terminal Totals (ECR -> POS): TerminalID(8) + '0' + 'T' + CashRegisterID(8) + gtFlag(0/1) + reserved(7x'0')
function buildTerminalTotalsRequest($terminalId, $cashRegisterId, $gtPresent) {
    $msg  = str_pad(substr($terminalId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '0T';
    $msg .= str_pad(substr($cashRegisterId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= $gtPresent . str_repeat('0', 7);
    return $msg;
}

// Close session (ECR -> POS): TerminalID(8) + '0' + 'C' + CashRegisterID(8) + gtFlag(0/1) + reserved(7x'0')
function buildCloseSessionRequest($terminalId, $cashRegisterId, $gtPresent) {
    $msg  = str_pad(substr($terminalId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '0C';
    $msg .= str_pad(substr($cashRegisterId, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= $gtPresent . str_repeat('0', 7);
    return $msg;
}

/**
 * Nexi: LRC = 0x7F XOR tutti i byte da STX a ETX inclusi
 * Frame: STX(0x02) + payload + ETX(0x03) + LRC
 */
function wrapStxEtxLrc($payload) {
    $stx = chr(0x02);
    $etx = chr(0x03);
    $data = $stx . $payload . $etx;
    $lrc = 0x7F;
    for ($i = 0; $i < strlen($data); $i++) {
        $lrc ^= ord($data[$i]);
    }
    return $data . chr($lrc);
}

function dumpHex($label, $data, $maxBytes) {
    $hex = bin2hex($data);
    $hexSpaced = trim(chunk_split($hex, 2, ' '));
    if (strlen($data) > $maxBytes) {
        $hexSpaced = substr($hexSpaced, 0, $maxBytes * 3) . ' ...';
    }
    echo $label . " (" . strlen($data) . " byte): " . $hexSpaced . "\n";
}

function calcLrcNexi($stxPayloadEtx) {
    $lrc = 0x7F;
    for ($i = 0; $i < strlen($stxPayloadEtx); $i++) {
        $lrc ^= ord($stxPayloadEtx[$i]);
    }
    return $lrc & 0xFF;
}

function renderTicketText($data) {
    $out = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $b = ord($data[$i]);
        if ($b === 0x7D) { // new line
            $out .= "\n";
        } elseif ($b === 0x1B) { // ticket end marker
            $out .= "[END]";
        } elseif ($b === 0x7F) { // double height bold start (used)
            $out .= "[DH_B]";
        } elseif ($b < 0x20) {
            $out .= sprintf("[0x%02X]", $b);
        } else {
            $out .= $data[$i];
        }
    }
    return $out;
}

function buildAckFrame() {
    $ack = chr(0x06) . chr(0x03);
    $lrc = (0x7F ^ 0x06 ^ 0x03) & 0xFF; // = 0x7A
    return $ack . chr($lrc);
}

if ($statusRequest) {
    $payload = buildStatusRequest($terminalId);
} elseif ($totalsRequest) {
    $payload = buildTerminalTotalsRequest($terminalId, $cashRegisterId, $gtPresent);
} elseif ($closeRequest) {
    $payload = buildCloseSessionRequest($terminalId, $cashRegisterId, $gtPresent);
} elseif ($reprintRequest || $ticketRequest) {
    $payload = buildReprintTicketRequest($terminalId, $printOnEcr, $ticketType);
} else {
    $payload = buildPaymentRequest($terminalId, $cashRegisterId, $amountCents, '');
}

$frame = $useRaw ? $payload : wrapStxEtxLrc($payload);

$cmdLabel = $statusRequest
    ? "Terminal Status"
    : ($totalsRequest
        ? "Terminal Totals (T)"
        : ($closeRequest
            ? "Close session (C)"
            : (($reprintRequest || $ticketRequest) ? "Reprint ticket (R)" : "Payment")));

echo "<pre>";
echo "=== Nexi Traditional POS - $cmdLabel ===\n";
echo "Host: $POS_HOST : $POS_PORT | Terminal: $terminalId";
echo ($statusRequest || $reprintRequest || $ticketRequest) ? "\n" : " | Cassa: $cashRegisterId\n";
if ($totalsRequest) {
    echo "Cassa: $cashRegisterId | GT add.data: " . ($gtPresent === '1' ? "presente" : "assente") . "\n";
}
if ($closeRequest) {
    echo "Cassa: $cashRegisterId | GT add.data: " . ($gtPresent === '1' ? "presente" : "assente") . "\n";
}
if ($reprintRequest || $ticketRequest) {
    echo "Stampa ECR: " . ($printOnEcr === '1' ? "sì" : "no") . " | Tipo: " . ($ticketType === '1' ? "servizio" : "finanziario") . "\n";
}
if (!$statusRequest && !$totalsRequest && !$closeRequest && !$reprintRequest && !$ticketRequest) {
    echo "Importo: " . number_format($amount, 2, ',', '.') . " EUR ($amountCents cent)\n";
}
echo "Frame: " . ($useRaw ? "RAW" : "STX+ETX+LRC") . " (" . strlen($frame) . " byte)\n\n";

$fp = @fsockopen($POS_HOST, $POS_PORT, $errno, $errstr, 10);
if (!$fp) {
    echo "ERRORE: [$errno] $errstr\n</pre>";
    exit;
}

if (fwrite($fp, $frame) != strlen($frame)) {
    echo "Errore invio.\n</pre>";
    fclose($fp);
    exit;
}

echo "Inviati " . strlen($frame) . " byte. In attesa risposta...\n\n";

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

    // ACK immediato di eventuali frame STX..ETX ricevuti (utile per stream ticket 'S')
    while (true) {
        $stxPos = strpos($buf, chr(0x02));
        if ($stxPos === false) {
            if (strlen($buf) > 4096) $buf = substr($buf, -128);
            break;
        }
        $etxPos = strpos($buf, chr(0x03), $stxPos + 1);
        if ($etxPos === false || ($etxPos + 1) >= strlen($buf)) {
            break;
        }
        @fwrite($fp, $ackFrame);
        $buf = substr($buf, $etxPos + 2); // rimuovi STX..ETX+LRC dal buffer
    }
}

fclose($fp);

echo "--- Risposta POS (" . strlen($response) . " byte) ---\n";
if (strlen($response) === 0) {
    echo "Nessun dato (timeout o connessione chiusa).\n</pre>";
    exit;
}

dumpHex("Hex", $response, 200);

$offset = 0;
while ($offset < strlen($response)) {
    $b0 = ord($response[$offset]);

    if ($b0 === 0x06 && ($offset + 2) < strlen($response) && ord($response[$offset + 1]) === 0x03) {
        echo "\n[ACK] POS ha confermato il messaggio.\n";
        $offset += 3;
        continue;
    }

    if ($b0 === 0x15 && ($offset + 2) < strlen($response) && ord($response[$offset + 1]) === 0x03) {
        echo "\n[NAK] POS ha rifiutato il messaggio.\n";
        echo "Verifica Terminal ID ($terminalId), Cash Register ID o formato LRC (base 0x7F).\n";
        $offset += 3;
        continue;
    }

    if ($b0 === 0x01 && strlen($response) - $offset >= 22) {
        $msg = substr($response, $offset + 1, 20);
        $eot = ord($response[$offset + 21]);
        if ($eot === 0x04) {
            echo "\n[Progress] " . trim($msg) . "\n";
            $offset += 22;
            continue;
        }
    }

    if ($b0 === 0x02) {
        $etxPos = strpos($response, chr(0x03), $offset + 1);
        if ($etxPos === false || ($etxPos + 1) >= strlen($response)) {
            break;
        }

        $payloadRx = substr($response, $offset + 1, $etxPos - ($offset + 1));
        $lrcRx = ord($response[$etxPos + 1]);
        $lrcCalc = calcLrcNexi(substr($response, $offset, ($etxPos - $offset + 1))); // STX..ETX

        echo "\n[Frame] STX..ETX (payload " . strlen($payloadRx) . " byte) | LRC " . sprintf("%02X", $lrcRx) . ($lrcRx === $lrcCalc ? " (OK)" : " (ATTESO " . sprintf("%02X", $lrcCalc) . ")") . "\n";

        if (strlen($payloadRx) >= 10) {
            $code = $payloadRx[9];

            if ($code === 's') {
                $labels = array(
                    '0' => 'non configurato',
                    '1' => 'configurato, no DLL',
                    '2' => 'operativo',
                    '3' => 'non allineato',
                    '4' => 'chiave KMPB corrotta',
                    '5' => 'DLL in attesa',
                    '6' => 'SW update in attesa'
                );
                $st = strlen($payloadRx) > 30 ? $payloadRx[30] : '?';
                echo "\n[Terminal status]:\n";
                echo "  Terminal: " . substr($payloadRx, 0, 8) . " | Data/ora: " . (strlen($payloadRx) >= 30 ? substr($payloadRx, 20, 10) : '') . " (DDMMYYhhmm)\n";
                echo "  Stato: $st (" . (isset($labels[$st]) ? $labels[$st] : '?') . ")\n";
                if (strlen($payloadRx) > 31) echo "  SW: " . trim(substr($payloadRx, 31)) . "\n";
            } elseif ($code === 'S') {
                $ticketChunk = substr($payloadRx, 10);
                echo "\n[Ticket S] Righe ricevute (" . strlen($ticketChunk) . " byte):\n";
                echo renderTicketText($ticketChunk) . "\n";
            } elseif ($code === 'T') {
                // Terminal totals response: TerminalID(8) + '0' + 'T' + result(2) + total(16) + reserved(6)
                $result = substr($payloadRx, 10, 2);
                $totalStr = substr($payloadRx, 12, 16);
                $totalCents = (int)$totalStr;
                $totalEur = number_format($totalCents / 100, 2, ',', '.');

                echo "\n[Terminal totals]:\n";
                echo "  Esito: $result " . ($result === '00' ? "(OK)" : ($result === '01' ? "(KO)" : ($result === '09' ? "(unknown tag GT)" : ""))) . "\n";
                echo "  Totale EFT-POS: $totalStr cent (" . $totalEur . " EUR)\n";
            } elseif ($code === 'C') {
                // Close session response:
                // TerminalID(8) + '0' + 'C' + result(2) + [eftTotal(16) + hostTotal(16) if result==00]
                $result = substr($payloadRx, 10, 2);
                echo "\n[Close session]:\n";
                echo "  Esito: $result " . ($result === '00' ? "(OK)" : ($result === '01' ? "(KO)" : ($result === '09' ? "(unknown tag GT)" : ""))) . "\n";

                if ($result === '00' && strlen($payloadRx) >= 44) {
                    $eftStr = substr($payloadRx, 12, 16);
                    $hostStr = substr($payloadRx, 28, 16);
                    $eftCents = (int)$eftStr;
                    $hostCents = (int)$hostStr;
                    echo "  Totale EFT-POS: $eftStr cent (" . number_format($eftCents / 100, 2, ',', '.') . " EUR)\n";
                    echo "  Totale HOST:    $hostStr cent (" . number_format($hostCents / 100, 2, ',', '.') . " EUR)\n";
                }
            } elseif ($code === 'E' || $code === 'V') {
                $result = substr($payloadRx, 10, 2);
                echo "\n[Payment response]: " . ($code === 'V' ? "DCC " : "") . "Esito $result ";
                if ($result === '00') echo "(OK)\n";
                elseif ($result === '01') echo "(KO)\n";
                elseif ($result === '05') echo "(carta assente)\n";
                else echo "\n";
            } else {
                echo "\n[Frame] Code '$code' non gestito.\n";
            }
        } else {
            echo "\n[Frame] Payload troppo corto per code.\n";
        }

        $offset = $etxPos + 2;
        continue;
    }

    $offset++;
}

if (($reprintRequest || $ticketRequest) && $printOnEcr === '0') {
    echo "\nNota: per 'Reprint ticket' con stampa sul POS (print=0) la doc prevede solo ACK come conferma.\n";
    echo "Se non stampa: verifica che il POS abbia stampante/carta e che esista un ultimo ticket da ristampare.\n";
    echo "Per vedere le righe ricevute dall'ECR, usa print=1 (attesi frame 'S').\n";
}

echo "\n--- Fine ---\n</pre>";

?>