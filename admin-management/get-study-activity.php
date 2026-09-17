<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('GET, OPTIONS');
$studentId=(int)($_GET['student_id']??0);
if($studentId<=0){echo json_encode(['success'=>false,'status'=>'error','message'=>'Invalid student_id.','data'=>[]]);exit;}
$c=bp_admin_conn();
$s=$c->prepare(
 'SELECT sms.session_id,sms.student_id,sms.material_id,sms.schedule_id,lm.title AS material_title,
         s.subject_name,sms.status,sms.started_at,sms.last_heartbeat_at,sms.total_seconds,sms.completed_at,
         (sms.status="studying" AND sms.last_heartbeat_at >= DATE_SUB(NOW(),INTERVAL 30 SECOND)) AS currently_studying
  FROM study_material_sessions sms
  INNER JOIN learning_material lm ON lm.material_id=sms.material_id
  LEFT JOIN subject s ON s.subject_id=lm.subject_id
  WHERE sms.student_id=? AND lm.date_deleted IS NULL
  ORDER BY sms.last_heartbeat_at DESC'
);
$s->bind_param('i',$studentId);$s->execute();$r=$s->get_result();$data=[];
while($row=$r->fetch_assoc()){
 foreach(['session_id','student_id','material_id','schedule_id','total_seconds','currently_studying'] as $k)$row[$k]=(int)$row[$k];
 $data[]=$row;
}
$s->close();$c->close();
echo json_encode(['success'=>true,'status'=>'success','data'=>$data],JSON_UNESCAPED_UNICODE);
