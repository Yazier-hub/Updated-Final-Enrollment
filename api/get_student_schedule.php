<?php
/**
 * API endpoint to get student's complete schedule from enrollments
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Model.php';
    require_once $basePath . '/classes/Enrollment.php';
    require_once $basePath . '/classes/Student.php';

    $studentId  = isset($_GET['student_id'])  ? (int) $_GET['student_id']  : 0;
    $schoolYear = isset($_GET['school_year']) ? trim($_GET['school_year']) : null;

    if ($studentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
        exit;
    }

    $student     = new Student();
    $studentData = $student->findById($studentId);

    if (!$studentData) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $enrollment = new Enrollment();
    $db         = Database::getInstance();

    if (!$schoolYear) {
        $schoolYear = date('Y') . '-' . (date('Y') + 1);
    }

    $schedule    = $enrollment->getStudentSchedule($studentId, $schoolYear);
    $grouped     = $enrollment->getStudentScheduleByDay($studentId, $schoolYear);
    $enrollments = $enrollment->getStudentEnrollments($studentId, $schoolYear);

    $totalSubjects = count($schedule);
    $totalUnits    = 0;
    foreach ($enrollments as $e) {
        $totalUnits += isset($e['units']) ? (int) $e['units'] : 0;
    }

    $statusCounts = ['enrolled' => 0, 'completed' => 0, 'dropped' => 0];
    foreach ($enrollments as $e) {
        $status = $e['enrollment_status'] ?? 'enrolled';
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'schedule'            => $schedule,
            'grouped'             => $grouped,
            'total_subjects'      => $totalSubjects,
            'total_units'         => $totalUnits,
            'days_with_schedule'  => count($grouped),
            'enrollments'         => $enrollments,
            'status_summary'      => $statusCounts
        ],
        'student' => [
            'student_id'     => $studentId,
            'student_number' => $studentData['student_number'] ?? null,
            'full_name'      => ($studentData['first_name'] ?? '') . ' ' . ($studentData['surname'] ?? '')
        ],
        'school_year' => $schoolYear,
        'message'     => $totalSubjects > 0
            ? 'Schedule retrieved successfully. Found ' . $totalSubjects . ' subject(s).'
            : 'No schedule found for this student.'
    ]);

} catch (Exception $e) {
    error_log('Error in get_student_schedule: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}