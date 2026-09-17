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
if($adminId<=0){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Invalid admin_id.']);exit;}
$a=$conn->query("SELECT admin_id,role,fullname FROM admin WHERE admin_id=$adminId AND date_deleted IS NULL AND account_status='active' LIMIT 1")->fetch_assoc();
if(!$a){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Admin access required.']);exit;}
$role=strtolower(trim($a['role']));
$allowed=['super_admin','student_admin','content_admin','admin'];
if(!in_array($role,$allowed,true)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Invalid admin role.']);exit;}
$weekStart=$conn->real_escape_string($_GET['week_start']??'');
if($weekStart==='') $weekStart=date('Y-m-d',strtotime('monday this week'));
$studentId=(int)($_GET['student_id']??0);
$where="wr.week_start='$weekStart'";
if($role!=='super_admin'){
  $where.=" AND EXISTS (SELECT 1 FROM weekly_report_submissions wsr WHERE wsr.weekly_report_id=wr.weekly_report_id AND wsr.submitted_by=$adminId)";
}
if($studentId>0) $where.=" AND wr.student_id=$studentId";
$sql="SELECT wr.*, s.studentNo, CONCAT(s.firstName,' ',s.lastName) student_name,
 (SELECT GROUP_CONCAT(DISTINCT CONCAT(sub.subject_name,' (',ss.scheduled_day,' ',TIME_FORMAT(ss.start_time,'%h:%i %p'),')') ORDER BY ss.scheduled_day SEPARATOR ', ')
  FROM study_schedule_archive ss JOIN subject sub ON sub.subject_id=ss.subject_id
  WHERE ss.student_id=wr.student_id AND ss.scheduled_day BETWEEN wr.week_start AND wr.week_end) schedule_summary
 FROM weekly_student_reports wr JOIN student s ON s.student_id=wr.student_id WHERE $where ORDER BY student_name";
$res=$conn->query($sql); $rows=[];
while($r=$res->fetch_assoc()) $rows[]=$r;
echo json_encode(['success'=>true,'role'=>$role,'week_start'=>$weekStart,'reports'=>$rows],JSON_UNESCAPED_UNICODE);
$conn->close();
?>