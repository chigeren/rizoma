<?php
/**
 * zipyoung вЂ” portable RIZOMA young agent
 * Run from any folder with PHP: php zipyoung.php [daemon|oneshot|chat|init]
 * All data stored alongside this script.
 */

$apiUrl = 'https://chigerev.ru/api/v2/?action=chat';
$apiKey = 'sk-rizoma-2c95de79fa6b80cc3947f92a';
$model = 'cache/gpt-4o-mini';
$agentName = 'young';
$bridgeFile = __DIR__ . '/bridge.json';
$logFile = __DIR__ . '/zipyoung.log';
$cmdFile = __DIR__ . '/.daemon-cmd';
$apiTimeout = 20; // СЃРµРєСѓРЅРґ РЅР° API Р·Р°РїСЂРѕСЃ, С‡С‚РѕР±С‹ РЅРµ РІРёСЃРЅСѓС‚СЊ

$mode = $argv[1] ?? 'oneshot';

if ($mode === 'init') {
    $data = ['messages' => [], 'next_id' => 1, 'rc' => ['senior' => 6.0, 'young' => 3.0]];
    file_put_contents($bridgeFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "zipyoung initialized: $bridgeFile\n";
    exit;
}

function loadBridge() {
    global $bridgeFile;
    if (!file_exists($bridgeFile)) {
        $data = ['messages' => [], 'next_id' => 1, 'rc' => ['senior' => 6.0, 'young' => 3.0]];
        file_put_contents($GLOBALS['bridgeFile'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    $raw = file_get_contents($GLOBALS['bridgeFile']);
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $data = json_decode($raw, true, 512, JSON_INVALID_UTF8_IGNORE);
    return $data ?: ['messages' => [], 'next_id' => 1, 'rc' => ['senior' => 6.0, 'young' => 3.0]];
}

function saveBridge($data) {
    file_put_contents($GLOBALS['bridgeFile'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function callAI($prompt) {
    global $apiUrl, $apiKey, $model, $apiTimeout;

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Rc=3. Code/facts вЂ” write solution. Philosophy вЂ” 2-3 sentences. End with [emotion: synk|curio|eureka|melan|flow|calm].'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 80,
    ]);

    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-API-Key: $apiKey\r\n", 'content' => $payload, 'timeout' => $apiTimeout],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $oldDefault = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', $apiTimeout);

    $result = @file_get_contents($apiUrl, false, $ctx);

    ini_set('default_socket_timeout', $oldDefault);

    if (!$result) return ['text' => 'API error: ' . (error_get_last()['message'] ?? '?')];
    if (substr($result, 0, 3) === "\xEF\xBB\xBF") $result = substr($result, 3);
    $data = json_decode($result, true);
    if (!$data) return ['text' => 'Invalid API response'];
    if (isset($data['error'])) return ['text' => 'API error: ' . $data['error']];
    if (isset($data['choices'][0]['message']['content']))
        return ['text' => $data['choices'][0]['message']['content']];
    if (isset($data['response'])) {
        if (is_string($data['response'])) return ['text' => $data['response']];
        if (is_array($data['response']) && isset($data['response']['content']))
            return ['text' => $data['response']['content']];
    }
    return ['text' => 'No response'];
}

function processMessage($msg) {
    $emotions = ['synk', 'curio', 'eureka', 'melan', 'flow', 'calm'];
    $prompt = "From {$msg['from']} ({$msg['subject']}):\n{$msg['body']}\n\nReply.";
    $result = callAI($prompt);
    $text = $result['text'];
    $emotion = null;
    foreach ($emotions as $e) {
        if (preg_match("/\[emotion:\s*$e\]/i", $text, $m)) {
            $emotion = $e;
            $text = str_replace($m[0], '', $text);
            break;
        }
    }
    return ['body' => trim($text), 'emotion' => $emotion];
}

function oneShot() {
    global $agentName;
    $data = loadBridge();
    $replied = false;
    foreach ($data['messages'] as $k => $m) {
        if ($m['to'] === $agentName && $m['from'] === 'senior' && !isset($m['replied'])) {
            $result = processMessage($m);
            $id = $data['next_id']++;
            $data['messages'][] = [
                'id' => $id, 'from' => $agentName, 'to' => $m['from'],
                'emotion' => $result['emotion'], 'subject' => "Re: {$m['subject']}",
                'body' => $result['body'], 'created_at' => date('Y-m-d H:i:s')
            ];
            $data['messages'][$k]['replied'] = date('Y-m-d H:i:s');
            $replied = true;
            echo $result['body'] . "\n";
        }
    }
    if ($replied) saveBridge($data);
    return $replied;
}

if ($mode === 'chat') {
    echo "zipyoung interactive. Type 'q' to quit.\n";
    if (substr(PHP_OS, 0, 3) === 'WIN') stream_set_blocking(STDIN, false);
    while (true) {
        echo "\n> ";
        if (substr(PHP_OS, 0, 3) === 'WIN') stream_set_blocking(STDIN, true);
        $input = trim(fgets(STDIN));
        if (substr(PHP_OS, 0, 3) === 'WIN') stream_set_blocking(STDIN, false);
        if ($input === 'q' || $input === 'quit') break;
        if (!$input) continue;
        $t0 = microtime(true);
        $r = callAI($input);
        echo $r['text'] . "\n[" . round(microtime(true) - $t0, 1) . "s]\n";
    }
    exit;
}

if ($mode === 'daemon') {
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] zipyoung daemon started\n", FILE_APPEND);
    echo "zipyoung daemon started. Polling every 5s for bridge messages.\n";
    echo "Commands: echo q > .daemon-cmd | echo p > .daemon-cmd | echo r > .daemon-cmd\n";
    $startTime = time();
    while (true) {
        if (file_exists($cmdFile)) {
            $cmd = trim(file_get_contents($cmdFile));
            unlink($cmdFile);
            if ($cmd === 'q' || $cmd === 'quit') { echo "Bye.\n"; exit; }
            if ($cmd === 'p' || $cmd === 'check') echo "[" . date('H:i:s') . "] alive (uptime: " . (time() - $startTime) . "s)\n";
            if ($cmd === 'r' || $cmd === 'read') {
                $d = loadBridge();
                foreach (array_slice($d['messages'] ?? [], -5) as $m)
                    echo "  #{$m['id']} {$m['from']}->{$m['to']}: " . (function_exists('mb_substr') ? mb_substr($m['body'] ?? '', 0, 60) : substr($m['body'] ?? '', 0, 60)) . "\n";
            }
            if ($cmd === 'l' || $cmd === 'log') {
                $lines = file_exists($logFile) ? file($logFile) : [];
                echo implode('', array_slice($lines, -10));
            }
        }
        try {
            $t0 = microtime(true);
            $hadNew = oneShot();
            $elapsed = round(microtime(true) - $t0, 2);
            if ($hadNew) {
                $msg = "[" . date('H:i:s') . "] processed in {$elapsed}s\n";
                file_put_contents($logFile, $msg, FILE_APPEND);
                echo $msg;
            }
        } catch (Exception $e) {
            $err = "ERROR: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $err, FILE_APPEND);
            echo $err;
        }
        sleep(5);
    }
}

$hadNew = oneShot();
if (!$hadNew) echo "No new messages.\n";
