<?php
/**
 * api/get_subjects_for_section.php
 * Gets subjects with schedules for a specific section
 *
 * FIXES IN THIS VERSION:
 *   • Catch Throwable (PHP 8 type errors)
 *   • Normalize semester to 'First'/'Second' AND '1st Semester'/'2nd Semester'
 *     before passing to Section::getSectionSubjectsWithSchedules()
 *   • Defensive student lookup (array shape checked)
 *   • Dedup has_schedule / enrolled counts by subject_id
 *   • Echo student_id in response for frontend verification
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $basePath = dirname(__DIR__);

    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Section.php';
    require_once $basePath . '/classes/Application.php';
    require_once $basePath . '/classes/Enrollment.php';
    require_once $basePath . '/classes/Student.php';

    if (!isset($_GET['section_id']) || empty($_GET['section_id'])) {
        echo json_encode(['success' => false, 'message' => 'Section ID is required']);
        exit;
    }

    $sectionId   = (int) $_GET['section_id'];
    $applicantId = isset($_GET['applicant_id']) ? (int) $_GET['applicant_id'] : 0;
    $studentId   = isset($_GET['student_id'])   ? (int) $_GET['student_id']   : 0;

    if ($sectionId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid section ID']);
        exit;
    }

    $db      = Database::getInstance();
    $section = new Section();

    $sectionData = $section->getSectionDetails($sectionId);
    if (!$sectionData) {
        echo json_encode(['success' => false, 'message' => 'Section not found']);
        exit;
    }

    $courseId  = $sectionData['program_id'];
    $yearLevel = $sectionData['grade_level'];
    $semester  = $sectionData['semester'] ?? '';

    /* ------------------------------------------------------------
       Normalize semester — accept int (1/2), text ('First'/'Second'),
       or full text ('1st Semester'/'2nd Semester').
       Section::getSectionSubjectsWithSchedules() may expect any of
       these depending on implementation, so we pass BOTH formats
       when it supports a flexible signature.
    ------------------------------------------------------------ */
    $semesterRaw = strtolower(trim((string) $semester));

    if (
        strpos($semesterRaw, 'second') !== false ||
        strpos($semesterRaw, '2nd')    !== false ||
        $semesterRaw === '2'
    ) {
        $semesterText = 'Second';
        $semesterNum  = 2;
    } else {
        $semesterText = 'First';
        $semesterNum  = 1;
    }

    /* ------------------------------------------------------------
       If applicant_id was given but student_id wasn't, resolve it.
    ------------------------------------------------------------ */
    if ($applicantId > 0 && $studentId === 0) {
        $student     = new Student();
        $studentData = $student->getStudentByApplicantId($applicantId);

        if (
            is_array($studentData) &&
            !empty($studentData['student_id'])
        ) {
            $studentId = (int) $studentData['student_id'];
        }
    }

    /* ------------------------------------------------------------
       Fetch subjects with schedules.
       The section method historically expects semester text
       ('First'/'Second'). We pass that; the numeric form is
       included in the response for frontends that need it.
    ------------------------------------------------------------ */
    $subjects = $section->getSectionSubjectsWithSchedules(
        $sectionId,
        $courseId,
        $yearLevel,
        $semesterText,
        $studentId
    );

    if (!is_array($subjects)) {
        $subjects = [];
    }

    /* ------------------------------------------------------------
       Dedup counters by subject_id (in case the same subject
       appears with multiple schedule rows).
    ------------------------------------------------------------ */
    $hasScheduleCount = 0;
    $enrolledCount    = 0;
    $seenHasSchedule  = [];
    $seenEnrolled     = [];

    foreach ($subjects as $s) {
        $sid = isset($s['subject_id']) ? (int) $s['subject_id'] : 0;

        if (!empty($s['has_schedule']) && $sid > 0 && !isset($seenHasSchedule[$sid])) {
            $hasScheduleCount++;
            $seenHasSchedule[$sid] = true;
        }

        if (!empty($s['is_enrolled']) && $sid > 0 && !isset($seenEnrolled[$sid])) {
            $enrolledCount++;
            $seenEnrolled[$sid] = true;
        }
    }

    /* ------------------------------------------------------------
       Section info
    ------------------------------------------------------------ */
    $sectionInfo = [
        'section_id'    => $sectionData['id'],
        'section_code'  => $sectionData['section_code'] ?? '',
        'grade_level'   => $sectionData['grade_level']  ?? '',
        'semester'      => $sectionData['semester']     ?? $semesterText,
        'semester_num'  => $semesterNum,
        'school_year'   => $sectionData['school_year']  ?? null,
        'course_id'     => $sectionData['program_id'],
        'course_code'   => $sectionData['course_code']  ?? '',
        'course_name'   => $sectionData['course_name']  ?? ''
    ];

    /* ------------------------------------------------------------
       Response
    ------------------------------------------------------------ */
    echo json_encode([
        'success'            => true,
        'data'               => array_values($subjects),
        'section'            => $sectionInfo,
        'count'              => count($subjects),
        'has_schedule_count' => $hasScheduleCount,
        'enrolled_count'     => $enrolledCount,
        'student_id'         => $studentId > 0 ? $studentId : null,
        'applicant_id'       => $applicantId > 0 ? $applicantId : null,
        'message'            => count($subjects) > 0
            ? 'Subjects retrieved successfully'
            : 'No subjects found for this section'
    ]);

} catch (PDOException $e) {
    error_log('get_subjects_for_section.php PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('get_subjects_for_section.php Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}