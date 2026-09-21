<?php
// classes/CourseController.php - FULLY FIXED for `kms` schema
//
// No schema changes needed. This version:
//   • Hardens all ID casts and null-safe reads
//   • Guards count() against non-array returns
//   • Preserves public API used by views

require_once 'Course.php';
require_once 'Section.php';
require_once 'Database.php';

class CourseController {
    private $course;
    private $db;

    public function __construct() {
        $this->course = new Course();
        $this->db     = Database::getInstance();
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
        header('Cache-Control: no-cache, must-revalidate');

        try {
            if (!isset($_GET['ajax']) || $_GET['ajax'] === '') {
                echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
                exit;
            }

            switch ($_GET['ajax']) {
                case 'get_course':                    $this->ajaxGetCourse();                    break;
                case 'get_courses':                   $this->ajaxGetCourses();                   break;
                case 'get_course_stats':              $this->ajaxGetCourseStats();              break;
                case 'get_available_courses':         $this->ajaxGetAvailableCourses();         break;
                case 'get_popular_courses':           $this->ajaxGetPopularCourses();           break;
                case 'search_courses':                $this->ajaxSearchCourses();               break;
                case 'get_course_enrollment':         $this->ajaxGetCourseEnrollment();         break;
                case 'get_course_utilization':        $this->ajaxGetCourseUtilization();        break;
                case 'get_sections':                  $this->ajaxGetSections();                 break;
                case 'get_section_occupancy':         $this->ajaxGetSectionOccupancy();         break;
                case 'get_course_enrollment_summary': $this->ajaxGetCourseEnrollmentSummary(); break;
                case 'get_course_distribution':       $this->ajaxGetCourseDistribution();      break;

                default:
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid AJAX action: ' . $_GET['ajax']
                    ]);
            }
        } catch (Exception $e) {
            error_log('AJAX Error in CourseController: ' . $e->getMessage());
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

    private function ajaxGetCourse() {
        if (empty($_GET['id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $id         = (int) $_GET['id'];
        $courseData = $this->course->getCourseWithStats($id);

        if ($courseData) {
            $courseData['enrollment_summary'] = $this->course->getCourseEnrollmentSummary($id);
            echo json_encode(['success' => true, 'data' => $courseData]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Course not found']);
        }
    }

    private function ajaxGetCourses() {
        $withStats    = isset($_GET['with_stats'])    && $_GET['with_stats']    === 'true';
        $withCapacity = isset($_GET['with_capacity']) && $_GET['with_capacity'] === 'true';

        if ($withCapacity) {
            $courses = $this->course->getCoursesWithCapacity();
        } elseif ($withStats) {
            $courses = $this->course->getAllWithSectionCount();
        } else {
            $courses = $this->course->findAll();
        }

        if (!is_array($courses)) {
            $courses = [];
        }

        echo json_encode([
            'success' => true,
            'data'    => $courses,
            'count'   => count($courses)
        ]);
    }

    private function ajaxGetCourseStats() {
        $stats = $this->course->getCourseStats();

        if ($stats) {
            echo json_encode(['success' => true, 'data' => $stats]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to get statistics']);
        }
    }

    private function ajaxGetAvailableCourses() {
        $courses = $this->course->getAvailableCourses();

        if (!is_array($courses)) {
            $courses = [];
        }

        echo json_encode([
            'success' => true,
            'data'    => $courses,
            'count'   => count($courses)
        ]);
    }

    private function ajaxGetPopularCourses() {
        $limit   = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;
        $courses = $this->course->getPopularCourses($limit);

        if (!is_array($courses)) {
            $courses = [];
        }

        echo json_encode([
            'success' => true,
            'data'    => $courses,
            'count'   => count($courses)
        ]);
    }

    private function ajaxSearchCourses() {
        if (empty($_GET['keyword'])) {
            echo json_encode(['success' => false, 'message' => 'Search keyword required']);
            return;
        }

        $keyword = trim($_GET['keyword']);
        $courses = $this->course->searchByName($keyword);

        if (!is_array($courses)) {
            $courses = [];
        }

        echo json_encode([
            'success' => true,
            'data'    => $courses,
            'count'   => count($courses)
        ]);
    }

    private function ajaxGetCourseEnrollment() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId = (int) $_GET['course_id'];
        $year     = $_GET['year'] ?? null;

        $stats = $this->course->getEnrollmentStats($year);

        if (!is_array($stats)) {
            $stats = [];
        }

        // Filter to only this course
        $courseStats = array_values(array_filter($stats, function ($s) use ($courseId) {
            return (int) ($s['id'] ?? 0) === $courseId;
        }));

        $summary = $this->course->getCourseEnrollmentSummary($courseId, $year);

        echo json_encode([
            'success' => true,
            'data'    => $courseStats,
            'summary' => $summary
        ]);
    }

    private function ajaxGetCourseUtilization() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId    = (int) $_GET['course_id'];
        $utilization = $this->course->getCoursesWithCapacity();

        if (!is_array($utilization)) {
            $utilization = [];
        }

        $courseUtil = array_values(array_filter($utilization, function ($u) use ($courseId) {
            return (int) ($u['id'] ?? 0) === $courseId;
        }));

        echo json_encode([
            'success' => true,
            'data'    => $courseUtil
        ]);
    }

    private function ajaxGetSections() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId     = (int) $_GET['course_id'];
        $sectionModel = new Section();
        $sections     = $sectionModel->getSectionsByCourse($courseId);

        if (!is_array($sections)) {
            $sections = [];
        }

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    }

    private function ajaxGetSectionOccupancy() {
        if (empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID required']);
            return;
        }

        $sectionId    = (int) $_GET['section_id'];
        $sectionModel = new Section();
        $occupancy    = $sectionModel->getOccupancyDetails($sectionId);

        if ($occupancy) {
            echo json_encode(['success' => true, 'data' => $occupancy]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Section not found']);
        }
    }

    private function ajaxGetCourseEnrollmentSummary() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId   = (int) $_GET['course_id'];
        $schoolYear = $_GET['school_year'] ?? null;

        $summary = $this->course->getCourseEnrollmentSummary($courseId, $schoolYear);

        echo json_encode([
            'success' => true,
            'data'    => $summary
        ]);
    }

    private function ajaxGetCourseDistribution() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId     = (int) $_GET['course_id'];
        $distribution = $this->course->getCourseStudentDistribution($courseId);

        if (!is_array($distribution)) {
            $distribution = [];
        }

        echo json_encode([
            'success' => true,
            'data'    => $distribution
        ]);
    }

    /* ============================================================
       POST ROUTER
    ============================================================ */

    public function handlePostRequest() {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
                return;
            }

            switch ($_POST['action']) {
                case 'add':         $this->handleAdd();         break;
                case 'update':      $this->handleUpdate();      break;
                case 'delete':      $this->handleDelete();      break;
                case 'bulk_delete': $this->handleBulkDelete();  break;

                default:
                    $_SESSION['message'] = '❌ Invalid action specified.';
            }
        } catch (Exception $e) {
            error_log('POST Error in CourseController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        $redirect = $_POST['redirect'] ?? '?page=courses';
        header('Location: ' . $redirect);
        exit;
    }

    /* ============================================================
       GET ROUTER
    ============================================================ */

    private function handleGetRequest() {
        try {
            switch ($_GET['action']) {
                case 'delete':
                    if (isset($_GET['id'])) {
                        $this->handleDelete();
                    }
                    break;

                case 'view':
                    if (isset($_GET['id'])) {
                        $_SESSION['view_course_id'] = (int) $_GET['id'];
                    }
                    break;

                default:
                    break;
            }
        } catch (Exception $e) {
            error_log('GET Error in CourseController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        if (!isset($_GET['action']) || $_GET['action'] !== 'view') {
            header('Location: ?page=courses');
            exit;
        }
    }

    /* ============================================================
       POST ACTION HANDLERS
    ============================================================ */

    private function handleAdd() {
        $required = ['code', 'name', 'years'];
        foreach ($required as $field) {
            if (!isset($_POST[$field]) || $_POST[$field] === '') {
                $_SESSION['message'] = "❌ The field '{$field}' is required.";
                return;
            }
        }

        $data   = $this->sanitizeCourseData($_POST);
        $result = $this->course->createCourse($data);

        $_SESSION['message'] = ($result['success'] ? '✅ ' : '❌ ') . $result['message'];
    }

    private function handleUpdate() {
        if (empty($_POST['id'])) {
            $_SESSION['message'] = '❌ Course ID required for update.';
            return;
        }

        $required = ['code', 'name', 'years'];
        foreach ($required as $field) {
            if (!isset($_POST[$field]) || $_POST[$field] === '') {
                $_SESSION['message'] = "❌ The field '{$field}' is required.";
                return;
            }
        }

        $data   = $this->sanitizeCourseData($_POST);
        $result = $this->course->updateCourse((int) $_POST['id'], $data);

        $_SESSION['message'] = ($result['success'] ? '✅ ' : '❌ ') . $result['message'];
    }

    private function handleDelete() {
        $id = isset($_POST['id'])
            ? (int) $_POST['id']
            : (isset($_GET['id']) ? (int) $_GET['id'] : null);

        if (!$id) {
            $_SESSION['message'] = '❌ Course ID required for deletion.';
            return;
        }

        $result = $this->course->deleteCourse($id);

        $_SESSION['message'] = ($result['success'] ? '✅ ' : '❌ ') . $result['message'];
    }

    private function handleBulkDelete() {
        if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
            $_SESSION['message'] = '❌ No courses selected for deletion.';
            return;
        }

        $successCount = 0;
        $failCount    = 0;
        $errors       = [];

        foreach ($_POST['ids'] as $id) {
            $result = $this->course->deleteCourse((int) $id);
            if (!empty($result['success'])) {
                $successCount++;
            } else {
                $failCount++;
                $errors[] = $result['message'] ?? 'Unknown error';
            }
        }

        if ($successCount > 0 && $failCount > 0) {
            $_SESSION['message'] = "⚠️ {$successCount} courses deleted, {$failCount} failed. " . implode(' ', $errors);
        } elseif ($successCount > 0) {
            $_SESSION['message'] = "✅ {$successCount} courses deleted successfully.";
        } else {
            $_SESSION['message'] = "❌ Failed to delete courses: " . implode(' ', $errors);
        }
    }

    /* ============================================================
       HELPERS
    ============================================================ */

    private function sanitizeCourseData($postData) {
        return [
            'code'  => strtoupper(trim(
                $postData['code']
                ?? $postData['course_code']
                ?? ''
            )),
            'name'  => trim(
                $postData['name']
                ?? $postData['course_name']
                ?? ''
            ),
            'years' => (int) (
                $postData['years']
                ?? $postData['duration_years']
                ?? 4
            )
        ];
    }

    /* ============================================================
       PUBLIC METHODS FOR VIEWS
    ============================================================ */

    public function getAllCourses() {
        return $this->course->findAll();
    }

    public function getAllCoursesWithStats() {
        return $this->course->getAllWithSectionCount();
    }

    public function getCoursesWithCapacity() {
        return $this->course->getCoursesWithCapacity();
    }

    public function getCourseById($id) {
        return $this->course->findById($id);
    }

    public function getCourseWithStats($id) {
        return $this->course->getCourseWithStats($id);
    }

    public function getAvailableCourses() {
        return $this->course->getAvailableCourses();
    }

    public function getPopularCourses($limit = 5) {
        return $this->course->getPopularCourses($limit);
    }

    public function searchCourses($keyword) {
        return $this->course->searchByName($keyword);
    }

    public function getCourseStats() {
        return $this->course->getCourseStats();
    }

    public function getCoursesWithYearLevelCounts() {
        return $this->course->getCoursesWithYearLevelCounts();
    }

    public function getEnrollmentStats($year = null) {
        return $this->course->getEnrollmentStats($year);
    }

    public function hasAvailableSections($courseId) {
        return $this->course->hasAvailableSections($courseId);
    }

    public function getCompletionRate($courseId) {
        return $this->course->getCompletionRate($courseId);
    }

    public function getSectionsForCourse($courseId) {
        $sectionModel = new Section();
        return $sectionModel->getSectionsByCourse($courseId);
    }

    public function getAvailableSectionsForCourse($courseId) {
        $sectionModel = new Section();
        return $sectionModel->getAvailableSections($courseId);
    }

    public function getCourseEnrollmentSummary($courseId, $schoolYear = null) {
        return $this->course->getCourseEnrollmentSummary($courseId, $schoolYear);
    }

    public function getCourseStudentDistribution($courseId) {
        return $this->course->getCourseStudentDistribution($courseId);
    }
}