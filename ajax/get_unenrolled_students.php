<?php
/**
 * ajax/get_unenrolled_students.php
 *
 * Gets students who:
 *   1. Have no subject enrollments for the current school year, OR
 *   2. Have incomplete subject enrollments for the current school year.
 *
 * RULE:
 * ONE STUDENT + ONE SCHEDULE + ONE SCHOOL YEAR = ONE ENROLLMENT
 */

session_start();

require_once '../classes/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

try {

    /* =========================================================
       1. DATABASE
    ========================================================= */

    $db = Database::getInstance();

    /* =========================================================
       2. CURRENT SCHOOL YEAR
    ========================================================= */

    $currentYear = (int) date('Y');
    $schoolYear  = $currentYear . '-' . ($currentYear + 1);

    /* =========================================================
       3. OPTIONAL FILTERS
    ========================================================= */

    $courseId = 0;
    if (isset($_GET['course_id']) && is_numeric($_GET['course_id'])) {
        $courseId = (int) $_GET['course_id'];
    }

    $yearLevel = 0;
    if (isset($_GET['year_level']) && is_numeric($_GET['year_level'])) {
        $yearLevel = (int) $_GET['year_level'];
    }

    $semester = 0;
    if (isset($_GET['semester']) && is_numeric($_GET['semester'])) {
        $semester = (int) $_GET['semester'];
    }

    /* =========================================================
       4. BUILD FILTERS
       ---------------------------------------------------------
       We intentionally do NOT filter on s.enrollment_status
       here, because "enrolled in the program" is different
       from "enrolled in all subjects for the current year".
       The post-processing step at the bottom handles
       "fully enrolled" exclusion properly.
    ========================================================= */

    $where  = [];
    $params = [];

    if ($courseId > 0) {
        $where[]  = "s.course_id = ?";
        $params[] = $courseId;
    }

    if ($yearLevel >= 1 && $yearLevel <= 4) {
        $where[]  = "s.year_level = ?";
        $params[] = $yearLevel;
    }

    $whereSql = empty($where)
        ? "1=1"
        : implode(" AND ", $where);

    /* =========================================================
       5. MAIN QUERY
    ========================================================= */

    $sql = "
        SELECT
            s.student_id,
            s.student_number,
            s.applicant_id,
            s.course_id,
            s.year_level,

            a.first_name,
            a.middle_name,
            a.surname,
            a.suffix,
            a.email,
            a.contact_number,

            sec.section_code,
            sec.id AS section_id,

            c.code AS course_code,
            c.name AS course_name,

            s.enrollment_status AS student_status,

            /* Current school year subject count */
            (
                SELECT COUNT(DISTINCT e.schedule_id)
                FROM enr_enrollments e
                WHERE e.student_id = s.student_id
                  AND e.school_year = ?
                  AND e.enrollment_status = 'enrolled'
                  AND e.schedule_id IS NOT NULL
            ) AS subject_count,

            /* Required subject count from active curriculum */
            (
                SELECT COUNT(DISTINCT rcs.subject_id)
                FROM rgr_curriculum_subjects rcs
                INNER JOIN rgr_curriculums cur
                    ON cur.id = rcs.curriculum_id
                WHERE cur.course_id = s.course_id
                  AND cur.is_active = 1
                  AND rcs.year_level = s.year_level
                  " . (
                      $semester >= 1 && $semester <= 2
                      ? "AND rcs.semester = ?"
                      : ""
                  ) . "
            ) AS total_required_subjects

        FROM enr_students s

        INNER JOIN enr_applicants a
            ON a.applicant_id = s.applicant_id

        LEFT JOIN cc_sections sec
            ON sec.id = s.section_id

        LEFT JOIN rgr_courses c
            ON c.id = s.course_id

        WHERE " . $whereSql . "

        ORDER BY
            a.surname ASC,
            a.first_name ASC

        LIMIT 50
    ";

    /* =========================================================
       6. PREPARE PARAMETERS
    ========================================================= */

    $executeParams = [];

    // 1st ? = school year in subject_count subquery
    $executeParams[] = $schoolYear;

    // Optional semester in required-count subquery
    if ($semester >= 1 && $semester <= 2) {
        $semesterDb = ($semester === 1) ? 'First' : 'Second';
        $executeParams[] = $semesterDb;
    }

    // WHERE params
    foreach ($params as $param) {
        $executeParams[] = $param;
    }

    /* =========================================================
       7. EXECUTE
    ========================================================= */

    $stmt = $db->prepare($sql);
    $stmt->execute($executeParams);

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($students)) {
        $students = [];
    }

    /* =========================================================
       8. PROCESS STUDENTS
    ========================================================= */

    $levels = [
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        4 => '4th Year'
    ];

    foreach ($students as &$student) {

        $subjectCount = isset($student['subject_count'])
            ? (int) $student['subject_count']
            : 0;

        $totalRequired = isset($student['total_required_subjects'])
            ? (int) $student['total_required_subjects']
            : 0;

        $studentYearLevel = isset($student['year_level'])
            ? (int) $student['year_level']
            : 1;

        $student['year_level_text'] =
            $levels[$studentYearLevel] ?? '1st Year';

        // Progress
        if ($totalRequired > 0) {
            $progress = min(
                100,
                round(($subjectCount / $totalRequired) * 100)
            );
        } else {
            $progress = 0;
        }
        $student['enrollment_progress'] = $progress;

        // Remaining
        $student['remaining_subjects'] =
            max(0, $totalRequired - $subjectCount);

        // State
        if ($totalRequired <= 0) {
            $student['enrollment_state'] = 'no_curriculum';
        } elseif ($subjectCount <= 0) {
            $student['enrollment_state'] = 'not_enrolled';
        } elseif ($subjectCount < $totalRequired) {
            $student['enrollment_state'] = 'incomplete';
        } else {
            $student['enrollment_state'] = 'complete';
        }
    }

    unset($student);

    /* =========================================================
       9. REMOVE FULLY ENROLLED STUDENTS
    ========================================================= */

    $students = array_values(
        array_filter(
            $students,
            function ($student) {
                $subjectCount = (int) ($student['subject_count'] ?? 0);
                $required     = (int) ($student['total_required_subjects'] ?? 0);

                // Keep students with no curriculum (can't determine)
                if ($required <= 0) {
                    return true;
                }

                return $subjectCount < $required;
            }
        )
    );

    /* =========================================================
       10. SUMMARY
    ========================================================= */

    $totalStudents    = count($students);
    $notEnrolledCount = 0;
    $incompleteCount  = 0;

    foreach ($students as $student) {
        if (($student['enrollment_state'] ?? '') === 'not_enrolled') {
            $notEnrolledCount++;
        } elseif (($student['enrollment_state'] ?? '') === 'incomplete') {
            $incompleteCount++;
        }
    }

    /* =========================================================
       11. RESPONSE
    ========================================================= */

    echo json_encode([
        'success'     => true,
        'school_year' => $schoolYear,
        'students'    => $students,
        'summary'     => [
            'total_students' => $totalStudents,
            'not_enrolled'   => $notEnrolledCount,
            'incomplete'     => $incompleteCount
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {

    error_log('get_unenrolled_students.php PDO Error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);

} catch (Throwable $e) {

    error_log('get_unenrolled_students.php Error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}