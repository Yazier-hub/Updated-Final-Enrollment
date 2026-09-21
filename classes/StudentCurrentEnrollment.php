<?php
// classes/StudentCurrentEnrollment.php - FULLY VERIFIED for `kms` schema
//
// No schema changes needed. Only hardened:
//   • (int) casts on IDs
//   • null-safe reads on units / enrollment_status / schedule_id
//   • is_array() guards before count()

require_once 'Model.php';
require_once 'Enrollment.php';

class StudentCurrentEnrollment extends Model {
    protected $table      = 'enr_students';
    protected $primaryKey = 'student_id';

    private $enrollment;

    public function __construct() {
        parent::__construct();
        $this->enrollment = new Enrollment();
    }

    /**
     * Get student's current semester subjects (via the fixed Enrollment method).
     */
    public function getCurrentSubjects($studentId, $schoolYear = null) {
        return $this->enrollment->getStudentCurrentSemesterEnrollments($studentId, $schoolYear);
    }

    /**
     * Get current semester subject count.
     */
    public function getCurrentSubjectCount($studentId, $schoolYear = null) {
        return $this->enrollment->getStudentCurrentSemesterSubjectCount($studentId, $schoolYear);
    }

    /**
     * Get student's current section info.
     */
    public function getCurrentSection($studentId) {
        try {
            $sql = "SELECT
                        s.section_id,
                        sec.section_code,
                        sec.grade_level,
                        sec.semester,
                        sec.school_year,
                        c.code as course_code,
                        c.name as course_name
                    FROM enr_students s
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    WHERE s.student_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $studentId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getCurrentSection: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get complete current semester data for a student.
     */
    public function getFullCurrentEnrollment($studentId, $schoolYear = null) {
        try {
            $section  = $this->getCurrentSection($studentId);
            $subjects = $this->getCurrentSubjects($studentId, $schoolYear);

            if (!is_array($subjects)) {
                $subjects = [];
            }

            $subjectCount      = count($subjects);
            $totalUnits        = 0;
            $completedCount    = 0;
            $inProgressCount   = 0;
            $withScheduleCount = 0;

            foreach ($subjects as $subject) {
                $totalUnits += (int) ($subject['units'] ?? 0);

                $status = $subject['enrollment_status'] ?? '';
                if ($status === 'completed') {
                    $completedCount++;
                } elseif ($status === 'enrolled') {
                    $inProgressCount++;
                }

                if (!empty($subject['schedule_id'])) {
                    $withScheduleCount++;
                }
            }

            return [
                'success'    => true,
                'student_id' => (int) $studentId,
                'section'    => $section,
                'subjects'   => $subjects,
                'summary' => [
                    'total_subjects' => $subjectCount,
                    'total_units'    => $totalUnits,
                    'completed'      => $completedCount,
                    'in_progress'    => $inProgressCount,
                    'with_schedule'  => $withScheduleCount
                ]
            ];
        } catch (Exception $e) {
            error_log('Error in getFullCurrentEnrollment: ' . $e->getMessage());
            return [
                'success'  => false,
                'message'  => $e->getMessage(),
                'subjects' => [],
                'summary' => [
                    'total_subjects' => 0,
                    'total_units'    => 0,
                    'completed'      => 0,
                    'in_progress'    => 0,
                    'with_schedule'  => 0
                ]
            ];
        }
    }
}