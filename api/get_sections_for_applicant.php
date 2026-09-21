<?php
/**
 * API endpoint to get available sections for an applicant with subject enrollment info
 *
 * RULE: ONE STUDENT + ONE SCHEDULE + ONE SCHOOL YEAR = ONE ENROLLMENT
 *
 * NOTE: enr_applicants has no year_level / semester columns.
 *       We fall back to the section's own data if available, otherwise 1st Year / 1st Semester.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Model.php';
    require_once $basePath . '/classes/Application.php';
    require_once $basePath . '/classes/Section.php';
    require_once $basePath . '/classes/Course.php';
    require_once $basePath . '/classes/Enrollment.php';

    $applicantId = isset($_GET['applicant_id']) ? (int) $_GET['applicant_id'] : 0;
    $studentId   = isset($_GET['student_id'])   ? (int) $_GET['student_id']   : null;

    if ($applicantId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid applicant ID']);
        exit;
    }

    $db          = Database::getInstance();
    $application = new Application();
    $section     = new Section();
    $course      = new Course();
    $enrollment  = new Enrollment();

    $applicant = $application->getApplicationById($applicantId);

    if (!$applicant) {
        echo json_encode(['success' => false, 'message' => 'Applicant not found']);
        exit;
    }

    if (empty($applicant['course_id'])) {
        echo json_encode(['success' => false, 'message' => 'Applicant has no course assigned']);
        exit;
    }

    $courseInfo = $course->findById($applicant['course_id']);

    if (!$courseInfo) {
        echo json_encode(['success' => false, 'message' => 'Course not found']);
        exit;
    }

    // ------------------------------------------------------------
    // Year level + semester
    // enr_applicants has no such columns, so we only use them if
    // they happen to be present (e.g. from a JOIN in the model).
    // ------------------------------------------------------------
    $yearLevel = '1st Year';
    $semester  = '1st Semester';

    if (isset($applicant['year_level']) && $applicant['year_level'] !== '') {
        if (is_numeric($applicant['year_level'])) {
            $levels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
            $yl = (int) $applicant['year_level'];
            $yearLevel = $levels[$yl] ?? '1st Year';
        } else {
            $yearLevel = (string) $applicant['year_level'];
        }
    }

    if (isset($applicant['semester']) && $applicant['semester'] !== '') {
        if (is_numeric($applicant['semester'])) {
            $semester = ((int) $applicant['semester'] === 1) ? '1st Semester' : '2nd Semester';
        } else {
            $semester = (string) $applicant['semester'];
        }
    }

    // ------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------
    $availableSections = $section->getSectionsByCourseAndYearLevel(
        $applicant['course_id'],
        $yearLevel,
        $semester
    );

    if (empty($availableSections)) {
        $alternateSemester = ($semester === '1st Semester') ? '2nd Semester' : '1st Semester';
        $availableSections = $section->getSectionsByCourseAndYearLevel(
            $applicant['course_id'],
            $yearLevel,
            $alternateSemester
        );
        if (!empty($availableSections)) {
            $semester = $alternateSemester;
        }
    }

    // ------------------------------------------------------------
    // School year
    // ------------------------------------------------------------
    $schoolYear = date('Y') . '-' . (date('Y') + 1);

    $syStmt = $db->prepare("SELECT id FROM rgr_school_years WHERE is_active = 1 LIMIT 1");
    $syStmt->execute();
    $syRow        = $syStmt->fetch(PDO::FETCH_ASSOC);
    $schoolYearId = $syRow ? (int) $syRow['id'] : 3;

    $semStmt = $db->prepare("SELECT id FROM rgr_semesters WHERE is_active = 1 LIMIT 1");
    $semStmt->execute();
    $semRow     = $semStmt->fetch(PDO::FETCH_ASSOC);
    $semesterId = $semRow ? (int) $semRow['id'] : 1;

    // ------------------------------------------------------------
    // Curriculum subjects
    // NOTE: rgr_subjects uses `code` and `name`, not subject_code/subject_name.
    // ------------------------------------------------------------
    $semesterDb = ($semester === '1st Semester') ? 'First' : 'Second';

    $levelMap = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
    $levelNum = $levelMap[$yearLevel] ?? 1;

    $subjectsSql = "
        SELECT
            rs.id,
            rs.code AS subject_code,
            rs.name AS subject_name,
            rs.units,
            rs.lecture_hours,
            rs.lab_hours,
            rcs.year_level,
            rcs.semester,
            rcs.id AS curriculum_subject_id
        FROM rgr_curriculum_subjects rcs
        INNER JOIN rgr_subjects rs
            ON rcs.subject_id = rs.id
        INNER JOIN rgr_curriculums cur
            ON rcs.curriculum_id = cur.id
        WHERE cur.course_id = ?
          AND cur.is_active = 1
          AND rcs.year_level = ?
          AND rcs.semester = ?
        ORDER BY rs.code
    ";

    $subjectsStmt = $db->prepare($subjectsSql);
    $subjectsStmt->execute([$applicant['course_id'], $levelNum, $semesterDb]);
    $curriculumSubjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($curriculumSubjects)) {
        $curriculumSubjects = [];
    }

    // ------------------------------------------------------------
    // Existing enrollments for student (via schedule_id)
    // ------------------------------------------------------------
    $enrolledScheduleIds = [];

    if ($studentId) {
        $enrollSql = "
            SELECT schedule_id
            FROM enr_enrollments
            WHERE student_id = ?
              AND school_year = ?
              AND enrollment_status = 'enrolled'
              AND schedule_id IS NOT NULL
        ";
        $enrollStmt = $db->prepare($enrollSql);
        $enrollStmt->execute([$studentId, $schoolYear]);
        $rows = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);
        $enrolledScheduleIds = array_map('intval', array_column($rows, 'schedule_id'));
    }

    // ------------------------------------------------------------
    // Format sections
    // ------------------------------------------------------------
    $formattedSections = array_map(function ($sec) use (
        $curriculumSubjects,
        $enrolledScheduleIds,
        $db,
        $schoolYear,
        $schoolYearId,
        $semesterId
    ) {
        $sectionId = (int) $sec['id'];

        // Current student count
        $countStmt = $db->prepare("
            SELECT COUNT(DISTINCT student_id) AS count
            FROM enr_enrollments
            WHERE section_id = ?
              AND enrollment_status = 'enrolled'
        ");
        $countStmt->execute([$sectionId]);
        $countRow     = $countStmt->fetch(PDO::FETCH_ASSOC);
        $currentCount = (int) ($countRow['count'] ?? 0);
        $maxStudents  = 40;

        // Schedule map for this section
        $scheduleStmt = $db->prepare("
            SELECT cs.id AS schedule_id, cs.subject_id
            FROM cc_schedule cs
            INNER JOIN cc_faculty_load fl
                ON cs.faculty_load_id = fl.id
            WHERE cs.section_id = ?
              AND cs.school_year_id = ?
              AND cs.semester_id = ?
        ");
        $scheduleStmt->execute([$sectionId, $schoolYearId, $semesterId]);
        $sectionSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

        $scheduleMap = [];
        foreach ($sectionSchedules as $sched) {
            $scheduleMap[(int) $sched['subject_id']] = (int) $sched['schedule_id'];
        }

        $subjects = array_map(function ($subject) use ($enrolledScheduleIds, $scheduleMap) {
            $subjectId  = (int) $subject['id'];
            $scheduleId = $scheduleMap[$subjectId] ?? null;
            $isEnrolled = $scheduleId !== null && in_array($scheduleId, $enrolledScheduleIds, true);

            return [
                'subject_id'        => $subjectId,
                'subject_code'      => $subject['subject_code'],
                'subject_name'      => $subject['subject_name'],
                'units'             => (int) $subject['units'],
                'schedule_id'       => $scheduleId,
                'is_enrolled'       => $isEnrolled,
                'enrollment_status' => $isEnrolled ? 'enrolled' : 'pending'
            ];
        }, $curriculumSubjects);

        $enrolledCount = 0;
        foreach ($subjects as $s) {
            if ($s['is_enrolled']) {
                $enrolledCount++;
            }
        }

        return [
            'section_id'        => $sectionId,
            'section_code'      => $sec['section_code'],
            'grade_level'       => $sec['grade_level'],
            'semester'          => $sec['semester'],
            'school_year'       => $sec['school_year'] ?? $schoolYear,
            'max_students'      => $maxStudents,
            'current_students'  => $currentCount,
            'available_slots'   => max(0, $maxStudents - $currentCount),
            'course_code'       => $sec['course_code'] ?? '',
            'course_name'       => $sec['course_name'] ?? '',
            'subjects'          => $subjects,
            'total_subjects'    => count($subjects),
            'enrolled_subjects' => $enrolledCount
        ];
    }, array_values($availableSections));

    $totalSections = count($formattedSections);
    $totalSubjects = 0;
    foreach ($formattedSections as $fs) {
        $totalSubjects += (int) $fs['total_subjects'];
    }

    echo json_encode([
        'success'        => true,
        'sections'       => $formattedSections,
        'course_id'      => (int) $applicant['course_id'],
        'course_code'    => $courseInfo['code'],
        'course_name'    => $courseInfo['name'],
        'year_level'     => $yearLevel,
        'semester'       => $semester,
        'school_year'    => $schoolYear,
        'applicant_name' => trim(
            $applicant['first_name']
            . ' ' . ($applicant['middle_name'] ?? '')
            . ' ' . $applicant['surname']
        ),
        'total_sections' => $totalSections,
        'total_subjects' => $totalSubjects,
        'has_student_id' => !is_null($studentId),
        'message'        => $totalSections > 0 ? 'Sections found' : 'No available sections'
    ]);

} catch (PDOException $e) {
    error_log('get_sections_for_applicant PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('get_sections_for_applicant Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}