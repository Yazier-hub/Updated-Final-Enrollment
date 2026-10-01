<?php
// classes/ApplicationController.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • sanitizeApplicationData(): year_graduated preserves "2024-2025" format
//     (was cast to int, breaking the YYYY-YYYY requirement)
//   • sanitizeApplicationData(): optional fields default to 'N/A' instead of ''
//     so DB always shows a consistent fallback value
//   • handleUpdate(): removed manual $data['updated_at'] — MySQL ON UPDATE
//     handles it automatically now that Application::$timestamps = false
//   • handleSubmit(): surfaces real DB error via Application::getLastError()
//     instead of generic "Failed to submit application"
//   • handleUpdate(): same real-error surfacing
//   • getSectionsForApplicant(): enr_applicants has no year_level/semester
//     → derive them from preferred_section_id
//   • ajaxGetSubjectsForSection(): subject rows use
//     `representative_schedule_id`, not `schedule_id`
//   • Defensive guards on missing array keys throughout

require_once 'Application.php';
require_once 'Course.php';
require_once 'Section.php';
require_once 'Enrollment.php';
require_once 'Requirement.php';
require_once 'Student.php';

class ApplicationController {
    private $application;
    private $course;
    private $section;
    private $enrollment;
    private $requirement;
    private $student;

    /**
     * Optional fields that should default to 'N/A' when blank.
     * Mirrors Application::$naDefaults.
     */
    private $naFields = [
        'middle_name',
        'suffix',
        'address_complete',
        'how_hear',
        'religion',
        'facebook',
        'messenger',
        'address',
        'parent_contact',
        'parent_address',
    ];

    public function __construct() {
        $this->application  = new Application();
        $this->course       = new Course();
        $this->section      = new Section();
        $this->enrollment   = new Enrollment();
        $this->requirement  = new Requirement();
        $this->student      = new Student();
    }

    /* ============================================================
       ROUTER
    ============================================================ */

    public function handleRequest() {
        if (isset($_GET['ajax'])) {
            $this->handleAjaxRequest();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $this->handlePostRequest();
            return;
        }

        if (isset($_GET['action'])) {
            $this->handleGetRequest();
        }
    }

    /* ============================================================
       AJAX ROUTER
    ============================================================ */

