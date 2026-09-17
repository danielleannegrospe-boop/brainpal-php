<?php
require_once __DIR__ . '/../_shared/db.php';
brainpal_cors('POST, OPTIONS');
$conn = brainpal_db();
$data = brainpal_json_input();
$notificationId = (int)($data['notification_id'] ?? 0);
$userId = (int)($data['user_id'] ?? 0);
$role = strtolower(trim((string)($data['role'] ?? '')));

if ($notificationId <= 0 || $userId <= 0 || !in_array($role, ['admin','student'], true)) {
    echo json_encode(['status'=>'error','success'=>false,'message'=>'Invalid notification data.']);
    $conn->close();
    exit;
}

$stmt = $conn->prepare(
    'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ? AND user_role = ?'
);
$stmt->bind_param('iis', $notificationId, $userId, $role);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

echo json_encode([
    'status'=>$ok ? 'success' : 'error',
    'success'=>$ok,
    'message'=>$ok ? 'Notification marked as read.' : 'Unable to mark notification as read.'
]);
