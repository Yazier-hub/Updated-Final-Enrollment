<?php
// classes/Section.php - FULLY FIXED for `kms` schema (no new columns)
//
// KEY FIX:
//   cc_sections has NO `semester` / `school_year` columns.
//   Only `semester_id` and `school_year_id` (FKs to rgr_semesters/rgr_school_years).
//   Every method now JOINs to those tables and aliases `name` back to
//   `semester` / `school_year` so downstream code keeps working.
//   Filters now use sem.name / sy.name instead of s.semester / s.school_year.

require_once 'Model.php';

class Section extends Model {
    protected $table      = 'cc_sections';
    protected $primaryKey = 'id';
    protected $fillable = [
        'section_code', 'program_id', 'grade_level',
        'semester_id', 'school_year_id', 'adviser_id'
    ];

    private $hasCapacityColumns = false;

    public function __construct() {
        parent::__construct();
        $this->checkCapacityColumns();
    }

    private function checkCapacityColumns() {
        try {
            $sql  = "SHOW COLUMNS FROM cc_sections LIKE 'max_students'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $this->hasCapacityColumns = $stmt->fetch() ? true : false;
        } catch (Exception $e) {
            $this->hasCapacityColumns = false;
        }
    }

    /* ============================================================
       YEAR LEVEL / SEMESTER HELPERS
    ============================================================ */

    public function generateSectionCode($programCode, $yearLevel, $sectionNumber) {
        $yearNum = (int) $yearLevel;
        return $programCode . '-' . $yearNum . str_pad($sectionNumber, 3, '0', STR_PAD_LEFT);
    }

    public function convertYearLevelToText($yearLevel) {
        $levels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
        return $levels[(int) $yearLevel] ?? '1st Year';
    }

    public function convertYearLevelToNumeric($yearLevelText) {
        if (is_numeric($yearLevelText)) return (int) $yearLevelText;
        $map = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
        return $map[$yearLevelText] ?? 1;
    }

    /**
     * Normalize semester to DB format ('First' or 'Second').
     */
    public function normalizeSemester($semester) {
        if (is_numeric($semester)) {
            return ((int) $semester === 1) ? 'First' : 'Second';
        }

        $s = strtolower(trim((string) $semester));

        if (strpos($s, 'first') !== false
            || strpos($s, '1st') !== false
            || $s === '1'
            || $s === 'first semester') {
            return 'First';
        }

        if (strpos($s, 'second') !== false
            || strpos($s, '2nd') !== false
            || $s === '2'
            || $s === 'second semester') {
            return 'Second';
        }

        error_log("WARNING: Unrecognized semester format: '{$semester}', defaulting to First");
        return 'First';
    }

    /* ============================================================
       HELPERS: resolve semester_id / school_year_id from text
    ============================================================ */

