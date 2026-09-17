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
$sid=(int)($d['student_id']??0); $days=$d['days']??[];
if($sid<=0 || !is_array($days)){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Invalid availability data.']);exit;}
$conn->query("CREATE TABLE IF NOT EXISTS student_weekly_availability (
 availability_id INT NOT NULL AUTO_INCREMENT, student_id INT NOT NULL, day_name VARCHAR(20) NOT NULL,
 slot_start TIME NOT NULL, slot_end TIME NOT NULL, date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(availability_id), UNIQUE KEY uq_student_day_slot(student_id,day_name,slot_start,slot_end), KEY idx_availability_student(student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$conn->query("DELETE FROM student_weekly_availability WHERE student_id=$sid");
$stmt=$conn->prepare("INSERT INTO student_weekly_availability(student_id,day_name,slot_start,slot_end) VALUES(?,?,?,?)");
$count=0;
foreach($days as $day){
  if(empty($day['available']) || !is_array($day['slots']??null)) continue;
  $name=trim((string)($day['day']??''));
  foreach($day['slots'] as $slot){
    $start=trim((string)($slot['start']??'')); $end=trim((string)($slot['end']??''));
    if($name!=='' && preg_match('/^\d{2}:\d{2}$/',$start) && preg_match('/^\d{2}:\d{2}$/',$end) && $start<$end){
      $stmt->bind_param('isss',$sid,$name,$start,$end); if($stmt->execute()) $count++;
    }
  }
}
$stmt->close();$conn->close();
echo json_encode(['success'=>true,'saved_slots'=>$count]);
?>