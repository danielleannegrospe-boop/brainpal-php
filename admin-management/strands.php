<?php
require_once __DIR__.'/../_shared/admin-auth.php';
bp_cors('GET, POST, DELETE, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $adminId = (int)($_GET['admin_id'] ?? 0);
    bp_require_role(['admin_id'=>$adminId], ['super_admin','student_admin']);
    $c=bp_admin_conn();
    $sql="SELECT s.strand_id,s.strand_name,COUNT(sp.specialization_id) AS specialization_count
          FROM strand s LEFT JOIN specialization sp ON sp.strand_id=s.strand_id AND sp.date_deleted IS NULL
          WHERE s.date_deleted IS NULL GROUP BY s.strand_id,s.strand_name ORDER BY s.strand_name";
    $r=$c->query($sql); $items=[];
    while($row=$r->fetch_assoc()) $items[]=['strand_id'=>(int)$row['strand_id'],'strand_name'=>$row['strand_name'],'specialization_count'=>(int)$row['specialization_count']];
    $c->close(); echo json_encode(['status'=>'success','strands'=>$items]); exit;
}
$d=bp_json(); $actor=bp_require_role($d,['super_admin','student_admin']); $c=bp_admin_conn();
if($method==='POST'){
    $id=(int)($d['strand_id']??0); $name=trim((string)($d['strand_name']??''));
    if($name===''){echo json_encode(['status'=>'error','message'=>'Strand name is required.']);exit;}
    if($id>0){$s=$c->prepare('UPDATE strand SET strand_name=? WHERE strand_id=? AND date_deleted IS NULL');$s->bind_param('si',$name,$id);$msg='Strand updated successfully.'; $action='Updated Strand';}
    else{$s=$c->prepare('SELECT strand_id FROM strand WHERE strand_name=? AND date_deleted IS NULL');$s->bind_param('s',$name);$s->execute();if($s->get_result()->fetch_assoc()){echo json_encode(['status'=>'error','message'=>'Strand already exists.']);exit;}$s->close();$s=$c->prepare('INSERT INTO strand(strand_name) VALUES(?)');$s->bind_param('s',$name);$msg='Strand added successfully.';$action='Added Strand';}
    if(!$s->execute()){echo json_encode(['status'=>'error','message'=>'Failed to save strand.']);exit;} $s->close(); bp_log($c,(int)$actor['admin_id'],$action,$name); $c->close(); echo json_encode(['status'=>'success','message'=>$msg]); exit;
}
if($method==='DELETE'){
    $id=(int)($d['strand_id']??0); if($id<=0){echo json_encode(['status'=>'error','message'=>'Invalid strand.']);exit;}
    $s=$c->prepare('UPDATE strand SET date_deleted=NOW() WHERE strand_id=? AND date_deleted IS NULL');$s->bind_param('i',$id);$s->execute();$s->close();bp_log($c,(int)$actor['admin_id'],'Deleted Strand','Strand ID: '.$id);$c->close();echo json_encode(['status'=>'success','message'=>'Strand deleted successfully.']);exit;
}
