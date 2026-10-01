<?php
// classes/EnrollmentController.php - FULLY FIXED for `kms` schema
//
// PATCHES APPLIED:
//   • handleProgressionEnroll() now calls PrerequisiteValidator
//   • handleEnroll() now calls PrerequisiteValidator
//   • handleBulkEnroll() now calls PrerequisiteValidator
//   • ajaxGetAvailableSubjectsWithStatus() now blocks subjects with failed prereqs
//   • studentFailedSubject() uses final_grade + remarks='failed' (enum, lowercase)
//   • searchEnrollments() uses e.semester_id (not the non-existent e.semester)
//   • searchEnrollments() exposes esem.name AS enrollment_semester for display

require_once 'Enrollment.php';
require_once 'Student.php';
require_once 'Course.php';
require_once 'Section.php';
require_once 'Application.php';
require_once 'Requirement.php';
require_once 'Database.php';
require_once 'User.php';
require_once 'StudentProgression.php';
require_once 'SubjectStatusManager.php';
require_once 'PrerequisiteValidator.php';

class EnrollmentController {
    private $enrollment;
    private $student;
    private $course;
    private $section;
    private $application;
    private $requirement;
    private $user;
    private $db;
    private $progression;
    private $subjectStatus;
    private $prerequisiteValidator;

    public function __construct() {
        $this->enrollment             = new Enrollment();
        $this->student                = new Student();
        $this->course                 = new Course();
        $this->section                = new Section();
        $this->application            = new Application();
        $this->requirement            = new Requirement();
        $this->user                   = new User();
        $this->db                     = Database::getInstance();
        $this->progression            = new StudentProgression();
        $this->subjectStatus          = new SubjectStatusManager();
        $this->prerequisiteValidator  = new PrerequisiteValidator();
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
    }

    /* ============================================================
       POST ROUTER
    ============================================================ */

