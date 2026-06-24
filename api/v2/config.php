<?php
define('INDEX_ROUTED', true);
$_MODELS = [
    'github' => [
        'github/gpt-4o-mini' => ['provider' => 'github', 'cost' => 0, 'capabilities' => ['chat'], 'map' => 'gpt-4o-mini'],
    ],
    'openrouter' => [
        'openai/gpt-4o-mini' => ['provider' => 'openrouter', 'cost' => 0.00015, 'capabilities' => ['chat']],
    ],
];