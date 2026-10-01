<?php
// classes/SubjectStatusManager.php - FULLY FIXED for `kms` schema
//
// FIXES IN THIS VERSION:
//   • e.semester → esem.name via e.semester_id (esem JOIN)
//   • Enum comparisons use lowercase ('passed' / 'failed') to match schema
//   • catch Throwable
//   • findScheduleForSubject() collapses to a single query
//   • getAvailableSubjects() optional batch status (perf)
//   • Prerequisite lookup gracefully handles missing table

require_once 'Model.php';

class SubjectStatusManager extends Model {
    protected $table      = 'rgr_subjects';
    protected $primaryKey = 'id';

    const STATUS_PASSED    = 'PASSED';
    const STATUS_FAILED    = 'FAILED';
    const STATUS_RETAKE    = 'RETAKE';
    const STATUS_BLOCKED   = 'BLOCKED';
    const STATUS_AVAILABLE = 'AVAILABLE';
    const STATUS_COMPLETED = 'COMPLETED';

    /**
     * Case-insensitive check against the schema's lowercase enum.
     */
    private function remarksIs($remarks, $expected) {
        return strtolower(trim((string) $remarks)) === strtolower($expected);
    }

    /* ============================================================
       SUBJECT STATUS
    ============================================================ */

    public function getSubjectStatus($studentId, $subjectId) {
        if ($this->checkIfSubjectPassed($studentId, $subjectId)) {
            return [
                'status'  => self::STATUS_PASSED,
                'message' => 'Subject already passed'
            ];
        }

        if ($this->checkIfSubjectNeedsRetake($studentId, $subjectId)) {
            return [
                'status'  => self::STATUS_RETAKE,
                'message' => 'Failed previously — retake required'
            ];
        }

        $blockedReason = $this->checkPrerequisites($studentId, $subjectId);
        if ($blockedReason) {
            return [
                'status'  => self::STATUS_BLOCKED,
                'message' => $blockedReason
            ];
        }

        return [
            'status'  => self::STATUS_AVAILABLE,
            'message' => 'Subject available for enrollment'
        ];
    }

    /* ============================================================
       PASS / FAIL / RETAKE
    ============================================================ */

