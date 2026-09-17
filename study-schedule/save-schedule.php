<?php
require_once __DIR__ . '/../_shared/db.php';
require_once __DIR__ . '/../_shared/notification-helper.php';
brainpal_cors('POST, OPTIONS');
$conn = brainpal_db();
$data = brainpal_json_input();

$studentId = (int)($data['student_id'] ?? 0);
$resultId = (int)($data['result_id'] ?? 0);
$academicId = (int)($data['academic_id'] ?? 0);
$schedules = $data['schedules'] ?? [];
$pendingSubjectIds = array_values(array_unique(array_filter(array_map('intval', (array)$data['pending_subject_ids'] ?? []), fn($id) => $id > 0)));

if ($studentId <= 0 || !is_array($schedules) || count($schedules) === 0) {
    echo json_encode(['status'=>'error','success'=>false,'message'=>'Invalid schedule data.']);
    $conn->close();
    exit;
}

// A generated schedule is one weekly cycle. All sessions must fit within a 7-day window.
$dates = [];
foreach ($schedules as $item) {
    $date = trim((string)($item['scheduled_day'] ?? ''));
    $start = trim((string)($item['start_time'] ?? ''));
    $duration = (int)($item['duration_minutes'] ?? 0);
    $subjectId = (int)($item['subject_id'] ?? 0);
    if ($date === '' || $subjectId <= 0 || $duration <= 0 || $duration > 60) {
        echo json_encode(['status'=>'error','success'=>false,'message'=>'Each schedule session needs a valid date, subject, and duration (maximum 60 minutes).']);
        $conn->close();
        exit;
    }
    $dates[] = $date;
}

