<?php
// classes/StudentProgression.php - FULLY VERIFIED for `kms` schema
//
// No schema changes needed. Only hardened:
//   • (int) casts on IDs and year levels
//   • explicit $fillable so Model restricts writes to safe fields
//   • defensive is_array() on section lookup
//   • canProgress() now BLOCKS progression when retake subjects exist

require_once 'Model.php';

class StudentProgression extends Model {
    protected $table      = 'enr_students';
    protected $primaryKey = 'student_id';

    // FIX: explicit fillable so Model::update() only writes these fields.
    // Prevents accidental writes to e.g. student_number via progressStudent().
    protected $fillable = [
        'year_level',
        'section_id',
        'course_id',
        'status',
        'enrollment_status'
    ];

    /**
     * Get the next progression for a student.
     *
     * Rules:
     *   1st Year 1st Sem → 1st Year 2nd Sem
     *   1st Year 2nd Sem → 2nd Year 1st Sem
     *   ... etc up to 4th Year 2nd Sem = completed
     */
    public function getNextProgression($studentId, $currentYearLevel, $currentSemester) {
        $currentYearLevel = (int) $currentYearLevel;
        $currentSemester  = (int) $currentSemester;
        $maxYearLevel     = 4;

        if ($currentYearLevel >= $maxYearLevel && $currentSemester >= 2) {
            return [
                'year_level'   => $currentYearLevel,
                'semester'     => $currentSemester,
                'is_completed' => true
            ];
        }

        if ($currentSemester === 1) {
            return [
                'year_level'   => $currentYearLevel,
                'semester'     => 2,
                'is_completed' => false
            ];
        }

        $nextYear = $currentYearLevel + 1;
        if ($nextYear > $maxYearLevel) {
            $nextYear = $maxYearLevel;
        }

        return [
            'year_level'   => $nextYear,
            'semester'     => 1,
            'is_completed' => false
        ];
    }

    /**
     * Get the current semester name.
     */
    public function getSemesterName($semester) {
        return ((int) $semester === 1) ? 'First Semester' : 'Second Semester';
    }

    /**
     * Get the year level text.
     */
    public function getYearLevelText($yearLevel) {
        $levels = [
            1 => '1st Year',
            2 => '2nd Year',
            3 => '3rd Year',
            4 => '4th Year'
        ];
        return $levels[(int) $yearLevel] ?? '1st Year';
    }

    /**
     * Check if student can progress to next semester/year.
     *
     * Blocks progression when:
     *   • Student is not found
     *   • Student is not active
     *   • Student has retake subjects (bagsak na subjects)
     */
    public function canProgress($studentId) {
        $student = $this->findById($studentId);

        if (!$student) {
            return ['can_progress' => false, 'reason' => 'Student not found'];
        }
        if (($student['status'] ?? '') !== 'active') {
            return ['can_progress' => false, 'reason' => 'Student is not active'];
        }

        // NEW: Block progression kung may retake subjects
        try {
            require_once __DIR__ . '/SubjectStatusManager.php';
            $subjectStatus  = new SubjectStatusManager();
            $retakeSubjects = $subjectStatus->getRetakeSubjects($studentId);

            if (is_array($retakeSubjects) && count($retakeSubjects) > 0) {
                return [
                    'can_progress' => false,
                    'reason'       => 'May ' . count($retakeSubjects)
                        . ' retake subject(s) — kailangan munang tapusin.',
                    'retake_count' => count($retakeSubjects)
                ];
            }
        } catch (Exception $e) {
            error_log('canProgress() retake check failed: ' . $e->getMessage());
            // Fail-safe: huwag i-block kung may error sa check
        }

        return ['can_progress' => true, 'reason' => ''];
    }

    /**
     * Determine the appropriate section for a student based on progression.
     */
    public function getNextSection($studentId, $yearLevel, $semester, $courseId, $schoolYear) {
        require_once 'Section.php';
        $sectionModel = new Section();

        $yearLevelText = $this->getYearLevelText($yearLevel);
        $semesterText  = $this->getSemesterName($semester);

        $sections = $sectionModel->getSectionsByCourseAndYearLevel(
            $courseId,
            $yearLevelText,
            $semesterText
        );

        if (!is_array($sections) || empty($sections)) {
            return null;
        }

        return $sections[0];
    }

    /**
     * Update student's year level and (optionally) section for progression.
     *
     * BLOCKS progression if student has retake subjects.
     */
    public function progressStudent($studentId, $newYearLevel, $newSemester, $newSectionId = null) {
        try {
            $student = $this->findById($studentId);
            if (!$student) {
                return ['success' => false, 'message' => 'Student not found'];
            }

            // NEW: Block progression kung may retake subjects
            $progressCheck = $this->canProgress($studentId);
            if (!$progressCheck['can_progress']) {
                return [
                    'success' => false,
                    'message' => $progressCheck['reason']
                ];
            }

            $updateData = [
                'year_level' => (int) $newYearLevel
            ];

            if (!empty($newSectionId)) {
                $updateData['section_id'] = (int) $newSectionId;
            }

            $result = $this->update($studentId, $updateData);

            if ($result) {
                return [
                    'success'    => true,
                    'message'    => 'Student progressed successfully',
                    'year_level' => (int) $newYearLevel,
                    'semester'   => (int) $newSemester,
                    'section_id' => !empty($newSectionId) ? (int) $newSectionId : null
                ];
            }
            return ['success' => false, 'message' => 'Failed to update student'];
        } catch (Exception $e) {
            error_log('Error in progressStudent: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}