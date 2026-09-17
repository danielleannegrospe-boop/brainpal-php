<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('POST, OPTIONS');
$conn = brainpal_db();
$data = brainpal_json_input();
$userId = (int)($data['user_id'] ?? 0);
$role = strtolower(trim((string)($data['role'] ?? '')));

if ($userId <= 0 || !in_array($role, ['admin','student'], true)) {
    echo json_encode(['status'=>'error','success'=>false,'message'=>'Invalid user_id or role.']);
    $conn->close();
    exit;
}

$stmt = $conn->prepare(
    'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND user_role = ? AND is_read = 0'
);
$stmt->bind_param('is', $userId, $role);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

echo json_encode([
    'status'=>$ok ? 'success' : 'error',
    'success'=>$ok,
    'updated'=>$affected,
    'message'=>$ok ? 'All notifications marked as read.' : 'Unable to update notifications.'
]);
