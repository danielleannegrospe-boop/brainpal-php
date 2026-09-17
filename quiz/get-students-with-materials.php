<?php
require_once __DIR__ . '/../_shared/db.php';

error_reporting(E_ALL);
ini_set("display_errors", 0);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(200);
    exit();

}


/* =========================================================
   DATABASE
========================================================= */

$conn = brainpal_db();


if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed.",
        "students" => []
    ]);

    exit();

}


$conn->set_charset("utf8mb4");


/* =========================================================
   GET STUDENTS THAT HAVE LEARNING MATERIALS
========================================================= */

$sql = "

    SELECT DISTINCT

        st.student_id,

        st.studentNo,

        st.firstName,

        st.m_initial,

        st.lastName,

        st.extension,

        st.email,

        st.strand_id,

        sd.strand_name,

        st.specialization_id,

        st.academic_id,

        st.grade_level,

        st.profile_photo,

        ss.schedule_id,

        lm.material_id,

        lm.subject_id,

        lm.title AS material_title,

        lm.type AS material_type,

        lm.description AS material_description,

        lm.link AS material_link,

        lm.date_created AS material_date_created,

        s.subject_name

    FROM student st

    INNER JOIN study_schedule ss

        ON ss.student_id = st.student_id

        AND ss.date_deleted IS NULL

    INNER JOIN learning_material lm

        ON lm.schedule_id = ss.schedule_id

        AND lm.date_deleted IS NULL

    LEFT JOIN subject s

        ON s.subject_id = lm.subject_id

    LEFT JOIN strand sd

        ON sd.strand_id = st.strand_id

        AND sd.date_deleted IS NULL

    WHERE

        st.date_deleted IS NULL

    ORDER BY

        st.lastName ASC,

        st.firstName ASC,

        lm.date_created DESC

";


$result = $conn->query($sql);


if (!$result) {

    http_response_code(500);

    echo json_encode([

        "success" => false,

        "message" =>
            "Failed to retrieve students with learning materials.",

        "error" =>
            $conn->error,

        "students" => []

    ]);

    $conn->close();

    exit();

}


/* =========================================================
   GROUP BY STUDENT
========================================================= */

$students = [];


while ($row = $result->fetch_assoc()) {

    $studentId =
        intval($row["student_id"]);


    if (!isset($students[$studentId])) {

        $students[$studentId] = [

            "student_id" =>
                $studentId,

            "studentNo" =>
                $row["studentNo"] ?? "",

            "firstName" =>
                $row["firstName"] ?? "",

            "m_initial" =>
                $row["m_initial"] ?? "",

            "lastName" =>
                $row["lastName"] ?? "",

            "extension" =>
                $row["extension"] ?? "",

            "email" =>
                $row["email"] ?? "",

            "strand_id" =>
                $row["strand_id"] !== null
                    ? intval($row["strand_id"])
                    : null,

            "strand_name" =>
                $row["strand_name"] ?? "No Strand",

            "strand" =>
                $row["strand_name"] ?? "No Strand",

            "specialization_id" =>
                $row["specialization_id"] !== null
                    ? intval($row["specialization_id"])
                    : null,

            "academic_id" =>
                $row["academic_id"] !== null
                    ? intval($row["academic_id"])
                    : null,

            "grade_level" =>
                $row["grade_level"] ?? null,

            "profile_photo" =>
                $row["profile_photo"] ?? null,

            "learning_material_count" =>
                0,

            "materials" =>
                []

        ];

    }


    /* =====================================================
       ADD MATERIAL
    ===================================================== */

    $students[$studentId]["materials"][] = [

        "material_id" =>
            intval($row["material_id"]),

        "schedule_id" =>
            intval($row["schedule_id"]),

        "subject_id" =>
            intval($row["subject_id"]),

        "title" =>
            $row["material_title"] ?? "",

        "type" =>
            $row["material_type"] ?? "",

        "description" =>
            $row["material_description"] ?? "",

        "link" =>
            $row["material_link"] ?? "",

        "date_created" =>
            $row["material_date_created"] ?? null,

        "subject_name" =>
            $row["subject_name"] ?? ""

    ];


    $students[$studentId]["learning_material_count"]++;

}


/* =========================================================
   RESET ARRAY INDEXES
========================================================= */

$students =
    array_values($students);


/* =========================================================
   RESPONSE
========================================================= */

echo json_encode([

    "success" => true,

    "message" =>
        count($students) > 0
            ? "Students with learning materials loaded successfully."
            : "No students with learning materials found.",

    "count" =>
        count($students),

    "students" =>
        $students

], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


$result->free();

$conn->close();

exit();

?>