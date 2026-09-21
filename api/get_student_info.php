<?php
/**
 * API endpoint to get student information with enrollment details
 *
 * RULE: ONE STUDENT + ONE SCHEDULE + ONE SCHOOL YEAR = ONE ENROLLMENT
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

try {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    session_start();

    if (!isset($_SESSION['user_id'])) {
        ob_clean();
        echo json_encode([
            'success'    => false,
            'message'    => 'Unauthorized. Please log in first.',
            'error_code' => 'UNAUTHORIZED'
        ]);
        exit;
    }

    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Model.php';
    require_once $basePath . '/classes/Student.php';
    require_once $basePath . '/classes/Application.php';
    require_once $basePath . '/classes/Requirement.php';
    require_once $basePath . '/classes/Enrollment.php';

    if (!isset($_GET['student_id']) || empty($_GET['student_id'])) {
        ob_clean();
        echo json_encode([
            'success'    => false,
            'message'    => 'Student ID is required',
            'error_code' => 'MISSING_STUDENT_ID'
        ]);
        exit;
    }

    $studentId  = (int) $_GET['student_id'];
    $schoolYear = $_GET['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

    if ($studentId <= 0) {
        ob_clean();
        echo json_encode([
            'success'    => false,
            'message'    => 'Invalid student ID',
            'error_code' => 'INVALID_STUDENT_ID'
        ]);
        exit;
    }

    $student     = new Student();
    $application = new Application();
    $requirement = new Requirement();
    $enrollment  = new Enrollment();
    $db          = Database::getInstance();

    $studentData = $student->getStudentDetails($studentId);

    if (!$studentData) {
        ob_clean();
        echo json_encode([
            'success'    => false,
            'message'    => 'Student not found. Student ID: ' . $studentId,
            'error_code' => 'STUDENT_NOT_FOUND'
        ]);
        exit;
    }

    $requirements        = $requirement->getStudentRequirementsWithStatus($studentId);
    $completion          = $requirement->getRequirementCompletionStatus($studentId);
    $mandatoryCompleted  = $requirement->hasCompletedMandatoryRequirements($studentId);

    $enrollments  = $enrollment->getStudentEnrollments($studentId, $schoolYear);
    $schedule     = $enrollment->getStudentSchedule($studentId, $schoolYear);
    $scheduleByDay = $enrollment->getStudentScheduleByDay($studentId, $schoolYear);

    $enrolledSubjects = array_filter($enrollments, fn($e) => $e['enrollment_status'] === 'enrolled');
    $completedSubjects = array_filter($enrollments, fn($e) => $e['enrollment_status'] === 'completed');
    $droppedSubjects  = array_filter($enrollments, fn($e) => $e['enrollment_status'] === 'dropped');

    $totalUnits = array_sum(array_column($enrollments, 'units'));

    $progressByCategory = null;
    try {
        $progressByCategory = $requirement->getStudentProgressByCategory($studentId);
    } catch (Exception $e) {
        error_log('Error getting progress by category: ' . $e->getMessage());
    }

    $formattedRequirements = array_map(function ($req) {
        return [
            'requirement_id'       => $req['requirement_id'],
            'requirement_name'     => $req['requirement_name'],
            'requirement_category' => $req['requirement_category'],
            'is_mandatory'         => (bool) $req['is_mandatory'],
            'is_mandatory_label'   => $req['is_mandatory'] ? 'Required' : 'Optional',
            'is_submitted'         => (bool) $req['is_submitted'],
            'submitted_date'       => $req['submitted_date'],
            'notes'                => $req['notes'],
            'status'               => $req['is_submitted'] ? 'Submitted' : 'Pending',
            'status_class'         => $req['is_submitted'] ? 'success' : 'warning'
        ];
    }, $requirements);

    $totalRequirements     = $completion['total'] ?? 0;
    $submittedRequirements = $completion['submitted'] ?? 0;
    $percentageComplete    = $totalRequirements > 0
        ? round(($submittedRequirements / $totalRequirements) * 100)
        : 0;

    ob_clean();

    echo json_encode([
        'success'      => true,
        'student'      => $studentData,
        'requirements' => $formattedRequirements,
        'requirements_summary' => [
            'total'              => $totalRequirements,
            'submitted'          => $submittedRequirements,
            'remaining'          => $totalRequirements - $submittedRequirements,
            'percentage'         => $percentageComplete,
            'mandatory_completed' => $mandatoryCompleted
        ],
        'enrollments' => [
            'list'        => $enrollments,
            'total'       => count($enrollments),
            'enrolled'    => count($enrolledSubjects),
            'completed'   => count($completedSubjects),
            'dropped'     => count($droppedSubjects),
            'total_units' => $totalUnits,
            'school_year' => $schoolYear
        ],
        'schedule' => [
            'list'           => $schedule,
            'by_day'         => $scheduleByDay,
            'total_subjects' => count($schedule),
            'total_units'    => $totalUnits
        ],
        'progress_by_category' => $progressByCategory,
        'message'              => 'Student data retrieved successfully'
    ]);

} catch (PDOException $e) {
    ob_clean();
    error_log('Database error in get_student_info: ' . $e->getMessage());
    echo json_encode([
        'success'    => false,
        'message'    => 'Database error: Unable to retrieve student data',
        'error_code' => 'DATABASE_ERROR'
    ]);
} catch (Exception $e) {
    ob_clean();
    error_log('Error in get_student_info: ' . $e->getMessage());
    echo json_encode([
        'success'    => false,
        'message'    => 'Server error: ' . $e->getMessage(),
        'error_code' => 'SERVER_ERROR'
    ]);
} finally {
    if (ob_get_level()) {
        ob_end_flush();
    }
}