<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/admin-auth.php';

bp_cors('GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$adminId = (int)($_GET['admin_id'] ?? 0);
$actor = bp_require_role(
    ['admin_id' => $adminId],
    ['super_admin', 'student_admin', 'content_admin', 'admin']
);

$c = bp_admin_conn();
$role = strtolower(trim((string)$actor['role']));

// Super Admin can audit the entire system. Other admins only see their own
// activity on the dashboard, preventing cross-admin activity leakage.
if ($role === 'super_admin') {
    $stmt = $c->prepare(
        "SELECT
            al.log_id,
            al.admin_id,
            COALESCE(a.fullname, 'Unknown Admin') AS fullname,
            COALESCE(a.role, 'admin') AS role,
            al.action,
            al.description,
            al.date_created
         FROM activity_logs al
         LEFT JOIN admin a ON a.admin_id = al.admin_id
         ORDER BY al.date_created DESC, al.log_id DESC
         LIMIT 50"
    );
} else {
    $stmt = $c->prepare(
        "SELECT
            al.log_id,
            al.admin_id,
            COALESCE(a.fullname, 'Unknown Admin') AS fullname,
            COALESCE(a.role, 'admin') AS role,
            al.action,
            al.description,
            al.date_created
         FROM activity_logs al
         LEFT JOIN admin a ON a.admin_id = al.admin_id
         WHERE al.admin_id = ?
         ORDER BY al.date_created DESC, al.log_id DESC
         LIMIT 20"
    );
    if ($stmt) $stmt->bind_param('i', $adminId);
}

if (!$stmt || !$stmt->execute()) {
    if ($stmt) $stmt->close();
    $c->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to load activity logs.']);
    exit;
}

$result = $stmt->get_result();
$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = [
        'log_id' => (int)$row['log_id'],
        'admin_id' => (int)$row['admin_id'],
        'fullname' => $row['fullname'],
        'role' => strtolower(trim((string)$row['role'])),
        'action' => $row['action'],
        'description' => $row['description'],
        'date' => $row['date_created'],
        'date_created' => $row['date_created']
    ];
}
$stmt->close();
$c->close();

echo json_encode([
    'status' => 'success',
    'scope' => $role === 'super_admin' ? 'all_admins' : 'own_activity',
    'logs' => $logs
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
