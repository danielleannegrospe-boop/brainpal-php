<?php
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/notification-helper.php';
brainpal_cors('GET, OPTIONS');
$conn = brainpal_db();

$userId = (int)($_GET['user_id'] ?? 0);
$role = strtolower(trim((string)($_GET['role'] ?? '')));

if ($role === 'admin') {
    notifyInactiveStudents($conn);
}


/* =====================================================
   STUDENT NOTIFICATION PREFERENCES
   The bell and Preferences page use the same source.
===================================================== */
$preferenceCondition = '';
$preferenceTypes = [];
if ($role === 'student') {
    $p = $conn->prepare("SELECT daily_reminder, quiz_reminder, streak_alert, weekly_report,
        badge_unlocked, level_up, xp_milestones
        FROM notification_preferences WHERE user_id=? AND user_role='student' LIMIT 1");
    $prefs = null;
    if ($p) {
        $p->bind_param('i', $userId);
        $p->execute();
        $prefs = $p->get_result()->fetch_assoc();
        $p->close();
    }

    $prefs = $prefs ?: [
      'daily_reminder'=>1,'quiz_reminder'=>1,'streak_alert'=>1,
      'weekly_report'=>1,'badge_unlocked'=>1,'level_up'=>1,'xp_milestones'=>1
    ];

    $disabled = [];
    if (!(int)$prefs['daily_reminder']) {
        $disabled = array_merge($disabled, ['scheduled_day_morning','scheduled_soon','scheduled_now','schedule_reminder']);
    }
    if (!(int)$prefs['quiz_reminder']) $disabled[] = 'new_quiz';
    if (!(int)$prefs['streak_alert']) $disabled = array_merge($disabled, ['streak_increased','streak_reset']);
    if (!(int)$prefs['weekly_report']) $disabled[] = 'weekly_report';
    if (!(int)$prefs['badge_unlocked']) $disabled[] = 'badge_unlocked';
    if (!(int)$prefs['level_up']) $disabled[] = 'level_up';
    if (!(int)$prefs['xp_milestones']) $disabled = array_merge($disabled, ['xp_milestone','xp_milestones']);

    if ($disabled) {
        $placeholders = implode(',', array_fill(0, count($disabled), '?'));
        $preferenceCondition = " AND type NOT IN ($placeholders)";
        $preferenceTypes = $disabled;
    }
}

$limit = (int)($_GET['limit'] ?? 30);
$limit = max(1, min(100, $limit));

if ($userId <= 0 || !in_array($role, ['admin','student'], true)) {
    echo json_encode([
        'status'=>'error',
        'success'=>false,
        'message'=>'Invalid user_id or role.',
        'received'=>['user_id'=>$userId,'role'=>$role]
    ]);
    $conn->close();
    exit;
}

$sql = "SELECT notification_id, user_id, user_role, type, title, message, reference_id, is_read, date_created
     FROM notifications
     WHERE user_id = ? AND user_role = ? {$preferenceCondition}
     ORDER BY date_created DESC, notification_id DESC
     LIMIT {$limit}";
$stmt = $conn->prepare($sql);
if ($preferenceTypes) {
    $types = 'is' . str_repeat('s', count($preferenceTypes));
    $params = array_merge([$types, $userId, $role], $preferenceTypes);
    $refs = [];
    foreach ($params as $k => $v) $refs[$k] = &$params[$k];
    call_user_func_array([$stmt, 'bind_param'], $refs);
} else {
    $stmt->bind_param('is', $userId, $role);
}
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $row['notification_id'] = (int)$row['notification_id'];
    $row['user_id'] = (int)$row['user_id'];
    $row['reference_id'] = $row['reference_id'] !== null ? (int)$row['reference_id'] : null;
    $row['is_read'] = (int)$row['is_read'];
    $notifications[] = $row;
}
$stmt->close();

$countSql = "SELECT COUNT(*) AS unread_count,
     (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND user_role = ? {$preferenceCondition}) AS total_notifications
     FROM notifications WHERE user_id = ? AND user_role = ? AND is_read = 0 {$preferenceCondition}";
$countStmt = $conn->prepare($countSql);
$countParams = array_merge([$userId, $role], $preferenceTypes, [$userId, $role], $preferenceTypes);
$countTypes = 'is' . str_repeat('s', count($preferenceTypes)) . 'is' . str_repeat('s', count($preferenceTypes));
$refs = [];
$countBind = array_merge([$countTypes], $countParams);
foreach ($countBind as $k => $v) $refs[$k] = &$countBind[$k];
call_user_func_array([$countStmt, 'bind_param'], $refs);
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$countStmt->close();
$conn->close();

echo json_encode([
    'status'=>'success',
    'success'=>true,
    'notifications'=>$notifications,
    'unread_count'=>(int)($countRow['unread_count'] ?? 0),
    'total_notifications'=>(int)($countRow['total_notifications'] ?? count($notifications))
], JSON_UNESCAPED_UNICODE);
