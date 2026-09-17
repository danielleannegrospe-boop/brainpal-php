<?php
require_once __DIR__.'/../_shared/admin-auth.php';
require_once __DIR__.'/../_shared/notification-helper.php';
bp_cors('POST, OPTIONS');$d=bp_json();$a=bp_require_role($d,['super_admin']);$type=strtolower(trim((string)($d['content_type']??'')));$id=(int)($d['content_id']??0);$action=strtolower(trim((string)($d['action']??'')));$reason=trim((string)($d['reason']??''));if(!in_array($type,['quiz','learning_material'],true)||$id<=0||!in_array($action,['approve','reject'],true)){echo json_encode(['status'=>'error','message'=>'Invalid approval request.']);exit;}if($action==='reject'&&$reason===''){echo json_encode(['status'=>'error','message'=>'A rejection reason is required.']);exit;}$c=bp_admin_conn();$status=$action==='approve'?'approved':'rejected';
if($type==='quiz'){$s=$c->prepare('UPDATE quiz SET approval_status=?,reviewed_by=?,reviewed_at=NOW(),rejection_reason=? WHERE quiz_id=? AND approval_status="pending"');$s->bind_param('sisi',$status,$a['admin_id'],$reason,$id);$description='Quiz #'.$id.' '.$status.'.';}else{$s=$c->prepare('UPDATE learning_material SET approval_status=?,reviewed_by=?,reviewed_at=NOW(),rejection_reason=? WHERE material_id=? AND approval_status="pending"');$s->bind_param('sisi',$status,$a['admin_id'],$reason,$id);$description='Learning Material #'.$id.' '.$status.'.';}if(!$s->execute()||$s->affected_rows!==1){echo json_encode(['status'=>'error','message'=>'Content is no longer pending or was not found.']);$s->close();$c->close();exit;}$s->close();$submitted=null;if($type==='quiz'){$s=$c->prepare('SELECT admin_id,quiz_title FROM quiz WHERE quiz_id=?');}else{$s=$c->prepare('SELECT admin_id,title FROM learning_material WHERE material_id=?');}$s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();$submitted=(int)($row['admin_id']??0);$title=$row['quiz_title']??$row['title']??$description;$log=$c->prepare('INSERT INTO content_approval_logs(content_type,content_id,action,submitted_by,reviewed_by,reason) VALUES(?,?,?,?,?,?)');$log->bind_param('sisiis',$type,$id,$action,$submitted,$a['admin_id'],$reason);$log->execute();$log->close();bp_log($c,(int)$a['admin_id'],strtoupper($action).' CONTENT',$title.' - '.$description.($reason!==''?' Reason: '.$reason:''));// Notify the student(s) assigned to a learning material only after Super Admin approval.
$notificationCount = 0;
if ($action === 'approve') {
    if ($type === 'learning_material') {
        $scheduleLabel = '';
        $scheduleStmt = $c->prepare(
            'SELECT ss.scheduled_day, ss.start_time, ss.duration_minutes
             FROM learning_material lm
             INNER JOIN study_schedule ss ON ss.schedule_id = lm.schedule_id
             WHERE lm.material_id = ? LIMIT 1'
        );
        if ($scheduleStmt) {
            $scheduleStmt->bind_param('i', $id);
            $scheduleStmt->execute();
            $scheduleRow = $scheduleStmt->get_result()->fetch_assoc();
            $scheduleStmt->close();

            if ($scheduleRow) {
                $scheduleDate = strtotime(
                    (string)$scheduleRow['scheduled_day'] . ' ' .
                    (string)$scheduleRow['start_time']
                );
                $duration = max(1, (int)($scheduleRow['duration_minutes'] ?? 60));
                if ($scheduleDate !== false) {
                    $scheduleEnd = $scheduleDate + ($duration * 60);
                    $scheduleLabel =
                        ' It is scheduled for ' .
                        date('l, F j, Y', $scheduleDate) .
                        ' from ' .
                        date('g:i A', $scheduleDate) .
                        ' to ' .
                        date('g:i A', $scheduleEnd) .
                        '.';
                }
            }
        }

        $notificationCount = notifyAllMaterialStudents(
            $c,
            $id,
            'new_material',
            'New Learning Material Scheduled',
            'The learning material "' . $title . '" has been approved.' .
            ($scheduleLabel !== '' ? $scheduleLabel . ' It will be available only during that scheduled study time.' : ' It will be available according to your study schedule.'),
            $id
        );

        /*
         * If an already-approved quiz is attached to this material,
         * notify the assigned student(s) now that both layers are approved.
         */
        $quizStmt = $c->prepare(
            'SELECT quiz_id, quiz_title
             FROM quiz
             WHERE material_id = ?
               AND approval_status = "approved"'
        );
        if ($quizStmt) {
            $quizStmt->bind_param('i', $id);
            $quizStmt->execute();
            $quizResult = $quizStmt->get_result();
            while ($quizRow = $quizResult->fetch_assoc()) {
                $notificationCount += notifyAllMaterialStudents(
                    $c,
                    $id,
                    'new_quiz',
                    'New Quiz Available',
                    'A new quiz "' . ($quizRow['quiz_title'] ?? 'Practice Quiz') . '" is now available after approval.',
                    (int)$quizRow['quiz_id']
                );
            }
            $quizStmt->close();
        }
    } elseif ($type === 'quiz') {
        /*
         * A quiz is visible to students only when its own approval
         * AND its linked learning material are approved.
         */
        $materialStmt = $c->prepare(
            'SELECT q.quiz_id, q.quiz_title, q.material_id, lm.approval_status AS material_approval_status
             FROM quiz q
             INNER JOIN learning_material lm ON lm.material_id = q.material_id
             WHERE q.quiz_id = ? LIMIT 1'
        );
        if ($materialStmt) {
            $materialStmt->bind_param('i', $id);
            $materialStmt->execute();
            $quizRow = $materialStmt->get_result()->fetch_assoc();
            $materialStmt->close();

            if ($quizRow && ($quizRow['material_approval_status'] ?? '') === 'approved') {
                $notificationCount = notifyAllMaterialStudents(
                    $c,
                    (int)$quizRow['material_id'],
                    'new_quiz',
                    'New Quiz Available',
                    'A new quiz "' . ($quizRow['quiz_title'] ?? 'Practice Quiz') . '" is now available after approval.',
                    (int)$quizRow['quiz_id']
                );
            }
        }
    }
}

$c->close();
echo json_encode([
    'status' => 'success',
    'message' => 'Content ' . $status . ' successfully.',
    'approval_status' => $status,
    'notification_count' => $notificationCount
]);
