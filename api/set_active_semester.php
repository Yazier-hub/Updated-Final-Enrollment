<?php
/**
 * api/set_active_semester.php
 *
 * Sets a semester as active (is_active = 1) and deactivates all others.
 * Optionally sets the active school year.
 *
 * POST Parameters:
 * - semester_id: The ID of the semester to activate (required)
 * - school_year_id: The ID of the school year to activate (optional)
 *
 * NOTE: rgr_semesters column is `name`, not `semester_name`.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

function jsonResponse($data) {
    echo json_encode($data);
    exit;
}

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';

    $db = Database::getInstance();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'POST required']);
    }

    $semesterId   = isset($_POST['semester_id'])   ? (int) $_POST['semester_id']   : 0;
    $schoolYearId = isset($_POST['school_year_id']) ? (int) $_POST['school_year_id'] : 0;

    if ($semesterId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid semester ID']);
    }

    // Verify semester exists — FIX: column is `name`
    $stmt = $db->prepare("SELECT id, name FROM rgr_semesters WHERE id = ?");
    $stmt->execute([$semesterId]);
    $semester = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$semester) {
        jsonResponse(['success' => false, 'message' => 'Semester not found']);
    }

    $db->beginTransaction();
    try {
        $db->execute("UPDATE rgr_semesters SET is_active = 0");

        $stmt = $db->prepare("UPDATE rgr_semesters SET is_active = 1 WHERE id = ?");
        $stmt->execute([$semesterId]);

        if ($schoolYearId > 0) {
            $db->execute("UPDATE rgr_school_years SET is_active = 0");
            $stmt = $db->prepare("UPDATE rgr_school_years SET is_active = 1 WHERE id = ?");
            $stmt->execute([$schoolYearId]);
        }

        $db->commit();

        jsonResponse([
            'success'       => true,
            'message'       => 'Semester activated: ' . $semester['name'],
            'semester_id'   => $semesterId,
            'semester_name' => $semester['name']
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('set_active_semester.php PDO Error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'DB Error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('set_active_semester.php Error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}