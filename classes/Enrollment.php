<?php
// classes/Enrollment.php - FULLY FIXED for `kms` schema
//
// FIXES IN THIS VERSION:
//   • parseSemesterNumber() now handles "1st"/"First" AND "2nd"/"Second"
//     (previous code only checked "second" — missed "2nd Semester")
//   • All semester parsing uses the helper

require_once 'Model.php';
require_once 'Section.php';
require_once 'Student.php';
require_once 'Application.php';
require_once 'StudentProgression.php';
require_once 'SubjectStatusManager.php';
require_once 'PrerequisiteValidator.php';

class Enrollment extends Model {
    protected $table      = 'enr_enrollments';
    protected $primaryKey = 'enrollment_id';
    protected $fillable = [
        'student_id', 'section_id', 'school_year', 'semester',
        'enrollment_date', 'enrollment_status', 'academic_standing', 'schedule_id'
    ];

    private $progression;
    private $subjectStatus;
    private $prerequisiteValidator;

    public function __construct() {
        parent::__construct();
        $this->progression           = new StudentProgression();
        $this->subjectStatus         = new SubjectStatusManager();
        $this->prerequisiteValidator = new PrerequisiteValidator();
    }

    /* ============================================================
       HELPERS
    ============================================================ */

    /**
     * Parse any semester representation into 1 or 2.
     * Handles: "First", "1st", "First Semester", "1st Semester",
     *          "Second", "2nd", "Second Semester", "2nd Semester"
     */
    private function parseSemesterNumber($semesterName) {
        $s = strtolower(trim((string) $semesterName));

        if (strpos($s, 'second') !== false
            || strpos($s, '2nd') !== false
            || $s === '2') {
            return 2;
        }

        return 1;
    }

    private function getSubjectIdFromSchedule($scheduleId) {
        try {
            $sql  = "SELECT subject_id FROM cc_schedule WHERE id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$scheduleId]);
            $result = $stmt->fetch();
            return $result ? (int) $result['subject_id'] : null;
        } catch (Exception $e) {
            error_log('Error getting subject_id from schedule: ' . $e->getMessage());
            return null;
        }
    }

    private function getSubjectCode($subjectId) {
        try {
            $sql  = "SELECT code FROM rgr_subjects WHERE id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$subjectId]);
            $result = $stmt->fetch();
            return $result ? $result['code'] : null;
        } catch (Exception $e) {
            error_log('Error getting subject code: ' . $e->getMessage());
            return null;
        }
    }

    private function gradeLevelToNumber($gradeLevel) {
        if (empty($gradeLevel)) return null;
        if (strpos($gradeLevel, '1st') !== false) return 1;
        if (strpos($gradeLevel, '2nd') !== false) return 2;
        if (strpos($gradeLevel, '3rd') !== false) return 3;
        if (strpos($gradeLevel, '4th') !== false) return 4;
        return null;
    }

    private function getGradeLevelText($yearLevel) {
        switch ((int) $yearLevel) {
            case 1: return '1st Year';
            case 2: return '2nd Year';
            case 3: return '3rd Year';
            case 4: return '4th Year';
            default: return '1st Year';
        }
    }

    /* ============================================================
       PROGRESSION
    ============================================================ */

    public function getStudentCurrentProgression($studentId) {
        try {
            $sql = "SELECT
                        s.student_id,
                        s.year_level AS student_year_level,
                        s.section_id,
                        sec.section_code,
                        sec.grade_level,
                        sec.semester_id,
                        sem.name AS section_semester,
                        sy.name  AS section_school_year
                    FROM enr_students s
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.student_id = ?
                    LIMIT 1";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            $row = $stmt->fetch();

            if (!$row) {
                return null;
            }

            if (empty($row['section_id'])) {
                $semSql = "SELECT id, name AS semester_name
                           FROM rgr_semesters
                           WHERE is_active = 1 LIMIT 1";
                $semStmt = $this->connection->prepare($semSql);
                $semStmt->execute();
                $activeSem = $semStmt->fetch();

                $semName = 'First Semester';
                if ($activeSem) {
                    $semName = $activeSem['semester_name'];
                }
                $semNum = $this->parseSemesterNumber($semName);

                $yearLevel = (int) ($row['student_year_level'] ?? 1);

                return [
                    'year_level'    => $yearLevel,
                    'semester'      => $semNum,
                    'semester_name' => $semName,
                    'grade_level'   => $this->getGradeLevelText($yearLevel),
                    'section_id'    => null,
                    'section_code'  => null,
                    'school_year'   => null
                ];
            }

            $yearLevel = $this->gradeLevelToNumber($row['grade_level']);
            if ($yearLevel === null || $yearLevel === 0) {
                $yearLevel = (int) ($row['student_year_level'] ?? 1);
            }

            $semesterName = $row['section_semester'] ?: 'First Semester';
            $semesterNum  = $this->parseSemesterNumber($semesterName);

            return [
                'year_level'    => $yearLevel,
                'semester'      => $semesterNum,
                'semester_name' => $semesterName,
                'grade_level'   => $row['grade_level'] ?: $this->getGradeLevelText($yearLevel),
                'section_id'    => $row['section_id'],
                'section_code'  => $row['section_code'],
                'school_year'   => $row['section_school_year']
            ];

        } catch (Exception $e) {
            error_log('Error in getStudentCurrentProgression: ' . $e->getMessage());
            return null;
        }
    }

