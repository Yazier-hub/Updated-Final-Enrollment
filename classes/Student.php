<?php
// classes/Student.php - FULLY FIXED for `kms` schema
//
// FIXES IN THIS VERSION:
//   • cc_sections has NO `semester` / `school_year` — JOINs to lookup tables
//   • enr_students has NO AUTO_INCREMENT — createFromApplicant() computes
//     MAX(student_id) + 1 upfront and supplies it in the INSERT
//   • rgr_subjects uses `code` not `subject_code`

require_once 'Model.php';

class Student extends Model {
    protected $table      = 'enr_students';
    protected $primaryKey = 'student_id';
    protected $fillable = [
        'applicant_id', 'student_number', 'user_id', 'course_id', 'section_id',
        'year_level', 'enrollment_status', 'enrolled_at',
        'followup_date', 'followup_notes', 'followup_status', 'status'
    ];

    public function __construct() {
        parent::__construct();
    }

    /* ============================================================
       STUDENT NUMBER GENERATION
    ============================================================ */

    public function generateStudentNumber() {
        $prefix = date('ymd');

        try {
            $sql = "SELECT student_number
                    FROM enr_students
                    WHERE student_number LIKE ?
                    ORDER BY student_number DESC
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$prefix . '%']);
            $last = $stmt->fetch();

            if ($last) {
                $lastSeq = (int) substr($last['student_number'], -3);
                $newSeq  = str_pad($lastSeq + 1, 3, '0', STR_PAD_LEFT);
            } else {
                $newSeq = '001';
            }

