<?php
// classes/StudentController.php - FULLY FIXED for `kms` schema (no new columns)
//
// KEY FIX:
//   cc_sections has NO `semester` / `school_year` columns.
//   getStudents(), getStudentsByYearLevel(), getRecentStudents()
//   now JOIN to rgr_semesters / rgr_school_years and alias `name`.

require_once 'Student.php';
require_once 'Course.php';
require_once 'Section.php';
require_once 'Database.php';
require_once 'Requirement.php';

class StudentController {
    private $student;
    private $course;
    private $section;
    private $db;
    private $requirement;

    public function __construct() {
        $this->student     = new Student();
        $this->course      = new Course();
        $this->section     = new Section();
        $this->db          = Database::getInstance();
        $this->requirement = new Requirement();
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

    private function handleAjaxRequest() {
        header('Content-Type: application/json');

        try {
            if (empty($_GET['ajax'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
                exit;
            }

            switch ($_GET['ajax']) {
                case 'get_students':                    $this->ajaxGetStudents();                    break;
                case 'get_student_details':             $this->ajaxGetStudentDetails();             break;
                case 'get_student_stats':               $this->ajaxGetStudentStats();               break;
                case 'search_students':                 $this->ajaxSearchStudents();                break;
                case 'get_students_by_course':          $this->ajaxGetStudentsByCourse();           break;
                case 'update_status':                   $this->ajaxUpdateStatus();                  break;
                case 'update_section':                  $this->ajaxUpdateSection();                 break;
                case 'get_students_by_section':         $this->ajaxGetStudentsBySection();          break;
                case 'get_unenrolled_students':         $this->ajaxGetUnenrolledStudents();         break;
                case 'get_students_with_enrollments':   $this->ajaxGetStudentsWithEnrollments();    break;
                case 'get_student_schedule':            $this->ajaxGetStudentSchedule();            break;
                case 'get_student_requirements':        $this->ajaxGetStudentRequirements();        break;
                case 'get_student_progression':         $this->ajaxGetStudentProgression();         break;

                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
            }
        } catch (Exception $e) {
            error_log('AJAX Error in StudentController: ' . $e->getMessage());
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

    private function ajaxGetStudents() {
        $search    = $_GET['search']     ?? '';
        $courseId  = isset($_GET['course_id'])  ? (int) $_GET['course_id']  : null;
        $sectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;
        $status    = $_GET['status']     ?? null;

        $students = $this->getStudents($search, $courseId, $sectionId, $status);
        if (!is_array($students)) $students = [];

        echo json_encode([
            'success' => true,
            'data'    => $students,
            'count'   => count($students)
        ]);
    }

    private function ajaxGetStudentDetails() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $student = $this->getStudentById((int) $_GET['student_id']);
        if ($student) {
            echo json_encode(['success' => true, 'data' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
        }
    }

    private function ajaxGetStudentStats() {
        echo json_encode([
            'success' => true,
            'data'    => $this->getStudentStats()
        ]);
    }

    private function ajaxSearchStudents() {
        if (empty($_GET['keyword'])) {
            echo json_encode(['success' => false, 'message' => 'Search keyword required']);
            return;
        }

        $students = $this->student->searchStudents($_GET['keyword']);
        if (!is_array($students)) $students = [];

        echo json_encode([
            'success' => true,
            'data'    => $students,
            'count'   => count($students)
        ]);
    }

    private function ajaxGetStudentsByCourse() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        echo json_encode([
            'success' => true,
            'data'    => $this->getStudentsByCourse((int) $_GET['course_id'])
        ]);
    }

    private function ajaxUpdateStatus() {
        if (empty($_POST['student_id']) || empty($_POST['status'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID and status required']);
            return;
        }

        $validStatuses = ['enrolled', 'on_leave', 'graduated', 'dropped'];
        if (!in_array($_POST['status'], $validStatuses, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            return;
        }

        $result = $this->student->updateEnrollmentStatus(
            (int) $_POST['student_id'],
            $_POST['status']
        );

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Status updated successfully',
                'data'    => $this->getStudentById((int) $_POST['student_id'])
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }
    }

    private function ajaxUpdateSection() {
        if (empty($_POST['student_id']) || empty($_POST['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID and section ID required']);
            return;
        }

        $result = $this->student->updateSection(
            (int) $_POST['student_id'],
            (int) $_POST['section_id']
        );

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Section updated successfully',
                'data'    => $this->getStudentById((int) $_POST['student_id'])
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update section']);
        }
    }

    private function ajaxGetStudentsBySection() {
        if (empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID required']);
            return;
        }

        echo json_encode([
            'success' => true,
            'data'    => $this->getStudentsBySection((int) $_GET['section_id'])
        ]);
    }

    private function ajaxGetUnenrolledStudents() {
        $search   = $_GET['search'] ?? '';
        $students = $this->student->getUnenrolledStudents($search);
        if (!is_array($students)) $students = [];

        echo json_encode([
            'success' => true,
            'data'    => $students,
            'count'   => count($students)
        ]);
    }

    private function ajaxGetStudentsWithEnrollments() {
        $schoolYear = $_GET['school_year'] ?? null;
        $students   = $this->student->getStudentsWithSubjectEnrollments($schoolYear);
        if (!is_array($students)) $students = [];

        echo json_encode([
            'success' => true,
            'data'    => $students,
            'count'   => count($students)
        ]);
    }

    private function ajaxGetStudentSchedule() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId  = (int) $_GET['student_id'];
        $schoolYear = $_GET['school_year'] ?? null;

        try {
            require_once 'Enrollment.php';
            $enrollment = new Enrollment();
            $schedule   = $enrollment->getStudentSchedule($studentId, $schoolYear);
            $grouped    = $enrollment->getStudentScheduleByDay($studentId, $schoolYear);

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

    private function ajaxGetStudentRequirements() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId = (int) $_GET['student_id'];

        try {
            $completion   = $this->requirement->getRequirementCompletionStatus($studentId);
            $requirements = $this->requirement->getStudentRequirements($studentId);

            $total     = (int) ($completion['total']     ?? 0);
            $submitted = (int) ($completion['submitted'] ?? 0);

            echo json_encode([
                'success' => true,
                'data' => [
                    'completion'       => $completion,
                    'requirements'     => $requirements,
                    'is_complete'      => $total > 0 && $submitted === $total,
                    'has_requirements' => $total > 0
                ]
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetStudentRequirements: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading requirements: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetStudentProgression() {
        if (empty($_GET['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            return;
        }

        $studentId = (int) $_GET['student_id'];

        try {
            require_once 'StudentProgression.php';
            require_once 'Enrollment.php';

            $progression = new StudentProgression();
            $enrollment  = new Enrollment();

            $current = $enrollment->getStudentCurrentProgression($studentId);
            $next    = $enrollment->getStudentNextProgression($studentId);
            $student = $this->student->findById($studentId);

            echo json_encode([
                'success' => true,
                'data' => [
                    'student'            => $student,
                    'current_year_level' => $current ? $current['year_level'] : null,
                    'current_semester'   => $current ? $current['semester_name'] : null,
                    'next_year_level'    => $next ? $next['year_level'] : null,
                    'next_semester'      => $next ? $progression->getSemesterName($next['semester']) : null,
                    'is_completed'       => $next ? ($next['is_completed'] ?? false) : false
                ]
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetStudentProgression: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error loading progression: ' . $e->getMessage()
            ]);
        }
    }

    /* ============================================================
       POST
    ============================================================ */

    private function handlePostRequest() {
        try {
            if (empty($_POST['action'])) {
                $_SESSION['message'] = '❌ No action specified.';
                $this->redirectBack();
                return;
            }

            switch ($_POST['action']) {
                case 'update_status':       $this->handleUpdateStatus();       break;
                case 'update_section':      $this->handleUpdateSection();      break;
                case 'update_followup':     $this->handleUpdateFollowup();     break;
                case 'mark_followup_done':  $this->handleMarkFollowupDone();   break;
                case 'bulk_update_status':  $this->handleBulkUpdateStatus();   break;

                default:
                    $_SESSION['message'] = '❌ Invalid action specified.';
            }
        } catch (Exception $e) {
            error_log('POST Error in StudentController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        $this->redirectBack();
    }

    private function redirectBack() {
        $redirect = $_POST['redirect'] ?? '?page=students';
        header('Location: ' . $redirect);
        exit;
    }

    /* ============================================================
       GET
    ============================================================ */

    private function handleGetRequest() {
        try {
            if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
                $_SESSION['view_student_id'] = (int) $_GET['id'];
            }
        } catch (Exception $e) {
            error_log('GET Error in StudentController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        if (isset($_GET['action']) && $_GET['action'] !== 'view') {
            header('Location: ?page=students');
            exit;
        }
    }

    /* ============================================================
       POST HANDLERS
    ============================================================ */

    private function handleUpdateStatus() {
        if (empty($_POST['student_id']) || empty($_POST['status'])) {
            $_SESSION['message'] = '❌ Student ID and status required.';
            return;
        }

        $validStatuses = ['enrolled', 'on_leave', 'graduated', 'dropped'];
        if (!in_array($_POST['status'], $validStatuses, true)) {
            $_SESSION['message'] = '❌ Invalid status.';
            return;
        }

        $_SESSION['message'] = $this->student->updateEnrollmentStatus(
            (int) $_POST['student_id'],
            $_POST['status']
        )
            ? '✅ Student status updated successfully!'
            : '❌ Failed to update student status.';
    }

    private function handleUpdateSection() {
        if (empty($_POST['student_id']) || empty($_POST['section_id'])) {
            $_SESSION['message'] = '❌ Student ID and section ID required.';
            return;
        }

        $_SESSION['message'] = $this->student->updateSection(
            (int) $_POST['student_id'],
            (int) $_POST['section_id']
        )
            ? '✅ Student section updated successfully!'
            : '❌ Failed to update student section.';
    }

    private function handleUpdateFollowup() {
        if (empty($_POST['student_id']) || empty($_POST['followup_date'])) {
            $_SESSION['message'] = '❌ Student ID and follow-up date required.';
            return;
        }

        $notes = isset($_POST['followup_notes']) ? trim($_POST['followup_notes']) : null;

        if ($this->student->updateFollowup((int) $_POST['student_id'], $_POST['followup_date'], $notes)) {
            $_SESSION['message'] = '✅ Follow-up scheduled for '
                . date('M d, Y', strtotime($_POST['followup_date']));
        } else {
            $_SESSION['message'] = '❌ Failed to update follow-up.';
        }
    }

    private function handleMarkFollowupDone() {
        if (empty($_POST['student_id'])) {
            $_SESSION['message'] = '❌ Student ID required.';
            return;
        }

        $_SESSION['message'] = $this->student->markFollowupDone((int) $_POST['student_id'])
            ? '✅ Follow-up marked as completed!'
            : '❌ Failed to mark follow-up as completed.';
    }

    private function handleBulkUpdateStatus() {
        if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
            $_SESSION['message'] = '❌ No students selected.';
            return;
        }

        if (empty($_POST['status'])) {
            $_SESSION['message'] = '❌ Status required.';
            return;
        }

        $validStatuses = ['enrolled', 'on_leave', 'graduated', 'dropped'];
        if (!in_array($_POST['status'], $validStatuses, true)) {
            $_SESSION['message'] = '❌ Invalid status.';
            return;
        }

        $successCount = 0;
        $failCount    = 0;

        foreach ($_POST['ids'] as $studentId) {
            if ($this->student->updateEnrollmentStatus((int) $studentId, $_POST['status'])) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        if ($successCount > 0) {
            $_SESSION['message'] = "✅ {$successCount} students updated successfully.";
            if ($failCount > 0) {
                $_SESSION['message'] .= " {$failCount} failed.";
            }
        } else {
            $_SESSION['message'] = "❌ Failed to update students.";
        }
    }

    /* ============================================================
       PUBLIC METHODS FOR VIEWS
    ============================================================ */

    /**
     * FIX: cc_sections has no `semester` / `school_year` columns.
     */
    public function getStudents($search = '', $courseId = null, $sectionId = null, $status = null) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE 1=1";

            $params = [];

            if (!empty($search)) {
                $sql .= " AND (a.first_name LIKE ?
                            OR a.surname LIKE ?
                            OR s.student_number LIKE ?
                            OR a.email LIKE ?
                            OR a.contact_number LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }

            if (!empty($courseId)) {
                $sql     .= " AND s.course_id = ?";
                $params[] = (int) $courseId;
            }

            if (!empty($sectionId)) {
                $sql     .= " AND s.section_id = ?";
                $params[] = (int) $sectionId;
            }

            if (!empty($status)) {
                $sql     .= " AND s.enrollment_status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY a.surname, a.first_name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudents: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentById($studentId) {
        try {
            return $this->student->getStudentDetails($studentId);
        } catch (Exception $e) {
            error_log('Error in getStudentById: ' . $e->getMessage());
            return null;
        }
    }

    public function getStudentStats() {
        return $this->student->getStudentStats();
    }

    public function getStudentsByCourse($courseId) {
        return $this->getStudents('', $courseId);
    }

    public function getStudentsBySection($sectionId) {
        return $this->getStudents('', null, $sectionId);
    }

    public function getStudentsByStatus($status) {
        return $this->getStudents('', null, null, $status);
    }

    public function getStudentsNeedingFollowup() {
        return $this->student->getStudentsNeedingFollowup();
    }

    public function getStudentsWithIncompleteRequirements() {
        try {
            $students   = $this->getStudentsByStatus('enrolled');
            $incomplete = [];

            require_once 'Requirement.php';
            $requirement = new Requirement();

            foreach ($students as $student) {
                $completion = $requirement->getRequirementCompletionStatus($student['student_id']);
                $total      = (int) ($completion['total']     ?? 0);
                $submitted  = (int) ($completion['submitted'] ?? 0);

                if ($total > 0 && $submitted < $total) {
                    $student['completion'] = $completion;
                    $incomplete[] = $student;
                }
            }

            return $incomplete;
        } catch (Exception $e) {
            error_log('Error in getStudentsWithIncompleteRequirements: ' . $e->getMessage());
            return [];
        }
    }

    public function getAllCourses() {
        return $this->course->findAll();
    }

    public function getAllSections() {
        return $this->section->getAllSectionsWithDetails();
    }

    /**
     * FIX: JOIN rgr_semesters / rgr_school_years for semester / school_year.
     */
    public function getActiveSections() {
        try {
            $sql = "SELECT s.*,
                           c.code as course_code, c.name as course_name,
                           sem.name AS semester,
                           sy.name  AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    ORDER BY c.code, sem.name, s.section_code";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getActiveSections: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentCountByStatus($status) {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_students WHERE enrollment_status = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$status]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in getStudentCountByStatus: ' . $e->getMessage());
            return 0;
        }
    }

    public function getStudentByNumber($studentNumber) {
        return $this->student->getByStudentNumber($studentNumber);
    }

    public function studentExists($studentId) {
        return $this->getStudentById($studentId) !== null;
    }

    /**
     * FIX: JOIN rgr_semesters / rgr_school_years for semester / school_year.
     */
    public function getRecentStudents($limit = 10) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    ORDER BY s.enrolled_at DESC
                    LIMIT ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(1, (int) $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getRecentStudents: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentDistributionByYear() {
        try {
            $sql = "SELECT
                        year_level,
                        COUNT(*) as count,
                        SUM(CASE WHEN enrollment_status = 'enrolled' THEN 1 ELSE 0 END) as enrolled_count
                    FROM enr_students
                    GROUP BY year_level
                    ORDER BY year_level";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentDistributionByYear: ' . $e->getMessage());
            return [];
        }
    }

    public function getTotalStudentsCount() {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_students";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in getTotalStudentsCount: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * FIX: JOIN rgr_semesters / rgr_school_years for semester / school_year.
     */
    public function getStudentsByYearLevel($yearLevel) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.year_level = ?
                      AND s.enrollment_status = 'enrolled'
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$yearLevel]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsByYearLevel: ' . $e->getMessage());
            return [];
        }
    }

    public function getUnenrolledStudents($search = '') {
        return $this->student->getUnenrolledStudents($search);
    }

    public function getStudentsWithSubjectEnrollments($schoolYear = null) {
        return $this->student->getStudentsWithSubjectEnrollments($schoolYear);
    }

    public function getStudentCountBySection($sectionId) {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_students
                     WHERE section_id = ? AND enrollment_status = 'enrolled'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sectionId]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in getStudentCountBySection: ' . $e->getMessage());
            return 0;
        }
    }

    public function getStudentsWithEnrollmentCount() {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           COUNT(DISTINCT e.schedule_id) as subject_count,
                           SUM(CASE WHEN e.enrollment_status = 'enrolled'  THEN 1 ELSE 0 END) as enrolled_subjects,
                           SUM(CASE WHEN e.enrollment_status = 'completed' THEN 1 ELSE 0 END) as completed_subjects,
                           SUM(CASE WHEN e.enrollment_status = 'dropped'   THEN 1 ELSE 0 END) as dropped_subjects
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    LEFT JOIN enr_enrollments e ON s.student_id = e.student_id
                    GROUP BY s.student_id
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsWithEnrollmentCount: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentWithSchedule($studentId, $schoolYear = null) {
        return $this->student->getStudentWithSchedule($studentId, $schoolYear);
    }

    /* ============================================================
       HELPERS
    ============================================================ */

    public function hasCompleteRequirements($studentId) {
        try {
            $completion = $this->requirement->getRequirementCompletionStatus($studentId);
            $total      = (int) ($completion['total']     ?? 0);
            $submitted  = (int) ($completion['submitted'] ?? 0);
            return $total > 0 && $submitted === $total;
        } catch (Exception $e) {
            error_log('Error in hasCompleteRequirements: ' . $e->getMessage());
            return false;
        }
    }

    public function getRequirementStatus($studentId) {
        try {
            return $this->requirement->getRequirementCompletionStatus($studentId);
        } catch (Exception $e) {
            error_log('Error in getRequirementStatus: ' . $e->getMessage());
            return ['total' => 0, 'submitted' => 0, 'pending' => 0];
        }
    }

    public function getMissingRequirements($studentId) {
        try {
            $requirements = $this->requirement->getStudentRequirements($studentId);
            $missing = [];

            foreach ($requirements as $req) {
                if (empty($req['is_submitted'])) {
                    $missing[] = $req;
                }
            }

            return $missing;
        } catch (Exception $e) {
            error_log('Error in getMissingRequirements: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentProgression($studentId) {
        try {
            require_once 'StudentProgression.php';
            require_once 'Enrollment.php';

            $progression = new StudentProgression();
            $enrollment  = new Enrollment();

            $current = $enrollment->getStudentCurrentProgression($studentId);
            $next    = $enrollment->getStudentNextProgression($studentId);
            $student = $this->student->findById($studentId);

            return [
                'student'            => $student,
                'current_year_level' => $current ? $current['year_level'] : null,
                'current_semester'   => $current ? $current['semester_name'] : null,
                'next_year_level'    => $next ? $next['year_level'] : null,
                'next_semester'      => $next ? $progression->getSemesterName($next['semester']) : null,
                'is_completed'       => $next ? ($next['is_completed'] ?? false) : false
            ];
        } catch (Exception $e) {
            error_log('Error in getStudentProgression: ' . $e->getMessage());
            return null;
        }
    }
}