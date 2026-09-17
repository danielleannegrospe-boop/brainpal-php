<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('POST, OPTIONS');
$d=bp_json();
$actor=bp_require_role($d,['super_admin','student_admin']);

$id=(int)($d['id']??0);
$name=trim((string)($d['name']??''));
$email=trim((string)($d['email']??''));
$role=strtolower(trim((string)($d['role']??'')));

if($id<=0||$name===''||$email===''||!in_array($role,['student','admin','super_admin','student_admin','content_admin'],true)){
  echo json_encode(['status'=>'error','message'=>'Invalid user information.']);exit;
}
if(strtolower($actor['role'])==='student_admin' && $role!=='student'){
  echo json_encode(['status'=>'error','message'=>'Student Admin can only manage student accounts.']);exit;
}

$c=bp_admin_conn();
if($role==='student'){
  $s=$c->prepare('SELECT email FROM student WHERE email=? AND student_id<>? AND date_deleted IS NULL');
  $s->bind_param('si',$email,$id);
}else{
  $s=$c->prepare('SELECT email FROM admin WHERE email=? AND admin_id<>? AND date_deleted IS NULL');
  $s->bind_param('si',$email,$id);
}
$s->execute();
if($s->get_result()->fetch_assoc()){echo json_encode(['status'=>'error','message'=>'Email already exists.']);$s->close();$c->close();exit;}
$s->close();

if($role==='student'){
  $s=$c->prepare("UPDATE student SET firstName=?,m_initial='',lastName='',email=? WHERE student_id=? AND date_deleted IS NULL");
  $s->bind_param('ssi',$name,$email,$id);
}else{
  $s=$c->prepare('UPDATE admin SET fullname=?,email=?,role=? WHERE admin_id=? AND date_deleted IS NULL');
  $s->bind_param('sssi',$name,$email,$role,$id);
}
if(!$s||!$s->execute()){echo json_encode(['status'=>'error','message'=>'Failed to update user.']);if($s)$s->close();$c->close();exit;}
$s->close();bp_log($c,(int)$actor['admin_id'],'Updated User','Updated '.ucwords(str_replace('_',' ',$role)).': '.$name);$c->close();
echo json_encode(['status'=>'success','message'=>'User updated.']);