            return $prefix . $newSeq;
        } catch (Exception $e) {
            error_log('Error generating student number: ' . $e->getMessage());
            return date('ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        }
    }

    /* ============================================================
       LOOKUPS
    ============================================================ */

    public function getStudentAdmissionType($studentId) {
        try {
            $sql = "SELECT a.admission_type
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    WHERE s.student_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            $result = $stmt->fetch();
            return $result ? $result['admission_type'] : 'freshmen';
        } catch (Exception $e) {
            error_log('Error in getStudentAdmissionType: ' . $e->getMessage());
            return 'freshmen';
        }
    }

    public function findByApplicantId($applicantId) {
        try {
            $sql  = "SELECT * FROM enr_students WHERE applicant_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$applicantId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in findByApplicantId: ' . $e->getMessage());
            return null;
        }
    }

    public function getByStudentNumber($studentNumber) {
        try {
            $sql  = "SELECT * FROM enr_students WHERE student_number = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentNumber]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getByStudentNumber: ' . $e->getMessage());
            return null;
        }
    }

    public function getStudentByApplicantId($applicantId) {
        return $this->findByApplicantId($applicantId);
    }

    /* ============================================================
       CREATE FROM APPLICANT
       FIX: compute next student_id (enr_students has no AUTO_INCREMENT)
    ============================================================ */

    public function createFromApplicant($applicantId, $courseId, $sectionId, $yearLevel = 1) {
        try {
            $sql  = "SELECT * FROM enr_applicants WHERE applicant_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$applicantId]);
            $applicant = $stmt->fetch();

            if (!$applicant) {
                error_log("Applicant not found: " . $applicantId);
                return false;
            }

            $existing = $this->findByApplicantId($applicantId);
            if ($existing) {
                error_log("Student already exists for applicant: " . $applicantId);
                return (int) $existing['student_id'];
            }

            $studentNumber = $this->generateStudentNumber();

            // ============================================================
            // FIX: enr_students has NO AUTO_INCREMENT on student_id.
            // Compute the next available id manually.
            // ============================================================
            $idStmt = $this->connection->query(
                "SELECT COALESCE(MAX(student_id), 0) + 1 AS next_id FROM enr_students"
            );
            $idRow        = $idStmt->fetch(PDO::FETCH_ASSOC);
            $newStudentId = (int) ($idRow['next_id'] ?? 1);

            error_log("=== CREATING STUDENT ===");
            error_log("Applicant ID: " . $applicantId);
            error_log("Course ID: " . $courseId);
            error_log("Section ID: " . $sectionId);
            error_log("Year Level: " . $yearLevel);
            error_log("Student Number: " . $studentNumber);
            error_log("New Student ID: " . $newStudentId);

            // FIX: explicitly supply student_id in the INSERT
            $sql = "INSERT INTO enr_students (
                        student_id,
                        applicant_id,
                        student_number,
                        user_id,
                        course_id,
                        section_id,
                        year_level,
                        enrollment_status,
                        enrolled_at,
                        followup_status,
                        status
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?
                    )";

            $stmt   = $this->connection->prepare($sql);
            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;

            $result = $stmt->execute([
                $newStudentId,
                $applicantId,
                $studentNumber,
                $userId,
                $courseId,
                $sectionId,
                $yearLevel,
                'enrolled',
                'pending',
                'active'
            ]);

            if ($result) {
                error_log("Student created successfully: " . $studentNumber . " (ID: " . $newStudentId . ")");
                return $newStudentId;
            }

            $errorInfo = $stmt->errorInfo();
            error_log("Failed to create student for applicant: " . $applicantId);
            error_log("SQL Error: " . print_r($errorInfo, true));
            return false;

        } catch (Exception $e) {
            error_log('Error in createFromApplicant: ' . $e->getMessage());
            return false;
        }
    }

    public function createFromApplicantWithRequirements($applicantId, $courseId, $sectionId, $yearLevel = 1) {
        try {
            $transactionStarted = false;
            if (!$this->connection->inTransaction()) {
                $this->connection->beginTransaction();
                $transactionStarted = true;
                error_log("Student: Transaction started");
            } else {
                error_log("Student: Transaction already active");
                $this->connection->exec("SAVEPOINT create_from_applicant_with_req");
            }

            $studentId = $this->createFromApplicant($applicantId, $courseId, $sectionId, $yearLevel);

            if (!$studentId) {
                if ($transactionStarted && $this->connection->inTransaction()) {
                    $this->connection->rollBack();
                } else {
                    try {
                        $this->connection->exec("ROLLBACK TO SAVEPOINT create_from_applicant_with_req");
                    } catch (Exception $e) {
                        error_log("Error rolling back to savepoint: " . $e->getMessage());
                    }
                }
                return false;
            }

            require_once 'Requirement.php';
            $requirement = new Requirement();
            $requirement->initializeStudentRequirements($studentId, $applicantId);

            if ($transactionStarted) {
                if ($this->connection->inTransaction()) {
                    $this->connection->commit();
                    error_log("Student: Transaction committed");
                }
            } else {
                try {
                    $this->connection->exec("RELEASE SAVEPOINT create_from_applicant_with_req");
                    error_log("Student: Savepoint released");
                } catch (Exception $e) {
                    error_log("Error releasing savepoint: " . $e->getMessage());
                }
            }

            return $studentId;
        } catch (Exception $e) {
            if (isset($transactionStarted) && $transactionStarted && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            } else {
                try {
                    $this->connection->exec("ROLLBACK TO SAVEPOINT create_from_applicant_with_req");
                } catch (Exception $e2) {
                    error_log("Error rolling back to savepoint: " . $e2->getMessage());
                }
            }
            error_log('Error in createFromApplicantWithRequirements: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       COUNTS
    ============================================================ */

    public function getStudentCount($year = null) {
        try {
            if ($year) {
                $sql  = "SELECT COUNT(*) as count FROM enr_students WHERE student_number LIKE ?";
                $stmt = $this->connection->prepare($sql);
                $stmt->execute([$year . '%']);
            } else {
                $sql  = "SELECT COUNT(*) as count FROM enr_students";
                $stmt = $this->connection->prepare($sql);
                $stmt->execute();
            }
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in getStudentCount: ' . $e->getMessage());
            return 0;
        }
    }

    public function getStudentCountByCourse($courseId) {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_students
                     WHERE course_id = ? AND enrollment_status = 'enrolled'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$courseId]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in getStudentCountByCourse: ' . $e->getMessage());
            return 0;
        }
    }

    public function getStudentCountByYearLevel($yearLevel) {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_students
                     WHERE year_level = ? AND enrollment_status = 'enrolled'";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$yearLevel]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error in getStudentCountByYearLevel: ' . $e->getMessage());
            return 0;
        }
    }

    /* ============================================================
       DETAILS
    ============================================================ */

    public function getStudentDetails($studentId) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix, a.sex,
                           a.address_barangay, a.address_city, a.address_province, a.address_complete,
                           a.school_last_attended, a.year_graduated, a.email, a.date_of_birth,
                           a.place_of_birth, a.age, a.civil_status, a.contact_number,
                           a.parent_full_name, a.parent_contact, a.parent_address,
                           a.admission_type,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.student_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getStudentDetails: ' . $e->getMessage());
            return null;
        }
    }

    public function getStudentWithEnrollmentCount($studentId) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.student_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getStudentWithEnrollmentCount: ' . $e->getMessage());
            return null;
        }
    }

    public function getStudentWithSchedule($studentId, $schoolYear = null) {
        try {
            if (!$schoolYear) {
                $schoolYear = date('Y') . '-' . (date('Y') + 1);
            }

            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           COUNT(DISTINCT e.schedule_id) as subject_count,
                           GROUP_CONCAT(DISTINCT
                               CONCAT(rs.code, ' (', cs.day_of_week, ' ', cs.start_time, '-', cs.end_time, ')')
                           SEPARATOR '; ') as subjects_summary
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    LEFT JOIN enr_enrollments e
                        ON s.student_id = e.student_id
                        AND e.enrollment_status = 'enrolled'
                    LEFT JOIN cc_schedule cs ON e.schedule_id = cs.id
                    LEFT JOIN rgr_subjects rs ON cs.subject_id = rs.id
                    WHERE s.student_id = ?
                    GROUP BY s.student_id";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getStudentWithSchedule: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       UNENROLLED / ENROLLED LISTS
    ============================================================ */

    public function getUnenrolledStudents($search = '') {
        try {
            $sql = "SELECT s.student_id, s.student_number, s.enrollment_status, s.status,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    WHERE s.enrollment_status != 'enrolled'
                       OR s.enrollment_status IS NULL
                       OR s.student_id NOT IN (
                            SELECT DISTINCT student_id
                            FROM enr_enrollments
                            WHERE enrollment_status = 'enrolled'
                       )";

            if (!empty($search)) {
                $sql .= " AND (a.first_name LIKE ? OR a.surname LIKE ? OR s.student_number LIKE ?)";
            }

            $sql .= " ORDER BY a.surname, a.first_name";

            $stmt = $this->connection->prepare($sql);
            if (!empty($search)) {
                $searchTerm = '%' . $search . '%';
                $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            } else {
                $stmt->execute();
            }
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getUnenrolledStudents: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentsWithEnrollmentStatus() {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsWithEnrollmentStatus: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentsWithSubjectEnrollments($schoolYear = null) {
        try {
            $sql = "SELECT DISTINCT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled'
                              AND e.school_year = ?) as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.student_id IN (
                        SELECT DISTINCT student_id
                        FROM enr_enrollments
                        WHERE enrollment_status = 'enrolled'
                    )
                    ORDER BY a.surname, a.first_name";

            $params = [$schoolYear ?: (date('Y') . '-' . (date('Y') + 1))];
            $stmt   = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsWithSubjectEnrollments: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       FILTERS
    ============================================================ */

    public function getStudentsByCourse($courseId) {
        try {
            $sql = "SELECT s.*, a.first_name, a.middle_name, a.surname, a.suffix
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    WHERE s.course_id = ? AND s.enrollment_status = 'enrolled'
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$courseId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsByCourse: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentsByYearLevel($yearLevel) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    WHERE s.year_level = ? AND s.enrollment_status = 'enrolled'
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$yearLevel]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsByYearLevel: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentsBySection($sectionId) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    WHERE s.section_id = ? AND s.enrollment_status = 'enrolled'
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$sectionId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsBySection: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentsByStatus($status) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    WHERE s.enrollment_status = ?
                    ORDER BY a.surname, a.first_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$status]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsByStatus: ' . $e->getMessage());
            return [];
        }
    }

    public function getStudentsNeedingFollowup() {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.followup_status = 'pending'
                      AND s.followup_date IS NOT NULL
                      AND s.followup_date <= CURDATE()
                    ORDER BY s.followup_date ASC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in getStudentsNeedingFollowup: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       SEARCH
    ============================================================ */

    public function searchStudents($keyword) {
        try {
            $sql = "SELECT s.*,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name,
                           sec.section_code, sec.grade_level,
                           sem.name AS semester,
                           sy.name  AS school_year,
                           (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                            WHERE e.student_id = s.student_id
                              AND e.enrollment_status = 'enrolled') as subject_count
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    LEFT JOIN rgr_semesters sem ON sec.semester_id = sem.id
                    LEFT JOIN rgr_school_years sy ON sec.school_year_id = sy.id
                    WHERE s.student_number LIKE ?
                       OR a.first_name LIKE ?
                       OR a.surname LIKE ?
                       OR a.email LIKE ?
                       OR a.contact_number LIKE ?
                    ORDER BY a.surname, a.first_name";
            $stmt   = $this->connection->prepare($sql);
            $search = '%' . $keyword . '%';
            $stmt->execute([$search, $search, $search, $search, $search]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error in searchStudents: ' . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       STATS
    ============================================================ */

    public function getStudentStats() {
        try {
            $sql = "SELECT
                        COUNT(*) as total_students,
                        SUM(CASE WHEN enrollment_status = 'enrolled'  THEN 1 ELSE 0 END) as enrolled,
                        SUM(CASE WHEN enrollment_status = 'on_leave'  THEN 1 ELSE 0 END) as on_leave,
                        SUM(CASE WHEN enrollment_status = 'graduated' THEN 1 ELSE 0 END) as graduated,
                        SUM(CASE WHEN enrollment_status = 'dropped'   THEN 1 ELSE 0 END) as dropped,
                        AVG(year_level) as avg_year_level,
                        (SELECT COUNT(DISTINCT student_id) FROM enr_enrollments
                         WHERE enrollment_status = 'enrolled') as with_subject_enrollments
                    FROM enr_students";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error in getStudentStats: ' . $e->getMessage());
            return [
                'total_students'           => 0,
                'enrolled'                 => 0,
                'on_leave'                 => 0,
                'graduated'                => 0,
                'dropped'                  => 0,
                'avg_year_level'           => 0,
                'with_subject_enrollments' => 0
            ];
        }
    }

    /* ============================================================
       UPDATES
    ============================================================ */

    public function updateEnrollmentStatus($studentId, $status) {
        try {
            $validStatuses = ['enrolled', 'on_leave', 'graduated', 'dropped'];
            if (!in_array($status, $validStatuses, true)) {
                return false;
            }

            $sql  = "UPDATE enr_students SET enrollment_status = ? WHERE student_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$status, $studentId]);
        } catch (Exception $e) {
            error_log('Error in updateEnrollmentStatus: ' . $e->getMessage());
            return false;
        }
    }

    public function updateSection($studentId, $sectionId) {
        try {
            $sql  = "UPDATE enr_students SET section_id = ? WHERE student_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$sectionId, $studentId]);
        } catch (Exception $e) {
            error_log('Error in updateSection: ' . $e->getMessage());
            return false;
        }
    }

    public function updateFollowup($studentId, $date, $notes = null) {
        try {
            $sql = "UPDATE enr_students
                    SET followup_date = ?,
                        followup_notes = ?,
                        followup_status = 'pending'
                    WHERE student_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$date, $notes, $studentId]);
        } catch (Exception $e) {
            error_log('Error in updateFollowup: ' . $e->getMessage());
            return false;
        }
    }

    public function markFollowupDone($studentId) {
        try {
            $sql  = "UPDATE enr_students SET followup_status = 'done' WHERE student_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$studentId]);
        } catch (Exception $e) {
            error_log('Error in markFollowupDone: ' . $e->getMessage());
            return false;
        }
    }
}