<?php
/**
 * api/set_active_school_year.php
 *
 * Sets a school year as active (is_active = 1) and deactivates all others.
 *
 * POST Parameters:
 * - school_year_id: The ID of the school year to activate (required)
 *
 * NOTE: rgr_school_years column is `name`, not `school_year`.
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

    $syId = isset($_POST['school_year_id']) ? (int) $_POST['school_year_id'] : 0;

    if ($syId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid school year ID']);
    }

    // Verify school year exists — FIX: column is `name`
    $stmt = $db->prepare("SELECT id, name FROM rgr_school_years WHERE id = ?");
    $stmt->execute([$syId]);
    $sy = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sy) {
        jsonResponse(['success' => false, 'message' => 'School year not found']);
    }

    $db->beginTransaction();
    try {
        $db->execute("UPDATE rgr_school_years SET is_active = 0");

        $stmt = $db->prepare("UPDATE rgr_school_years SET is_active = 1 WHERE id = ?");
        $stmt->execute([$syId]);

        $db->commit();

        jsonResponse([
            'success'        => true,
            'message'        => 'School year activated: ' . $sy['name'],
            'school_year_id' => $syId,
            'school_year'    => $sy['name']
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('set_active_school_year.php PDO Error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'DB Error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('set_active_school_year.php Error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}