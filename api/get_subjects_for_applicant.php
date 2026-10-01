<?php
/**
 * api/get_subjects_for_applicant.php
 *
 * Returns available sections for an applicant.
 *
 * NOTE: enr_applicants has no year_level / semester columns.
 *       Fallbacks used.
 *
 * FIXES IN THIS VERSION:
 *   • Catch Throwable (PHP 8 type errors)
 *   • Null-safe applicant name
 *   • Defensive: cast to array before iterating
 *   • Expose top-level `school_year` in the response
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

    /* ------------------------------------------------------------
       Year level + semester (applicant may not have these)
    ------------------------------------------------------------ */
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

    /* ------------------------------------------------------------
       Sections (with alternate-semester fallback)
    ------------------------------------------------------------ */
    $sections = $section->getSectionsByCourseAndYearLevel(
        $applicant['course_id'],
        $yearLevel,
        $semester
    );

    if (!is_array($sections)) {
        $sections = [];
    }

    if (empty($sections)) {
        $alternateSemester = ($semester === '1st Semester') ? '2nd Semester' : '1st Semester';
        $alternate = $section->getSectionsByCourseAndYearLevel(
            $applicant['course_id'],
            $yearLevel,
            $alternateSemester
        );

        if (is_array($alternate) && !empty($alternate)) {
            $sections = $alternate;
            $semester = $alternateSemester;
        }
    }

    /* ------------------------------------------------------------
       Resolve top-level school year from active row (fallback to
       calendar).
    ------------------------------------------------------------ */
    $schoolYear = date('Y') . '-' . (date('Y') + 1);
    try {
        $syStmt = $db->prepare("SELECT name FROM rgr_school_years WHERE is_active = 1 LIMIT 1");
        $syStmt->execute();
        $syRow = $syStmt->fetch(PDO::FETCH_ASSOC);
        if ($syRow && !empty($syRow['name'])) {
            $schoolYear = $syRow['name'];
        }
    } catch (Exception $e) {
        error_log('get_subjects_for_applicant: SY lookup failed: ' . $e->getMessage());
    }

    /* ------------------------------------------------------------
       Format sections
    ------------------------------------------------------------ */
    $formattedSections = [];
    foreach ($sections as $sec) {
        if (!isset($sec['id'])) {
            continue; // skip malformed rows
        }

        $formattedSections[] = [
            'section_id'   => (int) $sec['id'],
            'section_code' => $sec['section_code'] ?? '',
            'grade_level'  => $sec['grade_level']  ?? $yearLevel,
            'semester'     => $sec['semester']     ?? $semester,
            'school_year'  => $sec['school_year']  ?? $schoolYear,
            'course_code'  => $sec['course_code']  ?? '',
            'course_name'  => $sec['course_name']  ?? ''
        ];
    }

    /* ------------------------------------------------------------
       Applicant name — null safe
    ------------------------------------------------------------ */
    $applicantName = trim(
        ($applicant['first_name']  ?? '')
        . ' ' . ($applicant['middle_name'] ?? '')
        . ' ' . ($applicant['surname']     ?? '')
    );

    /* ------------------------------------------------------------
       Response
    ------------------------------------------------------------ */
    echo json_encode([
        'success'        => true,
        'sections'       => $formattedSections,
        'course_id'      => (int) $applicant['course_id'],
        'course_code'    => $courseInfo['code'] ?? '',
        'course_name'    => $courseInfo['name'] ?? '',
        'year_level'     => $yearLevel,
        'semester'       => $semester,
        'school_year'    => $schoolYear,
        'applicant_name' => $applicantName,
        'total_sections' => count($formattedSections),
        'message'        => count($formattedSections) > 0
            ? 'Sections found'
            : 'No available sections'
    ]);

} catch (PDOException $e) {
    error_log('get_subjects_for_applicant.php PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success'  => false,
        'message'  => 'Database error: ' . $e->getMessage(),
        'sections' => []
    ]);
} catch (Throwable $e) {
    error_log('Error in get_subjects_for_applicant.php: ' . $e->getMessage());
    echo json_encode([
        'success'  => false,
        'message'  => 'Server error: ' . $e->getMessage(),
        'sections' => []
    ]);
}