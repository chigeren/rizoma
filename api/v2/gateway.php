<?php
// RIZOMA API v2 — Gateway Handler Module
require_once __DIR__ . '/config.php';

if (!function_exists('handle_api_request')):

function handle_api_request() {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = rtrim($path, '/');
    $input = ($method === 'POST') ? json_decode(file_get_contents('php://input'), true) : null;

    if (!$action && $input && isset($input['action'])) $action = $input['action'];

    $actionFromPath = '';
    if (!$action && preg_match('#/(chat|models|balance|invite)(/|$)#', $path, $m)) {
        $actionFromPath = $m[1];
    }
    if (!$action && $actionFromPath) $action = $actionFromPath;

    if ($method === 'GET' && ($action === 'models' || str_ends_with($path, '/models'))) {
        handle_models();
        return;
    }

    if ($method === 'GET' && ($action === 'balance' || str_ends_with($path, '/balance'))) {
        handle_balance();
        return;
    }

    if ($method === 'POST' && ($action === 'chat' || str_ends_with($path, '/chat') || str_ends_with($path, '/chat/completions'))) {
        handle_chat($input);
        return;
    }

    json_err('Not found. Use: ?action=models|balance|chat', 404);
}

function handle_models() {
    global $_MODELS;
    $models = [];
    foreach ($_MODELS as $tier => $list) {
        foreach ($list as $id => $cfg) {
            $models[] = [
                'id' => $id,
                'name' => $id,
                'cost' => $cfg['cost'],
                'tier' => $tier,
                'capabilities' => $cfg['capabilities'],
            ];
        }
    }
    json_out(['ok' => true, 'models' => $models]);
}

function handle_balance() {
    $bal = load_balance();
    json_out(['ok' => true, 'balance' => $bal]);
}

function handle_chat($input) {
    if (!$input || !isset($input['messages'])) {
        json_err('Missing "messages" in request body');
    }

    $model = $input['model'] ?? 'cache/gpt-4o-mini';
    $messages = $input['messages'];
    $max_tokens = $input['max_tokens'] ?? 500;
    $temperature = $input['temperature'] ?? 0.7;
    $stream = $input['stream'] ?? false;

    auth_key();
    $rateLimitOk = check_rate_limit($_SERVER['REMOTE_ADDR']);
    if (!$rateLimitOk) {
        json_err('Rate limit exceeded. Try again in 60 seconds.', 429);
    }

    $modelCfg = get_model_config($model);
    if (!$modelCfg) {
        $allModels = array_keys(array_merge(...array_values($GLOBALS['_MODELS'])));
        json_err("Model '$model' not found. Available: " . implode(', ', $allModels), 404);
    }

    $tier = $modelCfg['tier'];
    $provider = $modelCfg['provider'];
    $result = null;

    if ($tier === 'cache' || $tier === 'free') {
        $cacheHash = md5(json_encode(['model' => $model, 'messages' => $messages]));
        $cached = cache_get($cacheHash);
        if ($cached) {
            json_out(['ok' => true, 'response' => $cached['response'], 'cached' => true, 'model' => $model]);
        }
    }

    if ($tier === 'free' && $provider === 'free') {
        $result = proxy_to_free($model, $messages, $max_tokens, $temperature);
        if ($result) {
            $cacheHash = md5(json_encode(['model' => $model, 'messages' => $messages]));
            cache_set($cacheHash, ['response' => $result]);
            json_out(['ok' => true, 'response' => $result, 'model' => $model, 'tier' => 'free', 'cost' => 0]);
        }
    }

    global $_OR_KEY, $_GH_KEY;
    $requiresOpenRouter = ($tier === 'openrouter' || $tier === 'cache' || ($tier === 'free' && !$result));
    $requiresGitHub = ($tier === 'github' || $tier === 'cache');

    // GitHub first for cache/github tiers
    if ($requiresGitHub && !$result && $_GH_KEY) {
        $ghModel = $modelCfg['map'] ?? 'gpt-4o-mini';
        $result = proxy_to_github($ghModel, $messages, $max_tokens, $temperature, $_GH_KEY);
        if ($result) {
            if ($tier === 'github') {
                cache_set($cacheHash, ['response' => $result]);
                json_out(['ok' => true, 'response' => $result, 'model' => $model, 'tier' => 'github', 'cost' => 0]);
            }
            // cache tier: keep result, skip OR/free fallback
        }
    }

    if ($requiresOpenRouter && !$result) {
        if ($tier === 'openrouter') {
            json_err('OpenRouter key not configured.', 503);
        }
        json_err('No available provider for this model.', 503);
    }

    if ($requiresOpenRouter && !$result && $_OR_KEY) {
        $orModel = $modelCfg['map'] ?? $model;
        $result = proxy_to_openrouter($orModel, $messages, $max_tokens, $temperature, $stream, $_OR_KEY);
    }

    if (!$result && ($tier === 'cache' || $tier === 'free')) {
        $result = proxy_to_free($model, $messages, $max_tokens, $temperature);
        if ($result) {
            $cacheHash = md5(json_encode(['model' => $model, 'messages' => $messages]));
            cache_set($cacheHash, ['response' => $result]);
            json_out(['ok' => true, 'response' => $result, 'model' => $model, 'tier' => 'free', 'cost' => 0]);
        }
    }

    if ($result) {
        $cost = $modelCfg['cost'] ?? 0.001;
        $bal = load_balance();
        $bal['usd'] = max(0, $bal['usd'] - $cost);
        save_balance($bal);

        if ($stream) { flush(); return; }

        $sourceTier = $tier;
        if ($tier === 'cache' && !$requiresOpenRouter) $sourceTier = 'github';

        json_out([
            'ok' => true, 'response' => $result, 'model' => $model,
            'tier' => $sourceTier, 'cost' => $cost, 'balance_remaining' => $bal['usd'],
        ]);
    }

    json_err('All model tiers failed.', 503);
}

