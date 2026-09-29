<?php
/**
 * api/search_enrolled_students.php
 * Path: C:\xampp\htdocs\enrollment\api\search_enrolled_students.php
 *
 * AJAX endpoint para sa paghahanap ng enrolled students.
 *
 * URL: /enrollment/api/search_enrolled_students.php?search=Yazier
 *      /enrollment/api/search_enrolled_students.php?search=BSIS&course_id=1&year_level=1
 *
 * Returns JSON:
 *   { success, count, total, data[], message }
 */

// ============================================================
// HEADERS
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================================
// BOOTSTRAP — Load dependencies
// ============================================================
// File is at api/ folder — parent is the project root
$basePath = dirname(__DIR__);

// Verify class files exist
$requiredFiles = [
    $basePath . '/classes/Database.php',
    $basePath . '/classes/Enrollment.php',
    $basePath . '/classes/StudentProgression.php',
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required file: ' . basename($file),
            'path'    => $file
        ]);
        exit;
    }
}

try {
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Enrollment.php';
    require_once $basePath . '/classes/StudentProgression.php';

    $db = Database::getInstance();
} catch (Throwable $e) {
    error_log('search_enrolled_students bootstrap: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server initialization failed.',
        'error'   => $e->getMessage()
    ]);
    exit;
}

// ============================================================
// READ PARAMETERS
// ============================================================
$search    = trim($_GET['search']      ?? '');
$courseId  = isset($_GET['course_id'])  ? (int) $_GET['course_id']  : 0;
$yearLevel = isset($_GET['year_level']) ? (int) $_GET['year_level'] : 0;
$sectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;
$status    = trim($_GET['status']      ?? '');
$limit     = isset($_GET['limit'])      ? (int) $_GET['limit']      : 100;
$offset    = isset($_GET['offset'])     ? (int) $_GET['offset']     : 0;

// Sanity limits
if ($limit  <= 0)   $limit  = 100;
if ($limit  > 500)  $limit  = 500;
if ($offset < 0)    $offset = 0;

