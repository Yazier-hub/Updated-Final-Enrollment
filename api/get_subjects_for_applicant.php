<?php
/**
 * api/get_subjects_for_applicant.php
 *
 * Returns available sections for an applicant.
 *
 * NOTE: enr_applicants has no year_level / semester columns.
 *       Fallbacks used.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Application.php';
    require_once $basePath . '/classes/Section.php';
    require_once $basePath . '/classes/Course.php';

    if (!isset($_GET['applicant_id']) || empty($_GET['applicant_id'])) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Applicant ID is required',
            'sections' => []
        ]);
        exit;
    }

    $applicantId = (int) $_GET['applicant_id'];

    $db          = Database::getInstance();
    $application = new Application();
    $section     = new Section();
    $course      = new Course();

    $applicant = $application->getApplicationById($applicantId);
    if (!$applicant) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Applicant not found',
            'sections' => []
        ]);
        exit;
    }

    if (empty($applicant['course_id'])) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Applicant has no course assigned',
            'sections' => []
        ]);
        exit;
    }

    $courseInfo = $course->findById($applicant['course_id']);
    if (!$courseInfo) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Course not found',
            'sections' => []
        ]);
        exit;
    }

    $yearLevel = '1st Year';
    $semester  = '1st Semester';

    if (isset($applicant['year_level']) && $applicant['year_level'] !== '') {
        if (is_numeric($applicant['year_level'])) {
            $levels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
            $yl = (int) $applicant['year_level'];
            $yearLevel = $levels[$yl] ?? '1st Year';
        } else {
            $yearLevel = (string) $applicant['year_level'];
        }
    }

    if (isset($applicant['semester']) && $applicant['semester'] !== '') {
        if (is_numeric($applicant['semester'])) {
            $semester = ((int) $applicant['semester'] === 1) ? '1st Semester' : '2nd Semester';
        } else {
            $semester = (string) $applicant['semester'];
        }
    }

    $sections = $section->getSectionsByCourseAndYearLevel(
        $applicant['course_id'],
        $yearLevel,
        $semester
    );

    if (empty($sections)) {
        $alternateSemester = ($semester === '1st Semester') ? '2nd Semester' : '1st Semester';
        $sections = $section->getSectionsByCourseAndYearLevel(
            $applicant['course_id'],
            $yearLevel,
            $alternateSemester
        );
        if (!empty($sections)) {
            $semester = $alternateSemester;
        }
    }

    if (!is_array($sections)) {
        $sections = [];
    }

    $formattedSections = array_map(function ($sec) {
        return [
            'section_id'   => $sec['id'],
            'section_code' => $sec['section_code'],
            'grade_level'  => $sec['grade_level'],
            'semester'     => $sec['semester'],
            'school_year'  => $sec['school_year'] ?? null,
            'course_code'  => $sec['course_code'] ?? '',
            'course_name'  => $sec['course_name'] ?? ''
        ];
    }, $sections);

    echo json_encode([
        'success'        => true,
        'sections'       => $formattedSections,
        'course_id'      => (int) $applicant['course_id'],
        'course_code'    => $courseInfo['code'],
        'course_name'    => $courseInfo['name'],
        'year_level'     => $yearLevel,
        'semester'       => $semester,
        'applicant_name' => trim(
            $applicant['first_name']
            . ' ' . ($applicant['middle_name'] ?? '')
            . ' ' . $applicant['surname']
        ),
        'total_sections' => count($formattedSections),
        'message'        => count($formattedSections) > 0
            ? 'Sections found'
            : 'No available sections'
    ]);

} catch (Exception $e) {
    error_log('Error in get_subjects_for_applicant.php: ' . $e->getMessage());
    echo json_encode([
        'success'  => false,
        'message'  => 'Server error: ' . $e->getMessage(),
        'sections' => []
    ]);
}