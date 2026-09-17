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
$adminId=(int)($d['admin_id']??0); $submissionId=(int)($d['submission_id']??0);
$action=strtolower(trim($d['action']??'')); $notes=trim($d['notes']??'');
$a=$conn->query("SELECT admin_id,role FROM admin WHERE admin_id=$adminId AND date_deleted IS NULL AND account_status='active'")->fetch_assoc();
if(!$a || strtolower($a['role'])!=='super_admin'){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Super Admin approval is required.']);exit;}
if(!in_array($action,['approve','reject'],true) || $submissionId<=0){echo json_encode(['success'=>false,'message'=>'Invalid review request.']);exit;}
$status=$action==='approve'?'approved':'rejected';
$stmt=$conn->prepare("UPDATE weekly_report_submissions SET submission_status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=? WHERE submission_id=?");
$stmt->bind_param('sisi',$status,$adminId,$notes,$submissionId); $ok=$stmt->execute(); $stmt->close();
if($ok && $action==='approve'){
  $r=$conn->query("SELECT weekly_report_id FROM weekly_report_submissions WHERE submission_id=$submissionId")->fetch_assoc();
  if($r){$rid=(int)$r['weekly_report_id'];$conn->query("UPDATE weekly_student_reports SET report_status='approved',reviewed_by=$adminId,reviewed_at=NOW(),review_notes='".$conn->real_escape_string($notes)."' WHERE weekly_report_id=$rid");}
}
echo json_encode(['success'=>$ok,'message'=>$ok?'Weekly report review saved.':'Unable to review weekly report.']);
$conn->close();
?>