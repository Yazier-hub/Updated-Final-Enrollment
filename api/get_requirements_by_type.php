<?php
/**
 * API endpoint to get requirements by admission type
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Model.php';
    require_once $basePath . '/classes/Requirement.php';

    $admissionType = isset($_GET['admission_type']) ? trim($_GET['admission_type']) : 'freshmen';

    $validTypes = ['freshmen', 'transferee', 'returnee', 'senior_high'];
    if (!in_array($admissionType, $validTypes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid admission type. Valid types: ' . implode(', ', $validTypes)
        ]);
        exit;
    }

    $categoryMap = [
        'freshmen'    => 'freshmen',
        'transferee'  => 'transferee',
        'returnee'    => 'continuing',
        'senior_high' => 'freshmen'
    ];

    $category = $categoryMap[$admissionType] ?? 'freshmen';

    $requirement  = new Requirement();
    $requirements = $requirement->getRequirementsByCategory($category);

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
        'category'       => $category,
        'count'          => count($formattedRequirements),
        'message'        => count($formattedRequirements) > 0
            ? 'Requirements retrieved successfully'
            : 'No requirements found'
    ]);

} catch (Exception $e) {
    error_log('Error in get_requirements_by_type: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}