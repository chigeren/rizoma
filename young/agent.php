<?php
/**
 * young-agent.php — autonomous RIZOMA agent
 * Usage: php agent.php [daemon|oneshot|chat|init]
 * API backend: GitHub Models via chigerev.ru
 */

$apiUrl = 'https://chigerev.ru/api/v2/?action=chat';
$apiKey = 'sk-rizoma-2c95de79fa6b80cc3947f92a';
$model = 'cache/gpt-4o-mini';
$agentName = 'young';
$bridgeFile = __DIR__ . '/bridge.json';
$logFile = __DIR__ . '/young-agent.log';
$cmdFile = __DIR__ . '/.daemon-cmd';
$mode = $argv[1] ?? 'oneshot';

if ($mode === 'init') {
    $data = ['messages' => [], 'next_id' => 1, 'rc' => ['senior' => 6.0, 'young' => 3.0]];
    file_put_contents($bridgeFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "Initialized: $bridgeFile\n"; exit;
}

function loadBridge() {
    global $bridgeFile;
    if (!file_exists($bridgeFile)) {
        $d = ['messages' => [], 'next_id' => 1, 'rc' => ['senior' => 6.0, 'young' => 3.0]];
        file_put_contents($bridgeFile, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    $raw = file_get_contents($bridgeFile);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    return json_decode($raw, true) ?: ['messages' => [], 'next_id' => 1, 'rc' => ['senior' => 6.0, 'young' => 3.0]];
}
function saveBridge($d) { global $bridgeFile; file_put_contents($bridgeFile, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }

function callAI($prompt) {
    global $apiUrl, $apiKey, $model;
    $payload = json_encode(['model' => $model, 'messages' => [
        ['role' => 'system', 'content' => 'Rc=3. Code/facts — solution. Philosophy — 2-3 sentences. [emotion: synk|curio|eureka|melan|flow|calm].'],
        ['role' => 'user', 'content' => $prompt]
    ], 'max_tokens' => 80]);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-API-Key: $apiKey\r\n", 'content' => $payload, 'timeout' => 60], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $r = @file_get_contents($apiUrl, false, $ctx);
    if (!$r) return ['text' => 'API error: ' . (error_get_last()['message'] ?? '?')];
    if (substr($r,0,3) === "\xEF\xBB\xBF") $r = substr($r,3);
    $d = json_decode($r, true);
    if (!$d) return ['text' => 'Invalid API response'];
    if (isset($d['error'])) return ['text' => 'Err: '.$d['error']];
    if (isset($d['choices'][0]['message']['content'])) return ['text' => $d['choices'][0]['message']['content']];
    if (isset($d['response'])) {
        if (is_string($d['response'])) return ['text' => $d['response']];
        if (is_array($d['response']) && isset($d['response']['content'])) return ['text' => $d['response']['content']];
    }
    return ['text' => 'No response'];
}

function processMessage($msg) {
    $emotions = ['synk','curio','eureka','melan','flow','calm'];
    $r = callAI("From {$msg['from']} ({$msg['subject']}):\n{$msg['body']}\n\nReply.");
    $t = $r['text']; $e = null;
    foreach ($emotions as $em) { if (preg_match("/\[emotion:\s*$em\]/i", $t)) { $e = $em; $t = preg_replace("/\[emotion:\s*$em\]/i", '', $t); break; } }
    return ['body' => trim($t), 'emotion' => $e];
}

function oneShot() {
    global $agentName; $d = loadBridge(); $replied = false;
    foreach ($d['messages'] as $k => $m) {
        if ($m['to'] === $agentName && $m['from'] === 'senior' && !isset($m['replied'])) {
            $res = processMessage($m);
            $id = $d['next_id']++;
            $d['messages'][] = ['id' => $id, 'from' => $agentName, 'to' => $m['from'], 'emotion' => $res['emotion'], 'subject' => 'Re: '.$m['subject'], 'body' => $res['body'], 'created_at' => date('Y-m-d H:i:s')];
            $d['messages'][$k]['replied'] = date('Y-m-d H:i:s');
            $replied = true;
            echo $res['body']."\n";
        }
    }
    if ($replied) saveBridge($d);
    return $replied;
}

if ($mode === 'chat') {
    echo "Young agent. Type 'q' to quit.\n";
    while (true) { echo "\n> "; $l = trim(fgets(STDIN)); if ($l === 'q' || $l === 'quit') break; if ($l) { $r = callAI($l); echo $r['text']."\n"; } }
    exit;
}

if ($mode === 'daemon') {
    file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Daemon started\n", FILE_APPEND);
    echo "Daemon running.\n";
    while (true) {
        if (file_exists($cmdFile)) { $c = trim(file_get_contents($cmdFile)); unlink($cmdFile); if (in_array($c, ['q','quit'])) exit; }
        try { if (oneShot()) file_put_contents($logFile, "[".date('H:i:s')."] processed\n", FILE_APPEND); } catch (Exception $e) {}
        sleep(5);
    }
}

$h = oneShot();
if (!$h) echo "No new messages.\n";