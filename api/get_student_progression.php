<?php
/**
 * api/get_student_progression.php
 * Gets student's current and next progression.
 *
 * UPDATED: now includes can_progress flag from StudentProgression::canProgress()
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Student.php';
    require_once $basePath . '/classes/StudentProgression.php';
    require_once $basePath . '/classes/Enrollment.php';

    if (!isset($_GET['student_id']) || empty($_GET['student_id'])) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        exit;
    }

    $studentId = (int) $_GET['student_id'];
    $db        = Database::getInstance();

    $studentModel = new Student();
    $student      = $studentModel->findById($studentId);

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $progression = new StudentProgression();
    $enrollment  = new Enrollment();

    $current = $enrollment->getStudentCurrentProgression($studentId);
    $next    = $enrollment->getStudentNextProgression($studentId);

    $currentYear    = date('Y');
    $schoolYear     = $currentYear . '-' . ($currentYear + 1);
    $nextSchoolYear = ($currentYear + 1) . '-' . ($currentYear + 2);

    $currentYearLevel = $current['year_level'] ?? 1;
    $nextYearLevel    = $next['year_level']    ?? 1;
    $academicYearChanges = ($currentYearLevel != $nextYearLevel && $nextYearLevel > $currentYearLevel);

    require_once $basePath . '/classes/SubjectStatusManager.php';
    $subjectStatus  = new SubjectStatusManager();
    $retakeSubjects = $subjectStatus->getRetakeSubjects($studentId);

    // NEW: ask StudentProgression kung pwede bang mag-progress
    $canProgress = $progression->canProgress($studentId);

    echo json_encode([
        'success' => true,
        'data' => [
            'student' => [
                'student_id'     => $student['student_id'],
                'student_number' => $student['student_number'],
                'course_id'      => $student['course_id'],
                'year_level'     => $student['year_level']
            ],
            'current' => $current,
            'next'    => $next,
            'school_year'           => $schoolYear,
            'next_school_year'      => $academicYearChanges ? $nextSchoolYear : $schoolYear,
            'academic_year_changes' => $academicYearChanges,
            'current_year_level_text' => $current ? $progression->getYearLevelText($current['year_level']) : 'N/A',
            'next_year_level_text'    => $next    ? $progression->getYearLevelText($next['year_level'])    : 'N/A',
            'next_semester_name'      => $next    ? $progression->getSemesterName($next['semester'])       : 'N/A',
            'is_completed'  => $next ? ($next['is_completed'] ?? false) : false,
            'retake_count'  => count($retakeSubjects),

            // NEW: progression gate info
            'can_progress'        => $canProgress['can_progress'] ?? false,
            'progress_block_reason' => $canProgress['reason'] ?? ''
        ],
        'message' => 'Progression data retrieved successfully'
    ]);

} catch (PDOException $e) {
    error_log('get_student_progression.php PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('get_student_progression.php Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}