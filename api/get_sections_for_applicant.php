<?php
/**
 * API endpoint to get available sections for an applicant with subject enrollment info
 *
 * RULE: ONE STUDENT + ONE SCHEDULE + ONE SCHOOL YEAR = ONE ENROLLMENT
 *
 * NOTE: enr_applicants has no year_level / semester columns.
 *       We fall back to the section's own data if available, otherwise 1st Year / 1st Semester.
 *
 * FIXES IN THIS VERSION:
 *   • Single grouped query for section counts (no N+1)
 *   • Single grouped query for section schedules (no N+1)
 *   • Curriculum subject fetch now matches the ACTUAL section's year_level + semester
 *     (not the applicant's nominal value)
 *   • Reads active school_year / semester ids from DB — no magic numbers
 *   • Handles 'First'/'Second' AND 1/2 in rgr_curriculum_subjects.semester
 *   • Configurable MAX_STUDENTS via ?max=
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

try {
    $basePath = dirname(__DIR__);
    require_once $basePath . '/classes/Database.php';
    require_once $basePath . '/classes/Model.php';
    require_once $basePath . '/classes/Application.php';
    require_once $basePath . '/classes/Section.php';
    require_once $basePath . '/classes/Course.php';
    require_once $basePath . '/classes/Enrollment.php';

    $applicantId = isset($_GET['applicant_id']) ? (int) $_GET['applicant_id'] : 0;
    $studentId   = isset($_GET['student_id'])   ? (int) $_GET['student_id']   : null;

    if ($applicantId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid applicant ID']);
        exit;
    }

    $MAX_STUDENTS = 40;
    if (isset($_GET['max']) && is_numeric($_GET['max'])) {
        $MAX_STUDENTS = max(1, min(500, (int) $_GET['max']));
    }

    $db          = Database::getInstance();
    $application = new Application();
    $section     = new Section();
    $course      = new Course();
    $enrollment  = new Enrollment();

    $applicant = $application->getApplicationById($applicantId);

    if (!$applicant) {
        echo json_encode(['success' => false, 'message' => 'Applicant not found']);
        exit;
    }

    if (empty($applicant['course_id'])) {
        echo json_encode(['success' => false, 'message' => 'Applicant has no course assigned']);
        exit;
    }

    $courseInfo = $course->findById($applicant['course_id']);

    if (!$courseInfo) {
        echo json_encode(['success' => false, 'message' => 'Course not found']);
        exit;
    }

    // ------------------------------------------------------------
    // Year level + semester (applicant may not have these)
    // ------------------------------------------------------------
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

    // ------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------
    $availableSections = $section->getSectionsByCourseAndYearLevel(
        $applicant['course_id'],
        $yearLevel,
        $semester
    );

    if (empty($availableSections)) {
        $alternateSemester = ($semester === '1st Semester') ? '2nd Semester' : '1st Semester';
        $availableSections = $section->getSectionsByCourseAndYearLevel(
            $applicant['course_id'],
            $yearLevel,
            $alternateSemester
        );
        if (!empty($availableSections)) {
            $semester = $alternateSemester;
        }
    }

    if (!is_array($availableSections)) {
        $availableSections = [];
    }

    // ------------------------------------------------------------
    // Resolve school year / semester IDs from DB
    // ------------------------------------------------------------
    $schoolYear = date('Y') . '-' . (date('Y') + 1);

    $schoolYearId = null;
    try {
        $syStmt = $db->prepare("SELECT id, name FROM rgr_school_years WHERE is_active = 1 LIMIT 1");
        $syStmt->execute();
        $syRow = $syStmt->fetch(PDO::FETCH_ASSOC);
        if ($syRow) {
            $schoolYearId = (int) $syRow['id'];
            if (!empty($syRow['name'])) {
                $schoolYear = $syRow['name'];
            }
        }
    } catch (Exception $e) {
        error_log('get_sections_for_applicant: could not resolve active SY: ' . $e->getMessage());
    }

    $semesterId = null;
    try {
        $semStmt = $db->prepare("SELECT id FROM rgr_semesters WHERE is_active = 1 LIMIT 1");
        $semStmt->execute();
        $semRow = $semStmt->fetch(PDO::FETCH_ASSOC);
        if ($semRow) {
            $semesterId = (int) $semRow['id'];
        }
    } catch (Exception $e) {
        error_log('get_sections_for_applicant: could not resolve active semester: ' . $e->getMessage());
    }

    // ------------------------------------------------------------
    // Existing enrollments (schedule_ids) for student
    // ------------------------------------------------------------
    $enrolledScheduleIds = [];

    if ($studentId) {
        $enrollSql = "
            SELECT schedule_id
            FROM enr_enrollments
            WHERE student_id = ?
              AND school_year = ?
              AND enrollment_status = 'enrolled'
              AND schedule_id IS NOT NULL
        ";
        $enrollStmt = $db->prepare($enrollSql);
        $enrollStmt->execute([$studentId, $schoolYear]);
        $rows = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);
        $enrolledScheduleIds = array_map('intval', array_column($rows, 'schedule_id'));
    }

    // ------------------------------------------------------------
    // Collect section IDs
    // ------------------------------------------------------------
    $sectionIds = [];
    foreach ($availableSections as $s) {
        if (!empty($s['id'])) {
            $sectionIds[] = (int) $s['id'];
        }
    }
    $sectionIds = array_values(array_unique($sectionIds));

    // ------------------------------------------------------------
    // Single grouped query: student counts per section
    // (scoped to active SY for consistency)
    // ------------------------------------------------------------
    $countsBySection = [];

    if (!empty($sectionIds)) {
        try {
            $ph = implode(',', array_fill(0, count($sectionIds), '?'));

            if ($schoolYearId !== null) {
                $countSql = "SELECT e.section_id,
                                    COUNT(DISTINCT e.student_id) AS count
                             FROM enr_enrollments e
                             INNER JOIN cc_sections sec ON sec.id = e.section_id
                             WHERE e.section_id IN ($ph)
                               AND e.enrollment_status = 'enrolled'
                               AND sec.school_year_id = ?
                             GROUP BY e.section_id";
                $countParams = array_merge($sectionIds, [$schoolYearId]);
            } else {
                $countSql = "SELECT e.section_id,
                                    COUNT(DISTINCT e.student_id) AS count
                             FROM enr_enrollments e
                             WHERE e.section_id IN ($ph)
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
            error_log('get_sections_for_applicant count error: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------
    // Single grouped query: schedules per section
    // ------------------------------------------------------------
    // Map: section_id => [subject_id => schedule_id]
    $schedulesBySection = [];

    if (!empty($sectionIds) && $schoolYearId !== null && $semesterId !== null) {
        try {
            $ph = implode(',', array_fill(0, count($sectionIds), '?'));

            $schedSql = "SELECT cs.section_id, cs.id AS schedule_id, cs.subject_id
                         FROM cc_schedule cs
                         WHERE cs.section_id IN ($ph)
                           AND cs.school_year_id = ?
                           AND cs.semester_id = ?";
            $schedParams = array_merge($sectionIds, [$schoolYearId, $semesterId]);

            $schedStmt = $db->prepare($schedSql);
            $schedStmt->execute($schedParams);

            while ($row = $schedStmt->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int) $row['section_id'];
                $sub = (int) $row['subject_id'];
                if (!isset($schedulesBySection[$sid])) {
                    $schedulesBySection[$sid] = [];
                }
                $schedulesBySection[$sid][$sub] = (int) $row['schedule_id'];
            }
        } catch (Exception $e) {
            error_log('get_sections_for_applicant schedule error: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------
    // Cache of curriculum subjects keyed by "levelNum|semesterDb"
    // ------------------------------------------------------------
    $curriculumCache = [];

    $fetchCurriculum = function ($courseId, $levelNum, $semesterDb, $db) use (&$curriculumCache) {
        $key = $courseId . '|' . $levelNum . '|' . $semesterDb;
        if (isset($curriculumCache[$key])) {
            return $curriculumCache[$key];
        }

        $sql = "
            SELECT
                rs.id,
                rs.code AS subject_code,
                rs.name AS subject_name,
                rs.units,
                rs.lecture_hours,
                rs.lab_hours,
                rcs.year_level,
                rcs.semester,
                rcs.id AS curriculum_subject_id
            FROM rgr_curriculum_subjects rcs
            INNER JOIN rgr_subjects rs
                ON rcs.subject_id = rs.id
            INNER JOIN rgr_curriculums cur
                ON rcs.curriculum_id = cur.id
            WHERE cur.course_id = ?
              AND cur.is_active = 1
              AND rcs.year_level = ?
              AND (rcs.semester = ? OR rcs.semester = ?)
            ORDER BY rs.code
        ";

        // Bind both text and int variants of the semester to support
        // either schema convention.
        $semesterInt = ($semesterDb === 'First') ? 1 : 2;

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$courseId, $levelNum, $semesterDb, $semesterInt]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = [];
        } catch (Exception $e) {
            error_log('get_sections_for_applicant curriculum error: ' . $e->getMessage());
            $rows = [];
        }

        $curriculumCache[$key] = $rows;
        return $rows;
    };

    // ------------------------------------------------------------
    // Format sections
    // ------------------------------------------------------------
    $levelMap = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];

    $formattedSections = [];

    foreach ($availableSections as $sec) {
        $sectionId = (int) $sec['id'];

        // Resolve section's actual year + semester for curriculum lookup
        $secLevelText = $sec['grade_level'] ?? $yearLevel;
        $secLevelNum  = $levelMap[$secLevelText] ?? 1;

        $secSemRaw  = $sec['semester'] ?? $semester;
        $secSemText = 'First';
        if (is_string($secSemRaw) && (stripos($secSemRaw, 'second') !== false || stripos($secSemRaw, '2nd') !== false)) {
            $secSemText = 'Second';
        } elseif (is_numeric($secSemRaw) && (int) $secSemRaw === 2) {
            $secSemText = 'Second';
        }

        // FIX: use section's own level/semester for curriculum lookup
        $curriculumSubjects = $fetchCurriculum(
            (int) $applicant['course_id'],
            $secLevelNum,
            $secSemText,
            $db
        );

        $currentCount = $countsBySection[$sectionId] ?? 0;

        $scheduleMap = $schedulesBySection[$sectionId] ?? [];

        $subjects = [];
        $enrolledCount = 0;

        foreach ($curriculumSubjects as $subject) {
            $subjectId  = (int) $subject['id'];
            $scheduleId = $scheduleMap[$subjectId] ?? null;
            $isEnrolled = $scheduleId !== null && in_array($scheduleId, $enrolledScheduleIds, true);

            if ($isEnrolled) {
                $enrolledCount++;
            }

            $subjects[] = [
                'subject_id'        => $subjectId,
                'subject_code'      => $subject['subject_code'],
                'subject_name'      => $subject['subject_name'],
                'units'             => (int) $subject['units'],
                'schedule_id'       => $scheduleId,
                'is_enrolled'       => $isEnrolled,
                'enrollment_status' => $isEnrolled ? 'enrolled' : 'pending'
            ];
        }

        $formattedSections[] = [
            'section_id'        => $sectionId,
            'section_code'      => $sec['section_code'] ?? '',
            'grade_level'       => $sec['grade_level'] ?? $yearLevel,
            'semester'          => $sec['semester'] ?? $semester,
            'school_year'       => $sec['school_year'] ?? $schoolYear,
            'max_students'      => $MAX_STUDENTS,
            'current_students'  => $currentCount,
            'available_slots'   => max(0, $MAX_STUDENTS - $currentCount),
            'course_code'       => $sec['course_code'] ?? '',
            'course_name'       => $sec['course_name'] ?? '',
            'subjects'          => $subjects,
            'total_subjects'    => count($subjects),
            'enrolled_subjects' => $enrolledCount
        ];
    }

    $totalSections = count($formattedSections);
    $totalSubjects = 0;
    foreach ($formattedSections as $fs) {
        $totalSubjects += (int) $fs['total_subjects'];
    }

    echo json_encode([
        'success'        => true,
        'sections'       => $formattedSections,
        'course_id'      => (int) $applicant['course_id'],
        'course_code'    => $courseInfo['code'],
        'course_name'    => $courseInfo['name'],
        'year_level'     => $yearLevel,
        'semester'       => $semester,
        'school_year'    => $schoolYear,
        'applicant_name' => trim(
            ($applicant['first_name'] ?? '')
            . ' ' . ($applicant['middle_name'] ?? '')
            . ' ' . ($applicant['surname'] ?? '')
        ),
        'total_sections' => $totalSections,
        'total_subjects' => $totalSubjects,
        'has_student_id' => !is_null($studentId),
        'message'        => $totalSections > 0 ? 'Sections found' : 'No available sections'
    ]);

} catch (PDOException $e) {
    error_log('get_sections_for_applicant PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('get_sections_for_applicant Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}