<?php
// classes/SectionController.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • ajaxDebugSectionSubjects(): raw SQL used rs.subject_code/rs.subject_name
//     → changed to rs.code / rs.name (matches kms)
//   • Hardened all ID casts and null-safe count checks
//   • Preserved public API used by views/JS

require_once 'Section.php';
require_once 'Course.php';
require_once 'Database.php';
require_once 'Enrollment.php';

class SectionController {
    private $section;
    private $course;
    private $db;
    private $enrollment;

    public function __construct() {
        $this->section    = new Section();
        $this->course     = new Course();
        $this->db         = Database::getInstance();
        $this->enrollment = new Enrollment();
    }

    /* ============================================================
       ROUTER
    ============================================================ */

    public function handleRequest() {
        if (isset($_GET['ajax'])) {
            $this->handleAjaxRequest();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $this->handlePostRequest();
            return;
        }

        if (isset($_GET['action'])) {
            $this->handleGetRequest();
        }
    }

    /* ============================================================
       AJAX ROUTER
    ============================================================ */

    private function handleAjaxRequest() {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

        try {
            if (empty($_GET['ajax'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid AJAX action']);
                exit;
            }

            switch ($_GET['ajax']) {
                // Section management
                case 'get_sections':                    $this->ajaxGetSections();                    break;
                case 'get_section_details':             $this->ajaxGetSectionDetails();             break;
                case 'get_available_sections':          $this->ajaxGetAvailableSections();          break;
                case 'get_section_stats':               $this->ajaxGetSectionStats();               break;
                case 'get_sections_by_course':          $this->ajaxGetSectionsByCourse();           break;
                case 'get_sections_by_semester':        $this->ajaxGetSectionsBySemester();         break;
                case 'get_sections_by_year':            $this->ajaxGetSectionsByYear();             break;

                // Capacity
                case 'update_capacity':                 $this->ajaxUpdateCapacity();                break;
                case 'get_section_capacity':            $this->ajaxGetSectionCapacity();            break;

                // Subjects & schedules
                case 'get_section_subjects':            $this->ajaxGetSectionSubjects();            break;
                case 'get_subject_schedules':           $this->ajaxGetSubjectSchedules();           break;

                // Enrollment
                case 'get_sections_for_enrollment':     $this->ajaxGetSectionsForEnrollment();     break;
                case 'get_sections_for_applicant':      $this->ajaxGetSectionsForApplicant();      break;
                case 'get_section_enrollment_count':    $this->ajaxGetSectionEnrollmentCount();    break;

                // Validation
                case 'validate_section':                $this->ajaxValidateSection();              break;
                case 'check_section_availability':      $this->ajaxCheckSectionAvailability();     break;

                // Debug
                case 'debug_section_subjects':          $this->ajaxDebugSectionSubjects();         break;

                default:
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid AJAX action: ' . $_GET['ajax']
                    ]);
            }
        } catch (Exception $e) {
            error_log('AJAX Error in SectionController: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    /* ============================================================
       SECTION MANAGEMENT
    ============================================================ */

    private function ajaxGetSections() {
        $sections = $this->section->getAllSectionsWithDetails();
        if (!is_array($sections)) $sections = [];

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    }

    private function ajaxGetSectionDetails() {
        if (empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID required']);
            return;
        }

        $sectionId = (int) $_GET['section_id'];
        $section   = $this->section->getSectionDetails($sectionId);

        if ($section) {
            $section['enrollment_count'] = $this->section->countStudentsInSection($sectionId);
            echo json_encode(['success' => true, 'data' => $section]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Section not found']);
        }
    }

    private function ajaxGetAvailableSections() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId = (int) $_GET['course_id'];
        $sections = $this->section->getAvailableSections($courseId);
        if (!is_array($sections)) $sections = [];

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    }

    private function ajaxGetSectionStats() {
        echo json_encode([
            'success' => true,
            'data' => [
                'overall'   => $this->section->getSectionStats(),
                'by_year'   => $this->section->getSectionStatsByYearLevel(),
                'by_course' => $this->section->getSectionUtilizationByCourse()
            ]
        ]);
    }

    private function ajaxGetSectionsByCourse() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId = (int) $_GET['course_id'];
        $sections = $this->section->getSectionsByCourse($courseId);
        if (!is_array($sections)) $sections = [];

        foreach ($sections as &$section) {
            $section['student_count'] = $this->section->countStudentsInSection($section['id']);
        }
        unset($section);

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    }

    private function ajaxGetSectionsBySemester() {
        if (empty($_GET['semester'])) {
            echo json_encode(['success' => false, 'message' => 'Semester required']);
            return;
        }

        $semester = $_GET['semester'];
        $sections = $this->section->getSectionsBySemester($semester);
        if (!is_array($sections)) $sections = [];

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    }

    private function ajaxGetSectionsByYear() {
        if (empty($_GET['year_level'])) {
            echo json_encode(['success' => false, 'message' => 'Year level required']);
            return;
        }

        $yearLevel = (int) $_GET['year_level'];
        $sections  = $this->section->getSectionsByYearLevel($yearLevel);
        if (!is_array($sections)) $sections = [];

        echo json_encode([
            'success' => true,
            'data'    => $sections,
            'count'   => count($sections)
        ]);
    }

    /* ============================================================
       CAPACITY
    ============================================================ */

    private function ajaxUpdateCapacity() {
        if (!isset($_POST['section_id'], $_POST['max_students'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Section ID and max students required'
            ]);
            return;
        }

        $sectionId   = (int) $_POST['section_id'];
        $maxStudents = (int) $_POST['max_students'];

        $result = $this->section->updateSectionCapacity($sectionId, $maxStudents);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Capacity updated successfully',
                'data'    => $this->section->findById($sectionId)
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update capacity. New capacity cannot be less than current students.'
            ]);
        }
    }

    private function ajaxGetSectionCapacity() {
        if (empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID required']);
            return;
        }

        $sectionId = (int) $_GET['section_id'];
        $occupancy = $this->section->getOccupancyDetails($sectionId);

        if ($occupancy) {
            echo json_encode(['success' => true, 'data' => $occupancy]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Section not found']);
        }
    }

    /* ============================================================
       SUBJECTS & SCHEDULES
    ============================================================ */

    private function ajaxGetSectionSubjects() {
        if (!isset($_GET['section_id'], $_GET['course_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Section ID and Course ID required'
            ]);
            return;
        }

        $sectionId = (int) $_GET['section_id'];
        $courseId  = (int) $_GET['course_id'];
        $yearLevel = $_GET['year_level'] ?? '1st Year';
        $semester  = $_GET['semester']   ?? '1st Semester';
        $studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;

        $section = $this->section->getSectionDetails($sectionId);
        if (!$section) {
            echo json_encode(['success' => false, 'message' => 'Section not found']);
            return;
        }

        if ((int) $section['program_id'] !== $courseId) {
            echo json_encode([
                'success' => false,
                'message' => 'Section does not belong to the specified course'
            ]);
            return;
        }

        $subjects = $this->section->getSectionSubjectsWithSchedules(
            $sectionId, $courseId, $yearLevel, $semester, $studentId
        );
        if (!is_array($subjects)) $subjects = [];

        foreach ($subjects as &$subject) {
            if (!empty($subject['has_schedule'])) {
                $subject['schedule_details'] = $this->section->getSubjectScheduleDetails(
                    $subject['subject_id'],
                    $sectionId
                );
            }
        }
        unset($subject);

        $enrolledCount   = 0;
        $availableCount  = 0;
        $noScheduleCount = 0;

        foreach ($subjects as $s) {
            if (!empty($s['is_enrolled'])) {
                $enrolledCount++;
            } elseif (!empty($s['has_schedule'])) {
                $availableCount++;
            } else {
                $noScheduleCount++;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'section'            => $section,
                'subjects'           => $subjects,
                'total_subjects'     => count($subjects),
                'enrolled_count'     => $enrolledCount,
                'available_count'    => $availableCount,
                'no_schedule_count'  => $noScheduleCount
            ]
        ]);
    }

    private function ajaxGetSubjectSchedules() {
        if (!isset($_GET['subject_id'], $_GET['section_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Subject ID and Section ID required'
            ]);
            return;
        }

        $subjectId = (int) $_GET['subject_id'];
        $sectionId = (int) $_GET['section_id'];

        $schedules = $this->section->getSubjectScheduleDetails($subjectId, $sectionId);
        if (!is_array($schedules)) $schedules = [];

        echo json_encode([
            'success' => true,
            'data'    => $schedules,
            'count'   => count($schedules)
        ]);
    }

    /* ============================================================
       ENROLLMENT
    ============================================================ */

    private function ajaxGetSectionsForEnrollment() {
        if (empty($_GET['course_id'])) {
            echo json_encode(['success' => false, 'message' => 'Course ID required']);
            return;
        }

        $courseId    = (int) $_GET['course_id'];
        $yearLevel   = $_GET['year_level']  ?? '1st Year';
        $semester    = $_GET['semester']    ?? '1st Semester';
        $applicantId = isset($_GET['applicant_id']) ? (int) $_GET['applicant_id'] : 0;

        $sections = $this->section->getSectionsByCourseAndYearLevel($courseId, $yearLevel, $semester);

        if (empty($sections)) {
            $alternateSemester = ($semester === '1st Semester') ? '2nd Semester' : '1st Semester';
            $sections = $this->section->getSectionsByCourseAndYearLevel(
                $courseId, $yearLevel, $alternateSemester
            );
            if (!empty($sections)) {
                $semester = $alternateSemester;
            }
        }

        if (!is_array($sections)) $sections = [];

        $courseInfo    = $this->course->findById($courseId);
        $applicantName = '';
        $admissionType = '';

        if ($applicantId > 0) {
            require_once 'Application.php';
            $application   = new Application();
            $applicantData = $application->getApplicationById($applicantId);
            if ($applicantData) {
                $applicantName = trim(
                    $applicantData['first_name']
                    . ' ' . ($applicantData['middle_name'] ?? '')
                    . ' ' . $applicantData['surname']
                );
                $admissionType = $applicantData['admission_type'] ?? 'freshmen';
            }
        }

        $formattedSections = array_map(function ($sec) {
            $studentCount = $this->section->countStudentsInSection($sec['id']);
            $maxStudents  = 40;
            return [
                'id'               => $sec['id'],
                'section_code'     => $sec['section_code'],
                'grade_level'      => $sec['grade_level'],
                'semester'         => $sec['semester'],
                'school_year'      => $sec['school_year'],
                'program_id'       => $sec['program_id'],
                'course_code'      => $sec['course_code'] ?? '',
                'course_name'      => $sec['course_name'] ?? '',
                'current_students' => $studentCount,
                'max_students'     => $maxStudents,
                'available_slots'  => max(0, $maxStudents - $studentCount),
                'utilization'      => round(($studentCount / $maxStudents) * 100, 1)
            ];
        }, $sections);

        echo json_encode([
            'success' => true,
            'data' => [
                'sections'       => $formattedSections,
                'course_id'      => $courseId,
                'course_code'    => $courseInfo['code'] ?? '',
                'course_name'    => $courseInfo['name'] ?? '',
                'year_level'     => $yearLevel,
                'semester'       => $semester,
                'applicant_id'   => $applicantId,
                'applicant_name' => $applicantName,
                'admission_type' => $admissionType,
                'total_sections' => count($formattedSections)
            ],
            'message' => count($sections) > 0
                ? 'Sections retrieved successfully'
                : 'No sections available'
        ]);
    }

    private function ajaxGetSectionsForApplicant() {
        if (empty($_GET['applicant_id'])) {
            echo json_encode(['success' => false, 'message' => 'Applicant ID required']);
            return;
        }

        $applicantId       = (int) $_GET['applicant_id'];
        $selectedSectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;

        try {
            require_once 'Application.php';
            $application = new Application();
            $applicant   = $application->getApplicationById($applicantId);

            if (!$applicant) {
                echo json_encode(['success' => false, 'message' => 'Applicant not found']);
                return;
            }

            if (empty($applicant['course_id'])) {
                echo json_encode(['success' => false, 'message' => 'Applicant has no course assigned']);
                return;
            }

            $course = $this->course->findById($applicant['course_id']);
            if (!$course) {
                echo json_encode(['success' => false, 'message' => 'Course not found']);
                return;
            }

            // enr_applicants has no year_level/semester — derive from preferred_section_id
            $yearLevel         = '1st Year';
            $semester          = '1st Semester';
            $yearLevelNumeric  = 1;
            $semesterNumeric   = 1;

            if (!empty($applicant['preferred_section_id'])) {
                $prefSection = $this->section->getSectionDetails((int) $applicant['preferred_section_id']);
                if ($prefSection) {
                    $yearLevelNumeric = $this->section->convertYearLevelToNumeric(
                        $prefSection['grade_level'] ?? '1st Year'
                    );
                    $yearLevel = $this->section->convertYearLevelToText($yearLevelNumeric);

                    $semText = strtolower($prefSection['semester'] ?? '');
                    $semesterNumeric = (strpos($semText, 'second') !== false
                                        || strpos($semText, '2nd') !== false) ? 2 : 1;
                    $semester = ($semesterNumeric === 1) ? '1st Semester' : '2nd Semester';
                }
            }

            $sections = $this->section->getSectionsByCourseAndYearLevel(
                $applicant['course_id'], $yearLevel, $semester
            );

            if (empty($sections)) {
                $alternateSemester = ($semester === '1st Semester') ? '2nd Semester' : '1st Semester';
                $sections = $this->section->getSectionsByCourseAndYearLevel(
                    $applicant['course_id'], $yearLevel, $alternateSemester
                );
                if (!empty($sections)) {
                    $semester          = $alternateSemester;
                    $semesterNumeric   = ($semester === '1st Semester') ? 1 : 2;
                }
            }

            if (!is_array($sections)) $sections = [];

            // Existing student?
            $studentId = null;
            require_once 'Student.php';
            $student     = new Student();
            $studentData = $student->getStudentByApplicantId($applicantId);
            if ($studentData) {
                $studentId = (int) $studentData['student_id'];
            }

            $subjects = [];
            if ($selectedSectionId && $selectedSectionId > 0) {
                $subjects = $this->section->getSectionSubjectsWithSchedules(
                    $selectedSectionId,
                    $applicant['course_id'],
                    $yearLevelNumeric,
                    $semesterNumeric,
                    $studentId
                );
                if (!is_array($subjects)) $subjects = [];
            }

            $formattedSections = array_map(function ($sec) {
                $studentCount = $this->section->countStudentsInSection($sec['id']);
                return [
                    'section_id'       => $sec['id'],
                    'section_code'     => $sec['section_code'],
                    'grade_level'      => $sec['grade_level'],
                    'semester'         => $sec['semester'],
                    'school_year'      => $sec['school_year'],
                    'course_code'      => $sec['course_code'] ?? '',
                    'course_name'      => $sec['course_name'] ?? '',
                    'current_students' => $studentCount,
                    'available_slots'  => max(0, 40 - $studentCount)
                ];
            }, $sections);

            $formattedSubjects = array_map(function ($subj) {
                return [
                    'id'                         => $subj['subject_id'],
                    'subject_code'               => $subj['subject_code'],
                    'subject_name'               => $subj['subject_name'],
                    'units'                      => $subj['units'],
                    'has_schedule'               => $subj['has_schedule'],
                    'representative_schedule_id' => $subj['representative_schedule_id'],
                    'schedule_details'           => $subj['schedule_details'] ?? [],
                    'is_enrolled'                => $subj['is_enrolled'] ?? false
                ];
            }, $subjects);

            echo json_encode([
                'success' => true,
                'data' => [
                    'applicant' => [
                        'id'          => $applicant['applicant_id'],
                        'name'        => trim(
                            $applicant['first_name']
                            . ' ' . ($applicant['middle_name'] ?? '')
                            . ' ' . $applicant['surname']
                        ),
                        'course_id'   => $applicant['course_id'],
                        'course_code' => $course['code'],
                        'course_name' => $course['name'],
                        'year_level'  => $yearLevel,
                        'semester'    => $semester
                    ],
                    'sections'           => $formattedSections,
                    'subjects'           => $formattedSubjects,
                    'selected_section_id'=> $selectedSectionId,
                    'total_sections'     => count($formattedSections),
                    'total_subjects'     => count($formattedSubjects),
                    'has_student'        => $studentId !== null,
                    'student_id'         => $studentId
                ],
                'message' => count($sections) > 0
                    ? 'Sections and subjects retrieved successfully'
                    : 'No sections available'
            ]);

        } catch (Exception $e) {
            error_log('Error in ajaxGetSectionsForApplicant: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    private function ajaxGetSectionEnrollmentCount() {
        if (empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID required']);
            return;
        }

        $sectionId  = (int) $_GET['section_id'];
        $schoolYear = $_GET['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

        try {
            $sql = "SELECT COUNT(DISTINCT student_id) as student_count,
                           COUNT(enrollment_id) as subject_enrollment_count
                    FROM enr_enrollments
                    WHERE section_id = ?
                      AND school_year = ?
                      AND enrollment_status = 'enrolled'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sectionId, $schoolYear]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $section  = $this->section->getSectionDetails($sectionId);
            $subjects = [];

            if ($section) {
                $yearLevelNumeric = $this->section->convertYearLevelToNumeric($section['grade_level']);
                $semesterNumeric  = ($section['semester'] === '1st Semester') ? 1 : 2;

                $subjects = $this->section->getSectionSubjectsWithSchedules(
                    $sectionId,
                    $section['program_id'],
                    $yearLevelNumeric,
                    $semesterNumeric
                );
                if (!is_array($subjects)) $subjects = [];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'section_id'               => $sectionId,
                    'school_year'              => $schoolYear,
                    'student_count'            => (int) ($result['student_count'] ?? 0),
                    'subject_enrollment_count' => (int) ($result['subject_enrollment_count'] ?? 0),
                    'total_subjects'           => count($subjects),
                    'available_subjects'       => count(array_filter($subjects, function ($s) {
                        return !empty($s['has_schedule']) && empty($s['is_enrolled']);
                    }))
                ]
            ]);
        } catch (Exception $e) {
            error_log('Error in ajaxGetSectionEnrollmentCount: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    /* ============================================================
       VALIDATION
    ============================================================ */

    private function ajaxValidateSection() {
        if (!isset($_GET['section_id'], $_GET['course_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Section ID and Course ID required'
            ]);
            return;
        }

        $sectionId = (int) $_GET['section_id'];
        $courseId  = (int) $_GET['course_id'];
        $yearLevel = $_GET['year_level'] ?? '1st Year';
        $semester  = $_GET['semester']   ?? '1st Semester';

        $section = $this->section->getSectionDetails($sectionId);

        if (!$section) {
            echo json_encode([
                'success' => false,
                'valid'   => false,
                'message' => 'Section not found'
            ]);
            return;
        }

        $isValid = (
            (int) $section['program_id'] === $courseId
            && $section['grade_level'] === $yearLevel
            && $section['semester']    === $semester
        );

        echo json_encode([
            'success' => true,
            'valid'   => $isValid,
            'data'    => $section,
            'message' => $isValid
                ? 'Section is valid'
                : "Section does not match the applicant's course, year level, or semester"
        ]);
    }

    private function ajaxCheckSectionAvailability() {
        if (empty($_GET['section_id'])) {
            echo json_encode(['success' => false, 'message' => 'Section ID required']);
            return;
        }

        $sectionId     = (int) $_GET['section_id'];
        $studentCount  = $this->section->countStudentsInSection($sectionId);
        $maxStudents   = 40;
        $available     = max(0, $maxStudents - $studentCount);

        echo json_encode([
            'success' => true,
            'data' => [
                'section_id'           => $sectionId,
                'current_students'     => $studentCount,
                'max_students'         => $maxStudents,
                'available_slots'      => $available,
                'has_available_slots'  => $available > 0
            ]
        ]);
    }

    /* ============================================================
       DEBUG
       FIX: rgr_subjects uses `code` and `name`
    ============================================================ */

    private function ajaxDebugSectionSubjects() {
        if (!isset($_GET['section_id'], $_GET['course_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Section ID and Course ID required'
            ]);
            return;
        }

        $sectionId = (int) $_GET['section_id'];
        $courseId  = (int) $_GET['course_id'];
        $yearLevel = $_GET['year_level'] ?? '1st Year';
        $semester  = $_GET['semester']   ?? '1st Semester';
        $studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;

        $subjects = $this->section->getSectionSubjectsWithSchedules(
            $sectionId, $courseId, $yearLevel, $semester, $studentId
        );
        if (!is_array($subjects)) $subjects = [];

        $activeSchoolYear = $this->section->getActiveSchoolYear();
        $activeSemester   = $this->section->getActiveSemester();

        $yearLevelNumeric = $this->section->convertYearLevelToNumeric($yearLevel);
        $semesterDb       = $this->section->normalizeSemester($semester);

        $db = Database::getInstance();

        $curSql = "SELECT id FROM rgr_curriculums
                   WHERE course_id = ? AND is_active = 1 LIMIT 1";
        $curStmt = $db->prepare($curSql);
        $curStmt->execute([$courseId]);
        $curriculum = $curStmt->fetch(PDO::FETCH_ASSOC);

        $rawCurriculumSubjects = [];
        $rawSchedules          = [];

        if ($curriculum) {
            // FIX: `code` and `name`
            $subjSql = "SELECT rs.id,
                               rs.code AS subject_code,
                               rs.name AS subject_name,
                               rcs.year_level, rcs.semester
                        FROM rgr_curriculum_subjects rcs
                        JOIN rgr_subjects rs ON rcs.subject_id = rs.id
                        WHERE rcs.curriculum_id = ?
                          AND rcs.year_level = ?
                          AND rcs.semester = ?";
            $subjStmt = $db->prepare($subjSql);
            $subjStmt->execute([$curriculum['id'], $yearLevelNumeric, $semesterDb]);
            $rawCurriculumSubjects = $subjStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($activeSchoolYear && $activeSemester) {
            // FIX: `sub.code`
            $scheduleSql = "SELECT cs.*, sub.code AS subject_code
                            FROM cc_schedule cs
                            JOIN rgr_subjects sub ON cs.subject_id = sub.id
                            WHERE cs.section_id = ?
                              AND cs.school_year_id = ?
                              AND cs.semester_id = ?
                              AND cs.status = 'Scheduled'";
            $scheduleStmt = $db->prepare($scheduleSql);
            $scheduleStmt->execute([
                $sectionId,
                $activeSchoolYear['id'],
                $activeSemester['id']
            ]);
            $rawSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'debug' => [
                'section_id'              => $sectionId,
                'course_id'               => $courseId,
                'year_level_input'        => $yearLevel,
                'year_level_numeric'      => $yearLevelNumeric,
                'semester_input'          => $semester,
                'semester_normalized'     => $semesterDb,
                'student_id'              => $studentId,
                'active_school_year'      => $activeSchoolYear,
                'active_semester'         => $activeSemester,
                'curriculum'              => $curriculum,
                'raw_curriculum_subjects' => $rawCurriculumSubjects,
                'raw_schedules'           => $rawSchedules,
                'formatted_subjects'      => $subjects,
                'total_formatted'         => count($subjects),
                'has_schedule_count'      => count(array_filter($subjects, function ($s) {
                    return !empty($s['has_schedule']);
                }))
            ]
        ]);
    }

    /* ============================================================
       POST
    ============================================================ */

    private function handlePostRequest() {
        try {
            if (!isset($_POST['action'])) {
                $_SESSION['message'] = '❌ No action specified.';
                $this->redirectBack();
                return;
            }

            switch ($_POST['action']) {
                case 'create':          $this->handleCreate();          break;
                case 'update_capacity': $this->handleUpdateCapacity();  break;
                case 'delete':          $this->handleDelete();          break;
                case 'bulk_delete':     $this->handleBulkDelete();      break;
                default:
                    $_SESSION['message'] = '❌ Invalid action specified.';
            }
        } catch (Exception $e) {
            error_log('POST Error in SectionController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        $this->redirectBack();
    }

    private function redirectBack() {
        $redirect = $_POST['redirect'] ?? '?page=sections';
        header('Location: ' . $redirect);
        exit;
    }

    /* ============================================================
       GET
    ============================================================ */

    private function handleGetRequest() {
        try {
            if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
                $this->handleDelete();
            }
        } catch (Exception $e) {
            error_log('GET Error in SectionController: ' . $e->getMessage());
            $_SESSION['message'] = '❌ Error: ' . $e->getMessage();
        }

        $redirect = $_GET['redirect'] ?? '?page=sections';
        header('Location: ' . $redirect);
        exit;
    }

    /* ============================================================
       POST HANDLERS
    ============================================================ */

    private function handleCreate() {
        $required = ['program_id', 'year_level', 'semester', 'section_number'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $_SESSION['message'] = "❌ Missing required field: {$field}";
                return;
            }
        }

        $data = [
            'program_id'     => (int) $_POST['program_id'],
            'year_level'     => (int) $_POST['year_level'],
            'semester'       => $_POST['semester'],
            'section_number' => (int) $_POST['section_number'],
            'max_students'   => isset($_POST['max_students']) ? (int) $_POST['max_students'] : 40,
            'school_year'    => $_POST['school_year'] ?? (date('Y') . '-' . (date('Y') + 1))
        ];

        $result = $this->section->createSection($data);

        $_SESSION['message'] = (!empty($result['success']) ? '✅ ' : '❌ ')
            . ($result['message'] ?? 'Unknown error');
    }

    private function handleUpdateCapacity() {
        if (!isset($_POST['section_id'], $_POST['max_students'])) {
            $_SESSION['message'] = '❌ Section ID and max students required.';
            return;
        }

        $result = $this->section->updateSectionCapacity(
            (int) $_POST['section_id'],
            (int) $_POST['max_students']
        );

        $_SESSION['message'] = $result
            ? '✅ Section capacity updated successfully!'
            : '❌ Failed to update section capacity.';
    }

    private function handleDelete() {
        $id = isset($_POST['section_id'])
            ? (int) $_POST['section_id']
            : (isset($_GET['id']) ? (int) $_GET['id'] : null);

        if (!$id) {
            $_SESSION['message'] = '❌ Section ID required for deletion.';
            return;
        }

        $result = $this->section->deleteSection($id);
        $_SESSION['message'] = (!empty($result['success']) ? '✅ ' : '❌ ')
            . ($result['message'] ?? 'Unknown error');
    }

    private function handleBulkDelete() {
        if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
            $_SESSION['message'] = '❌ No sections selected for deletion.';
            return;
        }

        $successCount = 0;
        $failCount    = 0;
        $errors       = [];

        foreach ($_POST['ids'] as $id) {
            $result = $this->section->deleteSection((int) $id);
            if (!empty($result['success'])) {
                $successCount++;
            } else {
                $failCount++;
                $errors[] = "Section ID {$id}: " . ($result['message'] ?? 'Unknown error');
            }
        }

        if ($successCount > 0 && $failCount > 0) {
            $_SESSION['message'] = "⚠️ {$successCount} sections deleted, {$failCount} failed. " . implode(' ', $errors);
        } elseif ($successCount > 0) {
            $_SESSION['message'] = "✅ {$successCount} sections deleted successfully.";
        } else {
            $_SESSION['message'] = "❌ Failed to delete sections: " . implode(' ', $errors);
        }
    }

    /* ============================================================
       PUBLIC METHODS FOR VIEWS
    ============================================================ */

    public function getSections() {
        return $this->section->getAllSectionsWithDetails();
    }

    public function getCourses() {
        $sql  = "SELECT * FROM rgr_courses ORDER BY code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getSectionById($id) {
        return $this->section->getSectionDetails($id);
    }

    public function getSectionsByCourse($courseId) {
        return $this->section->getSectionsByCourse($courseId);
    }

    public function getSectionStats() {
        return $this->section->getSectionStats();
    }

    public function getSectionUtilizationByCourse() {
        return $this->section->getSectionUtilizationByCourse();
    }

    public function getSectionStatsByYearLevel() {
        return $this->section->getSectionStatsByYearLevel();
    }

    public function getFullSections() {
        return $this->section->getFullSections();
    }

    public function getAvailableSectionsList() {
        return $this->section->getAvailableSectionsList();
    }

    public function getSectionsWithStudentCount() {
        return $this->section->getSectionsWithStudentCount();
    }

    public function getSectionsBySemester($semester) {
        return $this->section->getSectionsBySemester($semester);
    }

    public function getSectionsByYearLevel($yearLevel) {
        return $this->section->getSectionsByYearLevel($yearLevel);
    }

    public function getSectionsWithCapacityStatus() {
        return $this->section->getSectionsWithCapacityStatus();
    }

    public function sectionCodeExists($sectionCode, $semester, $schoolYear) {
        return $this->section->sectionCodeExists($sectionCode, $semester, $schoolYear);
    }

    public function getTotalSectionsCount() {
        try {
            $sql  = "SELECT COUNT(*) as count FROM cc_sections";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error getting total sections count: ' . $e->getMessage());
            return 0;
        }
    }

    public function getSectionsBySchoolYear($schoolYear) {
        return $this->section->getSectionsBySchoolYear($schoolYear);
    }

    public function getAvailableSectionsBySemester($semester) {
        return $this->section->getAvailableSectionsBySemester($semester);
    }

    /* ============================================================
       PUBLIC — ENROLLMENT
    ============================================================ */

    public function getSectionsForApplicant($applicantId, $selectedSectionId = null) {
        try {
            require_once 'Application.php';
            $application = new Application();
            $applicant   = $application->getApplicationById($applicantId);

            if (!$applicant) {
                return ['success' => false, 'message' => 'Applicant not found'];
            }
            if (empty($applicant['course_id'])) {
                return ['success' => false, 'message' => 'Applicant has no course assigned'];
            }

            $course = $this->course->findById($applicant['course_id']);
            if (!$course) {
                return ['success' => false, 'message' => 'Course not found'];
            }

            $yearLevel        = '1st Year';
            $semester         = '1st Semester';
            $yearLevelNumeric = 1;
            $semesterNumeric  = 1;

            if (!empty($applicant['preferred_section_id'])) {
                $prefSection = $this->section->getSectionDetails((int) $applicant['preferred_section_id']);
                if ($prefSection) {
                    $yearLevelNumeric = $this->section->convertYearLevelToNumeric(
                        $prefSection['grade_level'] ?? '1st Year'
                    );
                    $yearLevel = $this->section->convertYearLevelToText($yearLevelNumeric);

                    $semText = strtolower($prefSection['semester'] ?? '');
                    $semesterNumeric = (strpos($semText, 'second') !== false
                                        || strpos($semText, '2nd') !== false) ? 2 : 1;
                    $semester = ($semesterNumeric === 1) ? '1st Semester' : '2nd Semester';
                }
            }

            $sections = $this->section->getSectionsByCourseAndYearLevel(
                $applicant['course_id'], $yearLevel, $semester
            );

            if (empty($sections)) {
                $alternateSemester = ($semester === '1st Semester') ? '2nd Semester' : '1st Semester';
                $sections = $this->section->getSectionsByCourseAndYearLevel(
                    $applicant['course_id'], $yearLevel, $alternateSemester
                );
                if (!empty($sections)) {
                    $semester        = $alternateSemester;
                    $semesterNumeric = ($semester === '1st Semester') ? 1 : 2;
                }
            }

            if (!is_array($sections)) $sections = [];

            $subjects = [];
            if ($selectedSectionId && $selectedSectionId > 0) {
                $subjects = $this->section->getSectionSubjectsWithSchedules(
                    $selectedSectionId,
                    $applicant['course_id'],
                    $yearLevelNumeric,
                    $semesterNumeric
                );
                if (!is_array($subjects)) $subjects = [];
            }

            return [
                'success' => true,
                'data' => [
                    'applicant' => [
                        'id'          => $applicant['applicant_id'],
                        'name'        => trim(
                            $applicant['first_name']
                            . ' ' . ($applicant['middle_name'] ?? '')
                            . ' ' . $applicant['surname']
                        ),
                        'course_id'   => $applicant['course_id'],
                        'course_code' => $course['code'],
                        'course_name' => $course['name'],
                        'year_level'  => $yearLevel,
                        'semester'    => $semester
                    ],
                    'sections'        => $sections,
                    'subjects'        => $subjects,
                    'selected_section_id' => $selectedSectionId,
                    'total_sections'  => count($sections),
                    'total_subjects'  => count($subjects)
                ],
                'message' => count($sections) > 0
                    ? 'Sections and subjects retrieved successfully'
                    : 'No sections available'
            ];
        } catch (Exception $e) {
            error_log('Error in getSectionsForApplicant: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function getSectionSubjects($sectionId, $courseId, $yearLevel, $semester, $studentId = null) {
        return $this->section->getSectionSubjectsWithSchedules(
            $sectionId, $courseId, $yearLevel, $semester, $studentId
        );
    }

    public function getSubjectSchedules($subjectId, $sectionId) {
        return $this->section->getSubjectScheduleDetails($subjectId, $sectionId);
    }

    public function validateSection($sectionId, $courseId, $yearLevel, $semester) {
        $section = $this->section->getSectionDetails($sectionId);

        if (!$section) {
            return ['valid' => false, 'message' => 'Section not found'];
        }

        $isValid = (
            (int) $section['program_id'] === (int) $courseId
            && $section['grade_level'] === $yearLevel
            && $section['semester']    === $semester
        );

        return [
            'valid'   => $isValid,
            'data'    => $section,
            'message' => $isValid
                ? 'Section is valid'
                : "Section does not match the applicant's course, year level, or semester"
        ];
    }

    public function getSectionEnrollmentCount($sectionId, $schoolYear = null) {
        if (!$schoolYear) {
            $schoolYear = date('Y') . '-' . (date('Y') + 1);
        }

        try {
            $sql = "SELECT COUNT(DISTINCT student_id) as student_count,
                           COUNT(enrollment_id) as subject_enrollment_count
                    FROM enr_enrollments
                    WHERE section_id = ?
                      AND school_year = ?
                      AND enrollment_status = 'enrolled'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sectionId, $schoolYear]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error in getSectionEnrollmentCount: ' . $e->getMessage());
            return ['student_count' => 0, 'subject_enrollment_count' => 0];
        }
    }
}