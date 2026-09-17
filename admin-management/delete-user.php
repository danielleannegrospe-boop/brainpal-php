<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('POST, OPTIONS');
$d=bp_json();
$actor=bp_require_role($d,['super_admin','student_admin']);
$id=(int)($d['id']??0);
$role=strtolower(trim((string)($d['role']??'')));
if($id<=0||$role===''){echo json_encode(['status'=>'error','message'=>'Invalid request.']);exit;}
if(strtolower($actor['role'])==='student_admin' && $role!=='student'){echo json_encode(['status'=>'error','message'=>'Student Admin can only manage student accounts.']);exit;}
$c=bp_admin_conn();
if($role==='student'){
  $s=$c->prepare("SELECT CONCAT_WS(' ',firstName,NULLIF(m_initial,''),lastName,NULLIF(extension,'')) AS fullname FROM student WHERE student_id=? AND date_deleted IS NULL");
}else{
  $s=$c->prepare("SELECT fullname FROM admin WHERE admin_id=? AND date_deleted IS NULL");
}
$s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
if(!$row){echo json_encode(['status'=>'error','message'=>'User not found.']);$c->close();exit;}
if($role==='student') $s=$c->prepare("UPDATE student SET date_deleted=NOW(),account_status='deactivated' WHERE student_id=? AND date_deleted IS NULL");
else $s=$c->prepare("UPDATE admin SET date_deleted=NOW(),account_status='deactivated' WHERE admin_id=? AND date_deleted IS NULL");
$s->bind_param('i',$id);
if(!$s->execute()){echo json_encode(['status'=>'error','message'=>'Unable to deactivate user.']);$s->close();$c->close();exit;}
$s->close();bp_log($c,(int)$actor['admin_id'],'Deleted User','Deleted '.ucwords(str_replace('_',' ',$role)).': '.$row['fullname']);$c->close();
echo json_encode(['status'=>'success','message'=>'User deactivated successfully.']);
