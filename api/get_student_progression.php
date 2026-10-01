<?php
/**
 * api/get_student_progression.php
 * Gets student's current and next progression.
 *
 * UPDATED: now includes can_progress flag from StudentProgression::canProgress()
 *
 * FIXES IN THIS VERSION:
 *   • Resolves school year from rgr_school_years (no calendar-year guesswork)
 *   • Defensive method_exists() checks around optional helpers
 *   • Adds retake_subjects list (not just count)
 *   • Falls back to Enrollment::getRetakeSubjectsForStudent() if the
 *     SubjectStatusManager method name differs
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Student.php';
    require_once $basePath . '/classes/StudentProgression.php';
    require_once $basePath . '/classes/Enrollment.php';
    require_once $basePath . '/classes/SubjectStatusManager.php';

    if (!isset($_GET['student_id']) || empty($_GET['student_id'])) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        exit;
    }

    $studentId = (int) $_GET['student_id'];
    $db        = Database::getInstance();

    $studentModel = new Student();
    $student      = $studentModel->findById($studentId);

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $progression = new StudentProgression();
    $enrollment  = new Enrollment();

    $current = $enrollment->getStudentCurrentProgression($studentId);
    $next    = $enrollment->getStudentNextProgression($studentId);

    // ------------------------------------------------------------
    // School year: prefer the active row from rgr_school_years
    // ------------------------------------------------------------
    $schoolYear = null;
    try {
        $syStmt = $db->prepare("SELECT name FROM rgr_school_years WHERE is_active = 1 LIMIT 1");
        $syStmt->execute();
        $syRow = $syStmt->fetch(PDO::FETCH_ASSOC);
        if ($syRow && !empty($syRow['name'])) {
            $schoolYear = $syRow['name'];
        }
    } catch (Exception $e) {
        error_log('get_student_progression: could not resolve active SY: ' . $e->getMessage());
    }

    if (!$schoolYear) {
        $currentYear = (int) date('Y');
        $schoolYear  = $currentYear . '-' . ($currentYear + 1);
    }

    // Next school year string (for display only — not authoritative)
    if (preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m)) {
        $nextSchoolYear = ((int) $m[1] + 1) . '-' . ((int) $m[2] + 1);
    } else {
        $nextSchoolYear = $schoolYear;
    }

    $currentYearLevel    = (int) ($current['year_level'] ?? 1);
    $nextYearLevel       = (int) ($next['year_level']    ?? 1);
    $academicYearChanges = ($nextYearLevel > $currentYearLevel);

    // ------------------------------------------------------------
    // Retake subjects — try both method names for compatibility
    // ------------------------------------------------------------
    $retakeSubjects = [];

    $subjectStatus = new SubjectStatusManager();

    if (method_exists($subjectStatus, 'getRetakeSubjects')) {
        try {
            $retakeSubjects = $subjectStatus->getRetakeSubjects($studentId);
        } catch (Exception $e) {
            error_log('get_student_progression: getRetakeSubjects failed: ' . $e->getMessage());
        }
    }

    if (empty($retakeSubjects) && method_exists($subjectStatus, 'getFailedSubjectsForRetake')) {
        try {
            $retakeSubjects = $subjectStatus->getFailedSubjectsForRetake($studentId);
        } catch (Exception $e) {
            error_log('get_student_progression: getFailedSubjectsForRetake failed: ' . $e->getMessage());
        }
    }

    // Final fallback — Enrollment's wrapper
    if (empty($retakeSubjects)) {
        try {
            $retakeSubjects = $enrollment->getRetakeSubjectsForStudent($studentId);
        } catch (Exception $e) {
            error_log('get_student_progression: Enrollment fallback failed: ' . $e->getMessage());
        }
    }

    if (!is_array($retakeSubjects)) {
        $retakeSubjects = [];
    }

    // ------------------------------------------------------------
    // Progression gate
    // ------------------------------------------------------------
    $canProgress = ['can_progress' => false, 'reason' => ''];
    if (method_exists($progression, 'canProgress')) {
        try {
            $result = $progression->canProgress($studentId);
            if (is_array($result)) {
                $canProgress = $result;
            }
        } catch (Exception $e) {
            error_log('get_student_progression: canProgress failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------
    // Year level / semester text — defensive
    // ------------------------------------------------------------
    $currentYearLevelText = 'N/A';
    if ($current && method_exists($progression, 'getYearLevelText')) {
        try {
            $currentYearLevelText = $progression->getYearLevelText($current['year_level']);
        } catch (Exception $e) {
            $currentYearLevelText = 'N/A';
        }
    } elseif ($current) {
        $levelsMap = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
        $currentYearLevelText = $levelsMap[(int) $current['year_level']] ?? 'N/A';
    }

    $nextYearLevelText = 'N/A';
    if ($next && method_exists($progression, 'getYearLevelText')) {
        try {
            $nextYearLevelText = $progression->getYearLevelText($next['year_level']);
        } catch (Exception $e) {
            $nextYearLevelText = 'N/A';
        }
    } elseif ($next) {
        $levelsMap = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
        $nextYearLevelText = $levelsMap[(int) $next['year_level']] ?? 'N/A';
    }

    $nextSemesterName = 'N/A';
    if ($next) {
        if (method_exists($progression, 'getSemesterName')) {
            try {
                $nextSemesterName = $progression->getSemesterName($next['semester']);
            } catch (Exception $e) {
                $nextSemesterName = 'N/A';
            }
        } else {
            $nextSemesterName = ((int) $next['semester'] === 2) ? 'Second Semester' : 'First Semester';
        }
    }

    // ------------------------------------------------------------
    // Response
    // ------------------------------------------------------------
    echo json_encode([
        'success' => true,
        'data' => [
            'student' => [
                'student_id'     => $student['student_id'],
                'student_number' => $student['student_number'],
                'course_id'      => $student['course_id'],
                'year_level'     => $student['year_level']
            ],
            'current' => $current,
            'next'    => $next,
            'school_year'              => $schoolYear,
            'next_school_year'         => $academicYearChanges ? $nextSchoolYear : $schoolYear,
            'academic_year_changes'    => $academicYearChanges,
            'current_year_level_text'  => $currentYearLevelText,
            'next_year_level_text'     => $nextYearLevelText,
            'next_semester_name'       => $nextSemesterName,
            'is_completed'  => $next ? ($next['is_completed'] ?? false) : false,
            'retake_count'    => count($retakeSubjects),
            'retake_subjects' => array_values($retakeSubjects),
            'can_progress'          => $canProgress['can_progress'] ?? false,
            'progress_block_reason' => $canProgress['reason'] ?? ''
        ],
        'message' => 'Progression data retrieved successfully'
    ]);

} catch (PDOException $e) {
    error_log('get_student_progression.php PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('get_student_progression.php Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}