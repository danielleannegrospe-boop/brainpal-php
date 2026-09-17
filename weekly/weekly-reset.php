<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/db.php';
date_default_timezone_set('Asia/Manila');
$bpCorsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$bpCorsAllowedOrigins = [
    'http://localhost:8100',
    'http://127.0.0.1:8100',
    'http://localhost',
    'https://localhost',
    'capacitor://localhost',
];

if (in_array($bpCorsOrigin, $bpCorsAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $bpCorsOrigin);
}
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
$conn = brainpal_db();
if($conn->connect_error){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Database connection failed.']);exit;}
$conn->set_charset('utf8mb4');

function monday(string $date): string {
  return date('Y-m-d', strtotime('monday this week', strtotime($date)));
}
$weekStart=monday(date('Y-m-d'));
$weekEnd=date('Y-m-d',strtotime($weekStart.' +6 days'));
$prevStart=date('Y-m-d',strtotime($weekStart.' -7 days'));
$prevEnd=date('Y-m-d',strtotime($weekStart.' -1 day'));

$conn->begin_transaction();
try {
  $conn->query("CREATE TABLE IF NOT EXISTS study_schedule_archive (
    archive_id INT NOT NULL AUTO_INCREMENT, original_schedule_id INT NOT NULL, student_id INT NOT NULL,
    subject_id INT DEFAULT NULL, result_id INT DEFAULT NULL, academic_id INT DEFAULT NULL,
    scheduled_day DATE DEFAULT NULL, start_time TIME DEFAULT NULL, duration_minutes INT DEFAULT NULL,
    ranking INT DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, ai_generated TINYINT(1) DEFAULT NULL,
    original_date_created TIMESTAMP NULL DEFAULT NULL, archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(archive_id), UNIQUE KEY uq_archive_original_schedule(original_schedule_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  $conn->query("CREATE TABLE IF NOT EXISTS weekly_student_reports (
    weekly_report_id INT NOT NULL AUTO_INCREMENT, student_id INT NOT NULL, week_start DATE NOT NULL,
    week_end DATE NOT NULL, scheduled_sessions INT NOT NULL DEFAULT 0, completed_materials INT NOT NULL DEFAULT 0,
    completed_quizzes INT NOT NULL DEFAULT 0, quiz_attempts INT NOT NULL DEFAULT 0,
    average_quiz_score DECIMAL(6,2) NOT NULL DEFAULT 0.00, total_study_seconds INT NOT NULL DEFAULT 0,
    pending_subjects INT NOT NULL DEFAULT 0, report_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, review_notes VARCHAR(1000) DEFAULT NULL,
    date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(weekly_report_id),
    UNIQUE KEY uq_weekly_student(student_id,week_start)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  $conn->query("CREATE TABLE IF NOT EXISTS weekly_report_submissions (
    submission_id INT NOT NULL AUTO_INCREMENT, weekly_report_id INT NOT NULL, submitted_by INT NOT NULL,
    submitted_role VARCHAR(50) NOT NULL, submission_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL, review_notes VARCHAR(1000) DEFAULT NULL,
    PRIMARY KEY(submission_id), UNIQUE KEY uq_weekly_report_admin(weekly_report_id,submitted_by)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

  $students=$conn->query("SELECT DISTINCT student_id FROM study_schedule WHERE date_deleted IS NULL AND status='scheduled' AND scheduled_day BETWEEN '$prevStart' AND '$prevEnd'");
  $processed=0;
  while($st=$students->fetch_assoc()){
    $sid=(int)$st['student_id'];
    $q=$conn->query("SELECT * FROM study_schedule WHERE student_id=$sid AND date_deleted IS NULL AND status='scheduled' AND scheduled_day BETWEEN '$prevStart' AND '$prevEnd'");
    if(!$q || $q->num_rows===0) continue;

    $sessions=$q->num_rows;
    $completedMaterials=(int)($conn->query("SELECT COUNT(*) c FROM study_material_sessions sms
      JOIN study_schedule ss ON ss.schedule_id=sms.schedule_id
      WHERE sms.student_id=$sid AND sms.status='completed' AND ss.scheduled_day BETWEEN '$prevStart' AND '$prevEnd'")->fetch_assoc()['c']??0);
    $quizRow=$conn->query("SELECT COUNT(DISTINCT qa.attempt_id) attempts,
      COALESCE(SUM(CASE WHEN qa.score IS NOT NULL THEN 1 ELSE 0 END),0) completed,
      COALESCE(AVG(qa.score),0) avg_score
      FROM quiz_attempt qa
      JOIN quiz qz ON qz.quiz_id=qa.quiz_id
      JOIN learning_material lm ON lm.material_id=qz.material_id
      JOIN study_schedule ss ON ss.schedule_id=lm.schedule_id
      WHERE qa.student_id=$sid
        AND ss.student_id=$sid
        AND ss.scheduled_day BETWEEN '$prevStart' AND '$prevEnd'
        AND qa.date_created BETWEEN '$prevStart 00:00:00' AND '$prevEnd 23:59:59'")->fetch_assoc();
    $studyRow=$conn->query("SELECT COALESCE(SUM(sms.total_seconds),0) secs FROM study_material_sessions sms
      JOIN study_schedule ss ON ss.schedule_id=sms.schedule_id
      WHERE sms.student_id=$sid AND ss.scheduled_day BETWEEN '$prevStart' AND '$prevEnd'")->fetch_assoc();
    $pending=0;
    $p=$conn->query("SELECT COUNT(*) c FROM study_schedule_pending WHERE student_id=$sid");
    if($p) $pending=(int)($p->fetch_assoc()['c']??0);

    $avgSql=$conn->real_escape_string((string)$avg);
    $conn->query("INSERT INTO weekly_student_reports
      (student_id,week_start,week_end,scheduled_sessions,completed_materials,completed_quizzes,quiz_attempts,average_quiz_score,total_study_seconds,pending_subjects)
      VALUES($sid,'$prevStart','$prevEnd',$sessions,$completedMaterials,$completedQ,$attempts,$avgSql,$secs,$pending)
      ON DUPLICATE KEY UPDATE scheduled_sessions=VALUES(scheduled_sessions),completed_materials=VALUES(completed_materials),
      completed_quizzes=VALUES(completed_quizzes),quiz_attempts=VALUES(quiz_attempts),average_quiz_score=VALUES(average_quiz_score),
      total_study_seconds=VALUES(total_study_seconds),pending_subjects=VALUES(pending_subjects)");

    $wrRow=$conn->query("SELECT weekly_report_id FROM weekly_student_reports WHERE student_id=$sid AND week_start='$prevStart' LIMIT 1")->fetch_assoc();
    $wrId=(int)($wrRow['weekly_report_id']??0);
    if($wrId>0){
      $adminsForReport=$conn->query("SELECT admin_id,role FROM admin WHERE date_deleted IS NULL AND account_status='active' AND LOWER(role)<>'super_admin'");
      while($ar=$adminsForReport->fetch_assoc()){
        $arId=(int)$ar['admin_id']; $arRole=$conn->real_escape_string(strtolower($ar['role']));
        $conn->query("INSERT IGNORE INTO weekly_report_submissions(weekly_report_id,submitted_by,submitted_role)
          VALUES($wrId,$arId,'$arRole')");
      }
    }

    while($r=$q->fetch_assoc()){
      $aid=$conn->real_escape_string((string)$r['schedule_id']);
      $conn->query("INSERT IGNORE INTO study_schedule_archive
        (original_schedule_id,student_id,subject_id,result_id,academic_id,scheduled_day,start_time,duration_minutes,ranking,status,ai_generated,original_date_created)
        VALUES($aid,".(int)$r['student_id'].",".((int)$r['subject_id']).",".((int)$r['result_id']).",".((int)$r['academic_id']).",
        '". $conn->real_escape_string((string)$r['scheduled_day'])."','". $conn->real_escape_string((string)$r['start_time'])."',
        ".((int)$r['duration_minutes']).",".((int)$r['ranking']).",'". $conn->real_escape_string((string)$r['status'])."',
        ".((int)$r['ai_generated']).",'". $conn->real_escape_string((string)$r['date_created'])."')");
    }
    $conn->query("UPDATE study_schedule SET status='archived', date_deleted=NOW()
      WHERE student_id=$sid AND date_deleted IS NULL AND status='scheduled' AND scheduled_day BETWEEN '$prevStart' AND '$prevEnd'");

    $stu=$conn->query("SELECT CONCAT(firstName,' ',lastName) name FROM student WHERE student_id=$sid")->fetch_assoc();
    $name=$stu['name']??'Student';
    $msg="Your previous weekly study schedule has been completed and archived. Your progress was preserved. A new week is now ready.";
    $exists=$conn->query("SELECT notification_id FROM notifications WHERE user_id=$sid AND user_role='student' AND type='weekly_reset' AND reference_id IS NULL AND DATE(date_created)=CURDATE()");
    if(!$exists || $exists->num_rows===0) $conn->query("INSERT INTO notifications(user_id,user_role,type,title,message,reference_id) VALUES($sid,'student','weekly_reset','New Study Week Started','$msg',NULL)");
    $processed++;
  }

  $admins=$conn->query("SELECT admin_id,role FROM admin WHERE date_deleted IS NULL AND account_status='active'");
  while($a=$admins->fetch_assoc()){
    $aid=(int)$a['admin_id']; $role=$conn->real_escape_string(strtolower($a['role']));
    $msg="The previous study week has been archived and student progress has been preserved. Weekly reports are ready for review.";
    $conn->query("INSERT INTO notifications(user_id,user_role,type,title,message,reference_id)
      SELECT $aid,'admin','weekly_reset','Weekly Schedule Reset','$msg',NULL
      WHERE NOT EXISTS(SELECT 1 FROM notifications WHERE user_id=$aid AND user_role='admin' AND type='weekly_reset' AND DATE(date_created)=CURDATE())");
  }
  $conn->commit();
  echo json_encode(['success'=>true,'week_start'=>$weekStart,'previous_week_start'=>$prevStart,'processed_students'=>$processed,'message'=>$processed.' student weekly schedule(s) archived.']);
} catch(Throwable $e){
  $conn->rollback(); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Weekly rollover failed.','error'=>$e->getMessage()]);
}
$conn->close();
?>