    public function handlePostRequest() {
        $redirect = $_POST['redirect'] ?? '?page=enrollments';
        $action   = $_POST['action']   ?? '';

        try {
            error_log("=== EnrollmentController::handlePostRequest ===");
            error_log("Action: " . $action);

            switch ($action) {
                case 'enroll':             $this->handleEnroll();             break;
                case 'progression_enroll': $this->handleProgressionEnroll(); break;
                case 'archive':            $this->handleArchive();            break;
                case 'restore':            $this->handleRestore();            break;
                case 'drop':               $this->handleDrop();               break;
                case 'drop_subject':       $this->handleDropSubject();        break;
                case 'update_status':      $this->handleUpdateStatus();       break;
                case 'bulk_enroll':        $this->handleBulkEnroll();         break;

                default:
                    $_SESSION['message'] = '❌ Invalid action specified.';
                    error_log("Invalid action: " . $action);
            }
        } catch (Exception $e) {
            error_log('POST Error in EnrollmentController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        header('Location: ' . $redirect);
        exit;
    }

    /* ============================================================
       AJAX ROUTER
    ============================================================ */

    public function handleAjaxRequest() {
        header('Content-Type: application/json');

        try {
            if (empty($_GET['ajax'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
                exit;
            }

            switch ($_GET['ajax']) {
                case 'get_sections':                        $this->ajaxGetSections();                        break;
                case 'get_enrollment_stats':                $this->ajaxGetEnrollmentStats();                break;
                case 'get_student_enrollment':              $this->ajaxGetStudentEnrollment();              break;
                case 'search_students':                     $this->ajaxSearchStudents();                    break;
                case 'get_course_enrollment':               $this->ajaxGetCourseEnrollment();               break;
                case 'get_section_details':                 $this->ajaxGetSectionDetails();                 break;
                case 'get_student_schedule':                $this->ajaxGetStudentSchedule();                break;
                case 'get_subject_schedules':               $this->ajaxGetSubjectSchedules();               break;
                case 'get_available_subjects':              $this->ajaxGetAvailableSubjects();              break;
                case 'get_requirements':                    $this->ajaxGetRequirements();                   break;
                case 'get_student_schedule_full':           $this->ajaxGetStudentScheduleFull();            break;
                case 'get_student_enrollments':             $this->ajaxGetStudentEnrollments();             break;
                case 'check_subject_enrollment':            $this->ajaxCheckSubjectEnrollment();            break;
                case 'get_student_progression':             $this->ajaxGetStudentProgression();             break;
                case 'get_available_subjects_with_status':  $this->ajaxGetAvailableSubjectsWithStatus();    break;
                case 'get_retake_subjects':                 $this->ajaxGetRetakeSubjects();                 break;
                case 'validate_subject_prerequisites':      $this->ajaxValidateSubjectPrerequisites();      break;

                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
            }
        } catch (Exception $e) {
            error_log('AJAX Error in EnrollmentController: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    /* ============================================================
       PROGRESSION AJAX
    ============================================================ */

    private function ajaxGetStudentProgression() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId = (int) $_GET['student_id'];
        $student   = $this->student->findById($studentId);

        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            return;
        }

        $current        = $this->enrollment->getStudentCurrentProgression($studentId);
        $next           = $this->enrollment->getStudentNextProgression($studentId);
        $enrollmentData = $this->enrollment->getStudentEnrollmentData($studentId);

        $canProgress = $this->progression->canProgress($studentId);

        echo json_encode([
            'success' => true,
            'data' => [
                'student'               => $student,
                'current'               => $current,
                'next'                  => $next,
                'enrollment_data'       => $enrollmentData,
                'can_progress'          => $canProgress['can_progress'] ?? false,
                'progress_block_reason' => $canProgress['reason'] ?? ''
            ]
        ]);
    }

    private function ajaxGetAvailableSubjectsWithStatus() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId = (int) $_GET['student_id'];
        $yearLevel = isset($_GET['year_level']) ? (int) $_GET['year_level'] : null;
        $semester  = isset($_GET['semester'])   ? (int) $_GET['semester']   : null;
        $sectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;

        try {
            if ($sectionId > 0) {
                $sectionData = $this->section->getSectionDetails($sectionId);
                if ($sectionData) {
                    $yearLevelNumeric = $this->section->convertYearLevelToNumeric($sectionData['grade_level']);

                    $semText    = strtolower($sectionData['semester'] ?? '');
                    $semesterDb = (strpos($semText, 'second') !== false || strpos($semText, '2nd') !== false)
                        ? 'Second'
                        : 'First';

                    $subjects = $this->section->getSectionSubjectsWithSchedules(
                        $sectionId,
                        $sectionData['program_id'],
                        $yearLevelNumeric,
                        $semesterDb,
                        $studentId
                    );

                    if (!is_array($subjects)) $subjects = [];

                    foreach ($subjects as &$subject) {
                        $statusInfo = $this->subjectStatus->getSubjectStatus($studentId, $subject['subject_id']);
                        $subject['status']  = $statusInfo['status'];
                        $subject['message'] = $statusInfo['message'];

                        if (!empty($subject['has_schedule'])) {
                            if ($statusInfo['status'] === SubjectStatusManager::STATUS_AVAILABLE) {

                                // ---- PATCH: Prerequisite check per subject ----
                                $prereqCheck = $this->prerequisiteValidator->validateSubject(
                                    $studentId,
                                    (int) $subject['subject_id']
                                );

                                if (!$prereqCheck['valid'] && strpos($prereqCheck['message'], 'Prerequisites') !== false) {
                                    $subject['status']  = SubjectStatusManager::STATUS_BLOCKED;
                                    $subject['message'] = $prereqCheck['message'];

                                // ---- PATCH: Allow retake if student failed this subject before ----
                                } elseif ($this->studentFailedSubject($studentId, (int) $subject['subject_id'])) {
                                    $subject['status']  = SubjectStatusManager::STATUS_RETAKE;
                                    $subject['message'] = 'Retake — failed previously';

                                } else {
                                    $subject['status']  = SubjectStatusManager::STATUS_AVAILABLE;
                                    $subject['message'] = 'Available for enrollment';
                                }
                            }
                        } else {
                            $subject['status']  = SubjectStatusManager::STATUS_BLOCKED;
                            $subject['message'] = 'No schedule available in selected section';
                        }
                    }
                    unset($subject);
                } else {
                    $subjects = [];
                }
            } else {
                $subjects = $this->enrollment->getAvailableSubjectsForStudent($studentId, $yearLevel, $semester);
            }

            if (!is_array($subjects)) $subjects = [];

            $categorized = [
                'available' => [],
                'retake'    => [],
                'blocked'   => [],
                'completed' => []
            ];

            foreach ($subjects as $subject) {
                $status = $subject['status'] ?? SubjectStatusManager::STATUS_AVAILABLE;
                switch ($status) {
                    case SubjectStatusManager::STATUS_AVAILABLE:
                        $categorized['available'][] = $subject;
                        break;
                    case SubjectStatusManager::STATUS_RETAKE:
                        $categorized['retake'][] = $subject;
                        break;
                    case SubjectStatusManager::STATUS_BLOCKED:
                        $categorized['blocked'][] = $subject;
                        break;
                    case SubjectStatusManager::STATUS_PASSED:
                    case SubjectStatusManager::STATUS_COMPLETED:
                        $categorized['completed'][] = $subject;
                        break;
                    default:
                        $categorized['available'][] = $subject;
                        break;
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'all_subjects' => $subjects,
                    'categorized'  => $categorized,
                    'counts' => [
                        'available' => count($categorized['available']),
                        'retake'    => count($categorized['retake']),
                        'blocked'   => count($categorized['blocked']),
                        'completed' => count($categorized['completed']),
                        'total'     => count($subjects)
                    ]
                ]
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetAvailableSubjectsWithStatus: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading subjects: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetRetakeSubjects() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId = (int) $_GET['student_id'];

        try {
            $retakeSubjects = $this->enrollment->getRetakeSubjectsForStudent($studentId);
            if (!is_array($retakeSubjects)) $retakeSubjects = [];

            echo json_encode([
                'success' => true,
                'data'    => $retakeSubjects,
                'count'   => count($retakeSubjects)
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetRetakeSubjects: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading retake subjects: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxValidateSubjectPrerequisites() {
        if (empty($_GET['student_id']) || empty($_GET['schedule_ids'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID and schedule IDs required']);
            return;
        }

        $studentId   = (int) $_GET['student_id'];
        $scheduleIds = array_map('intval', explode(',', $_GET['schedule_ids']));

        try {
            $result = $this->prerequisiteValidator->validateScheduleEnrollments($studentId, $scheduleIds);

            echo json_encode([
                'success' => true,
                'valid'   => $result['valid'],
                'message' => $result['message']
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxValidateSubjectPrerequisites: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error validating prerequisites: ' . $e->getMessage()
            ]);
        }
    }

    /* ============================================================
       EXISTING AJAX HANDLERS
    ============================================================ */

    private function ajaxGetSections() {
        if (empty($_GET['applicant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Applicant ID required']);
            return;
        }

        $result = $this->getSectionsForApplicant((int) $_GET['applicant_id']);
        echo json_encode($result);
    }

    private function ajaxGetEnrollmentStats() {
        echo json_encode([
            'success' => true,
            'data'    => $this->getEnrollmentStats()
        ]);
    }

    private function ajaxGetStudentEnrollment() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $enrollment = $this->enrollment->getEnrollmentByStudentId($studentId);
        $progression = $this->enrollment->getStudentEnrollmentData($studentId);

        if ($enrollment) {
            echo json_encode([
                'success' => true,
                'data'    => array_merge($enrollment, ['progression' => $progression])
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No active enrollment found']);
        }
    }

    private function ajaxSearchStudents() {
        if (empty($_GET['keyword'])) {
            echo json_encode(['success' => false, 'message' => 'Search keyword required']);
            return;
        }

        $students = $this->searchEnrollments($_GET['keyword']);
        echo json_encode(['success' => true, 'data' => $students]);
    }

    private function ajaxGetCourseEnrollment() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $students = $this->getEnrolledStudents((int) $_GET['course_id'], null);
        echo json_encode(['success' => true, 'data' => $students]);
    }

    private function ajaxGetSectionDetails() {
        if (empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID required']);
            return;
        }

        $section = $this->section->findById((int) $_GET['section_id']);

        if ($section) {
            echo json_encode(['success' => true, 'data' => $section]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Section not found']);
        }
    }

    private function ajaxGetStudentSchedule() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $schoolYear = $_GET['school_year'] ?? null;

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
    }

    private function ajaxGetSubjectSchedules() {
        if (empty($_GET['subject_id']) || empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Subject ID and Section ID required']);
            return;
        }

        $subjectId = (int) $_GET['subject_id'];
        $sectionId = (int) $_GET['section_id'];

        $schedules = $this->enrollment->getSubjectSchedules($subjectId, $sectionId);
        if (!is_array($schedules)) $schedules = [];

        echo json_encode([
            'success' => true,
            'data'    => $schedules,
            'count'   => count($schedules)
        ]);
    }

    private function ajaxGetAvailableSubjects() {
        if (empty($_GET['section_id']) || empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID and Course ID required']);
            return;
        }

        $sectionId = (int) $_GET['section_id'];
        $courseId  = (int) $_GET['course_id'];
        $yearLevel = $_GET['year_level'] ?? 1;
        $semester  = $_GET['semester']   ?? 1;
        $studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;

        $subjects = $this->section->getSectionSubjectsWithSchedules(
            $sectionId, $courseId, $yearLevel, $semester, $studentId
        );
        if (!is_array($subjects)) $subjects = [];

        if ($studentId) {
            foreach ($subjects as &$subject) {
                $status = $this->subjectStatus->getSubjectStatus($studentId, $subject['subject_id']);
                $subject['subject_status'] = $status['status'];
                $subject['status_message'] = $status['message'];
            }
            unset($subject);
        }

        echo json_encode([
            'success' => true,
            'data'    => $subjects,
            'count'   => count($subjects)
        ]);
    }

    private function ajaxGetRequirements() {
        $admissionType = $_GET['admission_type'] ?? 'freshmen';

        try {
            $requirements = $this->requirement->getRequirementsByAdmissionType($admissionType);
            if (!is_array($requirements)) $requirements = [];

            $formattedRequirements = array_map(function ($req) {
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
                'requirements'   => $formattedRequirements,
                'admission_type' => $admissionType,
                'count'          => count($formattedRequirements)
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetRequirements: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading requirements: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetStudentScheduleFull() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $schoolYear = $_GET['school_year'] ?? null;

        try {
            $enrollments = $this->enrollment->getStudentEnrollments($studentId, $schoolYear);
            $grouped     = $this->enrollment->getStudentScheduleByDay($studentId, $schoolYear);
            $schedule    = $this->enrollment->getStudentSchedule($studentId, $schoolYear);
            $progression = $this->enrollment->getStudentEnrollmentData($studentId);

            echo json_encode([
                'success' => true,
                'data' => [
                    'enrollments'         => $enrollments,
                    'grouped'             => $grouped,
                    'schedule'            => $schedule,
                    'progression'         => $progression,
                    'total_subjects'      => count($enrollments),
                    'total_schedule_days' => count($grouped)
                ]
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetStudentScheduleFull: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading schedule: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetStudentEnrollments() {
        if (empty($_GET['student_id'])) {
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
        if (empty($_GET['student_id']) || empty($_GET['schedule_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID and Schedule ID required']);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $scheduleId = (int) $_GET['schedule_id'];
        $schoolYear = $_GET['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

        try {
            $sql = "SELECT enrollment_id, enrollment_status
                    FROM enr_enrollments
                    WHERE student_id = ? AND schedule_id = ? AND school_year = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$studentId, $scheduleId, $schoolYear]);
            $result = $stmt->fetch();

            echo json_encode([
                'success'           => true,
                'is_enrolled'       => $result !== false,
                'enrollment_status' => $result ? $result['enrollment_status'] : null,
                'enrollment_id'     => $result ? $result['enrollment_id']     : null
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxCheckSubjectEnrollment: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error checking enrollment: ' . $e->getMessage()
            ]);
        }
    }

    /* ============================================================
       MAIN ENROLL HANDLER (NEW STUDENT)
    ============================================================ */

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
        }
        if (empty($scheduleIds) && isset($_POST['schedule_ids_array']) && is_array($_POST['schedule_ids_array'])) {
            $scheduleIds = array_map('intval', $_POST['schedule_ids_array']);
        }
        $scheduleIds = array_values(array_filter($scheduleIds, fn($id) => $id > 0));

        error_log("=== ENROLLMENT ATTEMPT (NEW STUDENT) ===");
        error_log("Applicant ID: {$applicantId}, Section ID: {$sectionId}, SY: {$schoolYear}");
        error_log("Schedule IDs: " . implode(',', $scheduleIds));

        if (empty($scheduleIds)) {
            $_SESSION['message'] = '❌ No subjects selected for enrollment.';
            return;
        }

        $applicantData = $this->application->getApplicationById($applicantId);

        if (!$applicantData) {
            $_SESSION['message'] = '❌ Applicant not found.';
            return;
        }
        if (empty($applicantData['course_id'])) {
            $_SESSION['message'] = '❌ Applicant has no course assigned.';
            return;
        }
        if ($applicantData['status'] !== 'pending') {
            $_SESSION['message'] = '❌ Applicant is not in pending status.';
            return;
        }

        $sectionCheck = $this->section->findById($sectionId);
        if (!$sectionCheck) {
            $_SESSION['message'] = '❌ Selected section does not exist.';
            return;
        }
        if ((int) $sectionCheck['program_id'] !== (int) $applicantData['course_id']) {
            $_SESSION['message'] = '❌ Course mismatch.';
            return;
        }

        require_once 'Student.php';
        $studentModel    = new Student();
        $existingStudent = $studentModel->findByApplicantId($applicantId);

        // ---- Prerequisite check for existing student ----
        if ($existingStudent) {
            $prereqResult = $this->prerequisiteValidator->validateScheduleEnrollments(
                (int) $existingStudent['student_id'],
                $scheduleIds
            );
            if (!$prereqResult['valid']) {
                $_SESSION['message'] = '❌ ' . $prereqResult['message'];
                error_log("Prerequisite blocked (existing student): " . $prereqResult['message']);
                return;
            }

            $validationResult = $this->enrollment->validateEnrollment(
                (int) $existingStudent['student_id'],
                $scheduleIds
            );
            if (!$validationResult['valid']) {
                $_SESSION['message'] = '❌ ' . $validationResult['message'];
                return;
            }
        }

        $requirements          = $_POST['requirements'] ?? [];
        $requirementsChecked   = $this->countCheckedRequirements($requirements);

        $admissionType = $applicantData['admission_type'] ?? 'freshmen';
        $reqList       = $this->requirement->getRequirementsByAdmissionType($admissionType);

        if (!empty($reqList) && $requirementsChecked === 0) {
            $_SESSION['message'] = '❌ Please check at least one requirement before enrolling.';
            return;
        }

        $result = $this->application->convertToStudentAndEnrollWithSubjects(
            $applicantId,
            $sectionId,
            $schoolYear,
            $scheduleIds
        );

        if ($result['success']) {
            if (is_array($requirements) && !empty($requirements)) {
                $studentId = $result['student_id'];
                $reqNotes  = $_POST['req_notes'] ?? [];
                $this->saveRequirements($studentId, $requirements, $reqNotes);
            }

            if (!empty($result['account_created'])) {
                $_SESSION['account_created'] = true;
                $_SESSION['username']        = $result['username'];
                $_SESSION['password']        = $result['password'];
                $_SESSION['is_new_account']  = $result['is_new_account'] ?? false;
                $_SESSION['email_sent']      = $result['email_sent'] ?? false;
                $_SESSION['email_error']     = $result['email_error'] ?? null;
            }

            $subjectCount = count($scheduleIds);
            $message = '✅ Student enrolled successfully! Student Number: ' . $result['student_number'];
            if ($subjectCount > 0) {
                $message .= ' Enrolled in ' . $subjectCount . ' subject(s).';
            }
            $_SESSION['message'] = $message;
        } else {
            $_SESSION['message'] = '❌ ' . $result['message'];
        }
    }

    /* ============================================================
       PROGRESSION ENROLL HANDLER
    ============================================================ */

    private function handleProgressionEnroll() {
        error_log("=== PROGRESSION ENROLL ATTEMPT ===");

        if (!isset($_POST['student_id'], $_POST['section_id'], $_POST['school_year'])) {
            $_SESSION['message'] = '❌ Missing required fields.';
            return;
        }

        $studentId  = (int) $_POST['student_id'];
        $sectionId  = (int) $_POST['section_id'];
        $schoolYear = trim($_POST['school_year']);

        $scheduleIds = [];
        if (!empty($_POST['schedule_ids'])) {
            $scheduleIds = array_map('intval', explode(',', $_POST['schedule_ids']));
        }
        if (empty($scheduleIds) && isset($_POST['schedule_ids_array']) && is_array($_POST['schedule_ids_array'])) {
            $scheduleIds = array_map('intval', $_POST['schedule_ids_array']);
        }
        $scheduleIds = array_values(array_filter($scheduleIds, fn($id) => $id > 0));

        error_log("Student ID: {$studentId}, Section ID: {$sectionId}, SY: {$schoolYear}");
        error_log("Schedule IDs: " . implode(',', $scheduleIds));

        if (empty($scheduleIds)) {
            $_SESSION['message'] = '❌ No subjects selected for enrollment.';
            return;
        }

        $student = $this->student->findById($studentId);
        if (!$student) {
            $_SESSION['message'] = '❌ Student not found.';
            return;
        }

        $sectionCheck = $this->section->findById($sectionId);
        if (!$sectionCheck) {
            $_SESSION['message'] = '❌ Selected section does not exist.';
            return;
        }
        if ((int) $sectionCheck['program_id'] !== (int) $student['course_id']) {
            $_SESSION['message'] = '❌ Course mismatch. Section belongs to a different course.';
            return;
        }

        // ---- STEP 1: Prerequisite check ----
        $prereqResult = $this->prerequisiteValidator->validateScheduleEnrollments(
            $studentId,
            $scheduleIds
        );
        if (!$prereqResult['valid']) {
            $_SESSION['message'] = '❌ ' . $prereqResult['message'];
            error_log("Prerequisite blocked: " . $prereqResult['message']);
            return;
        }

        // ---- STEP 2: Duplicate / general validation ----
        $validationResult = $this->enrollment->validateEnrollment($studentId, $scheduleIds);
        if (!$validationResult['valid']) {
            $_SESSION['message'] = '❌ ' . $validationResult['message'];
            return;
        }

        // ---- STEP 3: Actual enrollment ----
        $result = $this->enrollment->enrollStudentWithSubjects(
            $studentId,
            $sectionId,
            $schoolYear,
            $scheduleIds
        );

        if ($result['success']) {
            $subjectCount = $result['enrolled_count'] ?? count($scheduleIds);
            $message = "✅ Student enrolled in {$subjectCount} subject(s) successfully!";

            if (!empty($result['errors'])) {
                $message .= ' ⚠️ Some subjects failed: ' . implode(', ', $result['errors']);
            }
            $_SESSION['message'] = $message;
            error_log("Progression enroll success: {$subjectCount} subjects");
        } else {
            $_SESSION['message'] = '❌ Enrollment failed: ' . $result['message'];
            error_log("Progression enroll failed: " . $result['message']);
        }
    }

    /* ============================================================
       ARCHIVE / RESTORE / DROP
    ============================================================ */

    private function handleArchive() {
        if (empty($_POST['student_id'])) {
            $_SESSION['message'] = '❌ Missing student ID.';
            return;
        }

        $studentId  = (int) $_POST['student_id'];
        $reason     = trim($_POST['archive_reason'] ?? 'Dropped');
        $schoolYear = $_POST['school_year'] ?? null;

        $validReasons = ['Dropped', 'Transferred', 'LOA', 'Graduated', 'Other'];
        if (!in_array($reason, $validReasons, true)) {
            $reason = 'Dropped';
        }

        if ($this->enrollment->archiveStudent($studentId, $reason, $schoolYear)) {
            $_SESSION['message'] = '✅ Student archived successfully! Pwede pang i-restore.';
            error_log("Archive success: Student {$studentId}, Reason: {$reason}");
        } else {
            $_SESSION['message'] = '❌ Failed to archive student.';
            error_log("Archive failed: Student {$studentId}");
        }
    }

    private function handleRestore() {
        if (empty($_POST['student_id'])) {
            $_SESSION['message'] = '❌ Missing student ID.';
            return;
        }

        $studentId = (int) $_POST['student_id'];

        if ($this->enrollment->restoreStudent($studentId)) {
            $_SESSION['message'] = '✅ Student restored successfully!';
            error_log("Restore success: Student {$studentId}");
        } else {
            $_SESSION['message'] = '❌ Failed to restore student.';
            error_log("Restore failed: Student {$studentId}");
        }
    }

    private function handleDrop() {
        if (empty($_POST['student_id'])) {
            $_SESSION['message'] = '❌ Missing student ID.';
            return;
        }

        $studentId  = (int) $_POST['student_id'];
        $schoolYear = $_POST['school_year'] ?? null;

        if ($this->enrollment->dropStudent($studentId, $schoolYear)) {
            $_SESSION['message'] = '✅ Student dropped from all subjects successfully!';
        } else {
            $_SESSION['message'] = '❌ Failed to drop student.';
        }
    }

    private function handleDropSubject() {
        if (empty($_POST['student_id']) || empty($_POST['schedule_id'])) {
            $_SESSION['message'] = '❌ Missing required fields.';
            return;
        }

        $studentId  = (int) $_POST['student_id'];
        $scheduleId = (int) $_POST['schedule_id'];
        $schoolYear = $_POST['school_year'] ?? null;

        if ($this->enrollment->dropStudentFromSubject($studentId, $scheduleId, $schoolYear)) {
            $_SESSION['message'] = '✅ Student dropped from subject successfully!';
        } else {
            $_SESSION['message'] = '❌ Failed to drop student from subject.';
        }
    }

    private function handleUpdateStatus() {
        if (empty($_POST['enrollment_id']) || empty($_POST['status'])) {
            $_SESSION['message'] = '❌ Missing required fields.';
            return;
        }

        $validStatuses = ['enrolled', 'dropped', 'completed'];
        if (!in_array($_POST['status'], $validStatuses, true)) {
            $_SESSION['message'] = '❌ Invalid status.';
            return;
        }

        try {
            $sql  = "UPDATE enr_enrollments SET enrollment_status = ? WHERE enrollment_id = ?";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$_POST['status'], (int) $_POST['enrollment_id']]);

            $_SESSION['message'] = $result
                ? '✅ Enrollment status updated successfully!'
                : '❌ Failed to update enrollment status.';
        } catch (Exception $e) {
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }
    }

    /* ============================================================
       BULK ENROLL HANDLER
    ============================================================ */

    private function handleBulkEnroll() {
        if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
            $_SESSION['message'] = '❌ No applicants selected for enrollment.';
            return;
        }

        if (empty($_POST['section_id']) || empty($_POST['school_year'])) {
            $_SESSION['message'] = '❌ Missing section or school year.';
            return;
        }

        $scheduleIds = [];
        if (!empty($_POST['schedule_ids'])) {
            $scheduleIds = array_map('intval', explode(',', $_POST['schedule_ids']));
        }
        if (empty($scheduleIds) && isset($_POST['schedule_ids_array']) && is_array($_POST['schedule_ids_array'])) {
            $scheduleIds = array_map('intval', $_POST['schedule_ids_array']);
        }
        $scheduleIds = array_values(array_filter($scheduleIds, fn($id) => $id > 0));

        if (empty($scheduleIds)) {
            $_SESSION['message'] = '❌ No subjects selected for enrollment.';
            return;
        }

        $successCount = 0;
        $failCount    = 0;
        $errors       = [];

        foreach ($_POST['ids'] as $applicantId) {
            $applicantId = (int) $applicantId;

            // ---- Prerequisite check per applicant ----
            $existingStudent = $this->student->findByApplicantId($applicantId);
            if ($existingStudent) {
                $prereqResult = $this->prerequisiteValidator->validateScheduleEnrollments(
                    (int) $existingStudent['student_id'],
                    $scheduleIds
                );
                if (!$prereqResult['valid']) {
                    $failCount++;
                    $errors[] = "Applicant ID {$applicantId}: " . $prereqResult['message'];
                    continue;
                }
            }

            $result = $this->application->convertToStudentAndEnrollWithSubjects(
                $applicantId,
                (int) $_POST['section_id'],
                $_POST['school_year'],
                $scheduleIds
            );

            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                $errors[] = "Applicant ID {$applicantId}: " . $result['message'];
            }
        }

        if ($successCount > 0 && $failCount > 0) {
            $_SESSION['message'] = "⚠️ {$successCount} enrolled, {$failCount} failed. " . implode(' ', $errors);
        } elseif ($successCount > 0) {
            $_SESSION['message'] = "✅ {$successCount} students enrolled successfully.";
        } else {
            $_SESSION['message'] = "❌ Failed to enroll students: " . implode(' ', $errors);
        }
    }

    /* ============================================================
       HELPERS
    ============================================================ */

    private function countCheckedRequirements($requirements) {
        $count = 0;
        if (is_array($requirements) && !empty($requirements)) {
            foreach ($requirements as $reqId => $submitted) {
                if ($submitted == '1' || $submitted === true || $submitted === 'on') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function saveRequirements($studentId, $requirements, $notes) {
        try {
            foreach ($requirements as $reqId => $submitted) {
                $isSubmitted = ($submitted == '1' || $submitted === true || $submitted === 'on') ? 1 : 0;
                $note        = isset($notes[$reqId]) ? trim($notes[$reqId]) : null;

                $existing = $this->requirement->getStudentRequirementStatus($studentId, $reqId);

                if ($existing) {
                    $this->requirement->updateRequirementStatus($studentId, $reqId, $isSubmitted, $note);
                } else {
                    $this->requirement->createRequirementStatus($studentId, $reqId, $isSubmitted, $note);
                }
            }
        } catch (Exception $e) {
            error_log('Error saving requirements: ' . $e->getMessage());
        }
    }

    /**
     * Check if student failed a subject previously.
     * Uses `final_grade` + `remarks='failed'` (lowercase enum).
     */
    private function studentFailedSubject($studentId, $subjectId) {
        try {
            $sql = "SELECT g.final_grade, g.remarks
                    FROM rgr_grades g
                    INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                    INNER JOIN cc_schedule s    ON e.schedule_id = s.id
                    WHERE e.student_id = ?
                      AND s.subject_id = ?
                    ORDER BY e.created_at DESC
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([(int) $studentId, (int) $subjectId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return false;

            // Primary: enum remarks = 'failed'
            if (($row['remarks'] ?? '') === 'failed') {
                return true;
            }

            // Fallback: final_grade <= 75
            if ($row['final_grade'] !== null && (float) $row['final_grade'] <= 75) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            error_log('studentFailedSubject() error: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       PUBLIC METHODS FOR VIEWS
    ============================================================ */

    public function getPendingApplicants($search = '') {
        try {
            if (!empty($search)) {
                $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                        FROM enr_applicants a
                        LEFT JOIN rgr_courses c ON a.course_id = c.id
                        WHERE a.status = 'pending'
                          AND (a.first_name LIKE ?
                            OR a.surname LIKE ?
                            OR a.email LIKE ?
                            OR a.contact_number LIKE ?)
                        ORDER BY a.submitted_at DESC";
                $stmt       = $this->db->prepare($sql);
                $searchTerm = '%' . $search . '%';
                $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
                return $stmt->fetchAll();
            }

            return $this->application->getPendingApplications();
        } catch (Exception $e) {
            error_log('Error getting pending applicants: ' . $e->getMessage());
            return [];
        }
    }

    public function getAllCourses() {
        try {
            return $this->course->findAll();
        } catch (Exception $e) {
            error_log('Error getting all courses: ' . $e->getMessage());
            return [];
        }
    }

    public function getEnrolledStudents($courseId = null, $yearLevel = null) {
        try {
            $sql = "SELECT
                        s.student_id,
                        s.student_number,
                        a.first_name,
                        a.middle_name,
                        a.surname,
                        a.suffix,
                        s.year_level,
                        s.section_id,
                        sec.section_code,
                        sec.grade_level,
                        sem.name AS section_semester,
                        sy.name  AS school_year,
                        c.code as course_code,
                        c.name as course_name,
                        (
                            SELECT COUNT(DISTINCT e2.schedule_id)
                            FROM enr_enrollments e2
                            WHERE e2.student_id = s.student_id
                              AND e2.section_id = s.section_id
                              AND e2.enrollment_status = 'enrolled'
                        ) as subject_count,
                        (
                            SELECT COUNT(DISTINCT e3.schedule_id)
                            FROM enr_enrollments e3
                            WHERE e3.student_id = s.student_id
                              AND e3.enrollment_status = 'enrolled'
                        ) as total_subject_enrollments
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    JOIN cc_sections sec ON s.section_id = sec.id
                    JOIN rgr_courses c ON sec.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.student_id IN (
                        SELECT DISTINCT student_id
                        FROM enr_enrollments
                        WHERE enrollment_status = 'enrolled'
                    )
                    AND s.archived_at IS NULL";

            $params = [];

            if (!empty($courseId)) {
                $sql     .= " AND c.id = ?";
                $params[] = $courseId;
            }

            if (!empty($yearLevel)) {
                $sql     .= " AND s.year_level = ?";
                $params[] = $yearLevel;
            }

            $sql .= " GROUP BY s.student_id
                      ORDER BY a.surname, a.first_name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getting enrolled students: ' . $e->getMessage());
            return [];
        }
    }

    public function getEnrollmentStats() {
        try {
            $stats = [
                'total_enrolled'            => 0,
                'total_subject_enrollments' => 0,
                'total_sections'            => 0,
                'pending_applications'      => 0,
                'recent_enrollments'        => 0
            ];

            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT student_id) as count
                                        FROM enr_enrollments WHERE enrollment_status = 'enrolled'");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['total_enrolled'] = (int) ($result['count'] ?? 0);

            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT schedule_id) as count
                                        FROM enr_enrollments WHERE enrollment_status = 'enrolled'");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['total_subject_enrollments'] = (int) ($result['count'] ?? 0);

            $stmt = $this->db->prepare("SELECT COUNT(*) as count
                                        FROM enr_applicants WHERE status = 'pending'");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['pending_applications'] = (int) ($result['count'] ?? 0);

            $stmt = $this->db->prepare("SELECT COUNT(*) as total_sections FROM cc_sections");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['total_sections'] = (int) ($result['total_sections'] ?? 0);

            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT student_id) as count
                                        FROM enr_enrollments
                                        WHERE enrollment_status = 'enrolled'
                                          AND enrollment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['recent_enrollments'] = (int) ($result['count'] ?? 0);

            return $stats;
        } catch (Exception $e) {
            error_log('Error getting enrollment stats: ' . $e->getMessage());
            return [
                'total_enrolled'            => 0,
                'total_subject_enrollments' => 0,
                'total_sections'            => 0,
                'pending_applications'      => 0,
                'recent_enrollments'        => 0
            ];
        }
    }

    public function getSectionsForApplicant($applicantId) {
        try {
            $applicantData = $this->application->getApplicationById($applicantId);
            if (!$applicantData || empty($applicantData['course_id'])) {
                return [
                    'success'  => false,
                    'message'  => 'Applicant not found or has no course',
                    'sections' => []
                ];
            }

            $yearLevel = '1st Year';
            $semester  = '1st Semester';

            if (!empty($applicantData['preferred_section_id'])) {
                $prefSection = $this->section->getSectionDetails((int) $applicantData['preferred_section_id']);
                if ($prefSection) {
                    $yearNum   = $this->section->convertYearLevelToNumeric($prefSection['grade_level'] ?? '1st Year');
                    $yearLevel = $this->section->convertYearLevelToText($yearNum);

                    $semText  = strtolower($prefSection['semester'] ?? '');
                    $semester = (strpos($semText, 'second') !== false || strpos($semText, '2nd') !== false)
                        ? '2nd Semester'
                        : '1st Semester';
                }
            }

            $sections = $this->section->getSectionsByCourseAndYearLevel(
                $applicantData['course_id'],
                $yearLevel,
                $semester
            );
            if (!is_array($sections)) $sections = [];

            $formattedSections = array_map(function ($section) {
                return [
                    'section_id'       => $section['id'],
                    'section_code'     => $section['section_code'],
                    'grade_level'      => $section['grade_level'],
                    'semester'         => $section['semester'],
                    'school_year'      => $section['school_year'],
                    'max_students'     => 40,
                    'current_students' => 0,
                    'available_slots'  => 40,
                    'course_code'      => $section['course_code'] ?? '',
                    'course_name'      => $section['course_name'] ?? ''
                ];
            }, $sections);

            return [
                'success'        => true,
                'sections'       => array_values($formattedSections),
                'course_id'      => (int) $applicantData['course_id'],
                'course_code'    => $applicantData['course_code'] ?? '',
                'course_name'    => $applicantData['course_name'] ?? '',
                'year_level'     => $yearLevel,
                'semester'       => $semester,
                'applicant_name' => $applicantData['first_name'] . ' ' . $applicantData['surname']
            ];
        } catch (Exception $e) {
            error_log('Error getting sections for applicant: ' . $e->getMessage());
            return [
                'success'  => false,
                'message'  => 'Error loading sections: ' . $e->getMessage(),
                'sections' => []
            ];
        }
    }

    public function getArchivedStudents($courseId = null, $yearLevel = null) {
        return $this->enrollment->getArchivedStudents($courseId, $yearLevel);
    }

    public function getArchivedStudentCount() {
        return $this->enrollment->getArchivedStudentCount();
    }

    public function getEnrollmentByStudentId($studentId) {
        return $this->enrollment->getEnrollmentByStudentId($studentId);
    }

    /* ============================================================
       SEARCH ENROLLMENTS
       FIX: `e.semester` → `e.semester_id`; add esem JOIN
    ============================================================ */

    public function searchEnrollments($keyword) {
        try {
            $sql = "SELECT
                        e.enrollment_id,
                        e.student_id,
                        e.section_id,
                        e.school_year,
                        e.semester_id,
                        e.schedule_id,
                        e.enrollment_status,
                        e.enrollment_date,
                        e.created_at,
                        s.student_number,
                        s.year_level,
                        a.first_name,
                        a.middle_name,
                        a.surname,
                        a.suffix,
                        rs.code AS subject_code,
                        rs.name AS subject_name,
                        rs.units,
                        sec.section_code,
                        sec.grade_level,
                        sem.name  AS section_semester,
                        esem.name AS enrollment_semester,
                        sy.name   AS school_year_name,
                        c.code as course_code,
                        c.name as course_name,
                        cs.day_of_week,
                        cs.start_time,
                        cs.end_time
                    FROM enr_enrollments e
                    JOIN enr_students s ON e.student_id = s.student_id
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    JOIN cc_schedule cs ON e.schedule_id = cs.id
                    JOIN rgr_subjects rs ON cs.subject_id = rs.id
                    JOIN cc_sections sec ON e.section_id = sec.id
                    JOIN rgr_courses c ON sec.program_id = c.id
                    LEFT JOIN rgr_semesters sem  ON sec.semester_id = sem.id
                    LEFT JOIN rgr_semesters esem ON e.semester_id   = esem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE e.enrollment_status = 'enrolled'
                      AND (a.first_name LIKE ?
                        OR a.surname LIKE ?
                        OR s.student_number LIKE ?
                        OR c.code LIKE ?
                        OR rs.code LIKE ?
                        OR rs.name LIKE ?)
                    ORDER BY a.surname, a.first_name";

            $stmt   = $this->db->prepare($sql);
            $search = '%' . $keyword . '%';
            $stmt->execute([$search, $search, $search, $search, $search, $search]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error searching enrollments: ' . $e->getMessage());
            return [];
        }
    }
}