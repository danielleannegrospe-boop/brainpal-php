<?php

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'status' => 'ok',
    'message' => 'BrainPal PHP API is online.',
    'service' => 'brainpal-php'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);