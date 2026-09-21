<?php
// classes/RequirementController.php - FULLY VERIFIED for `kms` schema
//
// No schema fixes required. This version only hardens ID casts
// and null-safe reads; behavior is unchanged.

require_once 'Requirement.php';
require_once 'Student.php';
require_once 'Application.php';
require_once 'Enrollment.php';
require_once 'Database.php';

class RequirementController {
    private $requirement;
    private $student;
    private $application;
    private $enrollment;
    private $db;

    public function __construct() {
        $this->requirement = new Requirement();
        $this->student     = new Student();
        $this->application = new Application();
        $this->enrollment  = new Enrollment();
        $this->db          = Database::getInstance();
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
        try {
            if (empty($_POST['action'])) {
                $_SESSION['message'] = '❌ No action specified.';
                $this->redirectBack();
                return;
            }

            switch ($_POST['action']) {
                case 'update_requirements':        $this->handleUpdateRequirements();        break;
                case 'add_requirement':            $this->handleAddRequirement();            break;
                case 'delete_requirement':         $this->handleDeleteRequirement();         break;
                case 'update_requirement':         $this->handleUpdateRequirement();         break;
                case 'update_followup':            $this->handleUpdateFollowup();            break;
                case 'mark_followup_done':         $this->handleMarkFollowupDone();          break;
                case 'bulk_update_requirements':   $this->handleBulkUpdateRequirements();    break;

                default:
                    $_SESSION['message'] = '❌ Invalid action: ' . $_POST['action'];
            }
        } catch (Exception $e) {
            error_log('POST Error in RequirementController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        $this->redirectBack();
    }

    private function handleUpdateRequirements() {
        if (empty($_POST['student_id']) || !isset($_POST['requirements'])) {
            $_SESSION['message'] = '❌ Missing required fields.';
            return;
        }

        $studentId = (int) $_POST['student_id'];
        $updated   = 0;
        $failed    = 0;

        foreach ((array) $_POST['requirements'] as $reqId => $submitted) {
            $isSubmitted = ($submitted == '1' || $submitted === true || $submitted === 'on') ? 1 : 0;
            $notes       = isset($_POST['req_notes'][$reqId])
                ? trim($_POST['req_notes'][$reqId])
                : null;

            if ($this->requirement->updateOrCreateRequirementStatus(
                $studentId,
                (int) $reqId,
                $isSubmitted,
                $notes
            )) {
                $updated++;
            } else {
                $failed++;
            }
        }

        $_SESSION['message'] = "✅ {$updated} requirements updated successfully"
            . ($failed > 0 ? " ({$failed} failed)" : "");
        $_SESSION['last_student_id'] = $studentId;
    }

    private function handleAddRequirement() {
        if (empty($_POST['requirement_name'])) {
            $_SESSION['message'] = '❌ Requirement name is required.';
            return;
        }
        if (empty($_POST['requirement_category'])) {
            $_SESSION['message'] = '❌ Category is required.';
            return;
        }

        $data = [
            'requirement_name'     => trim($_POST['requirement_name']),
            'requirement_category' => $_POST['requirement_category'],
            'is_mandatory'         => isset($_POST['is_mandatory']) ? 1 : 0
        ];

        $result = $this->requirement->addRequirement($data);

        $_SESSION['message'] = (!empty($result['success']) ? '✅ ' : '❌ ')
            . ($result['message'] ?? 'Unknown error');
    }

    private function handleDeleteRequirement() {
        if (empty($_POST['requirement_id'])) {
            $_SESSION['message'] = '❌ Requirement ID required.';
            return;
        }

        $result = $this->requirement->deleteRequirement((int) $_POST['requirement_id']);

        $_SESSION['message'] = (!empty($result['success']) ? '✅ ' : '❌ ')
            . ($result['message'] ?? 'Unknown error');
    }

    private function handleUpdateRequirement() {
        if (empty($_POST['requirement_id'])) {
            $_SESSION['message'] = '❌ Requirement ID required.';
            return;
        }
        if (empty($_POST['requirement_name'])) {
            $_SESSION['message'] = '❌ Requirement name is required.';
            return;
        }
        if (empty($_POST['requirement_category'])) {
            $_SESSION['message'] = '❌ Category is required.';
            return;
        }

        $data = [
            'requirement_name'     => trim($_POST['requirement_name']),
            'requirement_category' => $_POST['requirement_category'],
            'is_mandatory'         => isset($_POST['is_mandatory']) ? 1 : 0
        ];

        $result = $this->requirement->updateRequirement(
            (int) $_POST['requirement_id'],
            $data
        );

        $_SESSION['message'] = (!empty($result['success']) ? '✅ ' : '❌ ')
            . ($result['message'] ?? 'Unknown error');
    }

    private function handleUpdateFollowup() {
        if (empty($_POST['student_id'])) {
            $_SESSION['message'] = '❌ Student ID required.';
            return;
        }

        $studentId     = (int) $_POST['student_id'];
        $followupDate  = !empty($_POST['followup_date']) ? $_POST['followup_date'] : date('Y-m-d');
        $followupNotes = isset($_POST['followup_notes']) ? trim($_POST['followup_notes']) : null;

        try {
            $sql = "UPDATE enr_students
                    SET followup_date = ?,
                        followup_notes = ?,
                        followup_status = 'pending'
                    WHERE student_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$followupDate, $followupNotes, $studentId]);

            $_SESSION['message']         = '✅ Follow-up scheduled for '
                . date('M d, Y', strtotime($followupDate));
            $_SESSION['last_student_id'] = $studentId;
        } catch (Exception $e) {
            error_log('Error updating followup: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Failed to update follow-up.';
        }
    }

    private function handleMarkFollowupDone() {
        if (empty($_POST['student_id'])) {
            $_SESSION['message'] = '❌ Student ID required.';
            return;
        }

        $studentId = (int) $_POST['student_id'];

        try {
            $sql  = "UPDATE enr_students SET followup_status = 'done' WHERE student_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$studentId]);

            $_SESSION['message']         = '✅ Follow-up marked as completed!';
            $_SESSION['last_student_id'] = $studentId;
        } catch (Exception $e) {
            error_log('Error marking followup done: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Failed to mark follow-up as completed.';
        }
    }

    private function handleBulkUpdateRequirements() {
        if (!isset($_POST['student_ids']) || !is_array($_POST['student_ids']) || empty($_POST['student_ids'])) {
            $_SESSION['message'] = '❌ No students selected.';
            return;
        }
        if (empty($_POST['requirement_id'])) {
            $_SESSION['message'] = '❌ Requirement ID required.';
            return;
        }

        $requirementId = (int) $_POST['requirement_id'];
        $isSubmitted   = isset($_POST['is_submitted']) ? (bool) $_POST['is_submitted'] : false;
        $notes         = isset($_POST['notes']) ? trim($_POST['notes']) : null;
        $updated       = 0;
        $failed        = 0;

        foreach ($_POST['student_ids'] as $studentId) {
            if ($this->requirement->updateOrCreateRequirementStatus(
                (int) $studentId,
                $requirementId,
                $isSubmitted,
                $notes
            )) {
                $updated++;
            } else {
                $failed++;
            }
        }

        $_SESSION['message'] = "✅ {$updated} students updated"
            . ($failed > 0 ? " ({$failed} failed)" : "");
    }

    private function redirectBack() {
        $redirect = $_POST['redirect'] ?? null;

        if (!$redirect) {
            $view      = $_GET['view'] ?? 'students';
            $studentId = $_SESSION['last_student_id']
                      ?? ($_POST['student_id'] ?? null);

            $redirect = '?page=requirements&view=' . $view;
            if ($studentId) {
                $redirect .= '&student_id=' . $studentId;
            }
            if (isset($_GET['category'])) {
                $redirect .= '&category=' . urlencode($_GET['category']);
            }
            if (!empty($_GET['search'])) {
                $redirect .= '&search=' . urlencode($_GET['search']);
            }
        }

        header('Location: ' . $redirect);
        exit;
    }

    /* ============================================================
       AJAX ROUTER
    ============================================================ */

    private function handleAjaxRequest() {
        header('Content-Type: application/json');

        try {
            if (empty($_GET['ajax'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid AJAX request']);
                exit;
            }

            switch ($_GET['ajax']) {
                case 'get_student_requirements':           $this->ajaxGetStudentRequirements();           break;
                case 'update_requirement':                 $this->ajaxUpdateRequirement();                 break;
                case 'get_completion_status':              $this->ajaxGetCompletionStatus();              break;
                case 'search_students':                    $this->ajaxSearchStudents();                    break;
                case 'get_requirements_by_category':       $this->ajaxGetRequirementsByCategory();       break;
                case 'get_requirements_summary':           $this->ajaxGetRequirementsSummary();           break;
                case 'get_incomplete_students':            $this->ajaxGetIncompleteStudents();            break;
                case 'get_student_enrollment_summary':     $this->ajaxGetStudentEnrollmentSummary();     break;

                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
            }
        } catch (Exception $e) {
            error_log('AJAX Error in RequirementController: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    private function ajaxGetStudentRequirements() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId    = (int) $_GET['student_id'];
        $requirements = $this->requirement->getStudentRequirementsWithStatus($studentId);
        $completion   = $this->requirement->getRequirementCompletionStatus($studentId);

        if (!is_array($requirements)) $requirements = [];

        echo json_encode([
            'success' => true,
            'data' => [
                'requirements' => $requirements,
                'completion'   => $completion
            ]
        ]);
    }

    private function ajaxUpdateRequirement() {
        if (empty($_POST['student_id']) || empty($_POST['requirement_id'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        $studentId     = (int) $_POST['student_id'];
        $requirementId = (int) $_POST['requirement_id'];
        $isSubmitted   = isset($_POST['is_submitted']) ? (bool) $_POST['is_submitted'] : false;
        $notes         = isset($_POST['notes']) ? trim($_POST['notes']) : null;

        $result = $this->requirement->updateOrCreateRequirementStatus(
            $studentId,
            $requirementId,
            $isSubmitted,
            $notes
        );

        if ($result) {
            $completion = $this->requirement->getRequirementCompletionStatus($studentId);
            echo json_encode([
                'success'    => true,
                'message'    => 'Requirement updated successfully',
                'completion' => $completion
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update requirement']);
        }
    }

    private function ajaxGetCompletionStatus() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId          = (int) $_GET['student_id'];
        $completion         = $this->requirement->getRequirementCompletionStatus($studentId);
        $mandatoryCompleted = $this->requirement->hasCompletedMandatoryRequirements($studentId);

        echo json_encode([
            'success' => true,
            'data' => [
                'completion'          => $completion,
                'mandatory_completed' => $mandatoryCompleted
            ]
        ]);
    }

    private function ajaxSearchStudents() {
        if (empty($_GET['keyword'])) {
            echo json_encode(['success' => false, 'message' => 'Search keyword required']);
            return;
        }

        $keyword  = trim($_GET['keyword']);
        $students = $this->searchStudentsInDatabase($keyword);
        if (!is_array($students)) $students = [];

        echo json_encode(['success' => true, 'data' => $students]);
    }

    private function ajaxGetRequirementsByCategory() {
        if (empty($_GET['category'])) {
            echo json_encode(['success' => false, 'message' => 'Category required']);
            return;
        }

        $requirements = $this->requirement->getRequirementsByCategory($_GET['category']);
        if (!is_array($requirements)) $requirements = [];

        echo json_encode(['success' => true, 'data' => $requirements]);
    }

    private function ajaxGetRequirementsSummary() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId    = (int) $_GET['student_id'];
        $summary      = $this->requirement->getStudentRequirementsSummary($studentId);
        $requirements = $this->requirement->getRequirementsWithStudentStatus($studentId);

        if (!is_array($requirements)) $requirements = [];

        echo json_encode([
            'success' => true,
            'data' => [
                'summary'      => $summary,
                'requirements' => $requirements
            ]
        ]);
    }

    private function ajaxGetIncompleteStudents() {
        $admissionType = $_GET['admission_type'] ?? null;
        $students      = $this->requirement->getStudentsWithIncompleteRequirements($admissionType);
        if (!is_array($students)) $students = [];

        echo json_encode([
            'success' => true,
            'data'    => $students,
            'count'   => count($students)
        ]);
    }

    private function ajaxGetStudentEnrollmentSummary() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId = (int) $_GET['student_id'];
        $summary   = $this->requirement->getStudentEnrolledSubjectsSummary($studentId);

        echo json_encode([
            'success' => true,
            'data'    => $summary
        ]);
    }

    /* ============================================================
       PUBLIC METHODS FOR VIEWS
    ============================================================ */

    public function getAllStudents() {
        return $this->student->findAll();
    }

    public function getAllStudentsWithSearch($searchQuery = '') {
        if (empty($searchQuery)) {
            return $this->student->findAll();
        }
        return $this->searchStudentsInDatabase($searchQuery);
    }

    public function searchStudentsInDatabase($keyword) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           a.applicant_id,
                           a.admission_type,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level, sec.semester, sec.school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as enrolled_subjects
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    WHERE s.student_number LIKE ?
                       OR a.first_name LIKE ?
                       OR a.middle_name LIKE ?
                       OR a.surname LIKE ?
                       OR CONCAT(a.first_name, ' ', a.surname) LIKE ?
                       OR CONCAT(a.surname, ', ', a.first_name) LIKE ?
                       OR CONCAT(a.first_name, ' ', a.middle_name, ' ', a.surname) LIKE ?
                       OR c.code LIKE ?
                       OR c.name LIKE ?
                       OR sec.section_code LIKE ?
                    ORDER BY a.surname, a.first_name";
            $stmt   = $this->db->prepare($sql);
            $search = '%' . $keyword . '%';
            $stmt->execute([$search, $search, $search, $search, $search, $search, $search, $search, $search, $search]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error searching students: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentDetails($studentId) {
        $student = $this->student->getStudentDetails($studentId);

        if ($student) {
            $student['requirements_completion'] = $this->requirement->getRequirementCompletionStatus($studentId);
            $student['mandatory_completed']     = $this->requirement->hasCompletedMandatoryRequirements($studentId);
            $student['enrollment_summary']      = $this->requirement->getStudentEnrolledSubjectsSummary($studentId);
        }

        return $student;
    }

    public function getStudentRequirements($studentId, $category = null) {
        if ($category) {
            return $this->requirement->getStudentRequirementsByCategory($studentId, $category);
        }
        return $this->requirement->getStudentRequirements($studentId);
    }

    public function getStudentRequirementsWithStatus($studentId) {
        return $this->requirement->getStudentRequirementsWithStatus($studentId);
    }

    public function getCompletionStatus($studentId) {
        return $this->requirement->getRequirementCompletionStatus($studentId);
    }

    public function getAllCategories() {
        return $this->requirement->getAllCategories();
    }

    public function getAllRequirementsGrouped() {
        $result     = [];
        $categories = $this->requirement->getAllCategories();
        if (!is_array($categories)) $categories = [];

        foreach ($categories as $cat) {
            $category         = $cat['requirement_category'];
            $result[$category] = $this->requirement->getRequirementsByCategory($category);
        }
        return $result;
    }

    public function getApplicationById($applicantId) {
        try {
            return $this->application->getApplicationById($applicantId);
        } catch (Exception $e) {
            error_log('Error getting application by ID: ' . $e->getMessage());
            return null;
        }
    }

    public function getStudentsWithIncomplete() {
        return $this->requirement->getStudentsWithIncompleteRequirements();
    }

    public function getFollowupStudents() {
        try {
            $sql = "SELECT DISTINCT s.student_id, s.student_number,
                            a.first_name, a.middle_name, a.surname, a.suffix,
                            c.code as course_code, c.name as course_name,
                            a.admission_type,
                            sec.section_code, sec.grade_level,
                            s.followup_date, s.followup_notes, s.followup_status,
                            (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                             WHERE e.student_id = s.student_id
                               AND e.enrollment_status = 'enrolled') as enrolled_subjects,
                            (SELECT COUNT(*) FROM enr_student_requirements sr
                             JOIN enr_requirements r ON sr.requirement_id = r.requirement_id
                             WHERE sr.student_id = s.student_id
                               AND r.is_mandatory = 1
                               AND r.requirement_category = CASE
                                     WHEN a.admission_type = 'returnee'    THEN 'continuing'
                                     WHEN a.admission_type = 'senior_high' THEN 'freshmen'
                                     ELSE a.admission_type
                                   END
                               AND sr.is_submitted = 1) as submitted_mandatory,
                            (SELECT COUNT(*) FROM enr_student_requirements sr
                             JOIN enr_requirements r ON sr.requirement_id = r.requirement_id
                             WHERE sr.student_id = s.student_id
                               AND r.is_mandatory = 1
                               AND r.requirement_category = CASE
                                     WHEN a.admission_type = 'returnee'    THEN 'continuing'
                                     WHEN a.admission_type = 'senior_high' THEN 'freshmen'
                                     ELSE a.admission_type
                                   END) as total_mandatory
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    WHERE s.followup_status = 'pending'
                      AND s.followup_date IS NOT NULL
                      AND s.followup_date <= CURDATE()
                    ORDER BY s.followup_date ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getting followup students: ' . $e->getMessage());
            return [];
        }
    }

    public function handleAutoSelectOnSearch($searchQuery, $allStudents, $studentId, $categoryFilter = null) {
        $result = [
            'redirect'   => false,
            'url'        => '',
            'student_id' => $studentId
        ];

        if (!empty($searchQuery) && count($allStudents) === 1 && empty($studentId)) {
            $redirectKey = 'auto_select_' . md5($searchQuery);

            if (isset($_SESSION[$redirectKey]) && $_SESSION[$redirectKey] > time() - 5) {
                unset($_SESSION[$redirectKey]);
                return $result;
            }

            $_SESSION[$redirectKey] = time();

            $autoSelectedId        = $allStudents[0]['student_id'];
            $result['student_id']  = $autoSelectedId;
            $result['redirect']    = true;

            $redirectUrl = '?page=requirements&view=students&student_id='
                . $autoSelectedId . '&search=' . urlencode($searchQuery);
            if ($categoryFilter) {
                $redirectUrl .= '&category=' . urlencode($categoryFilter);
            }
            $result['url'] = $redirectUrl;
        } else {
            if (!empty($searchQuery)) {
                $redirectKey = 'auto_select_' . md5($searchQuery);
                unset($_SESSION[$redirectKey]);
            }
        }

        return $result;
    }

    public function processAutoSelect($searchQuery, $allStudents, $studentId, $categoryFilter = null) {
        if (!empty($studentId)) {
            return $studentId;
        }

        $result = $this->handleAutoSelectOnSearch($searchQuery, $allStudents, $studentId, $categoryFilter);

        if (!empty($result['redirect'])) {
            header('Location: ' . $result['url']);
            exit;
        }

        return $result['student_id'];
    }

    public function getRequirementCountByCategory() {
        return $this->requirement->getRequirementCountByCategory();
    }

    public function getStudentProgressByCategory($studentId) {
        return $this->requirement->getStudentProgressByCategory($studentId);
    }

    public function getTotalStudentsCount() {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_students";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error getting total students count: ' . $e->getMessage());
            return 0;
        }
    }

    public function getRequirementsSummary() {
        try {
            $sql = "SELECT
                        COUNT(*) as total_requirements,
                        SUM(is_mandatory) as mandatory_count,
                        COUNT(DISTINCT requirement_category) as category_count
                    FROM enr_requirements";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error getting requirements summary: ' . $e->getMessage());
            return ['total_requirements' => 0, 'mandatory_count' => 0, 'category_count' => 0];
        }
    }

    public function getRequirementsWithCountByCategory() {
        $categories = $this->requirement->getAllCategories();
        if (!is_array($categories)) $categories = [];

        $result = [];

        foreach ($categories as $cat) {
            $category     = $cat['requirement_category'];
            $requirements = $this->requirement->getRequirementsByCategory($category);
            if (!is_array($requirements)) $requirements = [];

            $result[] = [
                'category'        => $category,
                'requirements'    => $requirements,
                'count'           => count($requirements),
                'mandatory_count' => count(array_filter($requirements, function ($r) {
                    return !empty($r['is_mandatory']);
                }))
            ];
        }

        return $result;
    }

    public function getStudentEnrollmentSummary($studentId) {
        return $this->requirement->getStudentEnrolledSubjectsSummary($studentId);
    }
}