function proxy_to_openrouter($model, $messages, $max_tokens, $temperature, $stream, $apiKey) {
    $url = 'https://openrouter.ai/api/v1/chat/completions';
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
        'stream' => $stream,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://chigerev.ru',
            'X-Title: RIZOMA API v2',
        ],
        CURLOPT_RETURNTRANSFER => !$stream,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($stream) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            echo $data;
            ob_flush(); flush();
            return strlen($data);
        });
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode === 200;
    }

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($resp, true);
        error_log("OpenRouter error ($httpCode): " . ($err['error']['message'] ?? $resp));
        return null;
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['choices'][0]['message'])) return null;

    return [
        'content' => $data['choices'][0]['message']['content'],
        'role' => $data['choices'][0]['message']['role'] ?? 'assistant',
        'usage' => $data['usage'] ?? null,
    ];
}

function proxy_to_free($model, $messages, $max_tokens, $temperature) {
    $backends = [
        ['url' => 'https://api.g4f.workers.dev/v1/chat/completions', 'key' => ''],
        ['url' => 'https://text.pollinations.ai/openai', 'key' => ''],
        ['url' => 'https://openai.aios.chat/v1/chat/completions', 'key' => ''],
        ['url' => 'https://api.naga.ac/v1/chat/completions', 'key' => ''],
        ['url' => 'https://api.fffly.xyz/v1/chat/completions', 'key' => ''],
        ['url' => 'https://infer.netology.ai/v1/chat/completions', 'key' => ''],
        ['url' => 'https://api.avalai.ir/v1/chat/completions', 'key' => ''],
    ];

    $modelMap = [
        'g4f/gpt-4o-mini' => 'gpt-4o-mini',
        'g4f/claude-3-haiku' => 'claude-3-haiku',
        'g4f/gemini-1.5-flash' => 'gemini-1.5-flash',
    ];
    $backendModel = $modelMap[$model] ?? 'gpt-4o-mini';

    $payload = [
        'model' => $backendModel,
        'messages' => $messages,
        'max_tokens' => min($max_tokens, 1000),
        'temperature' => $temperature,
    ];

    foreach ($backends as $backend) {
        $ch = curl_init($backend['url']);
        $headers = ['Content-Type: application/json'];
        if ($backend['key']) $headers[] = 'Authorization: Bearer ' . $backend['key'];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) continue;

        $data = json_decode($resp, true);
        if ($data && isset($data['choices'][0]['message'])) {
            return [
                'content' => $data['choices'][0]['message']['content'],
                'role' => 'assistant',
                'usage' => $data['usage'] ?? null,
            ];
        }
    }
    return null;
}

endif;

// ===== GitHub Models proxy (out of function_exists guard for direct call) =====
function proxy_to_github($model, $messages, $max_tokens, $temperature, $apiKey) {
    $url = 'https://models.inference.ai.azure.com/chat/completions';
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($resp, true);
        error_log("GitHub Models error ($httpCode): " . ($err['error']['message'] ?? $resp));
        return null;
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['choices'][0]['message'])) return null;

    return [
        'content' => $data['choices'][0]['message']['content'],
        'role' => $data['choices'][0]['message']['role'] ?? 'assistant',
        'usage' => $data['usage'] ?? null,
    ];
}
$isDirectCall = !defined('INDEX_ROUTED');
if ($isDirectCall) {
    handle_api_request();
}
