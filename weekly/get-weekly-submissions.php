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
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){exit;}
$conn = brainpal_db();
if($conn->connect_error){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Database connection failed.']);exit;}
$conn->set_charset('utf8mb4');
$adminId=(int)($_GET['admin_id']??0);
$a=$conn->query("SELECT role FROM admin WHERE admin_id=$adminId AND date_deleted IS NULL AND account_status='active'")->fetch_assoc();
if(!$a || strtolower($a['role'])!=='super_admin'){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Super Admin only.']);exit;}
$res=$conn->query("SELECT ws.submission_id,ws.weekly_report_id,ws.submitted_by,ws.submitted_role,ws.submission_status,ws.submitted_at,
 wr.student_id,wr.week_start,wr.week_end,wr.scheduled_sessions,wr.completed_materials,wr.completed_quizzes,wr.quiz_attempts,wr.average_quiz_score,wr.total_study_seconds,wr.pending_subjects,
 CONCAT(s.firstName,' ',s.lastName) student_name, a.fullname submitted_by_name
 FROM weekly_report_submissions ws JOIN weekly_student_reports wr ON wr.weekly_report_id=ws.weekly_report_id
 JOIN student s ON s.student_id=wr.student_id JOIN admin a ON a.admin_id=ws.submitted_by
 ORDER BY ws.submitted_at DESC");
$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;
echo json_encode(['success'=>true,'submissions'=>$rows],JSON_UNESCAPED_UNICODE);$conn->close();
?>