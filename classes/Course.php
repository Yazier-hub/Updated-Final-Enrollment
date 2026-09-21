<?php
// classes/Course.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • cc_sections has NO current_students / max_students columns.
//     getAvailableCourses() and hasAvailableSections() now count
//     actual enrolled students via enr_enrollments instead.
//   • Everything else was already correct for `kms`.

require_once 'Model.php';
require_once 'Section.php';

class Course extends Model {
    protected $table = 'rgr_courses';
    protected $primaryKey = 'id';

    public function __construct() {
        parent::__construct();
    }

    /**
     * Get all courses with section count and enrollment stats.
     */
    public function getAllWithSectionCount() {
        try {
            $sql = "SELECT c.*,
                           COUNT(DISTINCT s.id) as total_sections,
                           (SELECT COUNT(DISTINCT e.student_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_students,
                           (SELECT COUNT(DISTINCT e.schedule_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_subject_enrollments
                    FROM {$this->table} c
                    LEFT JOIN cc_sections s ON c.id = s.program_id
                    GROUP BY c.id
                    ORDER BY c.code ASC";
            $stmt = $this->executeQuery($sql);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting courses with section count: ' . $e->getMessage());
            return $this->findAll();
        }
    }

    /**
     * Get course statistics.
     */
    public function getCourseStats() {
        try {
            $sql = "SELECT
                        COUNT(*) as total_courses,
                        (SELECT COUNT(*) FROM cc_sections) as total_sections,
                        (SELECT COUNT(DISTINCT student_id)
                         FROM enr_enrollments WHERE enrollment_status = 'enrolled') as total_students,
                        (SELECT COUNT(*)
                         FROM enr_enrollments WHERE enrollment_status = 'enrolled') as total_subject_enrollments,
                        (SELECT COUNT(DISTINCT schedule_id)
                         FROM enr_enrollments WHERE enrollment_status = 'enrolled') as total_schedule_enrollments
                    FROM {$this->table}";
            $stmt = $this->executeQuery($sql);
            return $stmt->fetch($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting course stats: ' . $e->getMessage());
            return [
                'total_courses'              => $this->count(),
                'total_sections'             => 0,
                'total_students'             => 0,
                'total_subject_enrollments'  => 0,
                'total_schedule_enrollments' => 0
            ];
        }
    }

    /**
     * Get course by code.
     */
    public function getByCode($code) {
        try {
            $sql = "SELECT * FROM {$this->table} WHERE code = ?";
            $stmt = $this->executeQuery($sql, [$code]);
            return $stmt->fetch($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting course by code: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Search courses by code or name.
     */
    public function searchByName($keyword) {
        try {
            $sql = "SELECT * FROM {$this->table}
                    WHERE code LIKE ? OR name LIKE ?
                    ORDER BY code ASC";
            $search = '%' . $keyword . '%';
            $stmt = $this->executeQuery($sql, [$search, $search]);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error searching courses: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get popular courses (most enrolled students).
     */
    public function getPopularCourses($limit = 5) {
        try {
            $sql = "SELECT c.*,
                           COUNT(DISTINCT e.student_id) as total_students,
                           COUNT(DISTINCT e.schedule_id) as total_subject_enrollments,
                           COUNT(e.enrollment_id) as total_enrollments
                    FROM {$this->table} c
                    JOIN cc_sections s ON c.id = s.program_id
                    JOIN enr_enrollments e ON s.id = e.section_id
                    WHERE e.enrollment_status = 'enrolled'
                    GROUP BY c.id
                    ORDER BY total_students DESC
                    LIMIT ?";
            $stmt = $this->executeQuery($sql, [(int) $limit]);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting popular courses: ' . $e->getMessage());
            return $this->findAll();
        }
    }

    /**
     * Get a course with aggregate stats.
     */
    public function getCourseWithStats($id) {
        try {
            $sql = "SELECT c.*,
                           COUNT(DISTINCT s.id) as total_sections,
                           (SELECT COUNT(DISTINCT e.student_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_students,
                           (SELECT COUNT(DISTINCT e.schedule_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_subject_enrollments,
                           (SELECT COUNT(e.enrollment_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_enrollments
                    FROM {$this->table} c
                    LEFT JOIN cc_sections s ON c.id = s.program_id
                    WHERE c.id = ?
                    GROUP BY c.id";
            $stmt = $this->executeQuery($sql, [$id]);
            return $stmt->fetch($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting course with stats: ' . $e->getMessage());
            return $this->findById($id);
        }
    }

    /**
     * Get courses with capacity info.
     */
    public function getCoursesWithCapacity() {
        try {
            $sql = "SELECT c.*,
                           COUNT(DISTINCT s.id) as total_sections,
                           (SELECT COUNT(DISTINCT e.student_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_students,
                           (SELECT COUNT(DISTINCT e.schedule_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_subject_enrollments,
                           (SELECT COUNT(e.enrollment_id)
                            FROM enr_enrollments e
                            JOIN cc_sections cs ON e.section_id = cs.id
                            WHERE cs.program_id = c.id
                              AND e.enrollment_status = 'enrolled'
                           ) as total_enrollments
                    FROM {$this->table} c
                    LEFT JOIN cc_sections s ON c.id = s.program_id
                    GROUP BY c.id
                    ORDER BY c.code ASC";
            $stmt = $this->executeQuery($sql);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting courses with capacity: ' . $e->getMessage());
            return $this->findAll();
        }
    }

    /**
     * Get available courses (with sections that still have room).
     *
     * FIX: cc_sections has no current_students / max_students columns.
     * Compute occupancy by counting enrolled students per section
     * and compare to a hard-coded capacity of 40.
     */
    public function getAvailableCourses() {
        try {
            $sql = "SELECT DISTINCT c.*
                    FROM {$this->table} c
                    JOIN cc_sections s ON c.id = s.program_id
                    WHERE (
                        SELECT COUNT(DISTINCT e.student_id)
                        FROM enr_enrollments e
                        WHERE e.section_id = s.id
                          AND e.enrollment_status = 'enrolled'
                    ) < 40
                    ORDER BY c.code ASC";
            $stmt = $this->executeQuery($sql);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting available courses: ' . $e->getMessage());
            return $this->findAll();
        }
    }

    /**
     * Check if a course has at least one section with available slots.
     *
     * FIX: cc_sections has no capacity columns.
     */
    public function hasAvailableSections($courseId) {
        try {
            $sql = "SELECT COUNT(*) AS count
                    FROM cc_sections s
                    WHERE s.program_id = ?
                      AND (
                        SELECT COUNT(DISTINCT e.student_id)
                        FROM enr_enrollments e
                        WHERE e.section_id = s.id
                          AND e.enrollment_status = 'enrolled'
                      ) < 40";
            $stmt = $this->executeQuery($sql, [$courseId]);
            $result = $stmt->fetch($this->fetchMode);
            return ((int) ($result['count'] ?? 0)) > 0;
        } catch (Exception $e) {
            error_log('Error checking available sections: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new course with validation.
     */
    public function createCourse($data) {
        try {
            if (empty($data['code']) || empty($data['name']) || empty($data['years'])) {
                return ['success' => false, 'message' => 'Code, name, and years are required'];
            }

            $existing = $this->getByCode($data['code']);
            if ($existing) {
                return ['success' => false, 'message' => 'Course code already exists'];
            }

            $sanitized = [
                'code'  => strtoupper(trim($data['code'])),
                'name'  => trim($data['name']),
                'years' => (int) $data['years']
            ];

            $result = $this->create($sanitized);
            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Course created successfully',
                    'id'      => $result
                ];
            }
            return ['success' => false, 'message' => 'Failed to create course'];
        } catch (Exception $e) {
            error_log('Error creating course: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Update a course with validation.
     */
    public function updateCourse($id, $data) {
        try {
            if (empty($data['code']) || empty($data['name']) || empty($data['years'])) {
                return ['success' => false, 'message' => 'Code, name, and years are required'];
            }

            $course = $this->findById($id);
            if (!$course) {
                return ['success' => false, 'message' => 'Course not found'];
            }

            $existing = $this->getByCode($data['code']);
            if ($existing && $existing['id'] != $id) {
                return ['success' => false, 'message' => 'Course code already exists'];
            }

            $sanitized = [
                'code'  => strtoupper(trim($data['code'])),
                'name'  => trim($data['name']),
                'years' => (int) $data['years']
            ];

            $result = $this->update($id, $sanitized);
            if ($result) {
                return ['success' => true, 'message' => 'Course updated successfully'];
            }
            return ['success' => false, 'message' => 'Failed to update course'];
        } catch (Exception $e) {
            error_log('Error updating course: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a course with validation.
     */
    public function deleteCourse($id) {
        try {
            $sql = "SELECT COUNT(*) as count FROM cc_sections WHERE program_id = ?";
            $stmt = $this->executeQuery($sql, [$id]);
            $result = $stmt->fetch($this->fetchMode);

            if (((int) ($result['count'] ?? 0)) > 0) {
                return ['success' => false, 'message' => 'Cannot delete course with existing sections'];
            }

            $result = $this->delete($id);
            if ($result) {
                return ['success' => true, 'message' => 'Course deleted successfully'];
            }
            return ['success' => false, 'message' => 'Failed to delete course'];
        } catch (Exception $e) {
            error_log('Error deleting course: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get courses with student counts per year level.
     */
    public function getCoursesWithYearLevelCounts() {
        try {
            $sql = "SELECT
                        c.id,
                        c.code,
                        c.name,
                        c.years,
                        COUNT(DISTINCT e.student_id) as total_students,
                        COUNT(DISTINCT e.schedule_id) as total_subject_enrollments,
                        COUNT(e.enrollment_id) as total_enrollments
                    FROM {$this->table} c
                    LEFT JOIN cc_sections s ON c.id = s.program_id
                    LEFT JOIN enr_enrollments e
                        ON s.id = e.section_id
                        AND e.enrollment_status = 'enrolled'
                    GROUP BY c.id
                    ORDER BY c.code ASC";
            $stmt = $this->executeQuery($sql);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting courses with year level counts: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get enrollment statistics per course.
     */
    public function getEnrollmentStats($year = null) {
        try {
            if (!$year) {
                $year = date('Y') . '-' . (date('Y') + 1);
            }

            $sql = "SELECT
                        c.id,
                        c.code,
                        c.name,
                        COUNT(DISTINCT e.schedule_id) as total_subject_enrollments,
                        COUNT(DISTINCT e.student_id) as unique_students,
                        COUNT(e.enrollment_id) as total_enrollments
                    FROM {$this->table} c
                    LEFT JOIN cc_sections s ON c.id = s.program_id
                    LEFT JOIN enr_enrollments e
                        ON s.id = e.section_id
                        AND e.school_year = ?
                        AND e.enrollment_status = 'enrolled'
                    GROUP BY c.id
                    ORDER BY total_subject_enrollments DESC";
            $stmt = $this->executeQuery($sql, [$year]);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting enrollment stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get course completion rate.
     */
    public function getCompletionRate($id) {
        try {
            $sql = "SELECT
                        COUNT(DISTINCT e.student_id) as total_students,
                        SUM(CASE WHEN e.enrollment_status = 'completed' THEN 1 ELSE 0 END) as completed,
                        COUNT(DISTINCT e.schedule_id) as total_subjects,
                        SUM(CASE WHEN e.enrollment_status = 'completed' THEN 1 ELSE 0 END) as completed_subjects
                    FROM {$this->table} c
                    JOIN cc_sections s ON c.id = s.program_id
                    JOIN enr_enrollments e ON s.id = e.section_id
                    WHERE c.id = ?
                      AND e.enrollment_status IN ('enrolled', 'completed')";
            $stmt = $this->executeQuery($sql, [$id]);
            $result = $stmt->fetch($this->fetchMode);

            if ($result && (int) $result['total_students'] > 0) {
                return [
                    'completion_rate'    => round(($result['completed'] / $result['total_students']) * 100, 2),
                    'total_students'     => (int) $result['total_students'],
                    'completed_students' => (int) $result['completed'],
                    'total_subjects'     => (int) $result['total_subjects'],
                    'completed_subjects' => (int) $result['completed_subjects']
                ];
            }
            return [
                'completion_rate'    => 0,
                'total_students'     => 0,
                'completed_students' => 0,
                'total_subjects'     => 0,
                'completed_subjects' => 0
            ];
        } catch (Exception $e) {
            error_log('Error getting completion rate: ' . $e->getMessage());
            return [
                'completion_rate'    => 0,
                'total_students'     => 0,
                'completed_students' => 0,
                'total_subjects'     => 0,
                'completed_subjects' => 0
            ];
        }
    }

    /**
     * Get course dropdown HTML options.
     */
    public function getDropdownOptions($selectedId = null) {
        try {
            $courses = $this->findAll();
            $options = '';
            foreach ($courses as $course) {
                $selected = ($selectedId && $course['id'] == $selectedId) ? 'selected' : '';
                $options .= "<option value=\"{$course['id']}\" {$selected}>"
                    . htmlspecialchars($course['code'] . ' - ' . $course['name'])
                    . "</option>";
            }
            return $options;
        } catch (Exception $e) {
            error_log('Error getting dropdown options: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get detailed enrollment summary for a course (schedule-based).
     */
    public function getCourseEnrollmentSummary($courseId, $schoolYear = null) {
        try {
            if (!$schoolYear) {
                $schoolYear = date('Y') . '-' . (date('Y') + 1);
            }

            $sql = "SELECT
                        COUNT(DISTINCT e.student_id) as total_students,
                        COUNT(DISTINCT e.schedule_id) as total_subject_enrollments,
                        COUNT(e.enrollment_id) as total_enrollments,
                        SUM(CASE WHEN e.enrollment_status = 'enrolled'  THEN 1 ELSE 0 END) as active_enrollments,
                        SUM(CASE WHEN e.enrollment_status = 'completed' THEN 1 ELSE 0 END) as completed_enrollments,
                        SUM(CASE WHEN e.enrollment_status = 'dropped'   THEN 1 ELSE 0 END) as dropped_enrollments,
                        COUNT(DISTINCT cs.subject_id) as unique_subjects
                    FROM enr_enrollments e
                    JOIN cc_sections s ON e.section_id = s.id
                    JOIN cc_schedule cs ON e.schedule_id = cs.id
                    WHERE s.program_id = ?
                      AND e.school_year = ?
                    GROUP BY s.program_id";
            $stmt = $this->executeQuery($sql, [$courseId, $schoolYear]);
            return $stmt->fetch($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting course enrollment summary: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get student distribution across year levels for a course.
     */
    public function getCourseStudentDistribution($courseId) {
        try {
            $sql = "SELECT
                        s.year_level,
                        COUNT(DISTINCT e.student_id) as student_count,
                        COUNT(DISTINCT e.schedule_id) as subject_enrollments
                    FROM enr_students s
                    JOIN enr_enrollments e ON s.student_id = e.student_id
                    WHERE s.course_id = ?
                      AND e.enrollment_status = 'enrolled'
                    GROUP BY s.year_level
                    ORDER BY s.year_level";
            $stmt = $this->executeQuery($sql, [$courseId]);
            return $stmt->fetchAll($this->fetchMode);
        } catch (Exception $e) {
            error_log('Error getting course student distribution: ' . $e->getMessage());
            return [];
        }
    }
}