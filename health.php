<?php

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'status' => 'ok',
    'message' => 'BrainPal API health check passed.'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);