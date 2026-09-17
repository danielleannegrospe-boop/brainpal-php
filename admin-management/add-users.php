<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('POST, OPTIONS');
$d=bp_json();
$actor=bp_require_role($d,['super_admin','student_admin']);

$name=trim((string)($d['name']??''));
$email=trim((string)($d['email']??''));
$role=strtolower(trim((string)($d['role']??'')));
$password=(string)($d['password']??'');

if($name===''||$email===''||$password===''){echo json_encode(['status'=>'error','message'=>'Please fill all fields.']);exit;}
if(!in_array($role,['student','admin','super_admin','student_admin','content_admin'],true)){echo json_encode(['status'=>'error','message'=>'Invalid role.']);exit;}
if(strtolower($actor['role'])==='student_admin' && $role!=='student'){echo json_encode(['status'=>'error','message'=>'Student Admin can only manage student accounts.']);exit;}

$c=bp_admin_conn();
$s=$c->prepare('SELECT email FROM student WHERE email=? AND date_deleted IS NULL UNION ALL SELECT email FROM admin WHERE email=? AND date_deleted IS NULL LIMIT 1');
$s->bind_param('ss',$email,$email);$s->execute();
if($s->get_result()->fetch_assoc()){echo json_encode(['status'=>'error','message'=>'Email already exists.']);$s->close();$c->close();exit;}
$s->close();$hash=password_hash($password,PASSWORD_DEFAULT);

if($role==='student'){
  $s=$c->prepare("INSERT INTO student(firstName,m_initial,lastName,extension,age,email,gender,hashed_password,strand_id,grade_level,gwa,date_created,account_status,is_verified) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,0)");
  $empty='';$age=18;$gender='Male';$strand=1;$grade=11;$gwa=85;$status='active';
  $s->bind_param('ssssisssiiis',$name,$empty,$empty,$empty,$age,$email,$gender,$hash,$strand,$grade,$gwa,$status);
}else{
  $s=$c->prepare('INSERT INTO admin(fullname,email,hashed_password,role,date_created,account_status) VALUES(?,?,?, ?,NOW(),"active")');
  $s->bind_param('ssss',$name,$email,$hash,$role);
}
if(!$s||!$s->execute()){echo json_encode(['status'=>'error','message'=>'Failed to create user.']);if($s)$s->close();$c->close();exit;}
$newId=$c->insert_id;$s->close();
bp_log($c,(int)$actor['admin_id'],'Added User','Added '.ucwords(str_replace('_',' ',$role)).': '.$name);
$c->close();
echo json_encode(['status'=>'success','message'=>'User added successfully.','user_id'=>(int)$newId,'role'=>$role]);
