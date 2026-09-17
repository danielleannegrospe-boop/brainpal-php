<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('GET, POST, DELETE, OPTIONS');
$method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'){
  $adminId=(int)($_GET['admin_id']??0); bp_require_role(['admin_id'=>$adminId],['super_admin','student_admin']);
  $strandId=(int)($_GET['strand_id']??0); $c=bp_admin_conn();
  if($strandId>0){$s=$c->prepare('SELECT specialization_id,strand_id,specialization_name FROM specialization WHERE strand_id=? AND date_deleted IS NULL ORDER BY specialization_name');$s->bind_param('i',$strandId);}
  else{$s=$c->prepare('SELECT specialization_id,strand_id,specialization_name FROM specialization WHERE date_deleted IS NULL ORDER BY specialization_name');}
  $s->execute();$r=$s->get_result();$items=[];while($row=$r->fetch_assoc())$items[]=['specialization_id'=>(int)$row['specialization_id'],'strand_id'=>(int)$row['strand_id'],'specialization_name'=>$row['specialization_name']];$s->close();$c->close();echo json_encode(['status'=>'success','specializations'=>$items]);exit;
}
$d=bp_json();$actor=bp_require_role($d,['super_admin','student_admin']);$c=bp_admin_conn();
if($method==='POST'){
 $id=(int)($d['specialization_id']??0);$strand=(int)($d['strand_id']??0);$name=trim((string)($d['specialization_name']??''));
 if($strand<=0||$name===''){echo json_encode(['status'=>'error','message'=>'Strand and specialization are required.']);exit;}
 if($id>0){$s=$c->prepare('UPDATE specialization SET strand_id=?,specialization_name=? WHERE specialization_id=? AND date_deleted IS NULL');$s->bind_param('isi',$strand,$name,$id);$msg='Specialization updated successfully.';$action='Updated Specialization';}
 else{$s=$c->prepare('SELECT specialization_id FROM specialization WHERE strand_id=? AND specialization_name=? AND date_deleted IS NULL');$s->bind_param('is',$strand,$name);$s->execute();if($s->get_result()->fetch_assoc()){echo json_encode(['status'=>'error','message'=>'Specialization already exists.']);exit;}$s->close();$s=$c->prepare('INSERT INTO specialization(strand_id,specialization_name) VALUES(?,?)');$s->bind_param('is',$strand,$name);$msg='Specialization added successfully.';$action='Added Specialization';}
 if(!$s->execute()){echo json_encode(['status'=>'error','message'=>'Failed to save specialization.']);exit;}$s->close();bp_log($c,(int)$actor['admin_id'],$action,$name);$c->close();echo json_encode(['status'=>'success','message'=>$msg]);exit;
}
if($method==='DELETE'){
 $id=(int)($d['specialization_id']??0);if($id<=0){echo json_encode(['status'=>'error','message'=>'Invalid specialization.']);exit;}
 $s=$c->prepare('UPDATE specialization SET date_deleted=NOW() WHERE specialization_id=? AND date_deleted IS NULL');$s->bind_param('i',$id);$s->execute();$s->close();bp_log($c,(int)$actor['admin_id'],'Deleted Specialization','Specialization ID: '.$id);$c->close();echo json_encode(['status'=>'success','message'=>'Specialization deleted successfully.']);exit;
}
