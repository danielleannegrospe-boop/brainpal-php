<?php

require_once __DIR__ . '/../_shared/admin-auth.php';

bp_cors('GET, POST, DELETE, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'];
$allowedRoles = ['super_admin', 'student_admin'];

/* =====================================================
   VIEW / LIST
===================================================== */
if ($method === 'GET') {

    $adminId = (int)($_GET['admin_id'] ?? 0);
    bp_require_role(['admin_id' => $adminId], $allowedRoles);

    $c = bp_admin_conn();

    $sql = "
        SELECT
            academic_id,
            academic_year,
            date_start,
            date_end,
            status,
            date_created
        FROM academic
        WHERE date_deleted IS NULL
        ORDER BY date_start DESC, academic_id DESC
    ";

    $result = $c->query($sql);

    if (!$result) {
        $c->close();
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to load academic years.'
        ]);
        exit;
    }

    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'academic_id' => (int)$row['academic_id'],
            'academic_year' => $row['academic_year'],
            'date_start' => $row['date_start'],
            'date_end' => $row['date_end'],
            'status' => $row['status'],
            'date_created' => $row['date_created']
        ];
    }

    $c->close();

    echo json_encode([
        'status' => 'success',
        'academic_years' => $items
    ]);
    exit;
}

/* =====================================================
   CREATE / UPDATE
===================================================== */
if ($method === 'POST') {

    $d = bp_json();
    $actor = bp_require_role($d, $allowedRoles);
    $c = bp_admin_conn();

    $id = (int)($d['academic_id'] ?? 0);
    $academicYear = trim((string)($d['academic_year'] ?? ''));
    $dateStart = trim((string)($d['date_start'] ?? ''));
    $dateEnd = trim((string)($d['date_end'] ?? ''));
    $status = strtolower(trim((string)($d['status'] ?? 'inactive')));

    if ($academicYear === '') {
        $c->close();
        echo json_encode([
            'status' => 'error',
            'message' => 'Academic year is required.'
        ]);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
        $c->close();
        echo json_encode([
            'status' => 'error',
            'message' => 'Academic year must use the format YYYY-YYYY.'
        ]);
        exit;
    }

    if ($dateStart === '' || $dateEnd === '') {
        $c->close();
        echo json_encode([
            'status' => 'error',
            'message' => 'Start date and end date are required.'
        ]);
        exit;
    }

    if ($dateEnd < $dateStart) {
        $c->close();
        echo json_encode([
            'status' => 'error',
            'message' => 'End date must not be earlier than the start date.'
        ]);
        exit;
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'inactive';
    }

    /* Duplicate academic year check */
    $check = $c->prepare("
        SELECT academic_id
        FROM academic
        WHERE academic_year = ?
          AND date_deleted IS NULL
          AND academic_id <> ?
        LIMIT 1
    ");

    $check->bind_param('si', $academicYear, $id);
    $check->execute();
    $duplicate = $check->get_result()->fetch_assoc();
    $check->close();

    if ($duplicate) {
        $c->close();
        echo json_encode([
            'status' => 'error',
            'message' => 'Academic year already exists.'
        ]);
        exit;
    }

    /* Only one active academic year at a time. */
    if ($status === 'active') {
        $deactivate = $c->prepare("
            UPDATE academic
            SET status = 'inactive'
            WHERE date_deleted IS NULL
              AND status = 'active'
              AND academic_id <> ?
        ");
        $deactivate->bind_param('i', $id);
        $deactivate->execute();
        $deactivate->close();
    }

    if ($id > 0) {

        $stmt = $c->prepare("
            UPDATE academic
            SET
                academic_year = ?,
                date_start = ?,
                date_end = ?,
                status = ?
            WHERE academic_id = ?
              AND date_deleted IS NULL
        ");

        $stmt->bind_param(
            'ssssi',
            $academicYear,
            $dateStart,
            $dateEnd,
            $status,
            $id
        );

        $message = 'Academic year updated successfully.';
        $action = 'Updated Academic Year';

    } else {

        $stmt = $c->prepare("
            INSERT INTO academic
            (
                academic_year,
                date_start,
                date_end,
                status
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'ssss',
            $academicYear,
            $dateStart,
            $dateEnd,
            $status
        );

        $message = 'Academic year added successfully.';
        $action = 'Added Academic Year';
    }

    if (!$stmt->execute()) {
        $stmt->close();
        $c->close();

        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to save academic year.'
        ]);
        exit;
    }

    $savedId = $id > 0 ? $id : (int)$c->insert_id;
    $stmt->close();

    bp_log(
        $c,
        (int)$actor['admin_id'],
        $action,
        $academicYear . ' (' . $savedId . ')'
    );

    $c->close();

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'academic_id' => $savedId
    ]);
    exit;
}

/* =====================================================
   DELETE (SOFT DELETE)
===================================================== */
if ($method === 'DELETE') {

    $d = bp_json();
    $actor = bp_require_role($d, $allowedRoles);
    $c = bp_admin_conn();

    $id = (int)($d['academic_id'] ?? 0);

    if ($id <= 0) {
        $c->close();
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid academic year.'
        ]);
        exit;
    }

    $find = $c->prepare("
        SELECT academic_year
        FROM academic
        WHERE academic_id = ?
          AND date_deleted IS NULL
        LIMIT 1
    ");
    $find->bind_param('i', $id);
    $find->execute();
    $record = $find->get_result()->fetch_assoc();
    $find->close();

    if (!$record) {
        $c->close();
        echo json_encode([
            'status' => 'error',
            'message' => 'Academic year not found.'
        ]);
        exit;
    }

    $stmt = $c->prepare("
        UPDATE academic
        SET date_deleted = NOW(), status = 'inactive'
        WHERE academic_id = ?
          AND date_deleted IS NULL
    ");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        $c->close();
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to delete academic year.'
        ]);
        exit;
    }

    bp_log(
        $c,
        (int)$actor['admin_id'],
        'Deleted Academic Year',
        $record['academic_year'] . ' (' . $id . ')'
    );

    $c->close();

    echo json_encode([
        'status' => 'success',
        'message' => 'Academic year deleted successfully.'
    ]);
    exit;
}

http_response_code(405);
echo json_encode([
    'status' => 'error',
    'message' => 'Method not allowed.'
]);
