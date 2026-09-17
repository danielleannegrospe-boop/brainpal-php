<?php
require_once __DIR__ . '/../_shared/admin-auth.php';

bp_cors('GET, POST, DELETE, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'];

$allowedRoles = [
    'super_admin',
    'content_admin'
];

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function bp_diag_json_error(
    string $message,
    int $status = 400
): void {
    http_response_code($status);

    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| GET
|--------------------------------------------------------------------------
| Loads:
| - diagnostic questions
| - strands
| - specializations
| - subjects (including specialization_id)
| - academic years
|--------------------------------------------------------------------------
*/
if ($method === 'GET') {

    $adminId = (int)(
        $_GET['admin_id'] ?? 0
    );

    bp_require_role(
        ['admin_id' => $adminId],
        $allowedRoles
    );

    $strandId =
        (int)($_GET['strand_id'] ?? 0);

    $specializationId =
        (int)($_GET['specialization_id'] ?? 0);

    $subjectId =
        (int)($_GET['subject_id'] ?? 0);

    $academicId =
        (int)($_GET['academic_id'] ?? 0);

    $semester =
        trim((string)(
            $_GET['semester'] ?? ''
        ));

    $difficulty =
        trim((string)(
            $_GET['difficulty'] ?? ''
        ));

    $c = bp_admin_conn();

    /*
    |--------------------------------------------------------------------------
    | Diagnostic question query
    |--------------------------------------------------------------------------
    */
    $where = [
        'd.date_deleted IS NULL',
        's.date_deleted IS NULL',
        'a.date_deleted IS NULL'
    ];

    $params = [];
    $types = '';

    if ($strandId > 0) {
        $where[] =
            's.strand_id = ?';

        $params[] =
            $strandId;

        $types .= 'i';
    }

    if ($specializationId > 0) {
        $where[] =
            '(COALESCE(s.specialization_id, 0) = ? OR COALESCE(s.specialization_id, 0) = 0)';

        $params[] =
            $specializationId;

        $types .= 'i';
    }

    if ($subjectId > 0) {
        $where[] =
            'd.subject_id = ?';

        $params[] =
            $subjectId;

        $types .= 'i';
    }

    if ($academicId > 0) {
        $where[] =
            'd.academic_id = ?';

        $params[] =
            $academicId;

        $types .= 'i';
    }

    if ($semester !== '') {
        $where[] =
            's.semester = ?';

        $params[] =
            $semester;

        $types .= 's';
    }

    if ($difficulty !== '') {
        $where[] =
            'd.difficulty = ?';

        $params[] =
            $difficulty;

        $types .= 's';
    }

    $sql = "
        SELECT
            d.diagnostic_id,
            d.subject_id,

            s.subject_name,
            s.strand_id,
            st.strand_name,

            s.specialization_id,
            COALESCE(
                sp.specialization_name,
                ''
            ) AS specialization_name,

            s.semester,
            s.grade_level,

            d.academic_id,
            a.academic_year,

            d.question,
            d.choice_a,
            d.choice_b,
            d.choice_c,
            d.choice_d,
            d.correct_answer,
            d.difficulty,

            d.admin_id,
            d.date_created

        FROM diagnostic d

        INNER JOIN subject s
            ON s.subject_id = d.subject_id

        LEFT JOIN strand st
            ON st.strand_id = s.strand_id
            AND st.date_deleted IS NULL

        LEFT JOIN specialization sp
            ON sp.specialization_id =
               NULLIF(s.specialization_id, 0)
            AND sp.date_deleted IS NULL

        INNER JOIN academic a
            ON a.academic_id = d.academic_id

        WHERE " . implode(
            ' AND ',
            $where
        ) . "

        ORDER BY
            a.date_start DESC,
            s.semester ASC,
            s.subject_name ASC,
            d.diagnostic_id ASC
    ";

    $stmt =
        $c->prepare($sql);

    if (!$stmt) {
        $c->close();

        bp_diag_json_error(
            'Failed to prepare diagnostic query.',
            500
        );
    }

    if ($params) {
        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    if (!$stmt->execute()) {
        $stmt->close();
        $c->close();

        bp_diag_json_error(
            'Failed to execute diagnostic query.',
            500
        );
    }

    $result =
        $stmt->get_result();

    $questions = [];

    while (
        $row =
            $result->fetch_assoc()
    ) {

        $questions[] = [
            'diagnostic_id' =>
                (int)$row[
                    'diagnostic_id'
                ],

            'subject_id' =>
                (int)$row[
                    'subject_id'
                ],

            'subject_name' =>
                (string)$row[
                    'subject_name'
                ],

            'strand_id' =>
                (int)$row[
                    'strand_id'
                ],

            'strand_name' =>
                (string)(
                    $row['strand_name']
                    ?? ''
                ),

            'specialization_id' =>
                $row[
                    'specialization_id'
                ] === null
                    ? null
                    : (int)$row[
                        'specialization_id'
                    ],

            'specialization_name' =>
                (string)(
                    $row[
                        'specialization_name'
                    ] ?? ''
                ),

            'semester' =>
                (string)$row[
                    'semester'
                ],

            'grade_level' =>
                $row['grade_level'] === null
                    ? null
                    : (int)$row[
                        'grade_level'
                    ],

            'academic_id' =>
                (int)$row[
                    'academic_id'
                ],

            'academic_year' =>
                (string)$row[
                    'academic_year'
                ],

            'question' =>
                (string)$row[
                    'question'
                ],

            'choice_a' =>
                (string)(
                    $row['choice_a'] ?? ''
                ),

            'choice_b' =>
                (string)(
                    $row['choice_b'] ?? ''
                ),

            'choice_c' =>
                (string)(
                    $row['choice_c'] ?? ''
                ),

            'choice_d' =>
                (string)(
                    $row['choice_d'] ?? ''
                ),

            'correct_answer' =>
                (string)(
                    $row[
                        'correct_answer'
                    ] ?? ''
                ),

            'difficulty' =>
                (string)(
                    $row['difficulty']
                    ?? ''
                ),

            'admin_id' =>
                $row['admin_id'] === null
                    ? null
                    : (int)$row[
                        'admin_id'
                    ],

            'date_created' =>
                $row['date_created']
        ];
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Metadata: Strands
    |--------------------------------------------------------------------------
    */
    $meta = [
        'strands' => [],
        'specializations' => [],
        'subjects' => [],
        'academic_years' => [],
        'difficulties' => [
            'ver_easy',
            'easy',
            'easy_medium',
            'medium',
            'hard'
        ]
    ];

    $r = $c->query("
        SELECT
            strand_id,
            strand_name
        FROM strand
        WHERE date_deleted IS NULL
        ORDER BY strand_name ASC
    ");

    while (
        $r &&
        ($row = $r->fetch_assoc())
    ) {
        $meta['strands'][] = [
            'strand_id' =>
                (int)$row['strand_id'],

            'strand_name' =>
                (string)$row['strand_name']
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata: Specializations
    |--------------------------------------------------------------------------
    */
    $r = $c->query("
        SELECT
            specialization_id,
            strand_id,
            specialization_name
        FROM specialization
        WHERE date_deleted IS NULL
        ORDER BY
            strand_id ASC,
            specialization_name ASC
    ");

    while (
        $r &&
        ($row = $r->fetch_assoc())
    ) {
        $meta['specializations'][] = [
            'specialization_id' =>
                (int)$row[
                    'specialization_id'
                ],

            'strand_id' =>
                (int)$row['strand_id'],

            'specialization_name' =>
                (string)$row[
                    'specialization_name'
                ]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata: Subjects
    |--------------------------------------------------------------------------
    | specialization_id is intentionally included.
    | 0 / NULL means General / Shared.
    |--------------------------------------------------------------------------
    */
    $r = $c->query("
        SELECT
            s.subject_id,
            s.subject_name,
            s.strand_id,
            s.specialization_id,
            s.semester,
            s.grade_level
        FROM subject s
        WHERE s.date_deleted IS NULL
        ORDER BY
            s.strand_id ASC,
            s.semester ASC,
            s.subject_name ASC
    ");

    while (
        $r &&
        ($row = $r->fetch_assoc())
    ) {
        $meta['subjects'][] = [
            'subject_id' =>
                (int)$row[
                    'subject_id'
                ],

            'subject_name' =>
                (string)$row[
                    'subject_name'
                ],

            'strand_id' =>
                (int)$row[
                    'strand_id'
                ],

            'specialization_id' =>
                $row[
                    'specialization_id'
                ] === null
                    ? null
                    : (int)$row[
                        'specialization_id'
                    ],

            'semester' =>
                (string)$row[
                    'semester'
                ],

            'grade_level' =>
                $row['grade_level'] === null
                    ? null
                    : (int)$row[
                        'grade_level'
                    ]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata: Academic Years
    |--------------------------------------------------------------------------
    */
    $r = $c->query("
        SELECT
            academic_id,
            academic_year,
            status
        FROM academic
        WHERE date_deleted IS NULL
        ORDER BY date_start DESC
    ");

    while (
        $r &&
        ($row = $r->fetch_assoc())
    ) {
        $meta['academic_years'][] = [
            'academic_id' =>
                (int)$row[
                    'academic_id'
                ],

            'academic_year' =>
                (string)$row[
                    'academic_year'
                ],

            'status' =>
                (string)$row[
                    'status'
                ]
        ];
    }

    $c->close();

    echo json_encode([
        'status' => 'success',
        'questions' => $questions,
        'meta' => $meta,
        'count' => count($questions)
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| POST / DELETE
|--------------------------------------------------------------------------
*/
$data = bp_json();

$actor = bp_require_role(
    $data,
    $allowedRoles
);

$c = bp_admin_conn();

/*
|--------------------------------------------------------------------------
| POST: Create / Update
|--------------------------------------------------------------------------
*/
if ($method === 'POST') {

    $id =
        (int)($data[
            'diagnostic_id'
        ] ?? 0);

    $subjectId =
        (int)($data[
            'subject_id'
        ] ?? 0);

    $academicId =
        (int)($data[
            'academic_id'
        ] ?? 0);

    $strandId =
        (int)($data[
            'strand_id'
        ] ?? 0);

    $specializationId =
        (int)($data[
            'specialization_id'
        ] ?? 0);

    $semester =
        trim((string)(
            $data['semester'] ?? ''
        ));

    $question =
        trim((string)(
            $data['question'] ?? ''
        ));

    $choiceA =
        trim((string)(
            $data['choice_a'] ?? ''
        ));

    $choiceB =
        trim((string)(
            $data['choice_b'] ?? ''
        ));

    $choiceC =
        trim((string)(
            $data['choice_c'] ?? ''
        ));

    $choiceD =
        trim((string)(
            $data['choice_d'] ?? ''
        ));

    $correctAnswer =
        strtoupper(
            trim((string)(
                $data[
                    'correct_answer'
                ] ?? ''
            ))
        );

    $difficulty =
        trim((string)(
            $data['difficulty'] ?? ''
        ));

    if (
        $subjectId <= 0 ||
        $academicId <= 0 ||
        $strandId <= 0 ||
        $semester === '' ||
        $question === '' ||
        $choiceA === '' ||
        $choiceB === '' ||
        $choiceC === '' ||
        $choiceD === ''
    ) {
        $c->close();

        bp_diag_json_error(
            'Complete all diagnostic question fields.'
        );
    }

    if (
        !in_array(
            $correctAnswer,
            ['A', 'B', 'C', 'D'],
            true
        )
    ) {
        $c->close();

        bp_diag_json_error(
            'Correct answer must be A, B, C, or D.'
        );
    }

    if (
        !in_array(
            $difficulty,
            [
                'ver_easy',
                'easy',
                'easy_medium',
                'medium',
                'hard'
            ],
            true
        )
    ) {
        $c->close();

        bp_diag_json_error(
            'Invalid difficulty level.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate the selected subject.
    |--------------------------------------------------------------------------
    | The subject must:
    | - be active
    | - belong to the selected strand
    | - belong to the selected semester
    | - be either shared/general OR belong to selected specialization
    |--------------------------------------------------------------------------
    */
    $subjectCheck = $c->prepare("
        SELECT
            subject_id,
            strand_id,
            specialization_id,
            semester,
            grade_level
        FROM subject
        WHERE subject_id = ?
          AND date_deleted IS NULL
        LIMIT 1
    ");

    if (!$subjectCheck) {
        $c->close();

        bp_diag_json_error(
            'Failed to validate selected subject.',
            500
        );
    }

    $subjectCheck->bind_param(
        'i',
        $subjectId
    );

    $subjectCheck->execute();

    $subjectRow =
        $subjectCheck
            ->get_result()
            ->fetch_assoc();

    $subjectCheck->close();

    if (!$subjectRow) {
        $c->close();

        bp_diag_json_error(
            'Selected subject was not found.'
        );
    }

    $actualStrandId =
        (int)$subjectRow[
            'strand_id'
        ];

    $actualSpecializationId =
        (int)(
            $subjectRow[
                'specialization_id'
            ] ?? 0
        );

    $actualSemester =
        trim((string)(
            $subjectRow[
                'semester'
            ] ?? ''
        ));

    if (
        $actualStrandId !==
        $strandId
    ) {
        $c->close();

        bp_diag_json_error(
            'Selected subject does not belong to the selected strand.'
        );
    }

    if (
        strcasecmp(
            $actualSemester,
            $semester
        ) !== 0
    ) {
        $c->close();

        bp_diag_json_error(
            'Selected subject does not belong to the selected semester.'
        );
    }

    /*
    | General / shared subject is valid for a selected specialization.
    */
    if (
        $actualSpecializationId !== 0 &&
        $actualSpecializationId !==
        $specializationId
    ) {
        $c->close();

        bp_diag_json_error(
            'Selected subject is not valid for the selected specialization.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate academic year
    |--------------------------------------------------------------------------
    */
    $academicCheck =
        $c->prepare("
            SELECT academic_id
            FROM academic
            WHERE academic_id = ?
              AND date_deleted IS NULL
            LIMIT 1
        ");

    if (!$academicCheck) {
        $c->close();

        bp_diag_json_error(
            'Failed to validate academic year.',
            500
        );
    }

    $academicCheck->bind_param(
        'i',
        $academicId
    );

    $academicCheck->execute();

    $academicExists =
        $academicCheck
            ->get_result()
            ->fetch_assoc();

    $academicCheck->close();

    if (!$academicExists) {
        $c->close();

        bp_diag_json_error(
            'Selected academic year was not found.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate question check
    |--------------------------------------------------------------------------
    */
    $check =
        $c->prepare("
            SELECT diagnostic_id
            FROM diagnostic
            WHERE subject_id = ?
              AND academic_id = ?
              AND question = ?
              AND date_deleted IS NULL
              AND diagnostic_id <> ?
            LIMIT 1
        ");

    if (!$check) {
        $c->close();

        bp_diag_json_error(
            'Failed to validate duplicate question.',
            500
        );
    }

    $check->bind_param(
        'iisi',
        $subjectId,
        $academicId,
        $question,
        $id
    );

    $check->execute();

    $duplicate =
        $check
            ->get_result()
            ->fetch_assoc();

    $check->close();

    if ($duplicate) {
        $c->close();

        bp_diag_json_error(
            'A diagnostic question with the same question already exists for this subject and academic year.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */
    if ($id > 0) {

        $stmt =
            $c->prepare("
                UPDATE diagnostic
                SET
                    subject_id = ?,
                    academic_id = ?,
                    question = ?,
                    choice_a = ?,
                    choice_b = ?,
                    choice_c = ?,
                    choice_d = ?,
                    correct_answer = ?,
                    difficulty = ?
                WHERE diagnostic_id = ?
                  AND date_deleted IS NULL
            ");

        if (!$stmt) {
            $c->close();

            bp_diag_json_error(
                'Failed to prepare update.',
                500
            );
        }

        $stmt->bind_param(
            'iisssssssi',
            $subjectId,
            $academicId,
            $question,
            $choiceA,
            $choiceB,
            $choiceC,
            $choiceD,
            $correctAnswer,
            $difficulty,
            $id
        );

        $action =
            'Updated Diagnostic Question';

        $message =
            'Diagnostic question updated successfully.';

    } else {

        $stmt =
            $c->prepare("
                INSERT INTO diagnostic (
                    subject_id,
                    admin_id,
                    academic_id,
                    question,
                    choice_a,
                    choice_b,
                    choice_c,
                    choice_d,
                    correct_answer,
                    difficulty
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");

        if (!$stmt) {
            $c->close();

            bp_diag_json_error(
                'Failed to prepare insert.',
                500
            );
        }

        $actorId =
            (int)$actor['admin_id'];

        $stmt->bind_param(
            'iiisssssss',
            $subjectId,
            $actorId,
            $academicId,
            $question,
            $choiceA,
            $choiceB,
            $choiceC,
            $choiceD,
            $correctAnswer,
            $difficulty
        );

        $action =
            'Added Diagnostic Question';

        $message =
            'Diagnostic question added successfully.';
    }

    if (!$stmt->execute()) {
        $error =
            $stmt->error;

        $stmt->close();
        $c->close();

        bp_diag_json_error(
            'Failed to save diagnostic question: ' .
            $error,
            500
        );
    }

    $stmt->close();

    bp_log(
        $c,
        (int)$actor['admin_id'],
        $action,
        $question
    );

    $c->close();

    echo json_encode([
        'status' => 'success',
        'message' => $message
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/
if ($method === 'DELETE') {

    $id =
        (int)($data[
            'diagnostic_id'
        ] ?? 0);

    if ($id <= 0) {
        $c->close();

        bp_diag_json_error(
            'Invalid diagnostic question.'
        );
    }

    $stmt =
        $c->prepare("
            UPDATE diagnostic
            SET date_deleted = NOW()
            WHERE diagnostic_id = ?
              AND date_deleted IS NULL
        ");

    if (!$stmt) {
        $c->close();

        bp_diag_json_error(
            'Failed to prepare delete.',
            500
        );
    }

    $stmt->bind_param(
        'i',
        $id
    );

    if (!$stmt->execute()) {
        $stmt->close();
        $c->close();

        bp_diag_json_error(
            'Failed to delete diagnostic question.',
            500
        );
    }

    $affected =
        $stmt->affected_rows;

    $stmt->close();

    if ($affected < 1) {
        $c->close();

        bp_diag_json_error(
            'Diagnostic question not found.'
        );
    }

    bp_log(
        $c,
        (int)$actor['admin_id'],
        'Deleted Diagnostic Question',
        'Diagnostic ID: ' . $id
    );

    $c->close();

    echo json_encode([
        'status' => 'success',
        'message' =>
            'Diagnostic question deleted successfully.'
    ]);

    exit;
}

http_response_code(405);

echo json_encode([
    'status' => 'error',
    'message' => 'Method not allowed.'
]);
