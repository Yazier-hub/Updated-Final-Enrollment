<?php
/**
 * api/get_sections_by_course.php
 * Gets sections by course ID
 *
 * UPDATED: supports year_level, semester, only_available filters
 *          + auto-computes available_slots (max 40 per section)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $basePath = dirname(__DIR__);

    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Section.php';

    if (!isset($_GET['course_id']) || empty($_GET['course_id'])) {
        echo json_encode(['success' => false, 'message' => 'Course ID is required']);
        exit;
    }

    $courseId = (int) $_GET['course_id'];

    if ($courseId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid course ID']);
        exit;
    }

    $yearLevel     = isset($_GET['year_level'])     ? (int) $_GET['year_level']  : 0;
    $semester      = isset($_GET['semester'])       ? (int) $_GET['semester']    : 0;
    $onlyAvailable = !empty($_GET['only_available']);

    $db      = Database::getInstance();
    $section = new Section();

    // ------------------------------------------------------------
    // Build query with optional filters
    // ------------------------------------------------------------
    $sql = "SELECT s.*, c.code AS course_code, c.name AS course_name,
                   sem.name AS semester, sy.name AS school_year,
                   sem.id   AS semester_id, sy.id AS school_year_id
            FROM cc_sections s
            JOIN rgr_courses c ON s.program_id = c.id
            LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
            LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
            WHERE s.program_id = ?";

    $params = [$courseId];

    // Filter by year level (convert number to text)
    if ($yearLevel > 0) {
        $yearMap  = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
        $yearText = $yearMap[$yearLevel] ?? null;
        if ($yearText) {
            $sql .= " AND s.grade_level = ?";
            $params[] = $yearText;
        }
    }

    // Filter by semester (1 = First, 2 = Second)
    if ($semester > 0) {
        $sql .= " AND s.semester_id = ?";
        $params[] = $semester;
    }

    $sql .= " ORDER BY s.grade_level, s.semester_id, s.section_code";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($sections)) {
        $sections = [];
    }

    // ------------------------------------------------------------
    // Compute current_students + available_slots (max 40)
    // ------------------------------------------------------------
    $MAX_STUDENTS = 40;

    foreach ($sections as &$sec) {
        $secId = isset($sec['id']) ? (int) $sec['id'] : 0;

        if ($secId > 0) {
            try {
                $countSql = "SELECT COUNT(DISTINCT student_id) AS count
                             FROM enr_enrollments
                             WHERE section_id = ?
                               AND enrollment_status = 'enrolled'";
                $countStmt = $db->prepare($countSql);
                $countStmt->execute([$secId]);
                $result = $countStmt->fetch(PDO::FETCH_ASSOC);
                $sec['current_students'] = (int) ($result['count'] ?? 0);
            } catch (Exception $e) {
                $sec['current_students'] = 0;
            }
        } else {
            $sec['current_students'] = 0;
        }

        $sec['max_students']    = $MAX_STUDENTS;
        $sec['available_slots'] = max(0, $MAX_STUDENTS - (int) $sec['current_students']);
        $sec['utilization']     = $MAX_STUDENTS > 0
            ? round(((int) $sec['current_students'] / $MAX_STUDENTS) * 100, 1)
            : 0;
    }
    unset($sec);

    // ------------------------------------------------------------
    // Filter only sections with available slots
    // ------------------------------------------------------------
    if ($onlyAvailable) {
        $sections = array_values(array_filter($sections, function ($s) {
            return (int) ($s['available_slots'] ?? 0) > 0;
        }));
    }

    // ------------------------------------------------------------
    // Sort by most available slots first (auto-fill priority)
    // ------------------------------------------------------------
    usort($sections, function ($a, $b) {
        return (int) ($b['available_slots'] ?? 0) - (int) ($a['available_slots'] ?? 0);
    });

    echo json_encode([
        'success'        => true,
        'data'           => array_values($sections),
        'count'          => count($sections),
        'course_id'      => $courseId,
        'year_level'     => $yearLevel,
        'semester'       => $semester,
        'only_available' => $onlyAvailable,
        'max_students'   => $MAX_STUDENTS,
        'message'        => count($sections) > 0
            ? 'Sections retrieved successfully'
            : 'No sections found for this course'
    ]);

} catch (PDOException $e) {
    error_log('get_sections_by_course.php PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('get_sections_by_course.php Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}