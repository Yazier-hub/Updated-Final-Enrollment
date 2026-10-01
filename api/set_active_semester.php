<?php
/**
 * api/set_active_semester.php
 *
 * Sets a semester as active (is_active = 1) and deactivates all others.
 *
 * POST Parameters:
 * - semester_id: The ID of the semester to activate (required)
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

    $semId = isset($_POST['semester_id']) ? (int) $_POST['semester_id'] : 0;

    if ($semId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid semester ID']);
    }

    // Verify semester exists — column is `name`
    $stmt = $db->prepare("
        SELECT s.id, s.name, s.school_year_id, sy.name AS school_year_name
        FROM rgr_semesters s
        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
        WHERE s.id = ?
    ");
    $stmt->execute([$semId]);
    $sem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sem) {
        jsonResponse(['success' => false, 'message' => 'Semester not found']);
    }

    $db->beginTransaction();
    try {
        // Deactivate all semesters
        $deactivate = $db->prepare("UPDATE rgr_semesters SET is_active = 0");
        $deactivate->execute();

        // Activate selected semester
        $activate = $db->prepare("UPDATE rgr_semesters SET is_active = 1 WHERE id = ?");
        $activate->execute([$semId]);

        // Also activate the corresponding school year
        if (!empty($sem['school_year_id'])) {
            $deactivateSy = $db->prepare("UPDATE rgr_school_years SET is_active = 0");
            $deactivateSy->execute();

            $activateSy = $db->prepare("UPDATE rgr_school_years SET is_active = 1 WHERE id = ?");
            $activateSy->execute([(int) $sem['school_year_id']]);
        }

        $db->commit();

        jsonResponse([
            'success'          => true,
            'message'          => 'Semester activated: ' . $sem['name'],
            'semester_id'      => $semId,
            'semester_name'    => $sem['name'],
            'school_year_id'   => $sem['school_year_id'],
            'school_year_name' => $sem['school_year_name']
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log('set_active_semester.php PDO Error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'DB Error: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('set_active_semester.php Error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}