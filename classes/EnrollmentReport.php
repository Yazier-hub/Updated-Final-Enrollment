<?php
// classes/EnrollmentReport.php - COMPLETE FIXED VERSION v2
// ✅ FIXED: Removed duplicate $db property (uses $this->connection from Model)
// ✅ FIXED: All queries use $this->connection

require_once 'Model.php';

class EnrollmentReport extends Model {
    protected $table = 'enr_enrollments';
    protected $primaryKey = 'enrollment_id';

    public function __construct() {
        parent::__construct();
    }

    /**
     * Get summary statistics
     */
    public function getSummaryStats() {
        try {
            $stats = [];
            
            // Total students
            $sql = "SELECT COUNT(*) as count FROM enr_students";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['total_students'] = (int)$stmt->fetch()['count'];

            // Total enrollments
            $sql = "SELECT COUNT(*) as count FROM enr_enrollments WHERE enrollment_status = 'enrolled'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['total_enrollments'] = (int)$stmt->fetch()['count'];

            // Active students
            $sql = "SELECT COUNT(DISTINCT student_id) as count FROM enr_enrollments WHERE enrollment_status = 'enrolled'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['active_students'] = (int)$stmt->fetch()['count'];

            // Total applications
            $sql = "SELECT COUNT(*) as count FROM enr_applicants";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['total_applications'] = (int)$stmt->fetch()['count'];

            // Pending applications
            $sql = "SELECT COUNT(*) as count FROM enr_applicants WHERE status = 'pending'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['pending_applications'] = (int)$stmt->fetch()['count'];

            // Converted applications
            $sql = "SELECT COUNT(*) as count FROM enr_applicants WHERE status = 'converted'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['converted_applications'] = (int)$stmt->fetch()['count'];

            // Rejected applications
            $sql = "SELECT COUNT(*) as count FROM enr_applicants WHERE status = 'rejected'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['rejected_applications'] = (int)$stmt->fetch()['count'];

            // Total sections
            $sql = "SELECT COUNT(*) as count FROM cc_sections";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['total_sections'] = (int)$stmt->fetch()['count'];

            // Total courses
            $sql = "SELECT COUNT(*) as count FROM rgr_courses";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $stats['total_courses'] = (int)$stmt->fetch()['count'];

            // Conversion rate
            $stats['conversion_rate'] = $stats['total_applications'] > 0 
                ? round($stats['converted_applications'] / $stats['total_applications'] * 100, 1) 
                : 0;

            return $stats;
        } catch (Exception $e) {
            error_log('Error in getSummaryStats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get enrollment by course
     */
    public function getEnrollmentByCourse() {
        try {
            $sql = "SELECT 
                        c.id as course_id,
                        c.code as course_code,
                        c.name as course_name,
                        COUNT(DISTINCT e.student_id) as student_count,
                        COUNT(DISTINCT e.schedule_id) as subject_count
                    FROM rgr_courses c
                    LEFT JOIN cc_sections sec ON c.id = sec.program_id
                    LEFT JOIN enr_enrollments e ON sec.id = e.section_id AND e.enrollment_status = 'enrolled'
                    GROUP BY c.id
                    ORDER BY student_count DESC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getEnrollmentByCourse: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get enrollment by year level
     */
    public function getEnrollmentByYearLevel() {
        try {
            $sql = "SELECT 
                        sec.grade_level,
                        COUNT(DISTINCT e.student_id) as student_count,
                        COUNT(DISTINCT e.schedule_id) as subject_count
                    FROM cc_sections sec
                    LEFT JOIN enr_enrollments e ON sec.id = e.section_id AND e.enrollment_status = 'enrolled'
                    GROUP BY sec.grade_level
                    ORDER BY FIELD(sec.grade_level, '1st Year', '2nd Year', '3rd Year', '4th Year')";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getEnrollmentByYearLevel: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get enrollment by section
     */
    public function getEnrollmentBySection() {
        try {
            $sql = "SELECT 
                        sec.id as section_id,
                        sec.section_code,
                        c.code as course_code,
                        sec.grade_level,
                        sec.semester,
                        COUNT(DISTINCT e.student_id) as student_count,
                        COUNT(DISTINCT e.schedule_id) as subject_count
                    FROM cc_sections sec
                    JOIN rgr_courses c ON sec.program_id = c.id
                    LEFT JOIN enr_enrollments e ON sec.id = e.section_id AND e.enrollment_status = 'enrolled'
                    GROUP BY sec.id
                    ORDER BY c.code, sec.grade_level, sec.section_code";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getEnrollmentBySection: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get monthly enrollment trend
     */
    public function getMonthlyTrend($year = null) {
        try {
            if (!$year) $year = date('Y');
            
            $sql = "SELECT 
                        MONTH(created_at) as month,
                        COUNT(*) as enrollment_count
                    FROM enr_enrollments 
                    WHERE YEAR(created_at) = ? AND enrollment_status = 'enrolled'
                    GROUP BY MONTH(created_at)
                    ORDER BY month";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$year]);
            $enrollments = $stmt->fetchAll();

            $sql = "SELECT 
                        MONTH(submitted_at) as month,
                        COUNT(*) as application_count
                    FROM enr_applicants 
                    WHERE YEAR(submitted_at) = ?
                    GROUP BY MONTH(submitted_at)
                    ORDER BY month";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$year]);
            $applications = $stmt->fetchAll();

            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $enrollmentTrend = array_fill(0, 12, 0);
            $applicationTrend = array_fill(0, 12, 0);

            foreach ($enrollments as $row) {
                $enrollmentTrend[$row['month'] - 1] = (int)$row['enrollment_count'];
            }
            foreach ($applications as $row) {
                $applicationTrend[$row['month'] - 1] = (int)$row['application_count'];
            }

            $result = [];
            foreach ($months as $i => $month) {
                $result[] = [
                    'month' => $month,
                    'enrollments' => $enrollmentTrend[$i],
                    'applications' => $applicationTrend[$i]
                ];
            }
            return $result;
        } catch (Exception $e) {
            error_log('Error in getMonthlyTrend: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all students with details
     */
    public function getAllStudentsWithDetails() {
        try {
            $sql = "SELECT 
                        s.student_id,
                        s.student_number,
                        a.first_name,
                        a.middle_name,
                        a.surname,
                        a.suffix,
                        c.code as course_code,
                        c.name as course_name,
                        sec.section_code,
                        sec.grade_level,
                        sec.semester,
                        sec.school_year,
                        s.year_level,
                        s.enrollment_status,
                        a.admission_type,
                        a.email,
                        a.contact_number,
                        s.enrolled_at
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getAllStudentsWithDetails: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all pending applications
     */
    public function getPendingApplications() {
        try {
            $sql = "SELECT 
                        a.applicant_id,
                        a.first_name,
                        a.middle_name,
                        a.surname,
                        a.suffix,
                        a.email,
                        a.contact_number,
                        a.admission_type,
                        c.code as course_code,
                        c.name as course_name,
                        a.submitted_at
                    FROM enr_applicants a
                    LEFT JOIN rgr_courses c ON a.course_id = c.id
                    WHERE a.status = 'pending'
                    ORDER BY a.submitted_at DESC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getPendingApplications: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all converted applicants
     */
    public function getConvertedApplications() {
        try {
            $sql = "SELECT 
                        a.applicant_id,
                        a.first_name,
                        a.middle_name,
                        a.surname,
                        a.suffix,
                        a.email,
                        a.admission_type,
                        c.code as course_code,
                        s.student_number,
                        s.enrolled_at
                    FROM enr_applicants a
                    LEFT JOIN rgr_courses c ON a.course_id = c.id
                    LEFT JOIN enr_students s ON a.applicant_id = s.applicant_id
                    WHERE a.status = 'converted'
                    ORDER BY s.enrolled_at DESC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getConvertedApplications: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get students with incomplete requirements
     */
    public function getStudentsWithIncompleteRequirements() {
        try {
            $sql = "SELECT 
                        s.student_id,
                        s.student_number,
                        a.first_name,
                        a.surname,
                        c.code as course_code,
                        COUNT(DISTINCT req.requirement_id) as total_requirements,
                        SUM(CASE WHEN sr.is_submitted = 1 THEN 1 ELSE 0 END) as submitted_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN enr_student_requirements sr ON s.student_id = sr.student_id
                    LEFT JOIN enr_requirements req ON sr.requirement_id = req.requirement_id
                    GROUP BY s.student_id
                    HAVING submitted_count < total_requirements OR total_requirements = 0
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsWithIncompleteRequirements: ' . $e->getMessage());
            return [];
        }
    }

        /**
     * Get recent enrollments (FIXED for kms schema)
     */
    public function getRecentEnrollments($limit = 20) {
        try {
            $limit = (int) $limit;

            $sql = "SELECT 
                        e.enrollment_id,
                        e.created_at AS enrolled_at,
                        e.enrollment_date,
                        e.enrollment_status,
                        e.school_year,
                        e.semester,

                        s.student_number,
                        s.year_level,

                        a.first_name,
                        a.surname,

                        rs.code AS subject_code,
                        rs.name AS subject_name,
                        rs.units,

                        sec.section_code,
                        c.code AS course_code,
                        c.name AS course_name,

                        cs.day_of_week,
                        cs.start_time,
                        cs.end_time,

                        f.first_name AS faculty_first,
                        f.last_name  AS faculty_last,

                        r.room_code

                    FROM enr_enrollments e
                    INNER JOIN enr_students      s   ON s.student_id     = e.student_id
                    INNER JOIN enr_applicants    a   ON a.applicant_id   = s.applicant_id
                    INNER JOIN cc_schedule       cs  ON cs.id            = e.schedule_id
                    INNER JOIN rgr_subjects      rs  ON rs.id            = cs.subject_id
                    INNER JOIN cc_sections       sec ON sec.id           = e.section_id
                    LEFT  JOIN rgr_courses       c   ON c.id             = sec.program_id
                    LEFT  JOIN cc_faculty        f   ON f.id             = cs.faculty_id
                    LEFT  JOIN cc_room           r   ON r.id             = cs.room_id
                    WHERE e.enrollment_status = 'enrolled'
                    ORDER BY e.created_at DESC, e.enrollment_id DESC
                    LIMIT {$limit}";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log('Error in getRecentEnrollments: ' . $e->getMessage());
            // TEMP DEBUG — remove after fixing
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                echo '<pre style="background:#fee;padding:10px;border:1px solid #c00;">';
                echo 'getRecentEnrollments() FAILED: ' . htmlspecialchars($e->getMessage());
                echo '</pre>';
            }
            return [];
        }
    }

    /**
     * Get predictive analytics data
     */
    public function getPredictiveAnalytics($year = null) {
        try {
            if (!$year) $year = date('Y');
            $trend = $this->getMonthlyTrend($year);
            
            if (empty($trend)) {
                return [
                    'avg_growth' => 0,
                    'predicted_next_month' => 0,
                    'predicted_next_quarter' => 0,
                    'last_month' => 0
                ];
            }

            $values = array_column($trend, 'enrollments');
            $lastMonth = end($values);
            $firstMonth = reset($values);
            $avgGrowth = count($values) > 0 ? ($lastMonth - $firstMonth) / count($values) : 0;

            return [
                'avg_growth' => round($avgGrowth, 2),
                'predicted_next_month' => round($lastMonth + $avgGrowth),
                'predicted_next_quarter' => round($lastMonth + ($avgGrowth * 3)),
                'last_month' => $lastMonth
            ];
        } catch (Exception $e) {
            error_log('Error in getPredictiveAnalytics: ' . $e->getMessage());
            return [
                'avg_growth' => 0,
                'predicted_next_month' => 0,
                'predicted_next_quarter' => 0,
                'last_month' => 0
            ];
        }
    }

    /**
     * Get complete report data (all sections)
     */
    public function getFullReport() {
        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $_SESSION['user_name'] ?? 'System',
            'summary' => $this->getSummaryStats(),
            'enrollment_by_course' => $this->getEnrollmentByCourse(),
            'enrollment_by_year' => $this->getEnrollmentByYearLevel(),
            'enrollment_by_section' => $this->getEnrollmentBySection(),
            'monthly_trend' => $this->getMonthlyTrend(),
            'predictive' => $this->getPredictiveAnalytics(),
            'students' => $this->getAllStudentsWithDetails(),
            'pending_applications' => $this->getPendingApplications(),
            'converted_applications' => $this->getConvertedApplications(),
            'incomplete_requirements' => $this->getStudentsWithIncompleteRequirements(),
            'recent_enrollments' => $this->getRecentEnrollments(20)
        ];
    }
}
?>