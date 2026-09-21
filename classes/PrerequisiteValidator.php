<?php
// classes/PrerequisiteValidator.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • getPrerequisites(): rgr_subjects uses `code` and `name`
//     → changed s.subject_code → s.code AS prerequisite_code
//               s.subject_name → s.name AS prerequisite_name
//   • Defensive (int) casts on IDs
//   • Null-safe read on grade / remarks

require_once 'Model.php';

class PrerequisiteValidator extends Model {
    protected $table      = 'enr_prerequisites';
    protected $primaryKey = 'id';

    /**
     * Validate prerequisites for a list of schedule enrollments.
     */
    public function validateScheduleEnrollments($studentId, $scheduleIds) {
        if (empty($scheduleIds)) {
            return ['valid' => false, 'message' => 'No schedules selected'];
        }

        $subjectIds = $this->getSubjectIdsFromSchedules($scheduleIds);

        // Check each subject for prerequisites
        foreach ($subjectIds as $subjectId) {
            $result = $this->validateSubject($studentId, $subjectId);
            if (!$result['valid']) {
                return $result;
            }
        }

        // Check for duplicate enrollments
        $duplicateResult = $this->checkDuplicateEnrollments($studentId, $scheduleIds);
        if (!$duplicateResult['valid']) {
            return $duplicateResult;
        }

        return ['valid' => true, 'message' => 'All prerequisites satisfied'];
    }

    /**
     * Validate prerequisites for a single subject.
     */
    public function validateSubject($studentId, $subjectId) {
        $passed = $this->checkIfSubjectPassed($studentId, $subjectId);
        if ($passed) {
            return [
                'valid'   => false,
                'message' => 'Subject already passed. Cannot enroll again.'
            ];
        }

        $prerequisites = $this->getPrerequisites($subjectId);
        if (empty($prerequisites)) {
            return ['valid' => true, 'message' => 'No prerequisites required'];
        }

        $failedPrereqs = [];

        foreach ($prerequisites as $prereq) {
            $isPassed = $this->checkIfSubjectPassed(
                $studentId,
                $prereq['prerequisite_subject_id']
            );
            if (!$isPassed) {
                $failedPrereqs[] = $prereq['prerequisite_code']
                    . ' - ' . $prereq['prerequisite_name'];
            }
        }

        if (!empty($failedPrereqs)) {
            return [
                'valid'   => false,
                'message' => 'Cannot enroll. Prerequisites not satisfied: '
                    . implode(', ', $failedPrereqs)
            ];
        }

        return ['valid' => true, 'message' => 'Prerequisites satisfied'];
    }

    /**
     * Get subject IDs from schedule IDs.
     */
    private function getSubjectIdsFromSchedules($scheduleIds) {
        if (empty($scheduleIds)) {
            return [];
        }

        $scheduleIds  = array_values(array_filter(array_map('intval', $scheduleIds), fn($id) => $id > 0));
        if (empty($scheduleIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
        $sql = "SELECT DISTINCT subject_id FROM cc_schedule WHERE id IN ($placeholders)";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($scheduleIds);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map('intval', array_column($results, 'subject_id'));
    }

    /**
     * Get prerequisites for a subject.
     * FIX: rgr_subjects columns are `code` and `name`.
     */
    private function getPrerequisites($subjectId) {
        $sql = "SELECT p.prerequisite_subject_id,
                       s.code AS prerequisite_code,
                       s.name AS prerequisite_name
                FROM enr_prerequisites p
                INNER JOIN rgr_subjects s ON p.prerequisite_subject_id = s.id
                WHERE p.subject_id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$subjectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a subject is passed (grade > 75 OR remarks = 'Passed').
     */
    private function checkIfSubjectPassed($studentId, $subjectId) {
        $sql = "SELECT g.grade, g.remarks
                FROM rgr_grades g
                INNER JOIN enr_enrollments e ON g.enrollment_id = e.enrollment_id
                INNER JOIN cc_schedule s ON e.schedule_id = s.id
                WHERE e.student_id = ?
                  AND s.subject_id = ?
                ORDER BY e.created_at DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$studentId, $subjectId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        if ($result['grade'] !== null && (float) $result['grade'] > 75) {
            return true;
        }
        if ($result['remarks'] === 'Passed') {
            return true;
        }

        return false;
    }

    /**
     * Check for duplicate enrollments.
     */
    private function checkDuplicateEnrollments($studentId, $scheduleIds) {
        if (empty($scheduleIds)) {
            return ['valid' => true, 'message' => ''];
        }

        $scheduleIds  = array_values(array_filter(array_map('intval', $scheduleIds), fn($id) => $id > 0));
        if (empty($scheduleIds)) {
            return ['valid' => true, 'message' => ''];
        }

        $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));
        $sql = "SELECT schedule_id FROM enr_enrollments
                WHERE student_id = ?
                  AND schedule_id IN ($placeholders)
                  AND enrollment_status = 'enrolled'";

        $params = array_merge([(int) $studentId], $scheduleIds);
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($existing)) {
            $existingIds = array_map('intval', array_column($existing, 'schedule_id'));
            return [
                'valid'                  => false,
                'message'                => 'Student is already enrolled in some selected schedules',
                'duplicate_schedule_ids' => $existingIds
            ];
        }

        return ['valid' => true, 'message' => ''];
    }
}