    public function getStudentNextProgression($studentId) {
        try {
            $current = $this->getStudentCurrentProgression($studentId);

            if (!$current) {
                return [
                    'year_level'    => 1,
                    'semester'      => 1,
                    'semester_name' => 'First Semester',
                    'grade_level'   => '1st Year',
                    'is_completed'  => false,
                    'is_new'        => true,
                    'can_progress'  => false
                ];
            }

            $currentYearLevel   = (int) $current['year_level'];
            $currentSemesterNum = (int) $current['semester'];
            $currentSectionId   = $current['section_id'] ?? null;

            $enrollCheck  = "SELECT COUNT(*) as count FROM enr_enrollments
                             WHERE student_id = ? AND enrollment_status = 'enrolled'";
            $enrollStmt   = $this->connection->prepare($enrollCheck);
            $enrollStmt->execute([$studentId]);
            $enrollResult = $enrollStmt->fetch(PDO::FETCH_ASSOC);

            $isNewStudent = ((int) $enrollResult['count'] === 0);

            if ($isNewStudent) {
                return [
                    'year_level'    => $currentYearLevel,
                    'semester'      => $currentSemesterNum,
                    'semester_name' => $current['semester_name'],
                    'grade_level'   => $current['grade_level'],
                    'is_completed'  => false,
                    'is_new'        => true,
                    'can_progress'  => false,
                    'reason'        => 'New student — no enrollments yet'
                ];
            }

            $hasAllGrades = $this->studentHasAllGrades($studentId, $currentSectionId);
            $maxYearLevel = 4;

            if ($currentYearLevel >= $maxYearLevel && $currentSemesterNum >= 2) {
                return [
                    'year_level'    => $currentYearLevel,
                    'semester'      => $currentSemesterNum,
                    'semester_name' => $current['semester_name'],
                    'grade_level'   => $current['grade_level'],
                    'is_completed'  => true,
                    'is_new'        => false,
                    'can_progress'  => false
                ];
            }

            if ($currentSemesterNum == 1) {
                $nextYearLevel   = $currentYearLevel;
                $nextSemesterNum = 2;
            } else {
                $nextYearLevel   = min($currentYearLevel + 1, $maxYearLevel);
                $nextSemesterNum = 1;
            }

            $nextSemesterName = ($nextSemesterNum === 2) ? 'Second Semester' : 'First Semester';

            if (!$hasAllGrades) {
                $progressSql = "SELECT
                                    COUNT(DISTINCT e.enrollment_id) AS total_enrollments,
                                    COUNT(DISTINCT g.enrollment_id) AS graded_enrollments
                                FROM enr_enrollments e
                                LEFT JOIN rgr_grades g ON e.enrollment_id = g.enrollment_id
                                WHERE e.student_id = ?
                                  AND e.enrollment_status = 'enrolled'";
                $progressParams = [$studentId];
                if ($currentSectionId) {
                    $progressSql    .= " AND e.section_id = ?";
                    $progressParams[] = $currentSectionId;
                }
                $progressStmt = $this->connection->prepare($progressSql);
                $progressStmt->execute($progressParams);
                $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);

                $total  = (int) ($progress['total_enrollments'] ?? 0);
                $graded = (int) ($progress['graded_enrollments'] ?? 0);

                return [
                    'year_level'    => $nextYearLevel,
                    'semester'      => $nextSemesterNum,
                    'semester_name' => $nextSemesterName,
                    'grade_level'   => $this->getGradeLevelText($nextYearLevel),
                    'is_completed'  => false,
                    'is_new'        => false,
                    'can_progress'  => false,
                    'reason'        => "Kailangan munang tapusin ang {$total} subject(s) at may grades bago makapag-enroll sa susunod na semester. ({$graded}/{$total} grades)"
                ];
            }

            return [
                'year_level'    => $nextYearLevel,
                'semester'      => $nextSemesterNum,
                'semester_name' => $nextSemesterName,
                'grade_level'   => $this->getGradeLevelText($nextYearLevel),
                'is_completed'  => false,
                'is_new'        => false,
                'can_progress'  => true,
                'current_section_id' => $currentSectionId
            ];

        } catch (Exception $e) {
            error_log('Error in getStudentNextProgression: ' . $e->getMessage());
            return [
                'year_level'    => 1,
                'semester'      => 1,
                'semester_name' => 'First Semester',
                'grade_level'   => '1st Year',
                'is_completed'  => false,
                'is_new'        => true,
                'can_progress'  => false
            ];
        }
    }

    public function studentHasAllGrades($studentId, $sectionId = null) {
        try {
            $sql = "SELECT
                        COUNT(DISTINCT e.enrollment_id) AS total_enrollments,
                        COUNT(DISTINCT g.enrollment_id) AS graded_enrollments
                    FROM enr_enrollments e
                    LEFT JOIN rgr_grades g ON e.enrollment_id = g.enrollment_id
                    WHERE e.student_id = ?
                      AND e.enrollment_status = 'enrolled'";

            $params = [$studentId];

            if ($sectionId) {
                $sql .= " AND e.section_id = ?";
                $params[] = $sectionId;
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || (int) $row['total_enrollments'] === 0) {
                return false;
            }

            return (int) $row['graded_enrollments'] >= (int) $row['total_enrollments'];

        } catch (Exception $e) {
            error_log('Error in studentHasAllGrades: ' . $e->getMessage());
            return false;
        }
    }

    public function getStudentEnrollmentSemester($studentId) {
        $next = $this->getStudentNextProgression($studentId);
        if ($next) {
            return [
                'year_level'    => $next['year_level'],
                'semester'      => $next['semester'],
                'semester_name' => ($next['semester'] == 1) ? 'First' : 'Second',
                'is_new'        => $next['is_new']        ?? false,
                'is_completed'  => $next['is_completed']  ?? false,
                'can_progress'  => $next['can_progress']  ?? false
            ];
        }

        return [
            'year_level'    => 1,
            'semester'      => 1,
            'semester_name' => 'First',
            'is_new'        => true,
            'is_completed'  => false,
            'can_progress'  => false
        ];
    }

    /* ============================================================
       VALIDATION
    ============================================================ */

    public function validateEnrollment($studentId, $scheduleIds) {
        if (empty($scheduleIds)) {
            return ['valid' => false, 'message' => 'No subjects selected'];
        }

        $subjectIds = [];
        foreach ($scheduleIds as $scheduleId) {
            $subjectId = $this->getSubjectIdFromSchedule($scheduleId);
            if ($subjectId) {
                $subjectIds[] = $subjectId;
            }
        }

        foreach ($subjectIds as $subjectId) {
            $status = $this->subjectStatus->getSubjectStatus($studentId, $subjectId);

            if ($status['status'] == SubjectStatusManager::STATUS_PASSED ||
                $status['status'] == SubjectStatusManager::STATUS_COMPLETED) {
                $subjectCode = $this->getSubjectCode($subjectId);
                return [
                    'valid'   => false,
                    'message' => "Cannot enroll in {$subjectCode}. Subject already passed."
                ];
            }

            if ($status['status'] == SubjectStatusManager::STATUS_BLOCKED) {
                return [
                    'valid'   => false,
                    'message' => $status['message']
                ];
            }
        }

        $prereqResult = $this->prerequisiteValidator->validateScheduleEnrollments($studentId, $scheduleIds);
        if (!$prereqResult['valid']) {
            return $prereqResult;
        }

        return ['valid' => true, 'message' => 'All validations passed'];
    }

    /* ============================================================
       ENROLL
    ============================================================ */

    public function enrollStudentInSubject($studentId, $sectionId, $scheduleId, $schoolYear) {
        try {
            $subjectId = $this->getSubjectIdFromSchedule($scheduleId);
            if (!$subjectId) {
                return ['success' => false, 'message' => 'Invalid schedule ID'];
            }

            $status = $this->subjectStatus->getSubjectStatus($studentId, $subjectId);

            if ($status['status'] == SubjectStatusManager::STATUS_PASSED ||
                $status['status'] == SubjectStatusManager::STATUS_COMPLETED) {
                $subjectCode = $this->getSubjectCode($subjectId);
                return ['success' => false, 'message' => "{$subjectCode} already passed. Cannot enroll again."];
            }

            if ($status['status'] == SubjectStatusManager::STATUS_BLOCKED) {
                return ['success' => false, 'message' => $status['message']];
            }

            $checkSql = "SELECT e.enrollment_id
                         FROM enr_enrollments e
                         JOIN cc_schedule cs ON e.schedule_id = cs.id
                         WHERE e.student_id = ?
                           AND cs.subject_id = ?
                           AND e.school_year = ?
                           AND e.enrollment_status = 'enrolled'";
            $checkStmt = $this->connection->prepare($checkSql);
            $checkStmt->execute([$studentId, $subjectId, $schoolYear]);

            if ($checkStmt->rowCount() > 0) {
                $subjectCode = $this->getSubjectCode($subjectId);
                return ['success' => false, 'message' => "Already enrolled in {$subjectCode}"];
            }

            // FIX: compute next enrollment_id (no AUTO_INCREMENT in kms)
            $idStmt = $this->connection->query(
                "SELECT COALESCE(MAX(enrollment_id), 0) + 1 AS next_id FROM enr_enrollments"
            );
            $idRow           = $idStmt->fetch(PDO::FETCH_ASSOC);
            $newEnrollmentId = (int) ($idRow['next_id'] ?? 1);

            // Semester name from schedule
            $semStmt = $this->connection->prepare("SELECT semester_id FROM cc_schedule WHERE id = ?");
            $semStmt->execute([$scheduleId]);
            $semResult = $semStmt->fetch();

            $semester = null;
            if ($semResult) {
                $semStmt2 = $this->connection->prepare("SELECT name AS semester_name FROM rgr_semesters WHERE id = ?");
                $semStmt2->execute([$semResult['semester_id']]);
                $semResult2 = $semStmt2->fetch();
                $semester = $semResult2 ? $semResult2['semester_name'] : null;
            }

            $sql = "INSERT INTO enr_enrollments
                    (enrollment_id, student_id, section_id, school_year, semester,
                     enrollment_date, enrollment_status, schedule_id)
                    VALUES (?, ?, ?, ?, ?, CURDATE(), 'enrolled', ?)";
            $stmt = $this->connection->prepare($sql);
            $result = $stmt->execute([
                $newEnrollmentId,
                $studentId,
                $sectionId,
                $schoolYear,
                $semester,
                $scheduleId
            ]);

            if ($result) {
                error_log("Enrolled student {$studentId} in schedule {$scheduleId} (subject: " . $this->getSubjectCode($subjectId) . ", enrollment_id: {$newEnrollmentId})");
                return ['success' => true, 'enrollment_id' => $newEnrollmentId];
            }
            return ['success' => false, 'message' => 'Failed to enroll student in subject'];

        } catch (Exception $e) {
            error_log('Error in enrollStudentInSubject: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function enrollStudentWithSubjects($studentId, $sectionId, $schoolYear, $scheduleIds = []) {
        try {
            error_log("=== ENROLLMENT WITH PROGRESSION DEBUG START ===");
            error_log("Student ID: " . $studentId);
            error_log("Section ID: " . $sectionId);
            error_log("School Year: " . $schoolYear);
            error_log("Schedule IDs: " . print_r($scheduleIds, true));

            $studentModel = new Student();
            $student = $studentModel->findById($studentId);
            if (!$student) {
                error_log("ERROR: Student not found - ID: " . $studentId);
                return ['success' => false, 'message' => 'Student not found'];
            }
            error_log("Student found: " . $student['student_number']);

            $sectionModel = new Section();
            $section = $sectionModel->findById($sectionId);
            if (!$section) {
                error_log("ERROR: Section not found - ID: " . $sectionId);
                return ['success' => false, 'message' => 'Section not found'];
            }
            error_log("Section found: " . $section['section_code']);

            $validationResult = $this->validateEnrollment($studentId, $scheduleIds);
            if (!$validationResult['valid']) {
                error_log("Validation failed: " . $validationResult['message']);
                return ['success' => false, 'message' => $validationResult['message']];
            }

            $transactionStarted = false;
            if (!$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
                $transactionStarted = true;
                error_log("Transaction started");
            } else {
                error_log("Transaction already active, using savepoint");
                $this->connection->exec("SAVEPOINT enroll_student_with_subjects");
            }

            $enrolledCount = 0;
            $errors        = [];
            $enrollmentIds = [];

            foreach ($scheduleIds as $scheduleId) {
                $result = $this->enrollStudentInSubject($studentId, $sectionId, $scheduleId, $schoolYear);

                if ($result['success']) {
                    $enrolledCount++;
                    $enrollmentIds[] = $result['enrollment_id'];
                    error_log("Subject enrolled with schedule_id: " . $scheduleId);
                } else {
                    $errors[] = $result['message'];
                    error_log("ERROR: Failed to enroll schedule: " . $scheduleId . " - " . $result['message']);
                }
            }

            if ($enrolledCount > 0) {
                try {
                    $studentModel->updateSection($studentId, $sectionId);
                    error_log("Student section updated to: " . $sectionId);
                } catch (Exception $e) {
                    error_log('Could not update student section: ' . $e->getMessage());
                }
            }

            if ($transactionStarted) {
                if ($this->connection->inTransaction()) {
                    $this->connection->commit();
                    error_log("Transaction committed successfully");
                }
            } else {
                try {
                    $this->connection->exec("RELEASE SAVEPOINT enroll_student_with_subjects");
                    error_log("Savepoint released");
                } catch (Exception $e) {
                    error_log("Error releasing savepoint: " . $e->getMessage());
                }
            }

            if ($enrolledCount > 0) {
                return [
                    'success'         => true,
                    'message'         => "Successfully enrolled in {$enrolledCount} subject(s)",
                    'enrolled_count'  => $enrolledCount,
                    'enrollment_ids'  => $enrollmentIds,
                    'errors'          => $errors
                ];
            }

            return [
                'success' => false,
                'message' => 'No subjects were enrolled. ' . implode(' ', $errors),
                'errors'  => $errors
            ];

        } catch (Exception $e) {
            if ($this->connection->inTransaction()) {
                if (isset($transactionStarted) && $transactionStarted) {
                    $this->connection->rollBack();
                    error_log("Transaction rolled back due to exception");
                } else {
                    try {
                        $this->connection->exec("ROLLBACK TO SAVEPOINT enroll_student_with_subjects");
                        error_log("Rolled back to savepoint");
                    } catch (Exception $e2) {
                        error_log("Error rolling back to savepoint: " . $e2->getMessage());
                    }
                }
            }
            error_log('ERROR in enrollStudentWithSubjects: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /* ============================================================
       SUBJECTS FOR STUDENT
    ============================================================ */

    public function getAvailableSubjectsForStudent($studentId, $yearLevel = null, $semester = null) {
        try {
            if (!$yearLevel || !$semester) {
                $enrollmentSem = $this->getStudentEnrollmentSemester($studentId);
                if ($enrollmentSem) {
                    $yearLevel = $enrollmentSem['year_level'];
                    $semester  = $enrollmentSem['semester'];
                } else {
                    $current   = $this->getStudentCurrentProgression($studentId);
                    $yearLevel = $current ? $current['year_level'] : 1;
                    $semester  = $current ? $current['semester']   : 1;
                }
            }

            return $this->subjectStatus->getAvailableSubjects($studentId, $yearLevel, $semester);
        } catch (Exception $e) {
            error_log('Error getting available subjects: ' . $e->getMessage());
            return [];
        }
    }

    public function getRetakeSubjectsForStudent($studentId) {
        try {
            return $this->subjectStatus->getFailedSubjectsForRetake($studentId);
        } catch (Exception $e) {
            error_log('Error getting retake subjects: ' . $e->getMessage());
            return [];
        }
    }

    public function findScheduleForSubject($subjectId) {
        try {
            return $this->subjectStatus->findScheduleForSubject($subjectId);
        } catch (Exception $e) {
            error_log('Error in Enrollment::findScheduleForSubject: ' . $e->getMessage());
            return null;
        }
    }

    public function getStudentEnrollmentData($studentId) {
        try {
            $studentModel = new Student();
            $student = $studentModel->findById($studentId);
            if (!$student) {
                return null;
            }

            $current       = $this->getStudentCurrentProgression($studentId);
            $next          = $this->getStudentNextProgression($studentId);
            $enrollmentSem = $this->getStudentEnrollmentSemester($studentId);

            $availableSubjects = $this->getAvailableSubjectsForStudent($studentId);
            $retakeSubjects    = $this->getRetakeSubjectsForStudent($studentId);
            $enrollments       = $this->getStudentEnrollments($studentId);

            return [
                'student'              => $student,
                'current_year_level'   => $current ? $current['year_level'] : 1,
                'current_semester'     => $current ? $current['semester_name'] : 'First Semester',
                'enrollment_semester'  => $enrollmentSem,
                'next_year_level'      => $next ? $next['year_level'] : null,
                'next_semester'        => $next ? $this->progression->getSemesterName($next['semester']) : null,
                'is_completed'         => $next ? ($next['is_completed'] ?? false) : false,
                'is_new_student'       => $enrollmentSem ? ($enrollmentSem['is_new'] ?? true) : true,
                'can_progress'         => $next ? ($next['can_progress'] ?? false) : false,
                'available_subjects'   => $availableSubjects,
                'retake_subjects'      => $retakeSubjects,
                'current_enrollments'  => $enrollments,
                'enrollment_count'     => count($enrollments)
            ];
        } catch (Exception $e) {
            error_log('Error getting student enrollment data: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       SCHEDULES
    ============================================================ */

    public function getSubjectSchedules($subjectId, $sectionId) {
        try {
            $sectionModel     = new Section();
            $activeSchoolYear = $sectionModel->getActiveSchoolYear();
            $activeSemester   = $sectionModel->getActiveSemester();

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
            $stmt->execute([$subjectId, $sectionId, $activeSchoolYear['id'], $activeSemester['id']]);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            error_log('Error getting subject schedules: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       STUDENT ENROLLMENTS
    ============================================================ */

    public function getStudentCurrentSemesterEnrollments($studentId, $schoolYear = null) {
        try {
            if (!$schoolYear) {
                $schoolYear = date('Y') . '-' . (date('Y') + 1);
            }

            $sectionSql  = "SELECT section_id FROM enr_students WHERE student_id = ?";
            $sectionStmt = $this->connection->prepare($sectionSql);
            $sectionStmt->execute([$studentId]);
            $studentData = $sectionStmt->fetch(PDO::FETCH_ASSOC);

            if (!$studentData || empty($studentData['section_id'])) {
                return $this->getStudentEnrollments($studentId, $schoolYear);
            }

            $currentSectionId = (int) $studentData['section_id'];

            $sql = "SELECT
                        e.enrollment_id,
                        e.student_id,
                        e.section_id,
                        e.school_year,
                        e.semester,
                        e.schedule_id,
                        e.enrollment_status,
                        e.academic_standing,
                        e.enrollment_date,
                        e.created_at,
                        rs.id   AS subject_id,
                        rs.code AS subject_code,
                        rs.name AS subject_name,
                        rs.units,
                        rs.lecture_hours,
                        rs.lab_hours,
                        sec.section_code,
                        sec.grade_level,
                        sem.name AS section_semester,
                        sy.name  AS section_school_year,
                        cs.id AS schedule_id,
                        cs.day_of_week,
                        cs.start_time,
                        cs.end_time,
                        cs.schedule_type,
                        f.first_name AS faculty_first,
                        f.middle_name AS faculty_middle,
                        f.last_name AS faculty_last,
                        f.faculty_code,
                        r.room_name,
                        r.room_code,
                        r.building,
                        r.floor,
                        g.grade,
                        g.remarks,
                        g.prelim,
                        g.midterm,
                        g.finals
                    FROM enr_enrollments e
                    JOIN cc_schedule cs ON e.schedule_id = cs.id
                    JOIN rgr_subjects rs ON cs.subject_id = rs.id
                    JOIN cc_sections sec ON e.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    LEFT JOIN cc_faculty f ON cs.faculty_id = f.id
                    LEFT JOIN cc_room r ON cs.room_id = r.id
                    LEFT JOIN rgr_grades g ON e.enrollment_id = g.enrollment_id
                    WHERE e.student_id = ?
                      AND e.school_year = ?
                      AND e.section_id = ?
                      AND e.enrollment_status = 'enrolled'
                    ORDER BY rs.code ASC";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $schoolYear, $currentSectionId]);
            $results = $stmt->fetchAll();

            foreach ($results as &$enrollment) {
                if (isset($enrollment['subject_id'], $enrollment['section_id'])) {
                    $schedules = $this->getSubjectSchedules(
                        $enrollment['subject_id'],
                        $enrollment['section_id']
                    );
                    $enrollment['all_schedules']       = $schedules;
                    $enrollment['total_schedule_days'] = count($schedules);
                }
            }
            unset($enrollment);

            return $results;

        } catch (Exception $e) {
            error_log('Error getting current semester enrollments: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentEnrollments($studentId, $schoolYear = null) {
        try {
            if (!$schoolYear) {
                $schoolYear = date('Y') . '-' . (date('Y') + 1);
            }

            $sql = "SELECT
                        e.enrollment_id,
                        e.student_id,
                        e.section_id,
                        e.school_year,
                        e.semester,
                        e.schedule_id,
                        e.enrollment_status,
                        e.academic_standing,
                        e.enrollment_date,
                        e.created_at,
                        rs.id   AS subject_id,
                        rs.code AS subject_code,
                        rs.name AS subject_name,
                        rs.units,
                        rs.lecture_hours,
                        rs.lab_hours,
                        sec.section_code,
                        sec.grade_level,
                        sem.name AS section_semester,
                        sy.name  AS section_school_year,
                        cs.id AS schedule_id,
                        cs.day_of_week,
                        cs.start_time,
                        cs.end_time,
                        cs.schedule_type,
                        f.first_name AS faculty_first,
                        f.middle_name AS faculty_middle,
                        f.last_name AS faculty_last,
                        f.faculty_code,
                        r.room_name,
                        r.room_code,
                        r.building,
                        r.floor,
                        g.grade,
                        g.remarks,
                        g.prelim,
                        g.midterm,
                        g.finals
                    FROM enr_enrollments e
                    JOIN cc_schedule cs ON e.schedule_id = cs.id
                    JOIN rgr_subjects rs ON cs.subject_id = rs.id
                    JOIN cc_sections sec ON e.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    LEFT JOIN cc_faculty f ON cs.faculty_id = f.id
                    LEFT JOIN cc_room r ON cs.room_id = r.id
                    LEFT JOIN rgr_grades g ON e.enrollment_id = g.enrollment_id
                    WHERE e.student_id = ?
                      AND e.school_year = ?
                    ORDER BY rs.code ASC";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $schoolYear]);
            $results = $stmt->fetchAll();

            foreach ($results as &$enrollment) {
                if (isset($enrollment['subject_id'], $enrollment['section_id'])) {
                    $schedules = $this->getSubjectSchedules(
                        $enrollment['subject_id'],
                        $enrollment['section_id']
                    );
                    $enrollment['all_schedules']       = $schedules;
                    $enrollment['total_schedule_days'] = count($schedules);
                }
            }
            unset($enrollment);

            return $results;

        } catch (Exception $e) {
            error_log('Error getting student enrollments: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentSchedule($studentId, $schoolYear = null) {
        return $this->getStudentEnrollments($studentId, $schoolYear);
    }

    public function getStudentScheduleByDay($studentId, $schoolYear = null) {
        $enrollments = $this->getStudentEnrollments($studentId, $schoolYear);
        $grouped = [];

        foreach ($enrollments as $enrollment) {
            if (isset($enrollment['all_schedules']) && is_array($enrollment['all_schedules'])) {
                foreach ($enrollment['all_schedules'] as $schedule) {
                    $day = $schedule['day_of_week'] ?? 'No Schedule';
                    if (!isset($grouped[$day])) {
                        $grouped[$day] = [];
                    }
                    $grouped[$day][] = [
                        'subject_code' => $enrollment['subject_code'],
                        'subject_name' => $enrollment['subject_name'],
                        'start_time'   => $schedule['start_time'],
                        'end_time'     => $schedule['end_time'],
                        'room_code'    => $schedule['room_code'],
                        'room_name'    => $schedule['room_name'],
                        'faculty_name' => $schedule['faculty_name']
                    ];
                }
            }
        }

        $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        uksort($grouped, function ($a, $b) use ($dayOrder) {
            $aPos = array_search($a, $dayOrder);
            $bPos = array_search($b, $dayOrder);
            if ($aPos === false) $aPos = 999;
            if ($bPos === false) $bPos = 999;
            return $aPos - $bPos;
        });

        return $grouped;
    }

    /* ============================================================
       COUNTS
    ============================================================ */

    public function getEnrollmentCountBySection($sectionId) {
        try {
            $sql = "SELECT COUNT(DISTINCT student_id) as count
                    FROM enr_enrollments
                    WHERE section_id = ? AND enrollment_status = 'enrolled'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$sectionId]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error getting enrollment count by section: ' . $e->getMessage());
            return 0;
        }
    }

    public function getEnrollmentByStudentId($studentId) {
        try {
            $sql = "SELECT
                        e.student_id,
                        e.section_id,
                        e.school_year,
                        sec.section_code,
                        sec.grade_level,
                        sem.name as section_semester,
                        sy.name  as school_year,
                        c.code as course_code,
                        c.name as course_name,
                        COUNT(DISTINCT e.schedule_id) as subject_count,
                        GROUP_CONCAT(DISTINCT rs.code SEPARATOR ', ') as subjects,
                        MIN(e.enrollment_date) as enrollment_date,
                        MAX(e.enrollment_status) as enrollment_status
                    FROM enr_enrollments e
                    JOIN cc_sections sec ON e.section_id = sec.id
                    JOIN rgr_courses c ON sec.program_id = c.id
                    JOIN cc_schedule cs ON e.schedule_id = cs.id
                    JOIN rgr_subjects rs ON cs.subject_id = rs.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE e.student_id = ?
                      AND e.enrollment_status = 'enrolled'
                    GROUP BY e.student_id, e.section_id, e.school_year";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error getting enrollment by student ID: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       DROP
    ============================================================ */

    public function dropStudent($studentId, $schoolYear = null) {
        try {
            $transactionStarted = false;
            if (!$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
                $transactionStarted = true;
            } else {
                $this->connection->exec("SAVEPOINT drop_student");
            }

            $sql    = "UPDATE enr_enrollments SET enrollment_status = 'dropped'
                       WHERE student_id = ? AND enrollment_status = 'enrolled'";
            $params = [$studentId];

            if ($schoolYear) {
                $sql     .= " AND school_year = ?";
                $params[] = $schoolYear;
            }

            $stmt = $this->connection->prepare($sql);
            $result = $stmt->execute($params);

            if ($result) {
                $studentModel = new Student();
                $studentModel->updateSection($studentId, null);
            }

            if ($transactionStarted) {
                if ($this->connection->inTransaction()) {
                    $this->connection->commit();
                }
            } else {
                try {
                    $this->connection->exec("RELEASE SAVEPOINT drop_student");
                } catch (Exception $e) {
                    error_log("Error releasing savepoint: " . $e->getMessage());
                }
            }
            return $result;

        } catch (Exception $e) {
            if ($this->connection->inTransaction()) {
                if (isset($transactionStarted) && $transactionStarted) {
                    $this->connection->rollBack();
                } else {
                    try {
                        $this->connection->exec("ROLLBACK TO SAVEPOINT drop_student");
                    } catch (Exception $e2) {
                        error_log("Error rolling back to savepoint: " . $e2->getMessage());
                    }
                }
            }
            error_log('Error in dropStudent: ' . $e->getMessage());
            return false;
        }
    }

    public function dropStudentFromSubject($studentId, $scheduleId, $schoolYear = null) {
        try {
            $sql    = "UPDATE enr_enrollments SET enrollment_status = 'dropped'
                       WHERE student_id = ? AND schedule_id = ? AND enrollment_status = 'enrolled'";
            $params = [$studentId, $scheduleId];

            if ($schoolYear) {
                $sql     .= " AND school_year = ?";
                $params[] = $schoolYear;
            }

            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);

        } catch (Exception $e) {
            error_log('Error in dropStudentFromSubject: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       ARCHIVE / RESTORE
    ============================================================ */

    public function archiveStudent($studentId, $reason = 'Dropped', $schoolYear = null) {
        try {
            $transactionStarted = false;
            if (!$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
                $transactionStarted = true;
            } else {
                $this->connection->exec("SAVEPOINT archive_student");
            }

            $sql    = "UPDATE enr_enrollments SET enrollment_status = 'dropped'
                       WHERE student_id = ? AND enrollment_status = 'enrolled'";
            $params = [$studentId];

            if ($schoolYear) {
                $sql     .= " AND school_year = ?";
                $params[] = $schoolYear;
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;

            $archiveSql = "UPDATE enr_students
                           SET status = 'inactive',
                               enrollment_status = 'dropped',
                               archived_at = NOW(),
                               archive_reason = ?,
                               archived_by = ?
                           WHERE student_id = ?";
            $archiveStmt = $this->connection->prepare($archiveSql);
            $archiveStmt->execute([$reason, $userId, $studentId]);

            if ($transactionStarted) {
                if ($this->connection->inTransaction()) {
                    $this->connection->commit();
                }
            } else {
                try {
                    $this->connection->exec("RELEASE SAVEPOINT archive_student");
                } catch (Exception $e) {
                    error_log("Error releasing savepoint: " . $e->getMessage());
                }
            }

            error_log("Student archived: {$studentId}, Reason: {$reason}");
            return true;

        } catch (Exception $e) {
            if ($this->connection->inTransaction()) {
                if (isset($transactionStarted) && $transactionStarted) {
                    $this->connection->rollBack();
                } else {
                    try {
                        $this->connection->exec("ROLLBACK TO SAVEPOINT archive_student");
                    } catch (Exception $e2) {
                        error_log("Error rolling back to savepoint: " . $e2->getMessage());
                    }
                }
            }
            error_log('Error in archiveStudent: ' . $e->getMessage());
            return false;
        }
    }

    public function restoreStudent($studentId) {
        try {
            $transactionStarted = false;
            if (!$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
                $transactionStarted = true;
            } else {
                $this->connection->exec("SAVEPOINT restore_student");
            }

            $restoreSql = "UPDATE enr_students
                           SET status = 'active',
                               enrollment_status = 'enrolled',
                               archived_at = NULL,
                               archive_reason = NULL,
                               archived_by = NULL
                           WHERE student_id = ?";
            $restoreStmt = $this->connection->prepare($restoreSql);
            $restoreStmt->execute([$studentId]);

            $enrollSql = "UPDATE enr_enrollments SET enrollment_status = 'enrolled'
                          WHERE student_id = ? AND enrollment_status = 'dropped'";
            $enrollStmt = $this->connection->prepare($enrollSql);
            $enrollStmt->execute([$studentId]);

            if ($transactionStarted) {
                if ($this->connection->inTransaction()) {
                    $this->connection->commit();
                }
            } else {
                try {
                    $this->connection->exec("RELEASE SAVEPOINT restore_student");
                } catch (Exception $e) {
                    error_log("Error releasing savepoint: " . $e->getMessage());
                }
            }

            error_log("Student restored: {$studentId}");
            return true;

        } catch (Exception $e) {
            if ($this->connection->inTransaction()) {
                if (isset($transactionStarted) && $transactionStarted) {
                    $this->connection->rollBack();
                } else {
                    try {
                        $this->connection->exec("ROLLBACK TO SAVEPOINT restore_student");
                    } catch (Exception $e2) {
                        error_log("Error rolling back to savepoint: " . $e2->getMessage());
                    }
                }
            }
            error_log('Error in restoreStudent: ' . $e->getMessage());
            return false;
        }
    }

    public function getArchivedStudents($courseId = null, $yearLevel = null) {
        try {
            $sql = "SELECT
                        s.student_id,
                        s.student_number,
                        s.year_level,
                        s.section_id,
                        s.archived_at,
                        s.archive_reason,
                        s.archived_by,
                        a.first_name,
                        a.middle_name,
                        a.surname,
                        a.suffix,
                        a.email,
                        a.contact_number,
                        a.admission_type,
                        sec.section_code,
                        sec.grade_level,
                        sem.name as section_semester,
                        sy.name  as school_year,
                        c.code as course_code,
                        c.name as course_name,
                        u.username as archived_by_username,
                        u.full_name as archived_by_name,
                        (
                            SELECT COUNT(DISTINCT e2.schedule_id)
                            FROM enr_enrollments e2
                            WHERE e2.student_id = s.student_id
                              AND e2.enrollment_status = 'dropped'
                        ) as dropped_subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN enr_users u ON s.archived_by = u.user_id
                    WHERE s.archived_at IS NOT NULL
                      AND s.status = 'inactive'";

            $params = [];

            if (!empty($courseId)) {
                $sql     .= " AND c.id = ?";
                $params[] = $courseId;
            }

            if (!empty($yearLevel)) {
                $sql     .= " AND s.year_level = ?";
                $params[] = $yearLevel;
            }

            $sql .= " ORDER BY s.archived_at DESC, a.surname, a.first_name";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            error_log('Error in getArchivedStudents: ' . $e->getMessage());
            return [];
        }
    }

    public function getArchivedStudentCount() {
        try {
            $sql = "SELECT COUNT(*) as count FROM enr_students
                    WHERE archived_at IS NOT NULL AND status = 'inactive'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in getArchivedStudentCount: ' . $e->getMessage());
            return 0;
        }
    }

    /* ============================================================
       UPDATE
    ============================================================ */

    public function updateStudentSection($studentId, $sectionId) {
        try {
            $checkSql  = "SELECT student_id FROM enr_students WHERE student_id = ?";
            $checkStmt = $this->connection->prepare($checkSql);
            $checkStmt->execute([$studentId]);
            if (!$checkStmt->fetch()) {
                error_log("updateStudentSection: Student not found - ID: " . $studentId);
                return false;
            }

            $secSql  = "SELECT id FROM cc_sections WHERE id = ?";
            $secStmt = $this->connection->prepare($secSql);
            $secStmt->execute([$sectionId]);
            if (!$secStmt->fetch()) {
                error_log("updateStudentSection: Section not found - ID: " . $sectionId);
                return false;
            }

            $sql  = "UPDATE enr_students SET section_id = ? WHERE student_id = ?";
            $stmt = $this->connection->prepare($sql);
            $result = $stmt->execute([$sectionId, $studentId]);

            if ($result) {
                error_log("updateStudentSection: Student {$studentId} section updated to {$sectionId}");
            }
            return $result;

        } catch (Exception $e) {
            error_log('Error in updateStudentSection: ' . $e->getMessage());
            return false;
        }
    }

    public function updateSubjectSchedule($enrollmentId, $scheduleId) {
        try {
            $checkSql  = "SELECT enrollment_id FROM enr_enrollments WHERE enrollment_id = ?";
            $checkStmt = $this->connection->prepare($checkSql);
            $checkStmt->execute([$enrollmentId]);
            if (!$checkStmt->fetch()) {
                error_log("updateSubjectSchedule: Enrollment not found - ID: " . $enrollmentId);
                return false;
            }

            if ($scheduleId !== null && $scheduleId > 0) {
                $schSql  = "SELECT id FROM cc_schedule WHERE id = ?";
                $schStmt = $this->connection->prepare($schSql);
                $schStmt->execute([$scheduleId]);
                if (!$schStmt->fetch()) {
                    error_log("updateSubjectSchedule: Schedule not found - ID: " . $scheduleId);
                    return false;
                }

                $sql  = "UPDATE enr_enrollments SET schedule_id = ? WHERE enrollment_id = ?";
                $stmt = $this->connection->prepare($sql);
                $result = $stmt->execute([$scheduleId, $enrollmentId]);

                if ($result) {
                    error_log("updateSubjectSchedule: Enrollment {$enrollmentId} schedule updated to {$scheduleId}");
                }
                return $result;
            }

            error_log("updateSubjectSchedule: scheduleId is null or 0 — no change made");
            return true;

        } catch (Exception $e) {
            error_log('Error in updateSubjectSchedule: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       AGGREGATES
    ============================================================ */

    public function getAllActiveEnrollments() {
        try {
            $sql = "SELECT
                        s.student_id,
                        s.student_number,
                        a.first_name,
                        a.middle_name,
                        a.surname,
                        a.suffix,
                        sec.section_code,
                        sec.grade_level,
                        sem.name as section_semester,
                        sy.name  as school_year,
                        c.code as course_code,
                        c.name as course_name,
                        COUNT(DISTINCT e.schedule_id) as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    JOIN enr_enrollments e ON s.student_id = e.student_id
                    JOIN cc_sections sec ON e.section_id = sec.id
                    JOIN rgr_courses c ON sec.program_id = c.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE e.enrollment_status = 'enrolled'
                    GROUP BY s.student_id, sec.id
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getting all active enrollments: ' . $e->getMessage());
            return [];
        }
    }

    public function getEnrollmentStatistics() {
        try {
            $sql = "SELECT
                        (SELECT COUNT(DISTINCT student_id) FROM enr_enrollments WHERE enrollment_status = 'enrolled') as total_enrolled_students,
                        (SELECT COUNT(DISTINCT schedule_id) FROM enr_enrollments WHERE enrollment_status = 'enrolled') as total_subject_enrollments,
                        (SELECT COUNT(*) FROM enr_applicants WHERE status = 'pending') as pending_applications";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error getting enrollment statistics: ' . $e->getMessage());
            return [
                'total_enrolled_students'   => 0,
                'total_subject_enrollments' => 0,
                'pending_applications'      => 0
            ];
        }
    }

    public function isStudentEnrolled($studentId, $schoolYear = null) {
        try {
            $sql    = "SELECT COUNT(*) as count FROM enr_enrollments
                       WHERE student_id = ? AND enrollment_status = 'enrolled'";
            $params = [$studentId];

            if ($schoolYear) {
                $sql     .= " AND school_year = ?";
                $params[] = $schoolYear;
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return ((int) ($result['count'] ?? 0)) > 0;
        } catch (Exception $e) {
            error_log('Error checking if student is enrolled: ' . $e->getMessage());
            return false;
        }
    }

    public function getStudentSubjectCount($studentId, $schoolYear = null) {
        try {
            $sql    = "SELECT COUNT(DISTINCT schedule_id) as count FROM enr_enrollments
                       WHERE student_id = ? AND enrollment_status = 'enrolled'";
            $params = [$studentId];

            if ($schoolYear) {
                $sql     .= " AND school_year = ?";
                $params[] = $schoolYear;
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error getting student subject count: ' . $e->getMessage());
            return 0;
        }
    }

    public function getStudentCurrentSemesterSubjectCount($studentId, $schoolYear = null) {
        try {
            if (!$schoolYear) {
                $schoolYear = date('Y') . '-' . (date('Y') + 1);
            }

            $sectionSql  = "SELECT section_id FROM enr_students WHERE student_id = ?";
            $sectionStmt = $this->connection->prepare($sectionSql);
            $sectionStmt->execute([$studentId]);
            $studentData = $sectionStmt->fetch(PDO::FETCH_ASSOC);

            if (!$studentData || empty($studentData['section_id'])) {
                return $this->getStudentSubjectCount($studentId, $schoolYear);
            }

            $currentSectionId = (int) $studentData['section_id'];

            $sql = "SELECT COUNT(DISTINCT schedule_id) as count FROM enr_enrollments
                    WHERE student_id = ?
                      AND school_year = ?
                      AND section_id = ?
                      AND enrollment_status = 'enrolled'";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $schoolYear, $currentSectionId]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error getting current semester subject count: ' . $e->getMessage());
            return 0;
        }
    }
}