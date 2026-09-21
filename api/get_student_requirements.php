<?php
/**
 * API endpoint to get student requirements
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Model.php';
    require_once $basePath . '/classes/Requirement.php';
    require_once $basePath . '/classes/Student.php';
    require_once $basePath . '/classes/Application.php';
    require_once $basePath . '/classes/Enrollment.php';

    if (!isset($_GET['student_id']) || empty($_GET['student_id'])) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        exit;
    }

    $studentId = (int) $_GET['student_id'];
    $category  = isset($_GET['category']) && $_GET['category'] !== ''
        ? trim($_GET['category'])
        : null;

    $requirement = new Requirement();
    $student     = new Student();
    $application = new Application();
    $enrollment  = new Enrollment();

    $studentDetails = $student->getStudentDetails($studentId);

    if (!$studentDetails) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }

    if ($category) {
        $categoryMap = [
            'freshmen'    => 'freshmen',
            'transferee'  => 'transferee',
            'returnee'    => 'continuing',
            'senior_high' => 'freshmen'
        ];

        $requirementCategory = $categoryMap[$category] ?? $category;
        $requirements = $requirement->getStudentRequirementsByCategory($studentId, $requirementCategory);
    } else {
        $requirements = $requirement->getStudentRequirementsWithStatus($studentId);
    }

    $completion         = $requirement->getRequirementCompletionStatus($studentId);
    $mandatoryCompleted = $requirement->hasCompletedMandatoryRequirements($studentId);

    $applicantData = $application->getApplicationById($studentDetails['applicant_id']);

    $schoolYear  = date('Y') . '-' . (date('Y') + 1);
    $enrollments = $enrollment->getStudentEnrollments($studentId, $schoolYear);

    $formattedRequirements = array_map(function ($req) {
        return [
            'requirement_id'       => $req['requirement_id'],
            'requirement_name'     => $req['requirement_name'],
            'requirement_category' => $req['requirement_category'],
            'is_mandatory'         => (bool) $req['is_mandatory'],
            'is_mandatory_label'   => $req['is_mandatory'] ? 'Required' : 'Optional',
            'is_submitted'         => (bool) ($req['is_submitted'] ?? 0),
            'submitted_date'       => $req['submitted_date'] ?? null,
            'notes'                => $req['notes'] ?? null,
            'status'               => ($req['is_submitted'] ?? 0) ? 'Submitted' : 'Pending',
            'status_class'         => ($req['is_submitted'] ?? 0) ? 'success' : 'warning'
        ];
    }, $requirements);

    $totalRequirements     = $completion['total'] ?? 0;
    $submittedRequirements = $completion['submitted'] ?? 0;
    $percentageComplete    = $totalRequirements > 0
        ? round(($submittedRequirements / $totalRequirements) * 100)
        : 0;

    echo json_encode([
        'success'        => true,
        'student'        => $studentDetails,
        'student_name'   => $studentDetails['first_name'] . ' ' . $studentDetails['surname'],
        'student_number' => $studentDetails['student_number'],
        'admission_type' => $applicantData['admission_type'] ?? 'freshmen',
        'course' => [
            'code' => $studentDetails['course_code'] ?? 'N/A',
            'name' => $studentDetails['course_name'] ?? 'N/A'
        ],
        'section' => [
            'code'        => $studentDetails['section_code'] ?? 'N/A',
            'grade_level' => $studentDetails['grade_level']  ?? 'N/A',
            'semester'    => $studentDetails['semester']     ?? 'N/A',
            'school_year' => $studentDetails['school_year']  ?? 'N/A'
        ],
        'requirements' => $formattedRequirements,
        'requirements_summary' => [
            'total'               => $totalRequirements,
            'submitted'           => $submittedRequirements,
            'remaining'           => $totalRequirements - $submittedRequirements,
            'percentage'          => $percentageComplete,
            'mandatory_completed' => $mandatoryCompleted
        ],
        'enrollments' => [
            'count' => count($enrollments),
            'list'  => $enrollments
        ],
        'completion'      => $completion,
        'filter_category' => $category,
        'message'         => 'Student requirements retrieved successfully'
    ]);

} catch (Exception $e) {
    error_log('Error in get_student_requirements: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}