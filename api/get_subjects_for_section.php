<?php
/**
 * api/get_subjects_for_section.php
 * Gets subjects with schedules for a specific section
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

    $db      = Database::getInstance();
    $section = new Section();

    $sectionData = $section->getSectionDetails($sectionId);
    if (!$sectionData) {
        echo json_encode(['success' => false, 'message' => 'Section not found']);
        exit;
    }

    $courseId  = $sectionData['program_id'];
    $yearLevel = $sectionData['grade_level'];
    $semester  = $sectionData['semester'];

    if ($applicantId > 0 && $studentId === 0) {
        $student = new Student();
        $studentData = $student->getStudentByApplicantId($applicantId);
        if ($studentData) {
            $studentId = (int) $studentData['student_id'];
        }
    }

    $subjects = $section->getSectionSubjectsWithSchedules(
        $sectionId,
        $courseId,
        $yearLevel,
        $semester,
        $studentId
    );

    if (!is_array($subjects)) {
        $subjects = [];
    }

    $sectionInfo = [
        'section_id'   => $sectionData['id'],
        'section_code' => $sectionData['section_code'],
        'grade_level'  => $sectionData['grade_level'],
        'semester'     => $sectionData['semester'],
        'school_year'  => $sectionData['school_year'] ?? null,
        'course_id'    => $sectionData['program_id'],
        'course_code'  => $sectionData['course_code'] ?? '',
        'course_name'  => $sectionData['course_name'] ?? ''
    ];

    $hasScheduleCount = 0;
    $enrolledCount    = 0;
    foreach ($subjects as $s) {
        if (!empty($s['has_schedule'])) $hasScheduleCount++;
        if (!empty($s['is_enrolled']))  $enrolledCount++;
    }

    echo json_encode([
        'success'            => true,
        'data'               => $subjects,
        'section'            => $sectionInfo,
        'count'              => count($subjects),
        'has_schedule_count' => $hasScheduleCount,
        'enrolled_count'     => $enrolledCount,
        'message'            => count($subjects) > 0
            ? 'Subjects retrieved successfully'
            : 'No subjects found for this section'
    ]);

} catch (PDOException $e) {
    error_log('get_subjects_for_section.php PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('get_subjects_for_section.php Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}