<?php
// classes/SubjectStatusManager.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • rgr_subjects uses `code` and `name` (not subject_code / subject_name)
//   • rgr_subjects has NO `subject_type` column — removed all references
//   • getAvailableSubjects() now accepts optional $courseId filter
//   • All other logic preserved

require_once 'Model.php';

class SubjectStatusManager extends Model {
    protected $table      = 'rgr_subjects';
    protected $primaryKey = 'id';

    const STATUS_PASSED     = 'PASSED';
    const STATUS_FAILED     = 'FAILED';
    const STATUS_RETAKE     = 'RETAKE';
    const STATUS_BLOCKED    = 'BLOCKED';
    const STATUS_AVAILABLE  = 'AVAILABLE';
    const STATUS_COMPLETED  = 'COMPLETED';

    /**
     * Get the status of a subject for a specific student.
     * Returns RETAKE when the student previously failed and has not passed.
     */
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

    /**
     * Check if a subject is already passed.
     */
    public function checkIfSubjectPassed($studentId, $subjectId) {
        $sql = "SELECT g.grade, g.remarks
                FROM rgr_grades g
                INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                INNER JOIN cc_schedule s     ON e.schedule_id    = s.id
                WHERE e.student_id = ?
                  AND s.subject_id = ?
                ORDER BY e.enrollment_id DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$studentId, $subjectId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        if ($result['grade'] !== null && $result['grade'] > 75) {
            return true;
        }
        if ($result['remarks'] === 'Passed') {
            return true;
        }

        return false;
    }

    /**
     * Check if a subject is failed.
     */
    public function checkIfSubjectFailed($studentId, $subjectId) {
        $sql = "SELECT g.grade, g.remarks
                FROM rgr_grades g
                INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                INNER JOIN cc_schedule s     ON e.schedule_id    = s.id
                WHERE e.student_id = ?
                  AND s.subject_id = ?
                ORDER BY e.enrollment_id DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$studentId, $subjectId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        if ($result['grade'] !== null && $result['grade'] <= 75) {
            return true;
        }
        if ($result['remarks'] === 'Failed') {
            return true;
        }

        return false;
    }

    /**
     * Check if a subject needs a retake:
     * TRUE when the student has a FAILED record AND no PASSED record.
     */
    public function checkIfSubjectNeedsRetake($studentId, $subjectId) {
        $sql = "SELECT g.grade, g.remarks
                FROM rgr_grades g
                INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                INNER JOIN cc_schedule s     ON e.schedule_id    = s.id
                WHERE e.student_id = ?
                  AND s.subject_id = ?
                ORDER BY e.enrollment_id DESC";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$studentId, $subjectId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            return false;
        }

        // Any passed grade → no retake needed
        foreach ($results as $result) {
            if (
                ($result['grade'] !== null && $result['grade'] > 75) ||
                $result['remarks'] === 'Passed'
            ) {
                return false;
            }
        }

        // Any failed grade → retake needed
        foreach ($results as $result) {
            if (
                ($result['grade'] !== null && $result['grade'] <= 75) ||
                $result['remarks'] === 'Failed'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check prerequisites for a subject.
     * FIX: rgr_subjects uses `code` and `name`.
     */
    public function checkPrerequisites($studentId, $subjectId) {
        $sql = "SELECT p.prerequisite_subject_id,
                       s.code AS subject_code,
                       s.name AS subject_name
                FROM enr_prerequisites p
                INNER JOIN rgr_subjects s
                    ON p.prerequisite_subject_id = s.id
                WHERE p.subject_id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$subjectId]);
        $prerequisites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($prerequisites)) {
            return null;
        }

        $blockedSubjects = [];

