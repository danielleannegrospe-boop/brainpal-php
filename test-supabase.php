<?php

declare(strict_types=1);

require_once __DIR__ . '/_shared/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $db = new BrainPalDb();
    $pdo = $db->rawPdo();

    $stmt = $pdo->query(
        'SELECT current_database() AS database_name, current_user AS current_user, NOW() AS server_time'
    );

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'message' => 'PHP successfully connected to Supabase PostgreSQL.',
        'database' => $row['database_name'] ?? null,
        'user' => $row['current_user'] ?? null,
        'server_time' => $row['server_time'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
