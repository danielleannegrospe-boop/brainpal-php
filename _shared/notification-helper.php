<?php
require_once __DIR__ . '/db.php';

function createNotification(
    BrainPalDb $conn,
    int $userId,
    string $userRole,
    string $type,
    string $title,
    string $message,
    ?int $referenceId = null
): bool {
    $userRole = strtolower(trim($userRole));
    if ($userId <= 0 || !in_array($userRole, ['admin', 'student'], true)) return false;

    $stmt = $conn->prepare(
        "INSERT INTO notifications
         (user_id, user_role, type, title, message, reference_id, is_read)
         VALUES (?, ?, ?, ?, ?, ?, 0)"
    );
    if (!$stmt) {
        error_log('createNotification prepare error: '.$conn->error);
        return false;
    }
    $stmt->bind_param('issssi', $userId, $userRole, $type, $title, $message, $referenceId);
    $ok = $stmt->execute();
    if (!$ok) error_log('createNotification execute error: '.$stmt->error);
    $stmt->close();
    return $ok;
}

function notifyStudent(
    BrainPalDb $conn,
    int $studentId,
    string $type,
    string $title,
    string $message,
    ?int $referenceId = null
): bool {
    return createNotification($conn, $studentId, 'student', $type, $title, $message, $referenceId);
}

function notifyAllAdmins(
    BrainPalDb $conn,
    string $type,
    string $title,
    string $message,
    ?int $referenceId = null
): int {
    $stmt = $conn->prepare("SELECT admin_id FROM admin WHERE date_deleted IS NULL");
    if (!$stmt || !$stmt->execute()) {
        error_log('notifyAllAdmins query error: '.($stmt ? $stmt->error : $conn->error));
        if ($stmt) $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        if (createNotification($conn, (int)$row['admin_id'], 'admin', $type, $title, $message, $referenceId)) $count++;
    }
    $stmt->close();
    return $count;
}

function notifyAllStudents(
    BrainPalDb $conn,
    string $type,
    string $title,
    string $message,
    ?int $referenceId = null
): int {
    $stmt = $conn->prepare("SELECT student_id FROM student WHERE date_deleted IS NULL");
    if (!$stmt || !$stmt->execute()) {
        error_log('notifyAllStudents query error: '.($stmt ? $stmt->error : $conn->error));
        if ($stmt) $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        if (createNotification($conn, (int)$row['student_id'], 'student', $type, $title, $message, $referenceId)) $count++;
    }
    $stmt->close();
    return $count;
}

function getStudentName(BrainPalDb $conn, int $studentId): string {
    if ($studentId <= 0) return 'Student';
    $stmt = $conn->prepare(
        "SELECT CONCAT_WS(' ', firstName, NULLIF(m_initial,''), lastName, NULLIF(extension,'')) AS full_name
         FROM student WHERE student_id = ? AND date_deleted IS NULL LIMIT 1"
    );
    if (!$stmt) return 'Student';
    $stmt->bind_param('i', $studentId);
    if (!$stmt->execute()) { $stmt->close(); return 'Student'; }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = trim((string)($row['full_name'] ?? ''));
    return $name !== '' ? $name : 'Student';
}

function getMaterialStudents(BrainPalDb $conn, int $materialId): array {
    if ($materialId <= 0) return [];
    $stmt = $conn->prepare(
        "SELECT DISTINCT ss.student_id
         FROM learning_material lm
         INNER JOIN study_schedule ss ON ss.schedule_id = lm.schedule_id
         INNER JOIN student s ON s.student_id = ss.student_id
         WHERE lm.material_id = ?
           AND lm.date_deleted IS NULL
           AND ss.date_deleted IS NULL
           AND s.date_deleted IS NULL"
    );
    if (!$stmt) return [];
    $stmt->bind_param('i', $materialId);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)($row['student_id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    $stmt->close();
    return array_values(array_unique($ids));
}

function getMaterialStudent(BrainPalDb $conn, int $materialId): int {
    $students = getMaterialStudents($conn, $materialId);
    return $students[0] ?? 0;
}

function notifyMaterialStudent(
    BrainPalDb $conn,
    int $materialId,
    string $type,
    string $title,
    string $message,
    ?int $referenceId = null
): bool {
    $studentId = getMaterialStudent($conn, $materialId);
    return $studentId > 0
        ? notifyStudent($conn, $studentId, $type, $title, $message, $referenceId)
        : false;
}

function notifyAllMaterialStudents(
    BrainPalDb $conn,
    int $materialId,
    string $type,
    string $title,
    string $message,
    ?int $referenceId = null
): int {
    $count = 0;
    foreach (getMaterialStudents($conn, $materialId) as $studentId) {
        if (notifyStudent($conn, $studentId, $type, $title, $message, $referenceId)) $count++;
    }
    return $count;
}


/**
 * Creates one daily inactivity notification per student for active admins.
 * It only considers students who have logged in at least once before.
 */
function notifyInactiveStudents(BrainPalDb $conn): int {
    $stmt = $conn->prepare(
        "SELECT student_id,
                CONCAT_WS(' ', firstName, NULLIF(m_initial,''), lastName) AS full_name,
                last_open_date
         FROM student
         WHERE date_deleted IS NULL
           AND account_status = 'active'
           AND last_open_date IS NOT NULL
           AND last_open_date < CURDATE()"
    );
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return 0;
    }

    $result = $stmt->get_result();
    $count = 0;

    while ($row = $result->fetch_assoc()) {
        $studentId = (int)$row['student_id'];
        $name = trim((string)($row['full_name'] ?? 'Student'));
        $lastLogin = (string)$row['last_open_date'];

        $check = $conn->prepare(
            "SELECT notification_id
             FROM notifications
             WHERE user_role = 'admin'
               AND type = 'student_inactive'
               AND reference_id = ?
               AND DATE(date_created) = CURDATE()
             LIMIT 1"
        );

        if (!$check) continue;

        $check->bind_param('i', $studentId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            $days = max(1, (int)(new DateTime($lastLogin))->diff(new DateTime('today'))->days);

            $count += notifyAllAdmins(
                $conn,
                'student_inactive',
                'Student Did Not Log In',
                $name . ' has not logged in for ' . $days . ' day(s). Last login: ' . $lastLogin . '.',
                $studentId
            ) > 0 ? 1 : 0;
        }
    }

    $stmt->close();
    return $count;
}
