<?php
// TEMPORARY diagnostic file — confirms whether Railway env vars reach PHP.
// Safe to delete after we get an answer; touches nothing else.
header('Content-Type: application/json');

echo json_encode([
    'getenv'  => getenv('TEST_ENV_CHECK') ?: 'NOT FOUND via getenv()',
    'ENV'     => $_ENV['TEST_ENV_CHECK'] ?? 'NOT FOUND via $_ENV',
    'SERVER'  => $_SERVER['TEST_ENV_CHECK'] ?? 'NOT FOUND via $_SERVER',
]);