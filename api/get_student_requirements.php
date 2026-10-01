<?php
/**
 * API endpoint to get student requirements
 *
 * FIXES IN THIS VERSION:
 *   • School year read from rgr_school_years (with calendar fallback)
 *   • Null-safe student name and section fields
 *   • Completion array normalized (never null)
 *   • Requirements rows are filtered to only well-formed entries
 *   • Enrollments slimmed down (no all_schedules[] payload)
 *   • Catch Throwable to prevent fatal page on PHP 8 type errors
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

    $includeEnrollments = !isset($_GET['include_enrollments'])
        || in_array(strtolower((string) $_GET['include_enrollments']), ['1', 'true', 'yes', 'on'], true);

    $db          = Database::getInstance();
    $requirement = new Requirement();
    $student     = new Student();
    $application = new Application();
    $enrollment  = new Enrollment();

    $studentDetails = $student->getStudentDetails($studentId);

    if (!$studentDetails) {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }

    /* ------------------------------------------------------------
       Requirements (optionally category-filtered)
    ------------------------------------------------------------ */
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

    if (!is_array($requirements)) {
        $requirements = [];
    }

    /* ------------------------------------------------------------
       Completion + mandatory summary
    ------------------------------------------------------------ */
    $completion         = $requirement->getRequirementCompletionStatus($studentId);
    $mandatoryCompleted = $requirement->hasCompletedMandatoryRequirements($studentId);

    if (!is_array($completion)) {
        $completion = ['total' => 0, 'submitted' => 0];
    }
    if (!isset($completion['total']))     $completion['total']     = 0;
    if (!isset($completion['submitted'])) $completion['submitted'] = 0;

    /* ------------------------------------------------------------
       Applicant data
    ------------------------------------------------------------ */
    $applicantData = null;
    if (!empty($studentDetails['applicant_id'])) {
        $applicantData = $application->getApplicationById($studentDetails['applicant_id']);
    }

    /* ------------------------------------------------------------
       School year — prefer active row from rgr_school_years
    ------------------------------------------------------------ */
    $schoolYear = null;
    try {
        $syStmt = $db->prepare("SELECT name FROM rgr_school_years WHERE is_active = 1 LIMIT 1");
        $syStmt->execute();
        $syRow = $syStmt->fetch(PDO::FETCH_ASSOC);
        if ($syRow && !empty($syRow['name'])) {
            $schoolYear = $syRow['name'];
        }
    } catch (Exception $e) {
        error_log('get_student_requirements: SY lookup failed: ' . $e->getMessage());
    }
    if (!$schoolYear) {
        $schoolYear = date('Y') . '-' . (date('Y') + 1);
    }

    /* ------------------------------------------------------------
       Enrollments (optional, slimmed)
    ------------------------------------------------------------ */
    $enrollments = [];
    if ($includeEnrollments) {
        try {
            $raw = $enrollment->getStudentEnrollments($studentId, $schoolYear);
            if (!is_array($raw)) $raw = [];

            foreach ($raw as $row) {
                // Drop heavy fields from the response payload
                unset($row['all_schedules']);

                $enrollments[] = [
                    'enrollment_id'     => $row['enrollment_id']     ?? null,
                    'subject_id'        => $row['subject_id']        ?? null,
                    'subject_code'      => $row['subject_code']      ?? null,
                    'subject_name'      => $row['subject_name']      ?? null,
                    'units'             => $row['units']             ?? null,
                    'section_code'      => $row['section_code']      ?? null,
                    'day_of_week'       => $row['day_of_week']       ?? null,
                    'start_time'        => $row['start_time']        ?? null,
                    'end_time'          => $row['end_time']          ?? null,
                    'enrollment_status' => $row['enrollment_status'] ?? null,
                    'final_grade'       => $row['final_grade']       ?? null,
                    'remarks'           => $row['remarks']           ?? null
                ];
            }
        } catch (Exception $e) {
            error_log('get_student_requirements: enrollment lookup failed: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------
       Format requirements
    ------------------------------------------------------------ */
    $formattedRequirements = [];
    foreach ($requirements as $req) {
        if (!isset($req['requirement_id'])) {
            continue; // skip malformed rows
        }

        $isSubmitted = !empty($req['is_submitted']);

        $formattedRequirements[] = [
            'requirement_id'       => $req['requirement_id'],
            'requirement_name'     => $req['requirement_name']     ?? '',
            'requirement_category' => $req['requirement_category'] ?? '',
            'is_mandatory'         => !empty($req['is_mandatory']),
            'is_mandatory_label'   => !empty($req['is_mandatory']) ? 'Required' : 'Optional',
            'is_submitted'         => $isSubmitted,
            'submitted_date'       => $req['submitted_date'] ?? null,
            'notes'                => $req['notes']          ?? null,
            'status'               => $isSubmitted ? 'Submitted' : 'Pending',
            'status_class'         => $isSubmitted ? 'success'   : 'warning'
        ];
    }

    /* ------------------------------------------------------------
       Summary
    ------------------------------------------------------------ */
    $totalRequirements     = (int) ($completion['total']     ?? 0);
    $submittedRequirements = (int) ($completion['submitted'] ?? 0);

    $percentageComplete = $totalRequirements > 0
        ? (int) round(($submittedRequirements / $totalRequirements) * 100)
        : 0;

    /* ------------------------------------------------------------
       Student name — null safe
    ------------------------------------------------------------ */
    $studentName = trim(
        ($studentDetails['first_name'] ?? '')
        . ' ' . ($studentDetails['surname'] ?? '')
    );

    /* ------------------------------------------------------------
       Response
    ------------------------------------------------------------ */
    echo json_encode([
        'success'        => true,
        'student'        => $studentDetails,
        'student_name'   => $studentName,
        'student_number' => $studentDetails['student_number'] ?? null,
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
            'remaining'           => max(0, $totalRequirements - $submittedRequirements),
            'percentage'          => $percentageComplete,
            'mandatory_completed' => (bool) $mandatoryCompleted
        ],
        'enrollments' => [
            'count'       => count($enrollments),
            'list'        => $enrollments,
            'school_year' => $schoolYear
        ],
        'completion'      => $completion,
        'filter_category' => $category,
        'message'         => 'Student requirements retrieved successfully'
    ]);

} catch (PDOException $e) {
    error_log('get_student_requirements PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('get_student_requirements Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}