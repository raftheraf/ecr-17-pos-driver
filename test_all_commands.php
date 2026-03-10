<?php
/**
 * Test tutti i comandi del protocollo Nexi ECR17 (da docs/Nexi_ECR17_Protocol.txt)
 * Pagina HTML con pulsanti per ogni comando; risposta mostrata in pagina.
 *
 * Parametri GET (per richiesta comando):
 *   cmd=status|payment|payment_ext|reversal|...
 *   host=192.168.1.15  IP del terminale (opzionale, altrimenti da config)
 *   port=8000           Porta (opzionale, altrimenti da config)
 *   tid=00000000   Terminal ID (8 cifre)
 *   crid=00000001  Cash Register ID (8 cifre)
 *   amount=1.00    Importo EUR (pagamento, preauth, ecc.)
 *   stan=000000    STAN per storno (6 cifre)
 *   preauth_code=  Codice pre-autorizzazione (per incr_auth e preauth_close)
 *   print_ecr=0|1  Abilita stampa su ECR (enable_print, reprint)
 *   ticket_type=0|1 0=finanziario, 1=servizio (reprint)
 *   vas_xml=       Payload XML per VAS/APM (comando K)
 *
 * Se richiesta senza cmd: restituisce la pagina HTML con i pulsanti.
 * Se richiesta con cmd (e ajax=1): restituisce JSON { ok, output, rawLength }.
 */

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}
if (!defined('POS_HOST')) {
    define('POS_HOST', getenv('POS_HOST') !== false ? getenv('POS_HOST') : '192.168.1.15');
}
if (!defined('POS_PORT')) {
    define('POS_PORT', (int)(getenv('POS_PORT') !== false ? getenv('POS_PORT') : 8000));
}
if (!defined('POS_TERMINAL_ID')) {
    define('POS_TERMINAL_ID', getenv('POS_TERMINAL_ID') !== false ? getenv('POS_TERMINAL_ID') : '00000000');
}

$POS_HOST = POS_HOST;
$POS_PORT = max(1, min(65535, (int)POS_PORT));
$timeout   = isset($_REQUEST['timeout']) ? max(1, min(60, (int)$_REQUEST['timeout'])) : 10;

if (isset($_REQUEST['host']) && trim($_REQUEST['host']) !== '') {
    $POS_HOST = trim($_REQUEST['host']);
}
if (isset($_REQUEST['port']) && $_REQUEST['port'] !== '') {
    $portReq = (int) $_REQUEST['port'];
    if ($portReq >= 1 && $portReq <= 65535) {
        $POS_PORT = $portReq;
    }
}

$tidRaw = isset($_REQUEST['tid']) ? preg_replace('/[^0-9]/', '', $_REQUEST['tid']) : preg_replace('/[^0-9]/', '', POS_TERMINAL_ID);
$terminalId = str_pad(substr($tidRaw, 0, 8), 8, '0', STR_PAD_LEFT);
$cridRaw = isset($_REQUEST['crid']) ? preg_replace('/[^0-9]/', '', $_REQUEST['crid']) : '00000001';
$cashRegisterId = str_pad(substr($cridRaw, 0, 8), 8, '0', STR_PAD_LEFT);

$amountRaw = isset($_REQUEST['amount']) ? (float)$_REQUEST['amount'] : 1.00;
$amount = round(max(0.01, min(99999.99, $amountRaw)), 2);
$amountCents = (int)round($amount * 100);

$stan = isset($_REQUEST['stan']) ? str_pad(preg_replace('/[^0-9]/', '', $_REQUEST['stan']), 6, '0', STR_PAD_LEFT) : '000000';
$preauthCode = isset($_REQUEST['preauth_code']) ? str_pad(substr(preg_replace('/[^0-9]/', '', $_REQUEST['preauth_code']), 0, 9), 9, '0', STR_PAD_LEFT) : '000000000';
$printEcr = isset($_REQUEST['print_ecr']) && $_REQUEST['print_ecr'] === '1' ? '1' : '0';
$ticketType = isset($_REQUEST['ticket_type']) && $_REQUEST['ticket_type'] === '1' ? '1' : '0';
$vasXml = isset($_REQUEST['vas_xml']) ? $_REQUEST['vas_xml'] : '<ecrreq><p k="ECRVASID">BPAY_TOTAL</p></ecrreq>';

