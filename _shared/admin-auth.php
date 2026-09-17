<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db.php';
function bp_admin_conn(): BrainPalDb {
    $conn = brainpal_db();
    if ($conn->connect_error) throw new Exception('Database connection failed.');
    $conn->set_charset('utf8mb4'); return $conn;
}
function bp_json(): array { $d=json_decode(file_get_contents('php://input'),true); return is_array($d)?$d:[]; }
function bp_cors(string $methods='GET, POST, OPTIONS'): void {
    header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Content-Type'); header('Access-Control-Allow-Methods: '.$methods); header('Content-Type: application/json; charset=UTF-8');
    if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
}
function bp_admin(int $id): ?array {
    if($id<=0)return null; $c=bp_admin_conn(); $s=$c->prepare('SELECT admin_id,fullname,email,role,account_status FROM admin WHERE admin_id=? AND date_deleted IS NULL AND account_status="active" LIMIT 1'); $s->bind_param('i',$id); $s->execute(); $r=$s->get_result()->fetch_assoc()?:null; $s->close();$c->close();return $r;
}
function bp_require_role(array $data,array $allowed): array { $a=bp_admin((int)($data['admin_id']??0));$role=strtolower(trim((string)($a['role']??'')));if(!$a||!in_array($role,$allowed,true)){http_response_code(403);echo json_encode(['status'=>'error','message'=>'You do not have permission to perform this action.']);exit;}return $a; }
function bp_log(BrainPalDb $c, int $id, string $action, string $desc): void {
    $stmt = $c->prepare('INSERT INTO activity_logs(admin_id,action,description) VALUES(?,?,?)');
    if (!$stmt) {
        error_log('bp_log prepare error: '.$c->error);
        return;
    }

    $stmt->bind_param('iss', $id, $action, $desc);
    if (!$stmt->execute()) {
        error_log('bp_log execute error: '.$stmt->error);
        $stmt->close();
        return;
    }
    $logId = (int)$stmt->insert_id;
    $stmt->close();

    // Every data-changing admin action is also surfaced to Super Admin.
    // Notifications are stored with user_role='admin' for compatibility
    // with the existing admin notification UI.
    $actorName = 'Administrator';
    $actorRole = 'admin';
    $nameStmt = $c->prepare('SELECT fullname, role FROM admin WHERE admin_id=? LIMIT 1');
    if ($nameStmt) {
        $nameStmt->bind_param('i', $id);
        if ($nameStmt->execute()) {
            $actor = $nameStmt->get_result()->fetch_assoc();
            if ($actor) {
                $actorName = trim((string)($actor['fullname'] ?? 'Administrator')) ?: 'Administrator';
                $actorRole = strtolower(trim((string)($actor['role'] ?? 'admin')));
            }
        }
        $nameStmt->close();
    }

    $title = 'Admin Activity: '.$action;
    $message = $actorName.' ('.str_replace('_', ' ', $actorRole).') '.$desc;

    $notify = $c->prepare(
        "INSERT INTO notifications (user_id,user_role,type,title,message,reference_id,is_read)
         SELECT admin_id,'admin','admin_activity',?,?,?,0
         FROM admin
         WHERE role='super_admin'
           AND date_deleted IS NULL
           AND account_status='active'
           AND admin_id <> ?"
    );
    if ($notify) {
        $notify->bind_param('ssii', $title, $message, $logId, $id);
        if (!$notify->execute()) {
            error_log('Super Admin notification error: '.$notify->error);
        }
        $notify->close();
    } else {
        error_log('Super Admin notification prepare error: '.$c->error);
    }
}
