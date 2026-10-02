<?php
/**
 * Nodo Elohim — esecutore stateless.
 *
 *   POST elohim.php   riceve una continuation, esegue un quantum, inoltra la successiva.
 *   GET  elohim.php   descrive il nodo ("I can execute a quantum.")
 *
 * Il nodo non scrive nulla su disco: lo stato viaggia nella richiesta.
 * L'unica configurazione è elohim.txt, accanto a questo file: un URL di nodo per riga.
 */

require __DIR__ . '/Basic.php';

const ELOHIM_VERSION = '0.1';
const QUANTUM        = 20;    // istruzioni BASIC per hop
const MAX_WAIT       = 10.0;  // secondi massimi in cui una continuation "dorme" dentro una request
const HOSTS_FILE     = 'elohim.txt';

/** URL canonico di un nodo: schema://host[:porta]/percorso/elohim.php */
function normalize_url(string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) $url = 'http://' . $url;
    $p = parse_url($url);
    $path = $p['path'] ?? '/';
    if (!str_ends_with($path, '.php')) $path = rtrim($path, '/') . '/elohim.php';
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
}

/** Legge elohim.txt: una riga per host, righe vuote e commenti (#) ignorati. */
function load_hosts(): array
{
    $file = __DIR__ . '/' . HOSTS_FILE;
    if (!is_readable($file)) return [];
    $hosts = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line !== '') $hosts[] = normalize_url($line);
    }
    return array_values(array_unique($hosts));
}

function self_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return normalize_url(($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['SCRIPT_NAME']);
}

function logline(string $msg): void
{
    global $node;
    $p = parse_url($node);
    error_log(sprintf("[%s] %s", $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $msg));
}

function respond(int $code, array $body): void
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    http_response_code($code);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
}

/** Chiude la connessione col client ma continua l'esecuzione dello script. */
function detach(): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level()) ob_end_flush();
    flush();
}

/** Hash canonico dello stato (escluso il campo hash stesso). */
function state_hash(array $s): string
{
    unset($s['hash']);
    // forma canonica: oggetti e array vuoti diventano indistinguibili, come dopo un json_decode(..., true)
    $canonical = json_decode(json_encode($s), true);
    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** POST JSON, ritorna il codice HTTP (0 se irraggiungibile). Usa curl se c'è, altrimenti gli stream. */
function http_post(string $url, string $body): int
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return (int)$code;
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $body,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false || empty($http_response_header[0])) return 0;
    return preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $mm) ? (int)$mm[1] : 0;
}

/** Invia la continuation al prossimo nodo dell'anello; se non risponde prova il successivo. */
function forward(array $state): ?string
{
    global $node, $hosts;
    $body = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // ordine ad anello a partire dal nodo dopo di noi; noi stessi siamo l'ultima risorsa
    $self = array_search($node, $hosts, true);
    $order = $self === false ? $hosts : array_merge(array_slice($hosts, $self + 1), array_slice($hosts, 0, $self + 1));
    if ($self === false) $order[] = $node;

    foreach ($order as $peer) {
        $code = http_post($peer, $body);
        if ($code === 202) return $peer;
        logline("peer $peer non disponibile (HTTP $code), provo il prossimo");
    }
    return null;
}

$node  = self_url();
$hosts = load_hosts();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, [
        'elohim'  => ELOHIM_VERSION,
        'node'    => $node,
        'says'    => 'I can execute a quantum.',
        'quantum' => QUANTUM,
        'in_ring' => in_array($node, $hosts, true),
        'hosts'   => $hosts,
        'curl'    => function_exists('curl_init'),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'method not allowed']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);

// --- boot: un client invia sorgente BASIC, il nodo genera S0
if (isset($in['boot'])) {
    try {
        $machine = Basic::boot($in['boot']['source'], $in['boot']['inputs'] ?? []);
    } catch (BasicError $e) {
        respond(400, ['error' => $e->getMessage()]);
        exit;
    }
    $in = [
        'machine_id'          => bin2hex(random_bytes(8)),
        'generation'          => 0,
        'born_at'             => time(),
        'previous_state_hash' => null,
        'hosts'               => [],
        'machine'             => $machine,
        'output'              => [],
    ];
    $in['hash'] = state_hash($in);
}

if (!is_array($in) || !isset($in['machine_id'], $in['hash'], $in['machine'])) {
    respond(400, ['error' => 'continuation non valida']);
    exit;
}
if (state_hash($in) !== $in['hash']) {
    respond(409, ['error' => 'catena causale corrotta: hash non valido']);
    exit;
}

// Accettiamo la continuation e chiudiamo subito la connessione col mittente:
// da qui in poi la macchina "vive" in questa request.
respond(202, ['accepted' => true, 'machine_id' => $in['machine_id'], 'generation' => $in['generation'], 'node' => $node]);
detach();

$id  = substr($in['machine_id'], 0, 8);
$gen = $in['generation'];
$m   = $in['machine'];

// --- idle: la continuation dorme dentro la request, al massimo MAX_WAIT secondi
if ($m['status'] === 'sleeping') {
    $wait = $m['wake_at'] - microtime(true);
    if ($wait > 0) usleep((int)(min($wait, MAX_WAIT) * 1e6));
    if ($m['wake_at'] <= microtime(true)) $m['status'] = 'running';
}

// --- execute: un quantum
$out = [];
$executed = 0;
if ($m['status'] === 'running' || $m['status'] === 'yield') {
    $vm = new Basic($m, function (string $port, string $text) use (&$out, $id, $gen) {
        $out[] = [$port, $text];
        logline(sprintf("%s gen %-4d %-6s| %s", $id, $gen, $port, $text));
    });
    $executed = $vm->run(QUANTUM);
    $m = $vm->state();
}

// --- capture: nuovo stato, collegato causalmente al precedente
$visited = $in['hosts'];
if (end($visited) !== $node) $visited[] = $node;
$next = [
    'machine_id'          => $in['machine_id'],
    'generation'          => $gen + 1,
    'born_at'             => $in['born_at'],
    'previous_state_hash' => $in['hash'],
    'hosts'               => array_slice($visited, -50),
    'machine'             => $m,
    'output'              => array_slice(array_merge($in['output'], $out), -50),
];
$next['hash'] = state_hash($next);

logline(sprintf("%s gen %-4d -> %-4d status=%s exec=%d hash=%s",
    $id, $gen, $gen + 1, $m['status'], $executed, substr($next['hash'], 0, 12)));

if ($m['status'] === 'halted' || $m['status'] === 'error') {
    $age = time() - $in['born_at'];
    logline("$id " . ($m['status'] === 'error' ? 'ERRORE: ' . $m['error'] : 'HALT') .
        " - generazioni: {$next['generation']}, istruzioni: {$m['steps']}, eta: {$age}s. La macchina non si propaga piu.");
    exit;
}

// --- forward
if (forward($next) === null) {
    logline("$id nessun nodo raggiungibile: la macchina e' morta alla generazione {$next['generation']}");
}
