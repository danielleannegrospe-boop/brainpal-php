<?php
require_once __DIR__ . '/../_shared/db.php';
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); echo json_encode(['success'=>true,'data'=>[]]); exit; }
$conn = brainpal_db();
if ($conn->connect_error) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database connection failed.','data'=>[]]); exit; }
$conn->set_charset('utf8mb4');
$sql="SELECT qa.attempt_id, qa.quiz_id, qa.student_id, qa.score, qa.earned_points, qa.total_points, qa.date_created, q.quiz_title, lm.title AS material_title, s.subject_name, CONCAT_WS(' ',st.firstName,NULLIF(st.m_initial,''),st.lastName,NULLIF(st.extension,'')) AS student_name, COALESCE(st.points,0) AS student_total_xp, COALESCE((SELECT AVG(qa2.score) FROM quiz_attempt qa2 INNER JOIN quiz q2 ON q2.quiz_id=qa2.quiz_id INNER JOIN learning_material lm2 ON lm2.material_id=q2.material_id WHERE qa2.student_id=qa.student_id AND lm2.subject_id=lm.subject_id),0) AS progress_percentage FROM quiz_attempt qa INNER JOIN quiz q ON q.quiz_id=qa.quiz_id LEFT JOIN learning_material lm ON lm.material_id=q.material_id LEFT JOIN subject s ON s.subject_id=lm.subject_id INNER JOIN student st ON st.student_id=qa.student_id WHERE st.date_deleted IS NULL ORDER BY qa.date_created DESC, qa.attempt_id DESC";
$stmt=$conn->prepare($sql);
if(!$stmt){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Failed to prepare admin quiz history query.','data'=>[]]);$conn->close();exit;}
if(!$stmt->execute()){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Failed to load admin quiz history.','error'=>$stmt->error,'data'=>[]]);$stmt->close();$conn->close();exit;}
$result=$stmt->get_result();$data=[];
while($row=$result->fetch_assoc()){$data[]=['attempt_id'=>(int)$row['attempt_id'],'quiz_id'=>(int)$row['quiz_id'],'student_id'=>(int)$row['student_id'],'student_name'=>trim((string)($row['student_name']??'Unknown Student')),'quiz_title'=>(string)($row['quiz_title']??'Quiz'),'subject_name'=>(string)($row['subject_name']??'Unknown Subject'),'material_title'=>(string)($row['material_title']??''),'score'=>(float)($row['score']??0),'earned_points'=>(int)($row['earned_points']??0),'total_points'=>(int)($row['total_points']??0),'student_total_xp'=>(int)($row['student_total_xp']??0),'progress_percentage'=>round(min(100,max(0,(float)($row['progress_percentage']??0)))),'date_created'=>(string)($row['date_created']??'')];}
$stmt->close();$conn->close();echo json_encode(['success'=>true,'message'=>count($data)?'Admin quiz history loaded successfully.':'No quiz history found.','count'=>count($data),'data'=>$data],JSON_UNESCAPED_UNICODE);
