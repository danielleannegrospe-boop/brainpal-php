<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('GET, OPTIONS');

$adminId=(int)($_GET['admin_id']??0);
$studentId=(int)($_GET['student_id']??0);
$actor=bp_require_role(['admin_id'=>$adminId],['super_admin','student_admin']);
if($studentId<=0){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Invalid student_id.']);exit;}

$c=bp_admin_conn();

$stmt=$c->prepare("SELECT s.*, st.strand_name, sp.specialization_name, a.academic_year,
    (SELECT MAX(qa.date_created) FROM quiz_attempt qa WHERE qa.student_id=s.student_id) AS last_quiz_date
    FROM student s
    LEFT JOIN strand st ON st.strand_id=s.strand_id AND st.date_deleted IS NULL
    LEFT JOIN specialization sp ON sp.specialization_id=s.specialization_id AND sp.date_deleted IS NULL
    LEFT JOIN academic a ON a.academic_id=s.academic_id AND a.date_deleted IS NULL
    WHERE s.student_id=? AND s.date_deleted IS NULL LIMIT 1");
$stmt->bind_param('i',$studentId);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$student){$c->close();http_response_code(404);echo json_encode(['success'=>false,'message'=>'Student not found.']);exit;}

$progress=[];
$p=$c->prepare("SELECT ps.subject_id, s.subject_name, ps.average_score, ps.total_attempts, ps.improvement_rate, ps.last_update
 FROM progress_summary ps LEFT JOIN subject s ON s.subject_id=ps.subject_id
 WHERE ps.student_id=? AND ps.date_deleted IS NULL ORDER BY ps.last_update DESC");
$p->bind_param('i',$studentId);$p->execute();$r=$p->get_result();
while($row=$r->fetch_assoc()){$progress[]=$row;}$p->close();

$week=$c->prepare("SELECT
 (SELECT COUNT(*) FROM quiz_attempt qa WHERE qa.student_id=? AND qa.date_created >= CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY) AS quiz_attempts,
 (SELECT COALESCE(AVG(qa.score),0) FROM quiz_attempt qa WHERE qa.student_id=? AND qa.date_created >= CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY) AS average_quiz_score,
 (SELECT COUNT(*) FROM study_material_sessions sms WHERE sms.student_id=? AND sms.status='completed' AND sms.completed_at >= CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY) AS materials_completed,
 (SELECT COALESCE(SUM(sms.total_seconds),0) FROM study_material_sessions sms WHERE sms.student_id=? AND sms.started_at >= CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY) AS study_seconds,
 (SELECT COUNT(*) FROM diagnostic_result dr WHERE dr.student_id=? AND dr.date_deleted IS NULL AND dr.date_taken >= CURDATE()-INTERVAL WEEKDAY(CURDATE()) DAY) AS diagnostics_taken");
$week->bind_param('iiiii',$studentId,$studentId,$studentId,$studentId,$studentId);$week->execute();$weekly=$week->get_result()->fetch_assoc()?:[];$week->close();

$student['student_id']=(int)$student['student_id'];
$student['points']=(int)($student['points']??0);
$student['diagnostic_completed']=(int)($student['diagnostic_completed']??0);
$student['current_streak']=(int)($student['current_streak']??0);
$student['longest_streak']=(int)($student['longest_streak']??0);
$student['total_opens']=(int)($student['total_opens']??0);
$student['is_verified']=(int)($student['is_verified']??0);

echo json_encode(['success'=>true,'student'=>$student,'progress'=>$progress,'weekly_summary'=>$weekly],JSON_UNESCAPED_UNICODE);
$c->close();
?>