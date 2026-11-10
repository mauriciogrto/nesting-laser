<?php

return [
    'engine_url' => env('NESTING_ENGINE_URL', 'http://127.0.0.1:3001'),
    'engine_token' => env('NESTING_ENGINE_TOKEN'),
    'pc_worker_enabled' => (bool) env('NESTING_PC_WORKER_ENABLED', false),
    'deslocamento_mm_min' => (float) env('NESTING_TRAVEL_SPEED_MM_MIN', 10000),
];
