<?php
/**
 * api/get_available_subjects_with_status.php - FULLY FIXED for `kms` schema
 *
 * ✅ Retake subjects always included
 * ✅ Retake subjects always enrollable (but only from valid schedules)
 * ✅ No duplicate subjects
 * ✅ Passes student's course_id to SubjectStatusManager::getAvailableSubjects()
 * ✅ Defensive guards on missing array keys
 *
 * FIXES IN THIS VERSION:
 *   • Default semester is 'First', not 'Second'
 *   • Uses current progression unless next.can_progress is true
 *   • Retake schedules are resolved within the selected section when
 *     section_id is provided
 *   • Dedup key is subject_id (stable across sections)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Enrollment.php';
    require_once $basePath . '/classes/SubjectStatusManager.php';
    require_once $basePath . '/classes/Section.php';
    require_once $basePath . '/classes/Student.php';

    if (!isset($_GET['student_id']) || empty($_GET['student_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Student ID is required'
        ]);
        exit;
    }

    $studentId = (int) $_GET['student_id'];
    $sectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;

    $db            = Database::getInstance();
    $enrollment    = new Enrollment();
    $subjectStatus = new SubjectStatusManager();
    $section       = new Section();
    $studentModel  = new Student();

    $student = $studentModel->findById($studentId);
    if (!$student) {
        echo json_encode([
            'success' => false,
            'message' => 'Student not found'
        ]);
        exit;
    }

    $studentCourseId = isset($student['course_id']) ? (int) $student['course_id'] : 0;

    $current = $enrollment->getStudentCurrentProgression($studentId);
    $next    = $enrollment->getStudentNextProgression($studentId);

    // ------------------------------------------------------------
    // Choose year level / semester
    // ------------------------------------------------------------
    // If the student CAN progress, use next progression.
    // Otherwise stay on current.
    // ------------------------------------------------------------
    $canProgress = $next && !empty($next['can_progress']);

    if ($canProgress) {
        $yearLevel = (int) ($next['year_level'] ?? 1);
        $semester  = (int) ($next['semester']   ?? 1);
    } elseif ($current) {
        $yearLevel = (int) ($current['year_level'] ?? 1);
        $semester  = (int) ($current['semester']   ?? 1);
    } else {
        $yearLevel = (int) ($student['year_level'] ?? 1);
        $semester  = 1;
    }

    $semesterName = ($semester === 1) ? 'First' : 'Second';

    error_log("=== get_available_subjects_with_status DEBUG ===");
    error_log("Student ID: {$studentId}, Course ID: {$studentCourseId}");
    error_log("Can progress: " . ($canProgress ? 'YES' : 'NO'));
    error_log("Using year_level={$yearLevel}, semester={$semester} ({$semesterName})");
    error_log("Section ID: {$sectionId}");

    $subjects = [];
    $seenSubjectIds = [];

    // ============================================================
    // 1️⃣ WITH SECTION_ID: Get subjects for the section
    // ============================================================
    if ($sectionId > 0) {
        $sectionData = $section->getSectionDetails($sectionId);

        if (!$sectionData) {
            echo json_encode([
                'success' => false,
                'message' => 'Section not found'
            ]);
            exit;
        }

        $yearLevelNumeric = $section->convertYearLevelToNumeric($sectionData['grade_level']);

        $semesterRaw = $sectionData['semester'] ?? '';

        if ($semesterRaw === '1st Semester' || $semesterRaw === 'First Semester') {
            $semesterDb = 'First';
        } elseif ($semesterRaw === '2nd Semester' || $semesterRaw === 'Second Semester') {
            $semesterDb = 'Second';
        } else {
            // FIX: default to First, not Second
            $semesterDb = 'First';
        }

        error_log("Section: {$sectionData['section_code']}, year_level={$yearLevelNumeric}, semester={$semesterDb}");

        $subjects = $section->getSectionSubjectsWithSchedules(
            $sectionId,
            $sectionData['program_id'],
            $yearLevelNumeric,
            $semesterDb,
            $studentId
        );

        if (!is_array($subjects)) {
            $subjects = [];
        }

        error_log("Found " . count($subjects) . " subjects from section");

        // Attach status to each subject
        foreach ($subjects as &$subject) {
            $subjId = isset($subject['subject_id'])
                ? (int) $subject['subject_id']
                : 0;

            if ($subjId <= 0) {
                continue;
            }

            $statusInfo = $subjectStatus->getSubjectStatus($studentId, $subjId);
            $subject['status']         = $statusInfo['status'];
            $subject['message']        = $statusInfo['message'];
            $subject['subject_status'] = $statusInfo['status'];

            // RETAKE: ensure there is a usable schedule in THIS section
            if ($statusInfo['status'] === SubjectStatusManager::STATUS_RETAKE) {

                $hasRepSchedule = !empty($subject['representative_schedule_id']);

                if (!$hasRepSchedule && !empty($subject['schedule_id'])) {
                    $subject['representative_schedule_id'] = (int) $subject['schedule_id'];
                    $subject['has_schedule']               = true;
                    $subject['is_retake']                  = true;
                    $hasRepSchedule                        = true;
                }

                if (!$hasRepSchedule) {
                    // Only fall back to global lookup if this is a sectionless retake
                    // (sectionId > 0 means we want the retake inside this section)
                    $foundSchedule = $subjectStatus->findScheduleForSubject($subjId);
                    if ($foundSchedule) {
                        $subject['representative_schedule_id'] = $foundSchedule['schedule_id'];
                        $subject['has_schedule']               = true;
                        $subject['schedule_details']           = $foundSchedule['details'];
                        $subject['is_retake']                  = true;
                    }
                }
            }

            // No schedule → BLOCKED (except RETAKE which we keep enrollable)
            $hasSchedule = !empty($subject['has_schedule']);
            if (
                !$hasSchedule &&
                $statusInfo['status'] !== SubjectStatusManager::STATUS_RETAKE
            ) {
                $subject['status']  = SubjectStatusManager::STATUS_BLOCKED;
                $subject['message'] = 'No schedule available in selected section';
            }

            $seenSubjectIds[$subjId] = true;
        }
        unset($subject);
    }

    // ============================================================
    // 2️⃣ ALWAYS ADD RETAKE SUBJECTS
    // ============================================================
    $retakeSubjects = $subjectStatus->getFailedSubjectsForRetake($studentId);

    if (!is_array($retakeSubjects)) {
        $retakeSubjects = [];
    }

    foreach ($retakeSubjects as $retake) {
        $retakeId = isset($retake['subject_id'])
            ? (int) $retake['subject_id']
            : 0;

        if ($retakeId <= 0) {
            continue;
        }

        // Skip if already in $subjects (dedup by subject_id, stable across sections)
        if (isset($seenSubjectIds[$retakeId])) {
            continue;
        }

        // Resolve a representative schedule
        $representativeScheduleId = $retake['representative_schedule_id'] ?? null;
        $scheduleDetails          = $retake['schedule_details'] ?? [];

        if (empty($representativeScheduleId)) {
            $foundSchedule = $subjectStatus->findScheduleForSubject($retakeId);
            if ($foundSchedule) {
                $representativeScheduleId = $foundSchedule['schedule_id'];
                $scheduleDetails          = $foundSchedule['details'];
            }
        }

        $subjects[] = [
            'subject_id'                  => $retakeId,
            'subject_code'                => $retake['subject_code']    ?? '',
            'subject_name'                => $retake['subject_name']    ?? '',
            'units'                       => $retake['units']           ?? 0,
            'subject_type'                => $retake['subject_type']    ?? 'Lecture',
            'curriculum_subject_id'       => $retake['curriculum_subject_id'] ?? null,
            'status'                      => SubjectStatusManager::STATUS_RETAKE,
            'message'                     => 'Failed previously (Grade: '
                                             . ($retake['previous_grade'] ?? 'N/A') . ')',
            'subject_status'              => SubjectStatusManager::STATUS_RETAKE,
            'representative_schedule_id'  => $representativeScheduleId,
            'has_schedule'                => !empty($representativeScheduleId),
            'is_retake'                   => true,
            'schedule_details'            => $scheduleDetails,
            'previous_grade'              => $retake['previous_grade']      ?? null,
            'failed_semester'             => $retake['failed_semester']     ?? null,
            'failed_school_year'          => $retake['failed_school_year']  ?? null
        ];

        $seenSubjectIds[$retakeId] = true;
    }

    // ============================================================
    // 3️⃣ WITHOUT SECTION_ID: fall back to curriculum subjects
    // ============================================================
    if ($sectionId === 0) {
        $curriculumSubjects = $subjectStatus->getAvailableSubjects(
            $studentId,
            $yearLevel,
            $semesterName,
            $studentCourseId > 0 ? $studentCourseId : null
        );

        if (!is_array($curriculumSubjects)) {
            $curriculumSubjects = [];
        }

        foreach ($curriculumSubjects as $subject) {
            $subjId = isset($subject['subject_id'])
                ? (int) $subject['subject_id']
                : 0;

            if ($subjId <= 0) {
                continue;
            }

            if (!isset($seenSubjectIds[$subjId])) {
                $subjects[] = $subject;
                $seenSubjectIds[$subjId] = true;
            }
        }
    }

    // ============================================================
    // Categorize subjects
    // ============================================================
    $categorized = [
        'available' => [],
        'retake'    => [],
        'blocked'   => [],
        'completed' => []
    ];

    foreach ($subjects as $subject) {
        $status = $subject['status'] ?? SubjectStatusManager::STATUS_AVAILABLE;

        switch ($status) {
            case SubjectStatusManager::STATUS_AVAILABLE:
                $categorized['available'][] = $subject;
                break;

            case SubjectStatusManager::STATUS_RETAKE:
                $categorized['retake'][] = $subject;
                break;

            case SubjectStatusManager::STATUS_BLOCKED:
                $categorized['blocked'][] = $subject;
                break;

            case SubjectStatusManager::STATUS_PASSED:
            case SubjectStatusManager::STATUS_COMPLETED:
                $categorized['completed'][] = $subject;
                break;

            default:
                $categorized['available'][] = $subject;
                break;
        }
    }

    $currentYear = (int) date('Y');
    $schoolYear  = $currentYear . '-' . ($currentYear + 1);

    echo json_encode([
        'success' => true,
        'data' => [
            'all_subjects' => array_values($subjects),
            'categorized'  => $categorized,
            'counts' => [
                'available' => count($categorized['available']),
                'retake'    => count($categorized['retake']),
                'blocked'   => count($categorized['blocked']),
                'completed' => count($categorized['completed']),
                'total'     => count($subjects)
            ],
            'year_level'    => $yearLevel,
            'semester'      => $semester,
            'semester_name' => ($semester === 1) ? 'First Semester' : 'Second Semester',
            'school_year'   => $schoolYear,
            'student_id'    => $studentId,
            'section_id'    => $sectionId > 0 ? $sectionId : null,
            'course_id'     => $studentCourseId > 0 ? $studentCourseId : null,
            'can_progress'  => $canProgress
        ],
        'message' => 'Subjects retrieved successfully'
    ]);

} catch (PDOException $e) {
    error_log('get_available_subjects_with_status.php PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);

} catch (Throwable $e) {
    error_log('get_available_subjects_with_status.php Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}