        foreach ($prerequisites as $prereq) {
            $passed = $this->checkIfSubjectPassed(
                $studentId,
                $prereq['prerequisite_subject_id']
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
    }

    /**
     * Get ALL failed subjects for retake, with full details.
     * FIX: rgr_subjects uses `code` and `name`; no `subject_type` column.
     */
    public function getFailedSubjectsForRetake($studentId) {
        try {
            $sql = "SELECT DISTINCT
                        rs.id   AS subject_id,
                        rs.code AS subject_code,
                        rs.name AS subject_name,
                        rs.units,
                        g.grade   AS previous_grade,
                        g.remarks AS previous_remarks,
                        e.semester    AS failed_semester,
                        e.school_year AS failed_school_year,
                        e.section_id  AS failed_section_id,
                        sec.grade_level  AS failed_grade_level,
                        sec.section_code AS failed_section_code
                    FROM enr_enrollments e
                    JOIN cc_schedule cs   ON e.schedule_id    = cs.id
                    JOIN rgr_subjects rs  ON cs.subject_id    = rs.id
                    JOIN rgr_grades g     ON e.enrollment_id  = g.enrollment_id
                    LEFT JOIN cc_sections sec ON e.section_id = sec.id
                    WHERE e.student_id = ?
                      AND (
                            g.remarks = 'Failed'
                            OR (g.grade IS NOT NULL AND g.grade <= 75)
                          )
                      AND NOT EXISTS (
                            SELECT 1
                            FROM enr_enrollments e2
                            JOIN cc_schedule cs2 ON e2.schedule_id = cs2.id
                            JOIN rgr_grades g2   ON e2.enrollment_id = g2.enrollment_id
                            WHERE e2.student_id = e.student_id
                              AND cs2.subject_id = rs.id
                              AND (
                                    g2.remarks = 'Passed'
                                    OR g2.grade > 75
                                  )
                      )
                    ORDER BY rs.code";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            $failedSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $retakeSubjects = [];
            foreach ($failedSubjects as $subject) {
                $scheduleInfo = $this->findScheduleForSubject($subject['subject_id']);

                $subject['status']                    = self::STATUS_RETAKE;
                $subject['message']                   = 'Failed previously (Grade: '
                                                        . $subject['previous_grade'] . ')';
                $subject['representative_schedule_id'] = $scheduleInfo
                    ? $scheduleInfo['schedule_id']
                    : null;
                $subject['has_schedule']    = $scheduleInfo !== null;
                $subject['is_retake']       = true;
                $subject['schedule_details'] = $scheduleInfo
                    ? $scheduleInfo['details']
                    : [];

                $retakeSubjects[] = $subject;
            }

            return $retakeSubjects;

        } catch (Exception $e) {
            error_log('Error in getFailedSubjectsForRetake: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Legacy wrapper.
     */
    public function getRetakeSubjects($studentId) {
        return $this->getFailedSubjectsForRetake($studentId);
    }

    /**
     * Find the schedule for a subject in the ACTIVE school year + semester.
     * Public so the API can call it directly.
     */
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
                        f.first_name,
                        f.last_name
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
                             cs.start_time
                    LIMIT 1";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$subjectId, $schoolYearId, $semesterId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$schedule) {
                return null;
            }

            $sql2 = "SELECT
                        cs.id,
                        cs.day_of_week,
                        cs.start_time,
                        cs.end_time,
                        r.room_code,
                        CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
                    FROM cc_schedule cs
                    LEFT JOIN cc_room    r ON cs.room_id    = r.id
                    LEFT JOIN cc_faculty f ON cs.faculty_id = f.id
                    WHERE cs.subject_id     = ?
                      AND cs.school_year_id = ?
                      AND cs.semester_id    = ?
                      AND cs.status         = 'Scheduled'
                      AND cs.schedule_type  = 'Class'
                    ORDER BY FIELD(cs.day_of_week,
                                   'Monday','Tuesday','Wednesday',
                                   'Thursday','Friday','Saturday'),
                             cs.start_time";

            $stmt2 = $this->connection->prepare($sql2);
            $stmt2->execute([$subjectId, $schoolYearId, $semesterId]);
            $allSchedules = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            return [
                'schedule_id'  => $schedule['schedule_id'],
                'section_id'   => $schedule['section_id'],
                'section_code' => $schedule['section_code'],
                'details'      => $allSchedules
            ];

        } catch (Exception $e) {
            error_log('Error in findScheduleForSubject: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all available subjects for a student.
     *
     * FIX:
     *   • rgr_subjects uses `code` and `name`
     *   • no `subject_type` column — removed
     *   • optional $courseId to filter curriculum by course
     */
    public function getAvailableSubjects($studentId, $yearLevel, $semester, $courseId = null) {
        $sql = "SELECT
                    cs.subject_id,
                    s.code AS subject_code,
                    s.name AS subject_name,
                    s.units
                FROM rgr_curriculum_subjects cs
                INNER JOIN rgr_subjects s    ON cs.subject_id    = s.id
                INNER JOIN rgr_curriculums c ON cs.curriculum_id = c.id
                WHERE c.is_active = 1
                  AND cs.year_level = ?
                  AND cs.semester   = ?";

        $params = [$yearLevel, $semester];

        if ($courseId !== null && (int) $courseId > 0) {
            $sql .= " AND c.course_id = ?";
            $params[] = (int) $courseId;
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $curriculumSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $availableSubjects = [];

        foreach ($curriculumSubjects as $subject) {
            $status = $this->getSubjectStatus($studentId, $subject['subject_id']);

            $subject['status']  = $status['status'];
            $subject['message'] = $status['message'];

            $availableSubjects[] = $subject;
        }

        // Add retake subjects that are not already in the curriculum list
        $retakeSubjects = $this->getFailedSubjectsForRetake($studentId);
        foreach ($retakeSubjects as $retake) {
            $exists = false;
            foreach ($availableSubjects as $existing) {
                if ((int) $existing['subject_id'] === (int) $retake['subject_id']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $availableSubjects[] = $retake;
            }
        }

        return $availableSubjects;
    }

    private function getCurrentSchoolYear() {
        $sql  = "SELECT id FROM rgr_school_years WHERE is_active = 1 LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    }

    private function getCurrentSemester() {
        $sql  = "SELECT id FROM rgr_semesters WHERE is_active = 1 LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    }

    public function getCurrentSchoolYearId() {
        return $this->getCurrentSchoolYear();
    }

    public function getCurrentSemesterId() {
        return $this->getCurrentSemester();
    }
}