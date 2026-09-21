<?php
/**
 * ajax/get_applicant.php
 *
 * RULE:
 * ONE STUDENT + ONE SCHEDULE + ONE SCHOOL YEAR = ONE ENROLLMENT
 *
 * Each subject enrollment is stored as a separate row in:
 * enr_enrollments with schedule_id
 */

session_start();

require_once '../classes/Application.php';
require_once '../classes/Section.php';
require_once '../classes/Database.php';
require_once '../classes/Enrollment.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

try {

    /* =========================================================
       1. CHECK APPLICANT / APPLICATION ID
    ========================================================= */

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Applicant/Application ID required'
        ]);
        exit;
    }

    $applicationId = (int) $_GET['id'];

    /* =========================================================
       2. INITIALIZE
    ========================================================= */

    $application = new Application();
    $section     = new Section();
    $enrollment  = new Enrollment();
    $db          = Database::getInstance();

    /* =========================================================
       3. GET APPLICATION
    ========================================================= */

    $data = $application->getApplicationById($applicationId);

    if (!$data) {
        echo json_encode([
            'success' => false,
            'message' => 'Application not found'
        ]);
        exit;
    }

    /* =========================================================
       4. YEAR LEVEL
       enr_applicants has no year_level column.
       Prefer enr_students.year_level if present, else default 1.
    ========================================================= */

    $yearLevel = isset($data['year_level'])
        ? (int) $data['year_level']
        : 1;

    if ($yearLevel < 1 || $yearLevel > 4) {
        $yearLevel = 1;
    }

    $levels = [
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        4 => '4th Year'
    ];

    $yearLevelText = $levels[$yearLevel];

    /* =========================================================
       5. SEMESTER
    ========================================================= */

    $semester = isset($data['semester'])
        ? (int) $data['semester']
        : 1;

    if ($semester !== 1 && $semester !== 2) {
        $semester = 1;
    }

    $semesterText = ($semester === 1)
        ? '1st Semester'
        : '2nd Semester';

    $semesterDb = ($semester === 1)
        ? 'First'
        : 'Second';

    /* =========================================================
       6. COURSE ID
    ========================================================= */

    $courseId = isset($data['course_id'])
        ? (int) $data['course_id']
        : 0;

    /* =========================================================
       7. GET AVAILABLE SECTIONS
    ========================================================= */

    $sections = [];

    if ($courseId > 0) {

        $sections = $section->getSectionsByCourseAndYearLevel(
            $courseId,
            $yearLevelText,
            $semesterText
        );

        if (!is_array($sections)) {
            $sections = [];
        }
    }

    /* =========================================================
       8. GET CURRICULUM SUBJECTS WITH SCHEDULES
       ---------------------------------------------------------
       NOTE: rgr_subjects uses `code` and `name`, NOT
             subject_code / subject_name.
    ========================================================= */

    $subjects = [];

    if ($courseId > 0) {

        $sql = "
            SELECT
                rs.id AS subject_id,
                rs.code AS subject_code,
                rs.name AS subject_name,
                rs.units,

                rcs.id AS curriculum_subject_id,
                rcs.year_level,
                rcs.semester,

                cur.id AS curriculum_id,
                cur.course_id,

                cs.id AS schedule_id,
                cs.start_time,
                cs.end_time,
                cs.day_of_week,
                cs.room_id,
                cs.faculty_id,
                cs.section_id AS schedule_section_id,

                r.room_code,
                r.room_name,

                f.first_name AS faculty_first_name,
                f.last_name AS faculty_last_name

            FROM rgr_curriculum_subjects rcs

            INNER JOIN rgr_subjects rs
                ON rs.id = rcs.subject_id

            INNER JOIN rgr_curriculums cur
                ON cur.id = rcs.curriculum_id

            INNER JOIN cc_faculty_load fl
                ON fl.subject_id = rs.id
                AND fl.section_id = ?
                AND fl.school_year_id = ?
                AND fl.semester_id = ?

            INNER JOIN cc_schedule cs
                ON cs.faculty_load_id = fl.id
                AND cs.section_id = ?
                AND cs.school_year_id = ?
                AND cs.semester_id = ?

            LEFT JOIN cc_room r
                ON r.id = cs.room_id

            LEFT JOIN cc_faculty f
                ON f.id = cs.faculty_id

            WHERE cur.course_id = ?
              AND cur.is_active = 1
              AND rcs.year_level = ?
              AND rcs.semester = ?

            GROUP BY rs.id, cs.id

            ORDER BY rs.code ASC
        ";

        // Active school year
        $syStmt = $db->prepare(
            "SELECT id FROM rgr_school_years WHERE is_active = 1 LIMIT 1"
        );
        $syStmt->execute();
        $schoolYearRow = $syStmt->fetch(PDO::FETCH_ASSOC);
        $schoolYearId = $schoolYearRow ? $schoolYearRow['id'] : 3;

        // Active semester
        $semStmt = $db->prepare(
            "SELECT id FROM rgr_semesters WHERE is_active = 1 LIMIT 1"
        );
        $semStmt->execute();
        $semesterRow = $semStmt->fetch(PDO::FETCH_ASSOC);
        $semesterId = $semesterRow ? $semesterRow['id'] : 1;

        // Section ID for the student
        $sectionId = isset($data['section_id'])
            ? (int) $data['section_id']
            : 1;

        $stmt = $db->prepare($sql);

        $stmt->execute([
            $sectionId,      // fl.section_id
            $schoolYearId,   // fl.school_year_id
            $semesterId,     // fl.semester_id
            $sectionId,      // cs.section_id
            $schoolYearId,   // cs.school_year_id
            $semesterId,     // cs.semester_id
            $courseId,       // cur.course_id
            $yearLevel,      // rcs.year_level
            $semesterDb      // rcs.semester
        ]);

        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($subjects)) {
            $subjects = [];
        }
    }

    /* =========================================================
       9. SCHOOL YEAR (string form, matches enr_enrollments.school_year)
    ========================================================= */

    $currentYear = (int) date('Y');

    $schoolYear = $currentYear . '-' . ($currentYear + 1);

    /* =========================================================
       10. STUDENT ID
    ========================================================= */

    $studentId = 0;

    if (
        isset($_GET['student_id']) &&
        is_numeric($_GET['student_id'])
    ) {
        $studentId = (int) $_GET['student_id'];
    }

    if ($studentId <= 0 && isset($data['student_id'])) {
        $studentId = (int) $data['student_id'];
    }

    /* =========================================================
       11. GET EXISTING ENROLLMENTS
       ---------------------------------------------------------
       NOTE: rgr_subjects uses `code` and `name`.
    ========================================================= */

    $existingEnrollments  = [];
    $enrolledScheduleIds  = [];

    if ($studentId > 0) {

        $sql = "
            SELECT
                e.enrollment_id,
                e.student_id,
                e.section_id,
                e.school_year,
                e.semester,
                e.enrollment_date,
                e.enrollment_status,
                e.academic_standing,
                e.schedule_id,
                e.created_at,

                rs.code AS subject_code,
                rs.name AS subject_name,
                rs.units,

                cs.start_time,
                cs.end_time,
                cs.day_of_week

            FROM enr_enrollments e

            INNER JOIN cc_schedule cs
                ON cs.id = e.schedule_id

            INNER JOIN rgr_subjects rs
                ON rs.id = cs.subject_id

            WHERE e.student_id = ?
              AND e.school_year = ?
              AND e.enrollment_status = 'enrolled'

            ORDER BY rs.code ASC
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            $studentId,
            $schoolYear
        ]);

        $existingEnrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($existingEnrollments)) {
            $existingEnrollments = [];
        }

        foreach ($existingEnrollments as $row) {
            if (
                isset($row['schedule_id']) &&
                $row['schedule_id'] !== null
            ) {
                $enrolledScheduleIds[] = (int) $row['schedule_id'];
            }
        }

        $enrolledScheduleIds = array_values(
            array_unique($enrolledScheduleIds)
        );
    }

    /* =========================================================
       12. MARK SUBJECTS
    ========================================================= */

    foreach ($subjects as &$subject) {

        $scheduleId = isset($subject['schedule_id'])
            ? (int) $subject['schedule_id']
            : 0;

        if (
            $studentId > 0 &&
            in_array($scheduleId, $enrolledScheduleIds, true)
        ) {
            $subject['is_enrolled']       = true;
            $subject['enrollment_status'] = 'enrolled';
            $subject['enrollment_id']     = null;

            foreach ($existingEnrollments as $enrollment) {
                if ((int)$enrollment['schedule_id'] === $scheduleId) {
                    $subject['enrollment_id'] = $enrollment['enrollment_id'];
                    break;
                }
            }
        } else {
            $subject['is_enrolled']       = false;
            $subject['enrollment_status'] = 'pending';
            $subject['enrollment_id']     = null;
        }
    }

    unset($subject);

    /* =========================================================
       13. SUMMARY
    ========================================================= */

    $totalSubjects    = count($subjects);
    $enrolledSubjects = 0;
    $pendingSubjects  = 0;

    foreach ($subjects as $subject) {
        if (
            isset($subject['is_enrolled']) &&
            $subject['is_enrolled'] === true
        ) {
            $enrolledSubjects++;
        } else {
            $pendingSubjects++;
        }
    }

    /* =========================================================
       14. RESPONSE
    ========================================================= */

    echo json_encode([
        'success' => true,
        'data' => $data,
        'sections' => $sections,
        'subjects' => $subjects,
        'existing_enrollments' => $existingEnrollments,
        'year_level' => $yearLevel,
        'year_level_text' => $yearLevelText,
        'semester' => $semester,
        'semester_text' => $semesterText,
        'school_year' => $schoolYear,
        'student_id' => $studentId,
        'summary' => [
            'total_subjects'    => $totalSubjects,
            'enrolled_subjects' => $enrolledSubjects,
            'pending_subjects'  => $pendingSubjects
        ]
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {

    error_log('get_applicant.php PDO Error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);

} catch (Throwable $e) {

    error_log('get_applicant.php Error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}