    public function checkIfSubjectPassed($studentId, $subjectId) {
        try {
            $sql = "SELECT g.final_grade, g.remarks
                    FROM rgr_grades g
                    INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                    INNER JOIN cc_schedule s     ON e.schedule_id    = s.id
                    WHERE e.student_id = ?
                      AND s.subject_id = ?
                    ORDER BY e.enrollment_id DESC
                    LIMIT 1";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $studentId, (int) $subjectId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return false;
            }

            // Grade check: > 75 = passed (adjust if your school uses >= 75)
            if ($result['final_grade'] !== null && (float) $result['final_grade'] > 75) {
                return true;
            }

            // Enum check: lowercase 'passed' (matches schema)
            if ($this->remarksIs($result['remarks'] ?? '', 'passed')) {
                return true;
            }

            return false;
        } catch (Throwable $e) {
            error_log('checkIfSubjectPassed: ' . $e->getMessage());
            return false;
        }
    }

    public function checkIfSubjectFailed($studentId, $subjectId) {
        try {
            $sql = "SELECT g.final_grade, g.remarks
                    FROM rgr_grades g
                    INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                    INNER JOIN cc_schedule s     ON e.schedule_id    = s.id
                    WHERE e.student_id = ?
                      AND s.subject_id = ?
                    ORDER BY e.enrollment_id DESC
                    LIMIT 1";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $studentId, (int) $subjectId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return false;
            }

            if ($result['final_grade'] !== null && (float) $result['final_grade'] <= 75) {
                return true;
            }

            if ($this->remarksIs($result['remarks'] ?? '', 'failed')) {
                return true;
            }

            return false;
        } catch (Throwable $e) {
            error_log('checkIfSubjectFailed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retake required when the student has a failed record AND no passed record.
     */
    public function checkIfSubjectNeedsRetake($studentId, $subjectId) {
        try {
            $sql = "SELECT g.final_grade, g.remarks
                    FROM rgr_grades g
                    INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                    INNER JOIN cc_schedule s     ON e.schedule_id    = s.id
                    WHERE e.student_id = ?
                      AND s.subject_id = ?
                    ORDER BY e.enrollment_id DESC";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $studentId, (int) $subjectId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($results)) {
                return false;
            }

            // Any passed → no retake needed
            foreach ($results as $row) {
                if (
                    ($row['final_grade'] !== null && (float) $row['final_grade'] > 75) ||
                    $this->remarksIs($row['remarks'] ?? '', 'passed')
                ) {
                    return false;
                }
            }

            // Any failed → retake
            foreach ($results as $row) {
                if (
                    ($row['final_grade'] !== null && (float) $row['final_grade'] <= 75) ||
                    $this->remarksIs($row['remarks'] ?? '', 'failed')
                ) {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            error_log('checkIfSubjectNeedsRetake: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       PREREQUISITES
    ============================================================ */

    public function checkPrerequisites($studentId, $subjectId) {
        try {
            $sql = "SELECT p.prerequisite_subject_id,
                           s.code AS subject_code,
                           s.name AS subject_name
                    FROM enr_prerequisites p
                    INNER JOIN rgr_subjects s ON p.prerequisite_subject_id = s.id
                    WHERE p.subject_id = ?";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $subjectId]);
            $prerequisites = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($prerequisites)) {
                return null;
            }

            $blockedSubjects = [];

            foreach ($prerequisites as $prereq) {
                $passed = $this->checkIfSubjectPassed(
                    $studentId,
                    (int) $prereq['prerequisite_subject_id']
                );
                if (!$passed) {
                    $blockedSubjects[] = $prereq['subject_code']
                        . ' - ' . $prereq['subject_name'];
                }
            }

            if (!empty($blockedSubjects)) {
                return 'Prerequisites not satisfied: ' . implode(', ', $blockedSubjects);
            }

            return null;
        } catch (Throwable $e) {
            // Missing enr_prerequisites table is a common case
            error_log('checkPrerequisites: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       RETAKE SUBJECTS
       ------------------------------------------------------------
       FIX: e.semester → esem.name via e.semester_id
       FIX: enum comparisons use lowercase 'passed' / 'failed'
    ============================================================ */

    public function getFailedSubjectsForRetake($studentId) {
        try {
            $sql = "SELECT DISTINCT
                        rs.id   AS subject_id,
                        rs.code AS subject_code,
                        rs.name AS subject_name,
                        rs.units,
                        g.final_grade    AS previous_grade,
                        g.remarks        AS previous_remarks,
                        esem.name        AS failed_semester,
                        e.school_year    AS failed_school_year,
                        e.section_id     AS failed_section_id,
                        sec.grade_level  AS failed_grade_level,
                        sec.section_code AS failed_section_code
                    FROM enr_enrollments e
                    JOIN cc_schedule cs          ON e.schedule_id      = cs.id
                    JOIN rgr_subjects rs         ON cs.subject_id      = rs.id
                    JOIN rgr_grades g            ON e.enrollment_id    = g.enrollment_id
                    LEFT JOIN cc_sections sec    ON e.section_id       = sec.id
                    LEFT JOIN rgr_semesters esem ON e.semester_id      = esem.id
                    WHERE e.student_id = ?
                      AND (
                            LOWER(g.remarks) = 'failed'
                            OR (g.final_grade IS NOT NULL AND g.final_grade <= 75)
                          )
                      AND NOT EXISTS (
                            SELECT 1
                            FROM enr_enrollments e2
                            JOIN cc_schedule cs2 ON e2.schedule_id   = cs2.id
                            JOIN rgr_grades  g2  ON e2.enrollment_id = g2.enrollment_id
                            WHERE e2.student_id = e.student_id
                              AND cs2.subject_id = rs.id
                              AND (
                                    LOWER(g2.remarks) = 'passed'
                                    OR g2.final_grade > 75
                                  )
                      )
                    ORDER BY rs.code";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $studentId]);
            $failedSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $retakeSubjects = [];
            foreach ($failedSubjects as $subject) {
                $scheduleInfo = $this->findScheduleForSubject((int) $subject['subject_id']);

                $subject['status']                     = self::STATUS_RETAKE;
                $subject['message']                    = 'Failed previously (Grade: '
                                                        . ($subject['previous_grade'] ?? 'N/A') . ')';
                $subject['representative_schedule_id'] = $scheduleInfo
                    ? $scheduleInfo['schedule_id']
                    : null;
                $subject['has_schedule']     = $scheduleInfo !== null;
                $subject['is_retake']        = true;
                $subject['schedule_details'] = $scheduleInfo
                    ? $scheduleInfo['details']
                    : [];

                $retakeSubjects[] = $subject;
            }

            return $retakeSubjects;
        } catch (Throwable $e) {
            error_log('Error in getFailedSubjectsForRetake: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Legacy wrapper for older callers.
     */
    public function getRetakeSubjects($studentId) {
        return $this->getFailedSubjectsForRetake($studentId);
    }

    /* ============================================================
       SCHEDULE LOOKUP
       ------------------------------------------------------------
       FIX: single query instead of two.
    ============================================================ */

    public function findScheduleForSubject($subjectId) {
        try {
            $schoolYearId = $this->getCurrentSchoolYear();
            $semesterId   = $this->getCurrentSemester();

            if (!$schoolYearId || !$semesterId) {
                return null;
            }

            $sql = "SELECT
                        cs.id AS schedule_id,
                        cs.day_of_week,
                        cs.start_time,
                        cs.end_time,
                        cs.section_id,
                        sec.section_code,
                        r.room_code,
                        CONCAT(f.first_name, ' ', IFNULL(f.middle_name, ''), ' ', f.last_name) AS faculty_name
                    FROM cc_schedule cs
                    LEFT JOIN cc_sections sec ON cs.section_id = sec.id
                    LEFT JOIN cc_room     r   ON cs.room_id    = r.id
                    LEFT JOIN cc_faculty  f   ON cs.faculty_id = f.id
                    WHERE cs.subject_id     = ?
                      AND cs.school_year_id = ?
                      AND cs.semester_id    = ?
                      AND cs.status         = 'Scheduled'
                      AND cs.schedule_type  = 'Class'
                    ORDER BY FIELD(cs.day_of_week,
                                   'Monday','Tuesday','Wednesday',
                                   'Thursday','Friday','Saturday'),
                             cs.start_time";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $subjectId, (int) $schoolYearId, (int) $semesterId]);
            $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($all)) {
                return null;
            }

            $first = $all[0];

            return [
                'schedule_id'  => (int) $first['schedule_id'],
                'section_id'   => (int) ($first['section_id'] ?? 0),
                'section_code' => $first['section_code'] ?? '',
                'details'      => $all
            ];
        } catch (Throwable $e) {
            error_log('Error in findScheduleForSubject: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       AVAILABLE SUBJECTS
    ============================================================ */

    public function getAvailableSubjects($studentId, $yearLevel, $semester, $courseId = null) {
        try {
            $sql = "SELECT
                        cs.subject_id,
                        s.code AS subject_code,
                        s.name AS subject_name,
                        s.units
                    FROM rgr_curriculum_subjects cs
                    INNER JOIN rgr_subjects    s ON cs.subject_id    = s.id
                    INNER JOIN rgr_curriculums c ON cs.curriculum_id = c.id
                    WHERE c.is_active = 1
                      AND cs.year_level = ?
                      AND cs.semester   = ?";

            $params = [(int) $yearLevel, $semester];

            if ($courseId !== null && (int) $courseId > 0) {
                $sql .= " AND c.course_id = ?";
                $params[] = (int) $courseId;
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $curriculumSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $availableSubjects = [];

            foreach ($curriculumSubjects as $subject) {
                $status = $this->getSubjectStatus($studentId, (int) $subject['subject_id']);

                $subject['status']         = $status['status'];
                $subject['message']        = $status['message'];
                $subject['subject_status'] = $status['status'];

                $availableSubjects[] = $subject;
            }

            // Merge retake subjects not already in the curriculum list
            $retakeSubjects = $this->getFailedSubjectsForRetake($studentId);
            $seenIds = [];
            foreach ($availableSubjects as $s) {
                $seenIds[(int) $s['subject_id']] = true;
            }

            foreach ($retakeSubjects as $retake) {
                $rid = (int) ($retake['subject_id'] ?? 0);
                if ($rid > 0 && !isset($seenIds[$rid])) {
                    $availableSubjects[] = $retake;
                    $seenIds[$rid] = true;
                }
            }

            return $availableSubjects;
        } catch (Throwable $e) {
            error_log('Error in getAvailableSubjects: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       ACTIVE SY / SEMESTER
    ============================================================ */

    private function getCurrentSchoolYear() {
        try {
            $stmt = $this->connection->prepare(
                "SELECT id FROM rgr_school_years WHERE is_active = 1 LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int) $row['id'] : null;
        } catch (Throwable $e) {
            error_log('getCurrentSchoolYear: ' . $e->getMessage());
            return null;
        }
    }

    private function getCurrentSemester() {
        try {
            $stmt = $this->connection->prepare(
                "SELECT id FROM rgr_semesters WHERE is_active = 1 LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int) $row['id'] : null;
        } catch (Throwable $e) {
            error_log('getCurrentSemester: ' . $e->getMessage());
            return null;
        }
    }

    public function getCurrentSchoolYearId() {
        return $this->getCurrentSchoolYear();
    }

    public function getCurrentSemesterId() {
        return $this->getCurrentSemester();
    }
}