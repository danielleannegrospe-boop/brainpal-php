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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){exit;}
$conn = brainpal_db();
if($conn->connect_error){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Database connection failed.']);exit;}
$conn->set_charset('utf8mb4');
$d=json_decode(file_get_contents('php://input'),true)??[];
$adminId=(int)($d['admin_id']??0); $reportId=(int)($d['weekly_report_id']??0);
$a=$conn->query("SELECT admin_id,role FROM admin WHERE admin_id=$adminId AND date_deleted IS NULL AND account_status='active'")->fetch_assoc();
if(!$a){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Admin access required.']);exit;}
$role=strtolower($a['role']);
if($role==='super_admin'){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Super Admin reviews reports; it does not submit them.']);exit;}
if($reportId<=0){echo json_encode(['success'=>false,'message'=>'Invalid weekly report.']);exit;}
$chk=$conn->query("SELECT weekly_report_id FROM weekly_student_reports WHERE weekly_report_id=$reportId")->fetch_assoc();
if(!$chk){echo json_encode(['success'=>false,'message'=>'Weekly report not found.']);exit;}
$stmt=$conn->prepare("INSERT INTO weekly_report_submissions(weekly_report_id,submitted_by,submitted_role) VALUES(?,?,?) ON DUPLICATE KEY UPDATE submitted_at=CURRENT_TIMESTAMP,submission_status='pending',reviewed_by=NULL,reviewed_at=NULL,review_notes=NULL");
$stmt->bind_param('iis',$reportId,$adminId,$role);
$ok=$stmt->execute(); $stmt->close(); $conn->close();
echo json_encode(['success'=>$ok,'message'=>$ok?'Weekly report submitted to Super Admin for review.':'Unable to submit weekly report.']);
?>