<?php
/**
 * api/get_sections_by_course.php
 * Gets sections by course ID
 *
 * UPDATED: supports year_level, semester, only_available filters
 *          + auto-computes available_slots (max 40 per section)
 *
 * FIXES IN THIS VERSION:
 *   • Single grouped query for student counts (no N+1)
 *   • Counts scoped to active school year
 *   • only_available=0 explicitly disables the filter
 *   • Preserves semantic ORDER BY when only_available is off
 *   • Configurable MAX_STUDENTS via `?max=`
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

    $yearLevel = isset($_GET['year_level']) ? (int) $_GET['year_level'] : 0;
    $semester  = isset($_GET['semester'])   ? (int) $_GET['semester']   : 0;

    // FIX: explicit boolean handling
    $onlyAvailable = false;
    if (isset($_GET['only_available'])) {
        $raw = strtolower(trim((string) $_GET['only_available']));
        $onlyAvailable = in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    // FIX: configurable cap
    $MAX_STUDENTS = 40;
    if (isset($_GET['max']) && is_numeric($_GET['max'])) {
        $MAX_STUDENTS = max(1, min(500, (int) $_GET['max']));
    }

    $db      = Database::getInstance();
    $section = new Section();

    // ------------------------------------------------------------
    // Resolve active school year (for scoping student counts)
    // ------------------------------------------------------------
    $activeSchoolYearId = null;
    try {
        $syStmt = $db->prepare("SELECT id FROM rgr_school_years WHERE is_active = 1 LIMIT 1");
        $syStmt->execute();
        $syRow = $syStmt->fetch(PDO::FETCH_ASSOC);
        if ($syRow) {
            $activeSchoolYearId = (int) $syRow['id'];
        }
    } catch (Exception $e) {
        // Non-fatal — we'll fall back to unscoped counts
        error_log('get_sections_by_course: could not resolve active SY: ' . $e->getMessage());
    }

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

    // Filter by semester (1 = First, 2 = Second) — matches rgr_semesters.id
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
    // FIX: Single grouped query for student counts (no N+1)
    // ------------------------------------------------------------
    $sectionIds = [];
    foreach ($sections as $s) {
        if (!empty($s['id'])) {
            $sectionIds[] = (int) $s['id'];
        }
    }

    $countsBySection = [];

    if (!empty($sectionIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));

            // Scope to active school year if available — otherwise
            // count only current enrollments across all years.
            if ($activeSchoolYearId !== null) {
                $countSql = "SELECT e.section_id,
                                    COUNT(DISTINCT e.student_id) AS count
                             FROM enr_enrollments e
                             INNER JOIN cc_sections sec ON sec.id = e.section_id
                             WHERE e.section_id IN ($placeholders)
                               AND e.enrollment_status = 'enrolled'
                               AND sec.school_year_id = ?
                             GROUP BY e.section_id";
                $countParams = array_merge($sectionIds, [$activeSchoolYearId]);
            } else {
                $countSql = "SELECT e.section_id,
                                    COUNT(DISTINCT e.student_id) AS count
                             FROM enr_enrollments e
                             WHERE e.section_id IN ($placeholders)
                               AND e.enrollment_status = 'enrolled'
                             GROUP BY e.section_id";
                $countParams = $sectionIds;
            }

            $countStmt = $db->prepare($countSql);
            $countStmt->execute($countParams);

            while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
                $countsBySection[(int) $row['section_id']] = (int) $row['count'];
            }
        } catch (Exception $e) {
            error_log('get_sections_by_course count error: ' . $e->getMessage());
            // Fail soft — sections still return, just with 0 counts
            $countsBySection = [];
        }
    }

    // ------------------------------------------------------------
    // Attach counts + available slots
    // ------------------------------------------------------------
    foreach ($sections as &$sec) {
        $secId = isset($sec['id']) ? (int) $sec['id'] : 0;

        $current = $countsBySection[$secId] ?? 0;

        $sec['current_students'] = $current;
        $sec['max_students']     = $MAX_STUDENTS;
        $sec['available_slots']  = max(0, $MAX_STUDENTS - $current);
        $sec['utilization']      = $MAX_STUDENTS > 0
            ? round(($current / $MAX_STUDENTS) * 100, 1)
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

        // Only re-sort by availability when the user asked for it
        usort($sections, function ($a, $b) {
            return (int) ($b['available_slots'] ?? 0) - (int) ($a['available_slots'] ?? 0);
        });
    }

    echo json_encode([
        'success'        => true,
        'data'           => array_values($sections),
        'count'          => count($sections),
        'course_id'      => $courseId,
        'year_level'     => $yearLevel,
        'semester'       => $semester,
        'only_available' => $onlyAvailable,
        'max_students'   => $MAX_STUDENTS,
        'school_year_id' => $activeSchoolYearId,
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