sort($dates);
$first = strtotime($dates[0]);
$last = strtotime(end($dates));
if ($first === false || $last === false || (($last - $first) / 86400) > 6) {
    echo json_encode([
        'status'=>'error',
        'success'=>false,
        'message'=>'The study schedule must cover only one weekly cycle (maximum 7 calendar days).'
    ]);
    $conn->close();
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        'INSERT INTO study_schedule
         (student_id, subject_id, result_id, academic_id, scheduled_day, start_time, duration_minutes, ranking, status, ai_generated)
         VALUES (?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, NULLIF(?, ""), ?, ?, "scheduled", 1)'
    );
    if (!$stmt) throw new Exception('Unable to prepare schedule query.');

    $inserted = [];
    foreach ($schedules as $index => $item) {
        $subjectId = (int)$item['subject_id'];
        $date = trim((string)$item['scheduled_day']);
        $start = trim((string)($item['start_time'] ?? ''));
        $duration = (int)$item['duration_minutes'];
        $ranking = (int)($item['ranking'] ?? ($index + 1));
        $stmt->bind_param('iiiissii', $studentId, $subjectId, $resultId, $academicId, $date, $start, $duration, $ranking);
        if (!$stmt->execute()) throw new Exception('Unable to save a schedule session.');
        $inserted[] = ['schedule_id' => (int)$conn->insert_id];
    }
    $stmt->close();

    /*
     * Persist subjects that could not fit this weekly cycle.
     * A subject is removed from the pending list once it is scheduled.
     */
    $conn->query(
        'CREATE TABLE IF NOT EXISTS study_schedule_pending (
            pending_id INT NOT NULL AUTO_INCREMENT,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            last_missed_week_start DATE NOT NULL,
            carry_count INT NOT NULL DEFAULT 1,
            date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (pending_id),
            UNIQUE KEY uq_pending_student_subject (student_id, subject_id),
            KEY idx_pending_student (student_id),
            KEY idx_pending_week (last_missed_week_start),
            CONSTRAINT fk_pending_student
              FOREIGN KEY (student_id) REFERENCES student(student_id)
              ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_pending_subject
              FOREIGN KEY (subject_id) REFERENCES subject(subject_id)
              ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    $weekStart = date('Y-m-d', strtotime('monday this week'));

    $pendingStmt = $conn->prepare(
        'INSERT INTO study_schedule_pending
            (student_id, subject_id, last_missed_week_start, carry_count)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            last_missed_week_start = VALUES(last_missed_week_start),
            carry_count = carry_count + 1,
            date_updated = CURRENT_TIMESTAMP'
    );

    if (!$pendingStmt) {
        throw new Exception('Unable to prepare pending-subject tracking.');
    }

    foreach ($pendingSubjectIds as $subjectId) {
        $pendingStmt->bind_param('iis', $studentId, $subjectId, $weekStart);
        if (!$pendingStmt->execute()) {
            $pendingStmt->close();
            throw new Exception('Unable to track an unscheduled subject.');
        }
    }
    $pendingStmt->close();

    /*
     * Remove subjects that were successfully scheduled this cycle.
     */
    $clearPending = $conn->prepare(
        'DELETE FROM study_schedule_pending
         WHERE student_id = ?
           AND subject_id NOT IN (
             SELECT subject_id
             FROM study_schedule
             WHERE student_id = ?
               AND date_deleted IS NULL
               AND scheduled_day BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)
               AND status = "scheduled"
           )'
    );
    if ($clearPending) {
        $clearPending->bind_param('iiss', $studentId, $studentId, $weekStart, $weekStart);
        $clearPending->execute();
        $clearPending->close();
    }

    $conn->commit();

    // Notify all admins that this student generated a study schedule.
    $studentName = getStudentName($conn, $studentId);
    notifyAllAdmins(
        $conn,
        'schedule_generated',
        'Study Schedule Generated',
        $studentName . ' generated a new weekly study schedule.',
        $resultId > 0 ? $resultId : null
    );

    /*
     * Notify the student about each exact study session.
     * This gives the student an advance reminder such as:
     * "Sunday, September 6, 2026 • 6:00 PM–7:00 PM — Mathematics".
     */
    $clearReminders = $conn->prepare(
        "DELETE FROM notifications
         WHERE user_id = ?
           AND user_role = 'student'
           AND type = 'schedule_reminder'"
    );
    if ($clearReminders) {
        $clearReminders->bind_param('i', $studentId);
        $clearReminders->execute();
        $clearReminders->close();
    }

    foreach ($schedules as $index => $item) {
        $scheduleId = (int)($inserted[$index]['schedule_id'] ?? 0);
        $subjectId = (int)($item['subject_id'] ?? 0);
        $date = trim((string)($item['scheduled_day'] ?? ''));
        $start = trim((string)($item['start_time'] ?? ''));
        $duration = (int)($item['duration_minutes'] ?? 60);

        $subjectName = 'your subject';
        $subjectStmt = $conn->prepare(
            'SELECT subject_name FROM subject WHERE subject_id = ? LIMIT 1'
        );
        if ($subjectStmt) {
            $subjectStmt->bind_param('i', $subjectId);
            $subjectStmt->execute();
            $subjectRow = $subjectStmt->get_result()->fetch_assoc();
            $subjectStmt->close();
            $subjectName = trim((string)($subjectRow['subject_name'] ?? '')) ?: $subjectName;
        }

        $startTs = strtotime($date . ' ' . $start);
        $endTs = $startTs !== false ? $startTs + ($duration * 60) : false;
        $dateLabel = $startTs !== false ? date('l, F j, Y', $startTs) : $date;
        $startLabel = $startTs !== false ? date('g:i A', $startTs) : $start;
        $endLabel = $endTs !== false ? date('g:i A', $endTs) : '';

        $message = $endLabel !== ''
            ? 'Study "' . $subjectName . '" on ' . $dateLabel . ' from ' . $startLabel . ' to ' . $endLabel . '.'
            : 'Study "' . $subjectName . '" on ' . $dateLabel . ' at ' . $startLabel . '.';

        notifyStudent(
            $conn,
            $studentId,
            'schedule_reminder',
            'Upcoming Study Schedule',
            $message,
            $scheduleId > 0 ? $scheduleId : null
        );
    }

    notifyStudent(
        $conn,
        $studentId,
        'schedule_updated',
        'Weekly Study Schedule Updated',
        'Your weekly study schedule has been saved. Check the schedule for your study days and times.',
        null
    );

    $conn->close();
    echo json_encode([
        'status'=>'success',
        'success'=>true,
        'message'=>'Weekly study schedule saved successfully.',
        'data'=>$inserted,
        'weekly'=>true
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    http_response_code(500);
    echo json_encode(['status'=>'error','success'=>false,'message'=>$e->getMessage()]);
}