$isAjax = isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === '1';
$cmd = isset($_REQUEST['cmd']) ? trim($_REQUEST['cmd']) : '';

// ---------- Builder comandi (formato documento Nexi) ----------

function buildStatus($tid) {
    return str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0s';
}

function buildPayment($tid, $crid, $amountCents, $receiptText = '', $extended = false) {
    $code = $extended ? 'X' : 'P';
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0' . $code;
    $msg .= str_pad(substr($crid, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '00000'; // gt=0, reserved, cardPresent=0, payType=0
    $msg .= str_pad((string)$amountCents, 8, '0', STR_PAD_LEFT);
    $msg .= str_pad(substr($receiptText, 0, 128), 128, ' ', STR_PAD_LEFT);
    $msg .= '00000000';
    return $msg;
}

function buildReversal($tid, $crid, $stan, $gt = '0') {
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0S';
    $msg .= str_pad(substr($crid, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= str_pad(substr($stan, 0, 6), 6, '0', STR_PAD_LEFT);
    $msg .= $gt . '0'; // gt + same card
    return $msg;
}

function buildPreauth($tid, $crid, $amountCents, $receiptText = '') {
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0p';
    $msg .= str_pad(substr($crid, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '00000';
    $msg .= str_pad((string)$amountCents, 8, '0', STR_PAD_LEFT);
    $msg .= str_pad(substr($receiptText, 0, 128), 128, ' ', STR_PAD_LEFT);
    $msg .= '00000000';
    return $msg;
}

function buildIncrementalAuth($tid, $crid, $amountCents, $preauthCode) {
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0i';
    $msg .= str_pad(substr($crid, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '00000';
    $msg .= str_pad((string)$amountCents, 8, '0', STR_PAD_LEFT);
    $msg .= str_repeat(' ', 128);
    $msg .= '00000000';
    // pos 160 (1-based) = byte 159: 9 bytes Preauthorization Code
    $len = strlen($msg);
    if ($len < 159) $msg .= str_repeat('0', 159 - $len);
    $msg .= str_pad(substr($preauthCode, 0, 9), 9, '0', STR_PAD_LEFT);
    return $msg;
}

function buildPreauthClose($tid, $crid, $amountCents, $preauthCode) {
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0c';
    $msg .= str_pad(substr($crid, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= '00000';
    $msg .= str_pad((string)$amountCents, 8, '0', STR_PAD_LEFT);
    $msg .= str_repeat(' ', 128);
    $msg .= '00000000';
    $len = strlen($msg);
    if ($len < 159) $msg .= str_repeat('0', 159 - $len);
    $msg .= str_pad(substr($preauthCode, 0, 9), 9, '0', STR_PAD_LEFT);
    return $msg;
}

function buildCardVerify($tid, $payType = '0') {
    // pos 10='H', 22='0' (standard), 23=payType
    $msg = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0H';
    $msg .= str_repeat('0', 12);
    $msg .= '0' . $payType;
    return $msg;
}

function buildAdditionalData($tid, $tagContent = '') {
    // Tipo pagamento 6, campo ISO 2, TAG 8, riservato 1, indice 4, riservato 5, contenuto fino a 100
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0U';
    $msg .= '000000'; // tipo pagamento
    $msg .= '62';    // campo ISO
    $msg .= 'DF8D01 '; // 8 byte TAG
    $msg .= '0';
    $msg .= '0000';
    $msg .= '00000';
    $content = substr($tagContent, 0, 100);
    $msg .= $content;
    if (strlen($content) < 100) $msg .= chr(0x01) . chr(0x0B); // 01 0B hex end
    return $msg;
}

function buildCloseSession($tid, $crid, $gt = '0') {
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0C';
    $msg .= str_pad(substr($crid, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= $gt . str_repeat('0', 7);
    return $msg;
}

function buildTerminalTotals($tid, $crid, $gt = '0') {
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0T';
    $msg .= str_pad(substr($crid, 0, 8), 8, '0', STR_PAD_LEFT);
    $msg .= $gt . str_repeat('0', 7);
    return $msg;
}

function buildLastResult($tid) {
    return str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0G';
}

function buildEnablePrint($tid, $enable = '1') {
    return str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0E' . $enable;
}

function buildReprint($tid, $printOnEcr = '0', $ticketType = '0') {
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0R';
    $msg .= $printOnEcr . $ticketType . str_repeat('0', 10);
    return $msg;
}

function buildVas($tid, $xmlPayload) {
    $xml = substr($xmlPayload, 0, 1024);
    $len = strlen($xml);
    $msg  = str_pad(substr($tid, 0, 8), 8, '0', STR_PAD_LEFT) . '0K';
    $msg .= str_repeat('0', 12); // fino a pos 23
    $msg .= str_pad((string)$len, 4, '0', STR_PAD_LEFT);
    $msg .= $xml;
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

/** Decodifica testo scontrino (comando S): 0x7D=nuova riga, 0x7F=grassetto, 0x1B=fine */
function renderTicketText($data) {
    $out = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $b = ord($data[$i]);
        if ($b === 0x7D) {
            $out .= "\n";
        } elseif ($b === 0x1B) {
            $out .= "[FINE SCONTRINO]";
        } elseif ($b === 0x7F) {
            $out .= "[GRASSETTO]";
        } elseif ($b < 0x20) {
            $out .= sprintf("[0x%02X]", $b);
        } else {
            $out .= $data[$i];
        }
    }
    return $out;
}

function sendAndReceive($host, $port, $frame, $timeout) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) {
        return ['errno' => $errno, 'errstr' => $errstr, 'response' => ''];
    }
    if (fwrite($fp, $frame) !== strlen($frame)) {
        fclose($fp);
        return ['errno' => 0, 'errstr' => 'Errore invio frame', 'response' => ''];
    }
    stream_set_timeout($fp, $timeout);
    $response = '';
    $buf = '';
    $ackFrame = buildAckFrame();
    $lastDataAt = microtime(true);
    while ((microtime(true) - $lastDataAt) < $timeout && strlen($response) < 16384) {
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
    return ['errno' => 0, 'errstr' => '', 'response' => $response];
}

/** Invia due frame su due connessioni separate (E poi R). Alcuni POS chiudono la connessione dopo l'ACK. */
function sendTwoCommandsAndReceive($host, $port, $frame1, $frame2, $timeout1 = 5, $timeout2 = 15) {
    $ackFrame = buildAckFrame();
    $allResponse = '';

    // Prima connessione: comando E (abilita stampa ECR)
    $fp = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) {
        return ['errno' => $errno, 'errstr' => $errstr, 'response' => ''];
    }
    if (fwrite($fp, $frame1) !== strlen($frame1)) {
        fclose($fp);
        return ['errno' => 0, 'errstr' => 'Errore invio frame E', 'response' => ''];
    }
    stream_set_timeout($fp, $timeout1);
    $buf = '';
    $lastDataAt = microtime(true);
    while ((microtime(true) - $lastDataAt) < $timeout1 && strlen($allResponse) < 8192) {
        $ch = fread($fp, 256);
        if ($ch === false || strlen($ch) === 0) {
            usleep(100000);
            continue;
        }
        $allResponse .= $ch;
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

    // Seconda connessione: comando R (ristampa, invio a ECR)
    $fp2 = @fsockopen($host, $port, $errno2, $errstr2, 10);
    if (!$fp2) {
        return ['errno' => $errno2, 'errstr' => $errstr2, 'response' => $allResponse];
    }
    if (fwrite($fp2, $frame2) !== strlen($frame2)) {
        fclose($fp2);
        return ['errno' => 0, 'errstr' => 'Errore invio frame R', 'response' => $allResponse];
    }
    stream_set_timeout($fp2, $timeout2);
    $buf2 = '';
    $lastDataAt = microtime(true);
    while ((microtime(true) - $lastDataAt) < $timeout2 && strlen($allResponse) < 32768) {
        $ch = fread($fp2, 256);
        if ($ch === false || strlen($ch) === 0) {
            usleep(100000);
            continue;
        }
        $allResponse .= $ch;
        $buf2 .= $ch;
        $lastDataAt = microtime(true);
        while (true) {
            $stxPos = strpos($buf2, chr(0x02));
            if ($stxPos === false) {
                if (strlen($buf2) > 4096) $buf2 = substr($buf2, -128);
                break;
            }
            $etxPos = strpos($buf2, chr(0x03), $stxPos + 1);
            if ($etxPos === false || ($etxPos + 1) >= strlen($buf2)) break;
            @fwrite($fp2, $ackFrame);
            $buf2 = substr($buf2, $etxPos + 2);
        }
    }
    fclose($fp2);
    return ['errno' => 0, 'errstr' => '', 'response' => $allResponse];
}

function formatHex($data, $maxBytes = 256) {
    $hex = bin2hex($data);
    $hexSpaced = trim(chunk_split($hex, 2, ' '));
    if (strlen($data) > $maxBytes) {
        $hexSpaced = substr($hexSpaced, 0, $maxBytes * 3) . ' ...';
    }
    return $hexSpaced;
}

function parseAndDescribe($response, $cmdLabel) {
    $out = "--- Risposta POS (" . strlen($response) . " byte) ---\n";
    $out .= "Hex: " . formatHex($response, 200) . "\n\n";
    $offset = 0;
    while ($offset < strlen($response)) {
        $b0 = ord($response[$offset]);
        if ($b0 === 0x06 && ($offset + 2) < strlen($response) && ord($response[$offset + 1]) === 0x03) {
            $out .= "[ACK] POS ha confermato il messaggio.\n";
            $offset += 3;
            continue;
        }
        if ($b0 === 0x15 && ($offset + 2) < strlen($response) && ord($response[$offset + 1]) === 0x03) {
            $out .= "[NAK] POS ha rifiutato il messaggio.\n";
            $offset += 3;
            continue;
        }
        if ($b0 === 0x02) {
            $etxPos = strpos($response, chr(0x03), $offset + 1);
            if ($etxPos === false || ($etxPos + 1) >= strlen($response)) break;
            $payloadRx = substr($response, $offset + 1, $etxPos - ($offset + 1));
            if (strlen($payloadRx) >= 10) {
                $code = $payloadRx[9];
                $out .= "[Frame] Codice messaggio: '" . $code . "' (0x" . sprintf('%02X', ord($code)) . ")\n";
                if ($code === 's') {
                    $st = strlen($payloadRx) > 30 ? $payloadRx[30] : '?';
                    $out .= "  Terminal: " . substr($payloadRx, 0, 8) . " | Data/ora: " . (strlen($payloadRx) >= 30 ? substr($payloadRx, 20, 10) : '') . "\n";
                    $out .= "  Stato: $st\n";
                } elseif ($code === 'C') {
                    $result = substr($payloadRx, 10, 2);
                    $out .= "  Esito chiusura: $result\n";
                } elseif ($code === 'T') {
                    $result = substr($payloadRx, 10, 2);
                    $totalStr = substr($payloadRx, 12, 16);
                    $out .= "  Esito totali: $result | Totale: $totalStr cent\n";
                } elseif ($code === 'E' || $code === 'V' || $code === 'e' || $code === 'c' || $code === 'i') {
                    $result = substr($payloadRx, 10, 2);
                    $out .= "  Esito transazione: $result\n";
                } elseif ($code === 'S') {
                    $ticketChunk = substr($payloadRx, 10);
                    $out .= "  --- Testo scontrino ---\n  " . str_replace("\n", "\n  ", trim(renderTicketText($ticketChunk))) . "\n";
                } elseif ($code === 'K') {
                    $out .= "  (Risposta VAS/APM XML)\n";
                }
            }
            $offset = $etxPos + 2;
            continue;
        }
        $offset++;
    }
    return $out;
}

// ---------- Esecuzione comando ----------
if ($cmd !== '' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $payload = '';
    $label = $cmd;
    switch ($cmd) {
        case 'status':
            $payload = buildStatus($terminalId);
            $label = 'Stato terminale (s)';
            break;
        case 'payment':
            $payload = buildPayment($terminalId, $cashRegisterId, $amountCents, '');
            $label = 'Pagamento base (P)';
            break;
        case 'payment_ext':
            $payload = buildPayment($terminalId, $cashRegisterId, $amountCents, '', true);
            $label = 'Pagamento con risultato esteso (X)';
            break;
        case 'reversal':
            $payload = buildReversal($terminalId, $cashRegisterId, $stan, '0');
            $label = 'Storno (S)';
            break;
        case 'preauth':
            $payload = buildPreauth($terminalId, $cashRegisterId, $amountCents, '');
            $label = 'Pre-autorizzazione (p)';
            break;
        case 'incr_auth':
            $payload = buildIncrementalAuth($terminalId, $cashRegisterId, $amountCents, $preauthCode);
            $label = 'Autorizzazione incrementale (i)';
            break;
        case 'preauth_close':
            $payload = buildPreauthClose($terminalId, $cashRegisterId, $amountCents, $preauthCode);
            $label = 'Chiusura pre-autorizzazione (c)';
            break;
        case 'card_verify':
            $payload = buildCardVerify($terminalId, '0');
            $label = 'Verifica carta (H)';
            break;
        case 'additional_data':
            $payload = buildAdditionalData($terminalId, '');
            $label = 'Dati aggiuntivi / TAG (U)';
            break;
        case 'close_session':
            $payload = buildCloseSession($terminalId, $cashRegisterId, '0');
            $label = 'Chiusura sessione (C)';
            break;
        case 'totals':
            $payload = buildTerminalTotals($terminalId, $cashRegisterId, '0');
            $label = 'Totali terminale (T)';
            break;
        case 'last_result':
            $payload = buildLastResult($terminalId);
            $label = 'Invia ultimo risultato (G)';
            break;
        case 'enable_print':
            $payload = buildEnablePrint($terminalId, $printEcr);
            $label = 'Abilita/Disabilita stampa ECR (E)';
            break;
        case 'reprint':
            $payload = buildReprint($terminalId, $printEcr, $ticketType);
            $label = 'Ristampa scontrino (R)';
            break;
        case 'reprint_ecr':
            // Comando combinato: prima E (abilita stampa su ECR), poi R (ristampa con invio a ECR)
            $frameE = wrapStxEtxLrc(buildEnablePrint($terminalId, '1'));
            $frameR = wrapStxEtxLrc(buildReprint($terminalId, '1', $ticketType));
            $result = sendTwoCommandsAndReceive($POS_HOST, $POS_PORT, $frameE, $frameR, 5, 15);
            $output = "=== Ristampa + stampa su ECR (E poi R) ===\n";
            $output .= "Host: $POS_HOST : $POS_PORT | Terminal: $terminalId\n";
            $output .= "1) Inviato E (abilita stampa ECR)\n";
            $output .= "2) Inviato R (ristampa, invio a ECR) | Tipo scontrino: " . ($ticketType === '1' ? 'servizio' : 'finanziario') . "\n\n";
            if ($result['errno'] !== 0 || $result['errstr'] !== '') {
                $output .= "ERRORE: [" . $result['errno'] . "] " . $result['errstr'] . "\n";
                echo json_encode(['ok' => false, 'output' => $output, 'rawLength' => 0], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $output .= parseAndDescribe($result['response'], 'reprint_ecr');
            if (strpos($result['response'], chr(0x02)) === false) {
                $output .= "\nNota: nessun frame S (scontrino) ricevuto. Il POS ha accettato E e R (doppio ACK).\n";
                $output .= "Lo scontrino può essere inviato alla stampante ECR fisica collegata al POS, non su questa connessione TCP.\n";
            }
            echo json_encode(['ok' => true, 'output' => $output, 'rawLength' => strlen($result['response'])], JSON_UNESCAPED_UNICODE);
            exit;
        case 'vas':
            $payload = buildVas($terminalId, $vasXml);
            $label = 'Richiesta VAS/APM (K)';
            break;
        default:
            echo json_encode(['ok' => false, 'output' => "Comando non valido: $cmd", 'rawLength' => 0], JSON_UNESCAPED_UNICODE);
            exit;
    }

    $frame = wrapStxEtxLrc($payload);
    $result = sendAndReceive($POS_HOST, $POS_PORT, $frame, $timeout);

    $output = "=== $label ===\n";
    $output .= "Host: $POS_HOST : $POS_PORT | Terminal: $terminalId";
    if (!in_array($cmd, ['status', 'last_result', 'card_verify'], true)) {
        $output .= " | Cassa: $cashRegisterId";
    }
    $output .= "\n";
    if (in_array($cmd, ['payment', 'payment_ext', 'preauth', 'incr_auth', 'preauth_close'], true)) {
        $output .= "Importo: " . number_format($amount, 2, ',', '.') . " EUR ($amountCents cent)\n";
    }
    if ($cmd === 'reversal') $output .= "STAN: $stan\n";
    if (in_array($cmd, ['incr_auth', 'preauth_close'], true)) $output .= "Preauth code: $preauthCode\n";
    $output .= "Frame inviato: " . strlen($frame) . " byte\n\n";

    if ($result['errno'] !== 0 || $result['errstr'] !== '') {
        $output .= "ERRORE: [" . $result['errno'] . "] " . $result['errstr'] . "\n";
        echo json_encode(['ok' => false, 'output' => $output, 'rawLength' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $output .= parseAndDescribe($result['response'], $label);
    echo json_encode([
        'ok' => true,
        'output' => $output,
        'rawLength' => strlen($result['response'])
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Pagina HTML (nessun cmd o non ajax) ----------
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test comandi ECR17 Nexi</title>
    <style>
        :root {
            --bg: #1a1b26;
            --surface: #24283b;
            --text: #c0caf5;
            --muted: #565f89;
            --accent: #7aa2f7;
            --ok: #9ece6a;
            --err: #f7768e;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 1.5rem;
            line-height: 1.5;
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0 0 0.5rem;
            color: var(--accent);
        }
        .sub {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .params {
            background: var(--surface);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.75rem;
            align-items: end;
        }
        .params label {
            display: block;
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 0.25rem;
        }
        .params input, .params select {
            width: 100%;
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--muted);
            border-radius: 4px;
            background: var(--bg);
            color: var(--text);
            font-size: 0.9rem;
        }
        section {
            margin-bottom: 1.5rem;
        }
        section h2 {
            font-size: 1rem;
            color: var(--muted);
            margin: 0 0 0.5rem;
            font-weight: 600;
        }
        .buttons {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 0.5rem;
        }
        .buttons button {
            padding: 0.6rem 0.9rem;
            border: 1px solid var(--muted);
            border-radius: 6px;
            background: var(--surface);
            color: var(--text);
            cursor: pointer;
            font-size: 0.9rem;
            text-align: left;
            transition: background 0.15s, border-color 0.15s;
        }
        .buttons button:hover {
            background: #2d325a;
            border-color: var(--accent);
        }
        .buttons button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        #result {
            background: var(--surface);
            border: 1px solid var(--muted);
            border-radius: 8px;
            padding: 1rem;
            min-height: 120px;
            margin-top: 1rem;
        }
        #result pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-all;
            font-size: 0.8rem;
            font-family: 'Consolas', 'Monaco', monospace;
            color: var(--text);
        }
        #result.loading { color: var(--muted); }
        #result.error pre { color: var(--err); }
        #result.success pre { color: var(--ok); }
    </style>
</head>
<body>
    <h1>Test comandi protocollo Nexi ECR17</h1>
    <p class="sub">Tutti i comandi da docs/Nexi_ECR17_Protocol.txt — Host: <?php echo htmlspecialchars($POS_HOST); ?>:<?php echo (int)$POS_PORT; ?></p>

    <div class="params">
        <div>
            <label>Host (IP terminale)</label>
            <input type="text" id="pos_host" value="<?php echo htmlspecialchars($POS_HOST); ?>" placeholder="192.168.1.15">
        </div>
        <div>
            <label>Porta</label>
            <input type="number" id="pos_port" value="<?php echo (int)$POS_PORT; ?>" min="1" max="65535" placeholder="8000">
        </div>
        <div>
            <label>Terminal ID</label>
            <input type="text" id="tid" value="<?php echo htmlspecialchars($terminalId); ?>" maxlength="8" placeholder="00000000">
        </div>
        <div>
            <label>Cash Register ID</label>
            <input type="text" id="crid" value="<?php echo htmlspecialchars($cashRegisterId); ?>" maxlength="8" placeholder="00000001">
        </div>
        <div>
            <label>Importo (EUR)</label>
            <input type="text" id="amount" value="1.00" placeholder="1.00">
        </div>
        <div>
            <label>STAN (storno)</label>
            <input type="text" id="stan" value="000000" maxlength="6" placeholder="000000">
        </div>
        <div>
            <label>Preauth code</label>
            <input type="text" id="preauth_code" value="000000000" maxlength="9" placeholder="000000000">
        </div>
        <div>
            <label>Stampa su ECR</label>
            <select id="print_ecr">
                <option value="0">No</option>
                <option value="1">Sì</option>
            </select>
        </div>
        <div>
            <label>Tipo scontrino (R)</label>
            <select id="ticket_type">
                <option value="0">Finanziario</option>
                <option value="1">Servizio</option>
            </select>
        </div>
    </div>

    <section>
        <h2>1. Stato e totali</h2>
        <div class="buttons">
            <button type="button" data-cmd="status">1. Stato terminale (s)</button>
            <button type="button" data-cmd="totals">11. Totali terminale (T)</button>
            <button type="button" data-cmd="close_session">10. Chiusura sessione (C)</button>
            <button type="button" data-cmd="last_result">12. Invia ultimo risultato (G)</button>
        </div>
    </section>

    <section>
        <h2>2. Pagamenti</h2>
        <div class="buttons">
            <button type="button" data-cmd="payment">2. Pagamento base (P)</button>
            <button type="button" data-cmd="payment_ext">3. Pagamento esteso (X)</button>
            <button type="button" data-cmd="reversal">4. Storno (S)</button>
            <button type="button" data-cmd="card_verify">8. Verifica carta (H)</button>
        </div>
    </section>

    <section>
        <h2>3. Pre-autorizzazioni</h2>
        <div class="buttons">
            <button type="button" data-cmd="preauth">5. Pre-autorizzazione (p)</button>
            <button type="button" data-cmd="incr_auth">6. Autorizzazione incrementale (i)</button>
            <button type="button" data-cmd="preauth_close">7. Chiusura pre-autorizzazione (c)</button>
        </div>
    </section>

    <section>
        <h2>4. Scontrini e stampa</h2>
        <div class="buttons">
            <button type="button" data-cmd="enable_print">13. Abilita/Disabilita stampa ECR (E)</button>
            <button type="button" data-cmd="reprint">15. Ristampa scontrino (R)</button>
            <button type="button" data-cmd="reprint_ecr">Ristampa + stampa su ECR (E poi R)</button>
        </div>
    </section>

    <section>
        <h2>5. Altri</h2>
        <div class="buttons">
            <button type="button" data-cmd="additional_data">9. Dati aggiuntivi / TAG (U)</button>
            <button type="button" data-cmd="vas">16. Richiesta VAS/APM (K)</button>
        </div>
    </section>

    <div id="result">
        <pre>Clicca un pulsante per inviare il comando al POS e vedere la risposta.</pre>
    </div>

    <script>
(function() {
    var resultEl = document.getElementById('result');
    function qs(id) { return document.getElementById(id); }
    function params() {
        var u = new URL(window.location.href);
        u.searchParams.set('ajax', '1');
        u.searchParams.set('cmd', resultEl.getAttribute('data-cmd') || '');
        u.searchParams.set('host', (qs('pos_host') && qs('pos_host').value.trim()) || '');
        u.searchParams.set('port', (qs('pos_port') && qs('pos_port').value) || '');
        u.searchParams.set('tid', (qs('tid') && qs('tid').value) || '00000000');
        u.searchParams.set('crid', (qs('crid') && qs('crid').value) || '00000001');
        u.searchParams.set('amount', (qs('amount') && qs('amount').value) || '1.00');
        u.searchParams.set('stan', (qs('stan') && qs('stan').value) || '000000');
        u.searchParams.set('preauth_code', (qs('preauth_code') && qs('preauth_code').value) || '000000000');
        u.searchParams.set('print_ecr', (qs('print_ecr') && qs('print_ecr').value) || '0');
        u.searchParams.set('ticket_type', (qs('ticket_type') && qs('ticket_type').value) || '0');
        return u.toString();
    }
    document.querySelectorAll('.buttons button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var cmd = this.getAttribute('data-cmd');
            if (!cmd) return;
            resultEl.setAttribute('data-cmd', cmd);
            resultEl.className = 'loading';
            resultEl.querySelector('pre').textContent = 'Invio comando...';
            btn.disabled = true;
            fetch(params())
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultEl.className = data.ok ? 'success' : 'error';
                    resultEl.querySelector('pre').textContent = data.output || (data.ok ? 'OK' : 'Errore');
                })
                .catch(function(e) {
                    resultEl.className = 'error';
                    resultEl.querySelector('pre').textContent = 'Errore di rete: ' + e.message;
                })
                .then(function() { btn.disabled = false; });
        });
    });
})();
    </script>
</body>
</html>
