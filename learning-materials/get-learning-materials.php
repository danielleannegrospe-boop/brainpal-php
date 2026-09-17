<?php
require_once __DIR__.'/../_shared/db.php';
brainpal_cors('GET, OPTIONS');
$studentId=(int)($_GET['student_id']??0);
if($studentId<=0){
 echo json_encode(['status'=>'error','success'=>false,'message'=>'Invalid student_id.','data'=>[]]);
 exit;
}
$c=brainpal_db();
$sql='SELECT
 lm.material_id,lm.schedule_id,lm.subject_id,lm.academic_id,lm.title,lm.type,lm.link,
 lm.difficulty_level,lm.description,lm.date_created,ss.scheduled_day,ss.start_time,s.subject_name,
 CASE WHEN ss.scheduled_day = CURDATE() THEN 1 ELSE 0 END AS scheduled_today,
 CASE WHEN ss.scheduled_day = CURDATE() AND CURRENT_TIME() >= ss.start_time AND CURRENT_TIME() < ADDTIME(ss.start_time, SEC_TO_TIME(ss.duration_minutes * 60)) THEN 1 ELSE 0 END AS scheduled_now,
 COALESCE(sms.status,"not_started") AS study_status,
 COALESCE(sms.total_seconds,0) AS study_seconds,
 sms.completed_at
 FROM learning_material lm
 INNER JOIN study_schedule ss ON ss.schedule_id=lm.schedule_id
 LEFT JOIN subject s ON s.subject_id=lm.subject_id
 LEFT JOIN (
   SELECT x.*
   FROM study_material_sessions x
   INNER JOIN (
     SELECT student_id,material_id,MAX(session_id) session_id
     FROM study_material_sessions
     GROUP BY student_id,material_id
   ) latest ON latest.session_id=x.session_id
 ) sms ON sms.material_id=lm.material_id AND sms.student_id=?
 WHERE ss.student_id=? AND ss.date_deleted IS NULL AND lm.date_deleted IS NULL
   AND lm.approval_status="approved"
   AND ss.status="scheduled"
 ORDER BY ss.scheduled_day ASC,ss.start_time ASC,lm.material_id DESC';
$s=$c->prepare($sql);
if(!$s){echo json_encode(['status'=>'error','success'=>false,'message'=>'Unable to load learning materials.','data'=>[]]);$c->close();exit;}
$s->bind_param('ii',$studentId,$studentId);$s->execute();$r=$s->get_result();$data=[];
while($row=$r->fetch_assoc()){
 $row['material_id']=(int)$row['material_id']; $row['schedule_id']=(int)$row['schedule_id'];
 $row['subject_id']=$row['subject_id']!==null?(int)$row['subject_id']:null;
 $row['study_seconds']=(int)$row['study_seconds'];
 $row['study_completed']=($row['study_status']==='completed')?1:0;
 $row['scheduled_today']=(int)($row['scheduled_today'] ?? 0);
 $row['scheduled_now']=(int)($row['scheduled_now'] ?? 0);
 $row['can_open_today']=($row['scheduled_today']===1 && $row['study_completed']===0) ? 1 : 0;
 $row['can_open_now']=($row['scheduled_now']===1 && $row['study_completed']===0) ? 1 : 0;
 $data[]=$row;
}
$s->close();$c->close();
echo json_encode(['status'=>'success','success'=>true,'data'=>$data,'materials'=>$data,'total'=>count($data)],JSON_UNESCAPED_UNICODE);