// ============================================================
// BUILD QUERY
// ============================================================
try {
    // ------------------------------------------------------------
    // Main query
    // ------------------------------------------------------------
    $sql = "SELECT
                s.student_id,
                s.student_number,
                s.year_level,
                s.section_id,
                s.enrollment_status AS student_enrollment_status,
                s.enrolled_at,

                a.first_name,
                a.middle_name,
                a.surname,
                a.suffix,
                a.email,
                a.contact_number,
                a.admission_type,

                c.id   AS course_id,
                c.code AS course_code,
                c.name AS course_name,

                sec.section_code,
                sec.grade_level,

                sem.name AS section_semester,
                sy.name  AS school_year,

                (
                    SELECT COUNT(DISTINCT e2.schedule_id)
                    FROM enr_enrollments e2
                    WHERE e2.student_id = s.student_id
                      AND e2.enrollment_status = 'enrolled'
                ) AS subject_count
            FROM enr_students s
            INNER JOIN enr_applicants a  ON s.applicant_id = a.applicant_id
            LEFT  JOIN cc_sections    sec ON s.section_id  = sec.id
            LEFT  JOIN rgr_courses    c   ON s.course_id   = c.id
            LEFT  JOIN rgr_semesters  sem ON sec.semester_id = sem.id
            LEFT  JOIN rgr_school_years sy ON sec.school_year_id = sy.id
            WHERE s.archived_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM enr_enrollments e
                  WHERE e.student_id = s.student_id
                    AND e.enrollment_status = 'enrolled'
              )";

    $params = [];

    // Filter by course
    if ($courseId > 0) {
        $sql .= " AND s.course_id = ?";
        $params[] = $courseId;
    }

    // Filter by year level
    if ($yearLevel > 0) {
        $sql .= " AND s.year_level = ?";
        $params[] = $yearLevel;
    }

    // Filter by section
    if ($sectionId > 0) {
        $sql .= " AND s.section_id = ?";
        $params[] = $sectionId;
    }

    // Filter by status
    if ($status !== '' && in_array($status, ['enrolled', 'dropped', 'completed', 'on_leave', 'graduated'], true)) {
        $sql .= " AND s.enrollment_status = ?";
        $params[] = $status;
    }

    // Search term — matches name, student number, section, course, email, contact
    $term = '%' . $search . '%';

    if ($search !== '') {
        $sql .= " AND (
                    CONCAT_WS(' ', a.first_name, a.middle_name, a.surname, a.suffix) LIKE ?
                    OR s.student_number LIKE ?
                    OR sec.section_code LIKE ?
                    OR c.code LIKE ?
                    OR c.name LIKE ?
                    OR a.email LIKE ?
                    OR a.contact_number LIKE ?
                  )";
        $params[] = $term;  // name
        $params[] = $term;  // student_number
        $params[] = $term;  // section_code
        $params[] = $term;  // course_code
        $params[] = $term;  // course_name
        $params[] = $term;  // email
        $params[] = $term;  // contact_number
    }

    $sql .= " GROUP BY s.student_id
              ORDER BY a.surname ASC, a.first_name ASC
              LIMIT {$limit} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($rows)) $rows = [];

    // ------------------------------------------------------------
    // Count total (for pagination)
    // ------------------------------------------------------------
    $countSql = "SELECT COUNT(DISTINCT s.student_id) AS total
                 FROM enr_students s
                 INNER JOIN enr_applicants a  ON s.applicant_id = a.applicant_id
                 LEFT  JOIN cc_sections    sec ON s.section_id  = sec.id
                 LEFT  JOIN rgr_courses    c   ON s.course_id   = c.id
                 WHERE s.archived_at IS NULL
                   AND EXISTS (
                       SELECT 1 FROM enr_enrollments e
                       WHERE e.student_id = s.student_id
                         AND e.enrollment_status = 'enrolled'
                   )";

    $countParams = [];

    if ($courseId > 0) {
        $countSql .= " AND s.course_id = ?";
        $countParams[] = $courseId;
    }
    if ($yearLevel > 0) {
        $countSql .= " AND s.year_level = ?";
        $countParams[] = $yearLevel;
    }
    if ($sectionId > 0) {
        $countSql .= " AND s.section_id = ?";
        $countParams[] = $sectionId;
    }
    if ($status !== '' && in_array($status, ['enrolled', 'dropped', 'completed', 'on_leave', 'graduated'], true)) {
        $countSql .= " AND s.enrollment_status = ?";
        $countParams[] = $status;
    }
    if ($search !== '') {
        $countSql .= " AND (
                        CONCAT_WS(' ', a.first_name, a.middle_name, a.surname, a.suffix) LIKE ?
                        OR s.student_number LIKE ?
                        OR sec.section_code LIKE ?
                        OR c.code LIKE ?
                        OR c.name LIKE ?
                        OR a.email LIKE ?
                        OR a.contact_number LIKE ?
                      )";
        $countParams[] = $term;
        $countParams[] = $term;
        $countParams[] = $term;
        $countParams[] = $term;
        $countParams[] = $term;
        $countParams[] = $term;
        $countParams[] = $term;
    }

    $countStmt = $db->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    // ------------------------------------------------------------
    // Format response
    // ------------------------------------------------------------
    $formatted = [];
    foreach ($rows as $row) {
        $fullName = trim(
            ($row['first_name']  ?? '') . ' ' .
            ($row['middle_name'] ?? '') . ' ' .
            ($row['surname']     ?? '') . ' ' .
            ($row['suffix']      ?? '')
        );

        $formatted[] = [
            'student_id'       => (int) ($row['student_id']       ?? 0),
            'student_number'   => $row['student_number']           ?? '',
            'full_name'        => $fullName,
            'first_name'       => $row['first_name']               ?? '',
            'middle_name'      => $row['middle_name']              ?? '',
            'surname'          => $row['surname']                  ?? '',
            'suffix'           => $row['suffix']                   ?? '',
            'email'            => $row['email']                    ?? '',
            'contact_number'   => $row['contact_number']           ?? '',
            'admission_type'   => $row['admission_type']           ?? '',

            'course_id'        => (int) ($row['course_id']         ?? 0),
            'course_code'      => $row['course_code']              ?? '',
            'course_name'      => $row['course_name']              ?? '',

            'section_id'       => (int) ($row['section_id']        ?? 0),
            'section_code'     => $row['section_code']             ?? '',
            'grade_level'      => $row['grade_level']              ?? '',
            'section_semester' => $row['section_semester']         ?? '',
            'school_year'      => $row['school_year']              ?? '',

            'year_level'       => (int) ($row['year_level']        ?? 1),
            'subject_count'    => (int) ($row['subject_count']     ?? 0),

            'enrollment_status'=> $row['student_enrollment_status'] ?? 'enrolled',
            'enrolled_at'      => $row['enrolled_at']              ?? null,
        ];
    }

    // ------------------------------------------------------------
    // Success response
    // ------------------------------------------------------------
    echo json_encode([
        'success'  => true,
        'count'    => count($formatted),
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
        'search'   => $search,
        'filters'  => [
            'course_id'  => $courseId,
            'year_level' => $yearLevel,
            'section_id' => $sectionId,
            'status'     => $status,
        ],
        'data'     => $formatted,
        'message'  => 'Search completed successfully'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $e) {
    error_log('search_enrolled_students PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('search_enrolled_students Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}