    public function handleAjaxRequest() {
        header('Content-Type: application/json');

        try {
            if (!isset($_GET['ajax'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid AJAX request']);
                exit;
            }

            switch ($_GET['ajax']) {
                case 'get_application':
                    $this->ajaxGetApplication();
                    break;
                case 'get_applications':
                    $this->ajaxGetApplications();
                    break;
                case 'update_status':
                    $this->ajaxUpdateStatus();
                    break;
                case 'delete_application':
                    $this->ajaxDeleteApplication();
                    break;
                case 'get_sections_for_applicant':
                    $this->ajaxGetSectionsForApplicant();
                    break;
                case 'get_subjects_for_section':
                    $this->ajaxGetSubjectsForSection();
                    break;
                case 'get_requirements':
                    $this->ajaxGetRequirements();
                    break;
                case 'get_student_schedule':
                    $this->ajaxGetStudentSchedule();
                    break;
                case 'get_student_enrollments':
                    $this->ajaxGetStudentEnrollments();
                    break;
                case 'check_subject_enrollment':
                    $this->ajaxCheckSubjectEnrollment();
                    break;
                case 'get_applicant_with_student':
                    $this->ajaxGetApplicantWithStudent();
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
            }
        } catch (Exception $e) {
            error_log('AJAX Error in ApplicationController: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    /* ============================================================
       AJAX HANDLERS
    ============================================================ */

    private function ajaxGetApplication() {
        if (!isset($_GET['id'])) {
            echo json_encode(['success' => false, 'message' => 'Application ID required']);
            return;
        }

        $id  = (int) $_GET['id'];
        $app = $this->application->getApplicationById($id);

        if ($app) {
            echo json_encode(['success' => true, 'data' => $app]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Application not found']);
        }
    }

    private function ajaxGetApplications() {
        $status = $_GET['status'] ?? null;
        $search = $_GET['search'] ?? '';

        if ($status) {
            $apps = $this->application->getApplicationsByStatus($status);
        } elseif (!empty($search)) {
            $apps = $this->application->searchApplications($search);
        } else {
            $apps = $this->application->getAllApplications();
        }

        echo json_encode(['success' => true, 'data' => $apps]);
    }

    private function ajaxUpdateStatus() {
        if (!isset($_POST['id'], $_POST['status'])) {
            echo json_encode(['success' => false, 'message' => 'ID and status required']);
            return;
        }

        $id     = (int) $_POST['id'];
        $status = $_POST['status'];

        $validStatuses = ['pending', 'converted', 'rejected'];
        if (!in_array($status, $validStatuses, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            return;
        }

        $result = $this->application->updateStatus($id, $status);

        echo json_encode($result
            ? ['success' => true,  'message' => 'Status updated successfully']
            : ['success' => false, 'message' => 'Failed to update status']);
    }

    private function ajaxDeleteApplication() {
        if (!isset($_POST['id'])) {
            echo json_encode(['success' => false, 'message' => 'Application ID required']);
            return;
        }

        $id  = (int) $_POST['id'];
        $app = $this->application->getApplicationById($id);

        if ($app && $app['status'] === 'converted') {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete converted applications'
            ]);
            return;
        }

        $result = $this->application->delete($id);

        echo json_encode($result
            ? ['success' => true,  'message' => 'Application deleted successfully']
            : ['success' => false, 'message' => 'Failed to delete application']);
    }

    private function ajaxGetSectionsForApplicant() {
        if (!isset($_GET['applicant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Applicant ID required']);
            return;
        }

        $applicantId = (int) $_GET['applicant_id'];
        $result      = $this->getSectionsForApplicant($applicantId);
        echo json_encode($result);
    }

    /**
     * Get subjects for a section with enrollment status.
     *
     * FIX: Section::getSectionSubjectsWithSchedules() returns
     * `representative_schedule_id` — not `schedule_id`.
     */
    private function ajaxGetSubjectsForSection() {
        if (!isset($_GET['section_id'], $_GET['course_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Section ID and Course ID required'
            ]);
            return;
        }

        $sectionId  = (int) $_GET['section_id'];
        $courseId   = (int) $_GET['course_id'];
        $yearLevel  = isset($_GET['year_level']) ? (int) $_GET['year_level'] : 1;
        $semester   = isset($_GET['semester'])   ? (int) $_GET['semester']   : 1;
        $studentId  = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;
        $schoolYear = $_GET['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

        $subjects = $this->section->getSectionSubjectsWithSchedules(
            $sectionId,
            $courseId,
            $yearLevel,
            $semester,
            $studentId
        );

        if (!is_array($subjects)) {
            $subjects = [];
        }

        if ($studentId) {
            $enrollments = $this->enrollment->getStudentEnrollments($studentId, $schoolYear);
            $enrolledScheduleIds = array_map(
                'intval',
                array_column($enrollments, 'schedule_id')
            );

            foreach ($subjects as &$subject) {
                // Accept both keys — Section.php returns `representative_schedule_id`
                $scheduleId = $subject['representative_schedule_id']
                           ?? $subject['schedule_id']
                           ?? null;

                $isEnrolled = $scheduleId
                    && in_array((int) $scheduleId, $enrolledScheduleIds, true);

                $subject['schedule_id']       = $scheduleId;
                $subject['is_enrolled']       = $isEnrolled;
                $subject['enrollment_status'] = $isEnrolled ? 'enrolled' : 'pending';
            }
            unset($subject);
        }

        echo json_encode([
            'success'     => true,
            'data'        => $subjects,
            'count'       => count($subjects),
            'school_year' => $schoolYear
        ]);
    }

    private function ajaxGetRequirements() {
        $admissionType = $_GET['admission_type'] ?? 'freshmen';

        try {
            $requirements = $this->requirement->getRequirementsByAdmissionType($admissionType);

            if (!is_array($requirements)) {
                $requirements = [];
            }

            $formatted = array_map(function ($req) {
                return [
                    'requirement_id'       => $req['requirement_id'],
                    'requirement_name'     => $req['requirement_name'],
                    'requirement_category' => $req['requirement_category'],
                    'is_mandatory'         => (bool) $req['is_mandatory'],
                    'is_mandatory_label'   => $req['is_mandatory'] ? 'Required' : 'Optional'
                ];
            }, $requirements);

            echo json_encode([
                'success'        => true,
                'requirements'   => $formatted,
                'admission_type' => $admissionType,
                'count'          => count($formatted)
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetRequirements: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading requirements: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetStudentSchedule() {
        if (!isset($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $schoolYear = $_GET['school_year'] ?? null;

        try {
            $schedule = $this->enrollment->getStudentSchedule($studentId, $schoolYear);
            $grouped  = $this->enrollment->getStudentScheduleByDay($studentId, $schoolYear);

            echo json_encode([
                'success' => true,
                'data' => [
                    'schedule'       => $schedule,
                    'grouped'        => $grouped,
                    'total_subjects' => count($schedule)
                ]
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetStudentSchedule: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading schedule: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetStudentEnrollments() {
        if (!isset($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $schoolYear = $_GET['school_year'] ?? null;

        try {
            $enrollments = $this->enrollment->getStudentEnrollments($studentId, $schoolYear);

            echo json_encode([
                'success' => true,
                'data'    => $enrollments,
                'count'   => count($enrollments)
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetStudentEnrollments: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading enrollments: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxCheckSubjectEnrollment() {
        if (!isset($_GET['student_id'], $_GET['schedule_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Student ID and Schedule ID required'
            ]);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $scheduleId = (int) $_GET['schedule_id'];
        $schoolYear = $_GET['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

        try {
            $enrollments = $this->enrollment->getStudentEnrollments($studentId, $schoolYear);
            $enrolledScheduleIds = array_map(
                'intval',
                array_column($enrollments, 'schedule_id')
            );

            $isEnrolled = in_array($scheduleId, $enrolledScheduleIds, true);

            $enrollment = null;
            foreach ($enrollments as $e) {
                if ((int) ($e['schedule_id'] ?? 0) === $scheduleId) {
                    $enrollment = $e;
                    break;
                }
            }

            echo json_encode([
                'success'     => true,
                'is_enrolled' => $isEnrolled,
                'enrollment'  => $enrollment,
                'schedule_id' => $scheduleId,
                'student_id'  => $studentId,
                'school_year' => $schoolYear
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxCheckSubjectEnrollment: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error checking enrollment: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetApplicantWithStudent() {
        if (!isset($_GET['applicant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Applicant ID required']);
            return;
        }

        $applicantId = (int) $_GET['applicant_id'];
        $applicant   = $this->application->getApplicationWithStudent($applicantId);

        if ($applicant) {
            echo json_encode(['success' => true, 'data' => $applicant]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Applicant not found']);
        }
    }

    /* ============================================================
       POST ROUTER
    ============================================================ */

    public function handlePostRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            try {
                switch ($_POST['action']) {
                    case 'update':      $this->handleUpdate();      break;
                    case 'submit':      $this->handleSubmit();      break;
                    case 'delete':      $this->handleDelete();      break;
                    case 'convert':     $this->handleConvert();     break;
                    case 'reject':      $this->handleReject();      break;
                    case 'bulk_action': $this->handleBulkAction();  break;
                    case 'enroll':      $this->handleEnroll();      break;
                    default:
                        $_SESSION['message'] = '❌ Invalid action specified.';
                }
            } catch (Exception $e) {
                error_log('POST Error in ApplicationController: ' . $e->getMessage());
                $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
            }

            $redirect = $_POST['redirect'] ?? '?page=applications';
            header('Location: ' . $redirect);
            exit;
        }
    }

    /* ============================================================
       GET ROUTER
    ============================================================ */

    private function handleGetRequest() {
        try {
            if (!isset($_GET['action'])) {
                return;
            }

            switch ($_GET['action']) {
                case 'delete':
                    if (isset($_GET['id'])) {
                        $this->handleDelete();
                    }
                    break;
                case 'view':
                    if (isset($_GET['id'])) {
                        $_SESSION['view_application_id'] = (int) $_GET['id'];
                    }
                    break;
                default:
                    break;
            }
        } catch (Exception $e) {
            error_log('GET Error in ApplicationController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        header('Location: ?page=applications');
        exit;
    }

    /* ============================================================
       POST ACTION HANDLERS
    ============================================================ */

    private function handleUpdate() {
        if (!isset($_POST['applicant_id'])) {
            $_SESSION['message'] = '❌ Application ID required for update.';
            return;
        }

        $data = $this->sanitizeApplicationData($_POST);

        // Normalize year_graduated to YYYY-YYYY (or N/A)
        $data['year_graduated'] = $this->normalizeYearGraduated(
            $data['year_graduated'] ?? ''
        );

        // Apply N/A defaults for optional fields
        foreach ($this->naFields as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                $data[$field] = 'N/A';
            }
        }

        // ✅ REMOVED: $data['updated_at'] = date('Y-m-d H:i:s');
        //    MySQL ON UPDATE current_timestamp() handles it automatically,
        //    and updated_at is no longer in $fillable anyway.

        $updated = $this->application->update((int) $_POST['applicant_id'], $data);

        if ($updated) {
            $_SESSION['message'] = '✅ Application updated successfully!';
        } else {
            $dbErr = method_exists($this->application, 'getLastError')
                ? $this->application->getLastError()
                : null;

            $_SESSION['message'] = '❌ Failed to update application.'
                                 . ($dbErr ? " ({$dbErr})" : '');
        }
    }

    private function handleSubmit() {
        $requiredFields = ['surname', 'first_name', 'email', 'contact_number', 'course_id'];

        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || $_POST[$field] === '') {
                $_SESSION['message'] = "❌ The field '{$field}' is required.";
                return;
            }
        }

        $data = $this->sanitizeApplicationData($_POST);

        // Normalize year_graduated BEFORE handing off
        $data['year_graduated'] = $this->normalizeYearGraduated(
            $data['year_graduated'] ?? ''
        );

        $result = $this->application->submitApplication($data);

        if ($result) {
            $_SESSION['message'] = '✅ Application submitted successfully! Go to Enrollments to enroll.';
        } else {
            // ✅ Surface the real DB error if available
            $dbErr = method_exists($this->application, 'getLastError')
                ? $this->application->getLastError()
                : null;

            $_SESSION['message'] = '❌ Failed to submit application.'
                                 . ($dbErr ? " ({$dbErr})" : '');
        }
    }

    private function handleDelete() {
        $id = isset($_POST['applicant_id'])
            ? (int) $_POST['applicant_id']
            : (isset($_GET['id']) ? (int) $_GET['id'] : null);

        if (!$id) {
            $_SESSION['message'] = '❌ Application ID required for deletion.';
            return;
        }

        $app = $this->application->getApplicationById($id);
        if ($app && $app['status'] === 'converted') {
            $_SESSION['message'] = '❌ Cannot delete converted applications.';
            return;
        }

        $_SESSION['message'] = $this->application->delete($id)
            ? '✅ Application deleted successfully!'
            : '❌ Failed to delete application.';
    }

    private function handleConvert() {
        if (!isset($_POST['applicant_id'], $_POST['section_id'])) {
            $_SESSION['message'] = '❌ Applicant ID and Section ID required for conversion.';
            return;
        }

        $schoolYear = $_POST['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

        $scheduleIds = [];
        if (!empty($_POST['schedule_ids'])) {
            $scheduleIds = array_map('intval', explode(',', $_POST['schedule_ids']));
        } elseif (isset($_POST['schedule_ids_array']) && is_array($_POST['schedule_ids_array'])) {
            $scheduleIds = array_map('intval', $_POST['schedule_ids_array']);
        }

        $result = $this->application->convertToStudentAndEnrollWithSubjects(
            (int) $_POST['applicant_id'],
            (int) $_POST['section_id'],
            $schoolYear,
            $scheduleIds
        );

        if ($result['success']) {
            $message = "✅ Applicant converted successfully! Student Number: {$result['student_number']}";
            if (!empty($result['enrollment_count'])) {
                $message .= " Enrolled in {$result['enrollment_count']} subject(s).";
            }
            $_SESSION['message'] = $message;
        } else {
            $_SESSION['message'] = "❌ Conversion failed: {$result['message']}";
        }
    }

    private function handleEnroll() {
        if (!isset($_POST['applicant_id'], $_POST['section_id'], $_POST['school_year'])) {
            $_SESSION['message'] = '❌ Missing required fields.';
            return;
        }

        $applicantId = (int) $_POST['applicant_id'];
        $sectionId   = (int) $_POST['section_id'];
        $schoolYear  = trim($_POST['school_year']);

        $scheduleIds = [];
        if (!empty($_POST['schedule_ids'])) {
            $scheduleIds = array_map('intval', explode(',', $_POST['schedule_ids']));
        } elseif (isset($_POST['schedule_ids_array']) && is_array($_POST['schedule_ids_array'])) {
            $scheduleIds = array_map('intval', $_POST['schedule_ids_array']);
        }

        error_log("=== ENROLLMENT ATTEMPT ===");
        error_log("Applicant ID: {$applicantId}, Section ID: {$sectionId}, SY: {$schoolYear}");
        error_log("Schedule IDs: " . implode(',', $scheduleIds));

        if (empty($scheduleIds)) {
            $_SESSION['message'] = '❌ No subjects selected for enrollment.';
            return;
        }

        $applicant = $this->application->getApplicationById($applicantId);
        if (!$applicant) {
            $_SESSION['message'] = '❌ Applicant not found.';
            return;
        }
        if (empty($applicant['course_id'])) {
            $_SESSION['message'] = '❌ Applicant has no course assigned.';
            return;
        }
        if ($applicant['status'] !== 'pending') {
            $_SESSION['message'] = '❌ Applicant is not in pending status.';
            return;
        }

        $section = $this->section->findById($sectionId);
        if (!$section) {
            $_SESSION['message'] = '❌ Selected section does not exist.';
            return;
        }
        if ((int) $section['program_id'] !== (int) $applicant['course_id']) {
            $_SESSION['message'] = '❌ Course mismatch. Applicant course does not match section course.';
            return;
        }

        $result = $this->application->convertToStudentAndEnrollWithSubjects(
            $applicantId,
            $sectionId,
            $schoolYear,
            $scheduleIds
        );

        if ($result['success']) {
            $message = "✅ Student enrolled successfully! Student Number: {$result['student_number']}";
            if (!empty($result['enrollment_count'])) {
                $message .= " Enrolled in {$result['enrollment_count']} subject(s).";
            }

            if (!empty($result['email_sent'])) {
                $message .= " Account credentials sent to email.";
            } elseif (!empty($result['email_error'])) {
                $message .= " Note: " . $result['email_error'];
            }

            $_SESSION['message'] = $message;

            if (!empty($result['account_created'])) {
                $_SESSION['account_created'] = true;
                $_SESSION['username']        = $result['username'];
                $_SESSION['password']        = $result['password'];
                $_SESSION['is_new_account']  = $result['is_new_account'] ?? false;
                $_SESSION['email_sent']      = $result['email_sent'] ?? false;
                $_SESSION['email_error']     = $result['email_error'] ?? null;
            }
        } else {
            $_SESSION['message'] = "❌ Enrollment failed: {$result['message']}";
        }
    }

    private function handleReject() {
        if (!isset($_POST['applicant_id'])) {
            $_SESSION['message'] = '❌ Application ID required for rejection.';
            return;
        }

        $notes = $_POST['notes'] ?? 'Application rejected';

        if ($this->application->updateStatus((int) $_POST['applicant_id'], 'rejected')) {
            $this->application->update((int) $_POST['applicant_id'], ['notes' => $notes]);
            $_SESSION['message'] = '✅ Application rejected successfully.';
        } else {
            $_SESSION['message'] = '❌ Failed to reject application.';
        }
    }

    private function handleBulkAction() {
        if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
            $_SESSION['message'] = '❌ No applications selected for bulk action.';
            return;
        }

        $action = $_POST['bulk_action'] ?? '';
        $successCount = 0;
        $failCount    = 0;

        switch ($action) {
            case 'delete':
                foreach ($_POST['ids'] as $id) {
                    $app = $this->application->getApplicationById((int) $id);
                    if ($app && $app['status'] !== 'converted') {
                        if ($this->application->delete((int) $id)) {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                    } else {
                        $failCount++;
                    }
                }
                break;

            case 'reject':
                foreach ($_POST['ids'] as $id) {
                    if ($this->application->updateStatus((int) $id, 'rejected')) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }
                break;

            default:
                $_SESSION['message'] = '❌ Invalid bulk action.';
                return;
        }

        $_SESSION['message'] = "✅ {$successCount} applications processed successfully. {$failCount} failed.";
    }

    /* ============================================================
       SANITIZATION
    ============================================================ */

    /**
     * Sanitize incoming POST data.
     *
     * FIX: year_graduated is now passed through sanitizeInput() (string)
     * instead of (int), preserving "2024-2025" format. Actual normalization
     * to YYYY-YYYY happens in normalizeYearGraduated().
     */
    private function sanitizeApplicationData($postData) {
        return [
            'surname'              => $this->sanitizeInput($postData['surname'] ?? ''),
            'first_name'           => $this->sanitizeInput($postData['first_name'] ?? ''),
            'middle_name'          => $this->sanitizeInput($postData['middle_name'] ?? ''),
            'suffix'               => $this->sanitizeInput($postData['suffix'] ?? ''),
            'admission_type'       => $this->sanitizeInput($postData['admission_type'] ?? 'freshmen'),
            'working_student'      => $this->sanitizeInput($postData['working_student'] ?? 'No'),
            'sex'                  => $this->sanitizeInput($postData['sex'] ?? ''),
            'address_barangay'     => $this->sanitizeInput($postData['address_barangay'] ?? ''),
            'address_city'         => $this->sanitizeInput($postData['address_city'] ?? ''),
            'address_province'     => $this->sanitizeInput($postData['address_province'] ?? ''),
            'address_complete'     => $this->sanitizeInput($postData['address_complete'] ?? ''),
            'school_last_attended' => $this->sanitizeInput($postData['school_last_attended'] ?? ''),

            // ✅ FIXED: keep as string, do NOT cast to int
            'year_graduated'       => $this->sanitizeInput($postData['year_graduated'] ?? ''),

            'how_hear'             => $this->sanitizeInput($postData['how_hear'] ?? ''),
            'email'                => $this->sanitizeEmail($postData['email'] ?? ''),
            'date_of_birth'        => $this->sanitizeInput($postData['date_of_birth'] ?? ''),
            'place_of_birth'       => $this->sanitizeInput($postData['place_of_birth'] ?? ''),
            'age'                  => !empty($postData['age']) ? (int) $postData['age'] : null,
            'civil_status'         => $this->sanitizeInput($postData['civil_status'] ?? 'Single'),
            'religion'             => $this->sanitizeInput($postData['religion'] ?? ''),
            'contact_number'       => $this->sanitizeContactNumber($postData['contact_number'] ?? ''),
            'facebook'             => $this->sanitizeInput($postData['facebook'] ?? ''),
            'messenger'            => $this->sanitizeInput($postData['messenger'] ?? ''),
            'address'              => $this->sanitizeInput($postData['address'] ?? ''),
            'parent_full_name'     => $this->sanitizeInput($postData['parent_full_name'] ?? ''),
            'parent_contact'       => $this->sanitizeContactNumber($postData['parent_contact'] ?? ''),
            'parent_address'       => $this->sanitizeInput($postData['parent_address'] ?? ''),
            'course_id'            => !empty($postData['course_id']) ? (int) $postData['course_id'] : null,
            'preferred_section_id' => !empty($postData['preferred_section_id']) ? (int) $postData['preferred_section_id'] : null
        ];
    }

    private function sanitizeInput($input) {
        if (is_string($input)) {
            $input = trim($input);
            $input = stripslashes($input);
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        }
        return $input;
    }

    private function sanitizeEmail($email) {
        $email = $this->sanitizeInput($email);
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }

    private function sanitizeContactNumber($number) {
        $number = trim($number);
        return preg_replace('/[^0-9+]/', '', $number);
    }

    /**
     * Normalize year_graduated to YYYY-YYYY format or 'N/A'.
     * Mirrors Application::normalizeYearGraduated().
     *
     * Accepts: "2024-2025", "2024 - 2025", "2024/2025", "2024"
     * Returns: "2024-2025" or "N/A"
     */
    private function normalizeYearGraduated($value) {
        $value = trim((string) $value);

        if ($value === '' || strtoupper($value) === 'N/A') {
            return 'N/A';
        }

        if (preg_match('/(\d{4})\s*[-\/]\s*(\d{4})/', $value, $m)) {
            return $m[1] . '-' . $m[2];
        }

        if (preg_match('/^(\d{4})$/', $value, $m)) {
            return $m[1] . '-' . ((int) $m[1] + 1);
        }

        return 'N/A';
    }

    private function convertYearLevelToText($yearLevel) {
        $levels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
        return $levels[$yearLevel] ?? '1st Year';
    }

    private function convertSemesterToText($semester) {
        $semesters = [1 => '1st Semester', 2 => '2nd Semester'];
        return $semesters[$semester] ?? '1st Semester';
    }

    /* ============================================================
       PUBLIC METHODS FOR VIEWS
    ============================================================ */

    public function getPendingApplications($search = '') {
        if (!empty($search)) {
            return $this->application->searchPendingApplications($search);
        }
        return $this->application->getPendingApplications();
    }

    public function getApplicationsByStatus($status) {
        return $this->application->getApplicationsByStatus($status);
    }

    public function getAllApplications() {
        return $this->application->getAllApplications();
    }

    public function getCourses() {
        return $this->course->findAll();
    }

    public function getCourseById($id) {
        return $this->course->findById($id);
    }

    public function getStats() {
        return [
            'total'     => $this->application->getTotalApplications(),
            'pending'   => $this->application->countApplicationsByStatus('pending'),
            'converted' => $this->application->countApplicationsByStatus('converted'),
            'rejected'  => $this->application->countApplicationsByStatus('rejected')
        ];
    }

    public function deleteOldApplications() {
        return $this->application->deleteOldApplications();
    }

    public function getApplicationById($id) {
        return $this->application->getApplicationById($id);
    }

    /**
     * Get sections for an applicant.
     *
     * FIX: enr_applicants has no year_level / semester columns.
     * We derive them from preferred_section_id when available.
     */
    public function getSectionsForApplicant($applicantId) {
        $applicant = $this->application->getApplicationById($applicantId);

        if (!$applicant || empty($applicant['course_id'])) {
            return [
                'success'  => false,
                'message'  => 'Applicant not found or has no course',
                'sections' => []
            ];
        }

        $yearLevel     = 1;
        $semester      = 1;
        $yearLevelText = '1st Year';
        $semesterText  = '1st Semester';

        // Derive year + semester from the preferred section, if any
        if (!empty($applicant['preferred_section_id'])) {
            $prefSection = $this->section->getSectionDetails((int) $applicant['preferred_section_id']);

            if ($prefSection) {
                $yearLevel = $this->section->convertYearLevelToNumeric(
                    $prefSection['grade_level'] ?? '1st Year'
                );

                $semText = strtolower($prefSection['semester'] ?? '');
                $semester = (strpos($semText, 'second') !== false || strpos($semText, '2nd') !== false)
                    ? 2
                    : 1;

                $yearLevelText = $this->convertYearLevelToText($yearLevel);
                $semesterText  = $this->convertSemesterToText($semester);
            }
        }

        $sections = $this->section->getSectionsByCourseAndYearLevel(
            $applicant['course_id'],
            $yearLevelText,
            $semesterText
        );

        if (!is_array($sections)) {
            $sections = [];
        }

        // Attach subject count for each section
        foreach ($sections as &$section) {
            $subjects = $this->section->getSectionSubjectsWithSchedules(
                $section['id'],
                $applicant['course_id'],
                $yearLevel,
                $semester
            );
            $section['subject_count'] = is_array($subjects) ? count($subjects) : 0;
        }
        unset($section);

        // Build applicant name skipping "N/A" middle name
        $nameParts = [];
        if (!empty($applicant['first_name'])) {
            $nameParts[] = $applicant['first_name'];
        }
        if (!empty($applicant['middle_name'])
            && strtoupper(trim($applicant['middle_name'])) !== 'N/A') {
            $nameParts[] = $applicant['middle_name'];
        }
        if (!empty($applicant['surname'])) {
            $nameParts[] = $applicant['surname'];
        }
        $applicantName = implode(' ', $nameParts);

        return [
            'success'        => true,
            'sections'       => $sections,
            'course_id'      => (int) $applicant['course_id'],
            'course_code'    => $applicant['course_code'] ?? '',
            'course_name'    => $applicant['course_name'] ?? '',
            'year_level'     => $yearLevelText,
            'semester'       => $semesterText,
            'applicant_name' => $applicantName
        ];
    }

    public function getStudentSubjects($studentId, $schoolYear = null) {
        return $this->enrollment->getStudentEnrollments($studentId, $schoolYear);
    }

    public function getStudentScheduleByDay($studentId, $schoolYear = null) {
        return $this->enrollment->getStudentScheduleByDay($studentId, $schoolYear);
    }
}