    private function findSemesterIdByName($name) {
        // Match flexibly: '1st Semester' → 'First Semester' → 'First'
        $sql = "SELECT id FROM rgr_semesters
                WHERE name = ?
                   OR name = ?
                   OR name = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            $name,
            $this->normalizeSemester($name) . ' Semester',
            $this->normalizeSemester($name)
        ]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    private function findSchoolYearIdByName($name) {
        $sql  = "SELECT id FROM rgr_school_years WHERE name = ? LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    /* ============================================================
       CREATE
    ============================================================ */

    public function createSection($data) {
        try {
            if (empty($data['section_code'])) {
                $sql = "SELECT code FROM rgr_courses WHERE id = ?";
                $stmt = $this->connection->prepare($sql);
                $stmt->execute([$data['program_id']]);
                $course = $stmt->fetch();
                if (!$course) {
                    return ['success' => false, 'message' => 'Course not found'];
                }
                $yearLevel     = $data['year_level']     ?? 1;
                $sectionNumber = $data['section_number'] ?? 1;
                $data['section_code'] = $this->generateSectionCode(
                    $course['code'], $yearLevel, $sectionNumber
                );
            }

            if (isset($data['year_level']) && !isset($data['grade_level'])) {
                $data['grade_level'] = $this->convertYearLevelToText((int) $data['year_level']);
                unset($data['year_level']);
            }

            // FIX: convert text semester/school_year to FK ids
            $semesterText   = $data['semester']    ?? '1st Semester';
            $schoolYearText = $data['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

            // If caller already provided ids, respect them
            if (empty($data['semester_id'])) {
                $semId = $this->findSemesterIdByName($semesterText);
                if (!$semId) {
                    return ['success' => false, 'message' => 'Semester not found: ' . $semesterText];
                }
                $data['semester_id'] = $semId;
            }
            if (empty($data['school_year_id'])) {
                $syId = $this->findSchoolYearIdByName($schoolYearText);
                if (!$syId) {
                    return ['success' => false, 'message' => 'School year not found: ' . $schoolYearText];
                }
                $data['school_year_id'] = $syId;
            }

            // Strip text fields that don't exist as columns
            unset($data['semester'], $data['school_year']);

            if ($this->hasCapacityColumns) {
                $data['max_students']     = $data['max_students'] ?? 40;
                $data['current_students'] = 0;
            }

            if ($this->sectionCodeExists(
                $data['section_code'],
                $semesterText,
                $schoolYearText
            )) {
                return ['success' => false, 'message' => 'Section code already exists'];
            }

            $result = $this->create($data);
            if ($result) {
                return ['success' => true, 'message' => 'Section created successfully', 'id' => $result];
            }
            return ['success' => false, 'message' => 'Failed to create section'];
        } catch (Exception $e) {
            error_log('Error in createSection: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /* ============================================================
       FETCHERS — all with JOINs to rgr_semesters / rgr_school_years
    ============================================================ */

    public function getAvailableSections($courseId) {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT s.*,
                               sem.name AS semester,
                               sy.name  AS school_year
                        FROM cc_sections s
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE s.program_id = ? AND s.current_students < s.max_students
                        ORDER BY s.grade_level, sem.name, s.section_code";
            } else {
                $sql = "SELECT s.*,
                               sem.name AS semester,
                               sy.name  AS school_year
                        FROM cc_sections s
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE s.program_id = ?
                        ORDER BY s.grade_level, sem.name, s.section_code";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$courseId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getAvailableSections: ' . $e->getMessage());
            return [];
        }
    }

    public function incrementStudentCount($sectionId) {
        if (!$this->hasCapacityColumns) return true;
        try {
            $sql  = "UPDATE cc_sections SET current_students = current_students + 1 WHERE id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$sectionId]);
        } catch (Exception $e) {
            error_log('Error in incrementStudentCount: ' . $e->getMessage());
            return false;
        }
    }

    public function decrementStudentCount($sectionId) {
        if (!$this->hasCapacityColumns) return true;
        try {
            $sql  = "UPDATE cc_sections SET current_students = current_students - 1
                     WHERE id = ? AND current_students > 0";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$sectionId]);
        } catch (Exception $e) {
            error_log('Error in decrementStudentCount: ' . $e->getMessage());
            return false;
        }
    }

    public function getAllSectionsWithDetails() {
        try {
            $sql = "SELECT s.*,
                           c.code as course_code, c.name as course_name, c.years as course_years,
                           sem.name AS semester,
                           sy.name  AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    ORDER BY c.code, s.grade_level, sem.name, s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getAllSectionsWithDetails: ' . $e->getMessage());
            return [];
        }
    }

    public function updateSectionCapacity($sectionId, $maxStudents) {
        if (!$this->hasCapacityColumns) return true;
        try {
            if ($maxStudents < 0) return false;
            $section = $this->findById($sectionId);
            if ($section && $maxStudents < $section['current_students']) return false;

            $sql  = "UPDATE cc_sections SET max_students = ? WHERE id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$maxStudents, $sectionId]);
        } catch (Exception $e) {
            error_log('Error in updateSectionCapacity: ' . $e->getMessage());
            return false;
        }
    }

    public function getSectionDetails($sectionId) {
        try {
            $sql = "SELECT s.*,
                           c.code as course_code, c.name as course_name, c.years as course_years,
                           sem.name AS semester,
                           sy.name  AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    WHERE s.id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$sectionId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getSectionDetails: ' . $e->getMessage());
            return null;
        }
    }

    public function getSectionsForEnrollment($courseId, $yearLevel) {
        try {
            $yearLevelText = $this->convertYearLevelToText((int) $yearLevel);
            if ($this->hasCapacityColumns) {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               (s.max_students - s.current_students) as available_slots
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE s.program_id = ? AND s.grade_level = ?
                          AND s.current_students < s.max_students
                        ORDER BY s.section_code";
            } else {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               40 as available_slots
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE s.program_id = ? AND s.grade_level = ?
                        ORDER BY s.section_code";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$courseId, $yearLevelText]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsForEnrollment: ' . $e->getMessage());
            return [];
        }
    }

    public function getSectionsByCourseAndYearLevel($courseId, $yearLevel, $semester = null) {
        try {
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           sem.name AS semester, sy.name AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    WHERE s.program_id = ? AND s.grade_level = ?";
            $params = [$courseId, $yearLevel];

            if ($semester !== null) {
                // Accept '1st Semester', 'First', 'First Semester', '1', 1
                $normalized = $this->normalizeSemester($semester); // 'First' or 'Second'
                $sql .= " AND (sem.name = ? OR sem.name = ? OR sem.name = ?)";
                $params[] = $semester;
                $params[] = $normalized;
                $params[] = $normalized . ' Semester';
            }

            $sql .= " ORDER BY s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsByCourseAndYearLevel: ' . $e->getMessage());
            return [];
        }
    }

    public function getSectionsByCourse($courseId) {
        try {
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           sem.name AS semester, sy.name AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    WHERE s.program_id = ?
                    ORDER BY s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$courseId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsByCourse: ' . $e->getMessage());
            return [];
        }
    }

    public function getSectionSubjectsWithSchedules($sectionId, $courseId, $yearLevel, $semester, $studentId = null) {
        try {
            $yearLevelNumeric = is_numeric($yearLevel)
                ? (int) $yearLevel
                : $this->convertYearLevelToNumeric($yearLevel);

            $semesterDb = $this->normalizeSemester($semester);

            $activeSchoolYear = $this->getActiveSchoolYear();
            $activeSemester   = $this->getActiveSemester();

            if (!$activeSchoolYear || !$activeSemester) {
                error_log("No active school year or semester found");
                return [];
            }

            $curSql = "SELECT id FROM rgr_curriculums WHERE course_id = ? AND is_active = 1 LIMIT 1";
            $curStmt = $this->connection->prepare($curSql);
            $curStmt->execute([$courseId]);
            $curriculum = $curStmt->fetch();

            if (!$curriculum) {
                error_log("No active curriculum found for course ID: " . $courseId);
                return [];
            }

            $sql = "SELECT
                        rs.id   AS subject_id,
                        rs.code AS subject_code,
                        rs.name AS subject_name,
                        rs.units,
                        rs.lecture_hours,
                        rs.lab_hours,
                        rcs.year_level,
                        rcs.semester
                    FROM rgr_curriculum_subjects rcs
                    JOIN rgr_subjects rs ON rcs.subject_id = rs.id
                    WHERE rcs.curriculum_id = ?
                      AND rcs.year_level = ?
                      AND rcs.semester = ?
                    ORDER BY rs.code ASC";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$curriculum['id'], $yearLevelNumeric, $semesterDb]);
            $curriculumSubjects = $stmt->fetchAll();

            if (empty($curriculumSubjects)) {
                error_log("No curriculum subjects found for curriculum_id={$curriculum['id']}, year={$yearLevelNumeric}, sem='{$semesterDb}'");
                return [];
            }

            $scheduleSql = "SELECT
                                cs.id AS schedule_id,
                                cs.subject_id,
                                cs.day_of_week,
                                cs.start_time,
                                cs.end_time,
                                cs.room_id,
                                cs.faculty_id,
                                r.room_code,
                                r.room_name,
                                r.building,
                                f.faculty_code,
                                CONCAT(f.first_name, ' ', IFNULL(f.middle_name, ''), ' ', f.last_name) AS faculty_name
                            FROM cc_schedule cs
                            LEFT JOIN cc_room r ON cs.room_id = r.id
                            LEFT JOIN cc_faculty f ON cs.faculty_id = f.id
                            WHERE cs.section_id = ?
                              AND cs.school_year_id = ?
                              AND cs.semester_id = ?
                              AND cs.status = 'Scheduled'
                              AND cs.schedule_type = 'Class'";

            $scheduleStmt = $this->connection->prepare($scheduleSql);
            $scheduleStmt->execute([
                $sectionId,
                $activeSchoolYear['id'],
                $activeSemester['id']
            ]);
            $sectionSchedules = $scheduleStmt->fetchAll();

            $scheduleMap = [];
            foreach ($sectionSchedules as $sched) {
                $subjId = (int) $sched['subject_id'];
                if (!isset($scheduleMap[$subjId])) {
                    $scheduleMap[$subjId] = [];
                }
                $scheduleMap[$subjId][] = [
                    'schedule_id'  => (int) $sched['schedule_id'],
                    'day'          => $sched['day_of_week'],
                    'start_time'   => $sched['start_time'],
                    'end_time'     => $sched['end_time'],
                    'room_code'    => $sched['room_code'] ?? '',
                    'room_name'    => $sched['room_name'] ?? '',
                    'building'     => $sched['building']  ?? '',
                    'faculty_name' => $sched['faculty_name'] ?? 'TBA'
                ];
            }

            $enrolledScheduleIds = [];
            if ($studentId) {
                $schoolYearStr = $activeSchoolYear['school_year']
                    ?? (date('Y') . '-' . (date('Y') + 1));

                $enrollSql = "SELECT schedule_id FROM enr_enrollments
                              WHERE student_id = ?
                                AND school_year = ?
                                AND enrollment_status = 'enrolled'";
                $enrollStmt = $this->connection->prepare($enrollSql);
                $enrollStmt->execute([$studentId, $schoolYearStr]);
                $enrolledScheduleIds = $enrollStmt->fetchAll(PDO::FETCH_COLUMN);
                $enrolledScheduleIds = array_map('intval', $enrolledScheduleIds);
            }

            $formattedSubjects = [];
            foreach ($curriculumSubjects as $subject) {
                $subjectId     = (int) $subject['subject_id'];
                $hasSchedule   = !empty($scheduleMap[$subjectId]);
                $scheduleId    = $hasSchedule ? $scheduleMap[$subjectId][0]['schedule_id'] : null;
                $scheduleDet   = $hasSchedule ? $scheduleMap[$subjectId] : [];
                $isEnrolled    = $scheduleId !== null
                                 && in_array($scheduleId, $enrolledScheduleIds, true);

                $formattedSubjects[] = [
                    'id'                         => $subjectId,
                    'subject_id'                 => $subjectId,
                    'subject_code'               => $subject['subject_code'],
                    'subject_name'               => $subject['subject_name'],
                    'units'                      => (int) $subject['units'],
                    'lecture_hours'              => (int) ($subject['lecture_hours'] ?? 0),
                    'lab_hours'                  => (int) ($subject['lab_hours'] ?? 0),
                    'year_level'                 => $subject['year_level'],
                    'semester'                   => $subject['semester'],
                    'has_schedule'               => $hasSchedule,
                    'representative_schedule_id' => $scheduleId,
                    'total_schedule_days'        => count($scheduleDet),
                    'schedule_details'           => $scheduleDet,
                    'is_enrolled'                => $isEnrolled,
                    'enrollment_status'          => $isEnrolled ? 'enrolled' : 'pending'
                ];
            }

            return $formattedSubjects;

        } catch (Exception $e) {
            error_log('Error in getSectionSubjectsWithSchedules: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       ACTIVE SY / SEMESTER
    ============================================================ */

    public function getActiveSchoolYear() {
        try {
            $sql  = "SELECT id, name AS school_year
                     FROM rgr_school_years
                     WHERE is_active = 1 LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();

            if (!$result) {
                $sql2  = "SELECT id, name AS school_year
                          FROM rgr_school_years
                          ORDER BY id DESC LIMIT 1";
                $stmt2 = $this->connection->prepare($sql2);
                $stmt2->execute();
                $result = $stmt2->fetch();
            }
            return $result;
        } catch (Exception $e) {
            error_log('Error in getActiveSchoolYear: ' . $e->getMessage());
            return null;
        }
    }

    public function getActiveSemester() {
        try {
            $sql  = "SELECT id, name AS semester_name
                     FROM rgr_semesters
                     WHERE is_active = 1 LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();

            if (!$result) {
                $sql2  = "SELECT id, name AS semester_name
                          FROM rgr_semesters
                          ORDER BY id LIMIT 1";
                $stmt2 = $this->connection->prepare($sql2);
                $stmt2->execute();
                $result = $stmt2->fetch();
            }
            return $result;
        } catch (Exception $e) {
            error_log('Error in getActiveSemester: ' . $e->getMessage());
            return null;
        }
    }

    public function isStudentEnrolledInSubject($studentId, $scheduleId, $sectionId) {
        try {
            $schoolYear = date('Y') . '-' . (date('Y') + 1);
            $sql = "SELECT enrollment_id FROM enr_enrollments
                    WHERE student_id = ? AND schedule_id = ? AND section_id = ?
                      AND school_year = ?
                      AND enrollment_status IN ('enrolled', 'completed')";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $scheduleId, $sectionId, $schoolYear]);
            return $stmt->fetch() ? true : false;
        } catch (Exception $e) {
            error_log('Error in isStudentEnrolledInSubject: ' . $e->getMessage());
            return false;
        }
    }

    public function getSubjectScheduleDetails($subjectId, $sectionId) {
        try {
            $activeSchoolYear = $this->getActiveSchoolYear();
            $activeSemester   = $this->getActiveSemester();

            if (!$activeSchoolYear || !$activeSemester) {
                return [];
            }

            $sql = "SELECT
                        cs.id AS schedule_id,
                        cs.day_of_week,
                        cs.start_time,
                        cs.end_time,
                        cs.status,
                        r.room_code,
                        r.room_name,
                        r.building,
                        f.faculty_code,
                        CONCAT(f.first_name, ' ', IFNULL(f.middle_name, ''), ' ', f.last_name) AS faculty_name
                    FROM cc_schedule cs
                    JOIN cc_room r ON cs.room_id = r.id
                    JOIN cc_faculty f ON cs.faculty_id = f.id
                    WHERE cs.subject_id = ?
                      AND cs.section_id = ?
                      AND cs.school_year_id = ?
                      AND cs.semester_id = ?
                      AND cs.status = 'Scheduled'
                      AND cs.schedule_type = 'Class'
                    ORDER BY FIELD(cs.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
                             cs.start_time";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([
                $subjectId, $sectionId,
                $activeSchoolYear['id'], $activeSemester['id']
            ]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSubjectScheduleDetails: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       STATS
    ============================================================ */

    public function getSectionStats() {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT COUNT(*) as total_sections, SUM(max_students) as total_capacity,
                               SUM(current_students) as total_students,
                               SUM(max_students - current_students) as available_slots,
                               ROUND(AVG(CASE WHEN max_students > 0
                                              THEN (current_students / max_students) * 100
                                              ELSE 0 END), 2) as avg_utilization
                        FROM cc_sections";
            } else {
                $sql = "SELECT COUNT(*) as total_sections,
                               (COUNT(*) * 40) as total_capacity,
                               0 as total_students,
                               (COUNT(*) * 40) as available_slots,
                               0 as avg_utilization
                        FROM cc_sections";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();

            if (!$result) {
                return ['total_sections' => 0, 'total_capacity' => 0, 'total_students' => 0,
                        'available_slots' => 0, 'avg_utilization' => 0];
            }

            return [
                'total_sections'  => (int)   $result['total_sections'],
                'total_capacity'  => (int)   $result['total_capacity'],
                'total_students'  => (int)   $result['total_students'],
                'available_slots' => (int)   $result['available_slots'],
                'avg_utilization' => (float) $result['avg_utilization']
            ];
        } catch (Exception $e) {
            error_log('Error in getSectionStats: ' . $e->getMessage());
            return ['total_sections' => 0, 'total_capacity' => 0, 'total_students' => 0,
                    'available_slots' => 0, 'avg_utilization' => 0];
        }
    }

    public function getSectionUtilizationByCourse() {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT c.id as course_id, c.code as course_code, c.name as course_name,
                               COUNT(s.id) as total_sections,
                               SUM(s.max_students) as total_capacity,
                               SUM(s.current_students) as total_students,
                               SUM(s.max_students - s.current_students) as available_slots,
                               ROUND(AVG(CASE WHEN s.max_students > 0
                                              THEN (s.current_students / s.max_students) * 100
                                              ELSE 0 END), 2) as utilization_rate
                        FROM rgr_courses c
                        LEFT JOIN cc_sections s ON c.id = s.program_id
                        GROUP BY c.id
                        ORDER BY utilization_rate DESC";
            } else {
                $sql = "SELECT c.id as course_id, c.code as course_code, c.name as course_name,
                               COUNT(s.id) as total_sections,
                               (COUNT(s.id) * 40) as total_capacity,
                               0 as total_students,
                               (COUNT(s.id) * 40) as available_slots,
                               0 as utilization_rate
                        FROM rgr_courses c
                        LEFT JOIN cc_sections s ON c.id = s.program_id
                        GROUP BY c.id ORDER BY c.code";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionUtilizationByCourse: ' . $e->getMessage());
            return [];
        }
    }

    public function getSectionStatsByYearLevel() {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT grade_level, COUNT(*) as total_sections,
                               SUM(max_students) as total_capacity,
                               SUM(current_students) as total_students,
                               SUM(max_students - current_students) as available_slots,
                               ROUND(AVG(CASE WHEN max_students > 0
                                              THEN (current_students / max_students) * 100
                                              ELSE 0 END), 2) as avg_utilization
                        FROM cc_sections
                        GROUP BY grade_level
                        ORDER BY FIELD(grade_level, '1st Year','2nd Year','3rd Year','4th Year')";
            } else {
                $sql = "SELECT grade_level, COUNT(*) as total_sections,
                               (COUNT(*) * 40) as total_capacity,
                               0 as total_students,
                               (COUNT(*) * 40) as available_slots,
                               0 as avg_utilization
                        FROM cc_sections
                        GROUP BY grade_level
                        ORDER BY FIELD(grade_level, '1st Year','2nd Year','3rd Year','4th Year')";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionStatsByYearLevel: ' . $e->getMessage());
            return [];
        }
    }

    public function getFullSections() {
        if (!$this->hasCapacityColumns) return [];
        try {
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           (s.max_students - s.current_students) as available_slots
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    WHERE s.current_students >= s.max_students
                    ORDER BY c.code, s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getFullSections: ' . $e->getMessage());
            return [];
        }
    }

    public function getAvailableSectionsList() {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               (s.max_students - s.current_students) as available_slots
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE s.current_students < s.max_students
                        ORDER BY available_slots DESC, c.code, s.section_code";
            } else {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               40 as available_slots
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        ORDER BY c.code, s.section_code";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getAvailableSectionsList: ' . $e->getMessage());
            return [];
        }
    }

    public function countStudentsInSection($sectionId) {
        try {
            $sql = "SELECT COUNT(DISTINCT student_id) as count
                    FROM enr_enrollments
                    WHERE section_id = ? AND enrollment_status = 'enrolled'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$sectionId]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in countStudentsInSection: ' . $e->getMessage());
            return 0;
        }
    }

    public function sectionCodeExists($sectionCode, $semester, $schoolYear) {
        try {
            $sql = "SELECT COUNT(*) as count
                    FROM cc_sections s
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    WHERE s.section_code = ?
                      AND (sem.name = ? OR sem.name = ?)
                      AND sy.name = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([
                $sectionCode,
                $semester,
                $this->normalizeSemester($semester) . ' Semester',
                $schoolYear
            ]);
            $result = $stmt->fetch();
            return ((int) ($result['count'] ?? 0)) > 0;
        } catch (Exception $e) {
            error_log('Error in sectionCodeExists: ' . $e->getMessage());
            return false;
        }
    }

    public function getSectionsWithStudentCount() {
        try {
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           sem.name AS semester, sy.name AS school_year,
                           COUNT(DISTINCT e.student_id) as student_count
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    LEFT JOIN enr_enrollments e
                        ON s.id = e.section_id AND e.enrollment_status = 'enrolled'
                    GROUP BY s.id
                    ORDER BY c.code, s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsWithStudentCount: ' . $e->getMessage());
            return [];
        }
    }

    public function getSectionsBySemester($semester) {
        try {
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           sem.name AS semester, sy.name AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    WHERE sem.name = ?
                    ORDER BY c.code, s.grade_level, s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$semester]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsBySemester: ' . $e->getMessage());
            return [];
        }
    }

    public function getSectionsByYearLevel($yearLevel) {
        try {
            $yearLevelText = $this->convertYearLevelToText((int) $yearLevel);
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           sem.name AS semester, sy.name AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    WHERE s.grade_level = ?
                    ORDER BY c.code, sem.name, s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$yearLevelText]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsByYearLevel: ' . $e->getMessage());
            return [];
        }
    }

    public function getSectionWithEnrollments($sectionId) {
        try {
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           sem.name AS semester, sy.name AS school_year,
                           COUNT(DISTINCT e.student_id) as student_count
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    LEFT JOIN enr_enrollments e
                        ON s.id = e.section_id AND e.enrollment_status = 'enrolled'
                    WHERE s.id = ?
                    GROUP BY s.id";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$sectionId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getSectionWithEnrollments: ' . $e->getMessage());
            return null;
        }
    }

    public function getSectionsWithCapacityStatus() {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               (s.max_students - s.current_students) as available_slots,
                               CASE
                                   WHEN s.current_students = 0 THEN 'Empty'
                                   WHEN s.current_students >= s.max_students THEN 'Full'
                                   WHEN (s.current_students / s.max_students) * 100 >= 80 THEN 'Near Full'
                                   WHEN (s.current_students / s.max_students) * 100 >= 50 THEN 'Half Full'
                                   ELSE 'Available'
                               END as capacity_status
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        ORDER BY c.code, s.grade_level, sem.name, s.section_code";
            } else {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               40 as max_students, 0 as current_students,
                               40 as available_slots, 'Available' as capacity_status
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        ORDER BY c.code, s.grade_level, sem.name, s.section_code";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsWithCapacityStatus: ' . $e->getMessage());
            return [];
        }
    }

    public function getOccupancyDetails($sectionId) {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT s.id, s.section_code, s.grade_level,
                               sem.name AS semester, sy.name AS school_year,
                               s.max_students, s.current_students,
                               (s.max_students - s.current_students) as available_slots,
                               ROUND((s.current_students / s.max_students) * 100, 2) as utilization_percentage
                        FROM cc_sections s
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE s.id = ?";
            } else {
                $sql = "SELECT s.id, s.section_code, s.grade_level,
                               sem.name AS semester, sy.name AS school_year,
                               40 as max_students, 0 as current_students,
                               40 as available_slots, 0 as utilization_percentage
                        FROM cc_sections s
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE s.id = ?";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$sectionId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getOccupancyDetails: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteSection($sectionId) {
        try {
            $studentCount = $this->countStudentsInSection($sectionId);
            if ($studentCount > 0) {
                return ['success' => false, 'message' => 'Cannot delete section with enrolled students'];
            }
            $result = $this->delete($sectionId);
            if ($result) {
                return ['success' => true, 'message' => 'Section deleted successfully'];
            }
            return ['success' => false, 'message' => 'Failed to delete section'];
        } catch (Exception $e) {
            error_log('Error in deleteSection: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function getSectionsBySchoolYear($schoolYear) {
        try {
            $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                           sem.name AS semester, sy.name AS school_year
                    FROM cc_sections s
                    JOIN rgr_courses c ON s.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                    WHERE sy.name = ?
                    ORDER BY c.code, s.grade_level, sem.name, s.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$schoolYear]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionsBySchoolYear: ' . $e->getMessage());
            return [];
        }
    }

    public function getAvailableSectionsBySemester($semester) {
        try {
            if ($this->hasCapacityColumns) {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               (s.max_students - s.current_students) as available_slots
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE sem.name = ? AND s.current_students < s.max_students
                        ORDER BY available_slots DESC, c.code, s.section_code";
            } else {
                $sql = "SELECT s.*, c.code as course_code, c.name as course_name,
                               sem.name AS semester, sy.name AS school_year,
                               40 as available_slots
                        FROM cc_sections s
                        JOIN rgr_courses c ON s.program_id = c.id
                        LEFT JOIN rgr_semesters sem ON s.semester_id = sem.id
                        LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
                        WHERE sem.name = ?
                        ORDER BY c.code, s.section_code";
            }
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$semester]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getAvailableSectionsBySemester: ' . $e->getMessage());
            return [];
        }
    }

    public function findById($id) {
        try {
            $sql  = "SELECT * FROM cc_sections WHERE id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            if ($result && !$this->hasCapacityColumns) {
                $result['max_students']     = 40;
                $result['current_students'] = 0;
            }
            return $result;
        } catch (Exception $e) {
            error_log('Error in findById: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       GROUPED SCHEDULE
    ============================================================ */

    public function getSectionScheduleGrouped($sectionId) {
        try {
            $activeSchoolYear = $this->getActiveSchoolYear();
            $activeSemester   = $this->getActiveSemester();

            if (!$activeSchoolYear || !$activeSemester) {
                return [];
            }

            $sql = "SELECT cs.faculty_load_id, cs.subject_id, cs.section_id,
                           sub.code AS subject_code, sub.name AS subject_name, sub.units,
                           MIN(cs.id) as representative_schedule_id,
                           GROUP_CONCAT(DISTINCT
                               CONCAT(cs.day_of_week, ' (',
                                      TIME_FORMAT(cs.start_time, '%h:%i %p'), ' - ',
                                      TIME_FORMAT(cs.end_time,   '%h:%i %p'), ')')
                               ORDER BY FIELD(cs.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday')
                               SEPARATOR ', ') as schedule_days,
                           r.room_code, f.faculty_code,
                           CONCAT(f.first_name, ' ', IFNULL(f.middle_name, ''), ' ', f.last_name) as faculty_name
                    FROM cc_schedule cs
                    JOIN rgr_subjects sub ON cs.subject_id = sub.id
                    JOIN cc_room r ON cs.room_id = r.id
                    JOIN cc_faculty f ON cs.faculty_id = f.id
                    WHERE cs.section_id = ?
                      AND cs.school_year_id = ?
                      AND cs.semester_id = ?
                      AND cs.status = 'Scheduled'
                      AND cs.schedule_type = 'Class'
                    GROUP BY cs.faculty_load_id, cs.subject_id, cs.section_id,
                             sub.code, sub.name, sub.units,
                             r.room_code, f.faculty_code,
                             f.first_name, f.middle_name, f.last_name
                    ORDER BY MIN(cs.id)";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$sectionId, $activeSchoolYear['id'], $activeSemester['id']]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSectionScheduleGrouped: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       SUBJECTS WITH SCHEDULES FOR ENROLLMENT
    ============================================================ */

    public function getSubjectsWithSchedulesForEnrollment($courseId, $yearLevel, $semester, $sectionId = null) {
        try {
            $yearLevelNumeric = is_numeric($yearLevel)
                ? (int) $yearLevel
                : $this->convertYearLevelToNumeric($yearLevel);
            $semesterDb = $this->normalizeSemester($semester);

            $activeSchoolYear = $this->getActiveSchoolYear();
            $activeSemester   = $this->getActiveSemester();

            if (!$activeSchoolYear || !$activeSemester) {
                return [];
            }

            $curSql = "SELECT id FROM rgr_curriculums WHERE course_id = ? AND is_active = 1 LIMIT 1";
            $curStmt = $this->connection->prepare($curSql);
            $curStmt->execute([$courseId]);
            $curriculum = $curStmt->fetch();

            if (!$curriculum) {
                return [];
            }

            if ($sectionId) {
                $sql = "SELECT rs.id AS subject_id,
                               rs.code AS subject_code,
                               rs.name AS subject_name,
                               rs.units,
                               rs.lecture_hours,
                               rs.lab_hours,
                               rcs.year_level, rcs.semester,
                               MIN(cs.id) AS representative_schedule_id,
                               GROUP_CONCAT(DISTINCT
                                   CONCAT(cs.day_of_week, '|',
                                          TIME_FORMAT(cs.start_time, '%h:%i %p'), '|',
                                          TIME_FORMAT(cs.end_time,   '%h:%i %p'), '|',
                                          cs.room_id, '|', cs.faculty_id)
                                   ORDER BY FIELD(cs.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday')
                                   SEPARATOR '||') AS schedule_data
                        FROM rgr_curriculum_subjects rcs
                        JOIN rgr_subjects rs ON rcs.subject_id = rs.id
                        LEFT JOIN cc_schedule cs
                            ON rs.id = cs.subject_id
                            AND cs.section_id = ?
                            AND cs.school_year_id = ?
                            AND cs.semester_id = ?
                            AND cs.status = 'Scheduled'
                            AND cs.schedule_type = 'Class'
                        WHERE rcs.curriculum_id = ?
                          AND rcs.year_level = ?
                          AND rcs.semester = ?
                        GROUP BY rs.id, rs.code, rs.name, rs.units,
                                 rs.lecture_hours, rs.lab_hours,
                                 rcs.year_level, rcs.semester
                        ORDER BY rs.code";

                $stmt = $this->connection->prepare($sql);
                $stmt->execute([
                    $sectionId,
                    $activeSchoolYear['id'],
                    $activeSemester['id'],
                    $curriculum['id'],
                    $yearLevelNumeric,
                    $semesterDb
                ]);
            } else {
                $sql = "SELECT rs.id AS subject_id,
                               rs.code AS subject_code,
                               rs.name AS subject_name,
                               rs.units,
                               rs.lecture_hours,
                               rs.lab_hours,
                               rcs.year_level, rcs.semester
                        FROM rgr_curriculum_subjects rcs
                        JOIN rgr_subjects rs ON rcs.subject_id = rs.id
                        WHERE rcs.curriculum_id = ?
                          AND rcs.year_level = ?
                          AND rcs.semester = ?
                        ORDER BY rs.code";

                $stmt = $this->connection->prepare($sql);
                $stmt->execute([$curriculum['id'], $yearLevelNumeric, $semesterDb]);
            }

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getSubjectsWithSchedulesForEnrollment: ' . $e->getMessage());
            return [];
        }
    }
}