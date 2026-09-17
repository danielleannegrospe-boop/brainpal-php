<?php

require_once __DIR__ . '/../_shared/admin-auth.php';

bp_cors('POST, OPTIONS');


// =====================================================
// GET REQUEST DATA
// =====================================================

$data = bp_json();

$email = trim(
    (string)($data['email'] ?? '')
);

$password = (string)(
    $data['password'] ?? ''
);


// =====================================================
// VALIDATION
// =====================================================

if ($email === '' || $password === '') {

    echo json_encode([
        'status' => 'error',
        'message' => 'Missing email or password'
    ]);

    exit;
}


// =====================================================
// DATABASE CONNECTION
// =====================================================

$c = bp_admin_conn();


// =====================================================
// FIND ADMIN
// =====================================================

$sql = "
    SELECT
        admin_id,
        fullname,
        email,
        hashed_password,
        role,
        account_status,
        date_created
    FROM admin
    WHERE email = ?
      AND date_deleted IS NULL
      AND account_status = 'active'
    LIMIT 1
";

$s = $c->prepare($sql);


if (!$s) {

    echo json_encode([
        'status' => 'error',
        'message' => 'Database query error'
    ]);

    $c->close();

    exit;
}


$s->bind_param(
    's',
    $email
);

$s->execute();

$result = $s->get_result();

$admin = $result->fetch_assoc();


// =====================================================
// VERIFY PASSWORD
// =====================================================

if (
    !$admin ||
    !password_verify(
        $password,
        $admin['hashed_password']
    )
) {

    $s->close();
    $c->close();

    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid email or password'
    ]);

    exit;
}


// =====================================================
// GET ACTUAL DATABASE ROLE
// =====================================================

$role = strtolower(
    trim(
        (string)(
            $admin['role'] ?? ''
        )
    )
);


// =====================================================
// VALID ADMIN ROLES
// =====================================================

$validRoles = [
    'super_admin',
    'student_admin',
    'content_admin',
    'admin'
];


// =====================================================
// INVALID ROLE
// =====================================================

if (!in_array($role, $validRoles, true)) {

    $role = 'admin';
}


// =====================================================
// SUCCESS RESPONSE
// =====================================================

echo json_encode([

    'status' => 'success',

    // IMPORTANT:
    // Return the ACTUAL role here.
    'role' => $role,

    'user' => [

        'admin_id' =>
            (int)$admin['admin_id'],

        'fullname' =>
            $admin['fullname'],

        'email' =>
            $admin['email'],

        'role' =>
            $role,

        'account_status' =>
            $admin['account_status'],

        'date_created' =>
            $admin['date_created']

    ]

]);


$s->close();
$c->close();

exit;