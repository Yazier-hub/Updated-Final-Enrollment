<?php
// classes/Application.php - CONFIRMED COMPATIBLE WITH `kms` SCHEMA
require_once 'Model.php';
require_once 'User.php';
require_once 'EmailSender.php';

class Application extends Model {
    protected $table = 'enr_applicants';
    protected $primaryKey = 'applicant_id';
    protected $fillable = [
        'surname', 'first_name', 'middle_name', 'suffix',
        'admission_type', 'working_student',
        'sex', 'address_barangay', 'address_city', 'address_province', 'address_complete',
        'school_last_attended', 'year_graduated', 'how_hear',
        'email', 'date_of_birth', 'place_of_birth', 'age',
        'civil_status', 'religion', 'contact_number',
        'facebook', 'messenger', 'address',
        'parent_full_name', 'parent_contact', 'parent_address',
        'course_id', 'preferred_section_id',
        'status', 'notes'
    ];

    public function __construct() {
        parent::__construct();
    }

    public function submitApplication($data) {
        unset($data['action'], $data['submit']);
        $data['submitted_at'] = date('Y-m-d H:i:s');
        $data['status'] = 'pending';
        if (empty($data['admission_type'])) {
            $data['admission_type'] = 'freshmen';
        }
        return $this->create($data);
    }

    public function getPendingApplications() {
        $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                FROM enr_applicants a
                LEFT JOIN rgr_courses c ON a.course_id = c.id
                WHERE a.status = 'pending'
                ORDER BY a.submitted_at DESC";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllApplications() {
        $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                FROM enr_applicants a
                LEFT JOIN rgr_courses c ON a.course_id = c.id
                ORDER BY a.submitted_at DESC";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getApplicationById($id) {
        $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                FROM enr_applicants a
                LEFT JOIN rgr_courses c ON a.course_id = c.id
                WHERE a.applicant_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getApplicationWithStudent($applicationId) {
        try {
            $sql = "SELECT a.*,
                           s.student_id, s.student_number, s.enrollment_status as student_status,
                           c.code as course_code, c.name as course_name
                    FROM enr_applicants a
                    LEFT JOIN enr_students s ON a.applicant_id = s.applicant_id
                    LEFT JOIN rgr_courses c ON a.course_id = c.id
                    WHERE a.applicant_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$applicationId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting application with student: ' . $e->getMessage());
            return null;
        }
    }

    public function getNonConvertedApplications() {
        try {
            $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                    FROM enr_applicants a
                    LEFT JOIN rgr_courses c ON a.course_id = c.id
                    WHERE a.status != 'converted'
                    ORDER BY a.submitted_at DESC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting non-converted applications: ' . $e->getMessage());
            return [];
        }
    }

    public function updateStatus($applicationId, $status) {
        try {
            $sql = "UPDATE enr_applicants SET status = ? WHERE applicant_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$status, $applicationId]);
        } catch (Exception $e) {
            error_log('Error updating application status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert applicant to student and enroll in subjects via schedule_ids.
     * ONE schedule = ONE enrollment record in enr_enrollments.
     */
    public function convertToStudentAndEnroll($applicantId, $sectionId, $schoolYear, $scheduleIds = []) {
        $applicant = $this->getApplicationById($applicantId);

        if (!$applicant) {
            return ['success' => false, 'message' => 'Applicant not found.'];
        }
        if (empty($applicant['course_id'])) {
            return ['success' => false, 'message' => 'Applicant has no course assigned.'];
        }
        if (empty($applicant['email']) || !filter_var($applicant['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Applicant has an invalid email address: ' . $applicant['email']];
        }

        if (!is_array($scheduleIds)) $scheduleIds = [];
        $scheduleIds = array_values(array_unique(array_filter(
            array_map('intval', $scheduleIds),
            fn($id) => $id > 0
        )));

        $transactionStarted = false;
        if (!$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
            $transactionStarted = true;
        } else {
            $this->connection->exec('SAVEPOINT convert_to_student');
        }

        try {
            require_once __DIR__ . '/Student.php';
            $studentModel = new Student();

            require_once __DIR__ . '/Section.php';
            $sectionModel = new Section();
            $section = $sectionModel->findById($sectionId);

            $yearLevel = 1;
            if ($section && isset($section['grade_level'])) {
                $yearLevel = $this->convertGradeLevelToNumeric($section['grade_level']);
            }

            $studentId = $studentModel->createFromApplicant(
                $applicantId, $applicant['course_id'], $sectionId, $yearLevel
            );
            if (!$studentId || $studentId <= 0) {
                throw new Exception('Failed to create student record.');
            }

            require_once __DIR__ . '/Requirement.php';
            $requirement = new Requirement();
            $requirement->initializeStudentRequirements($studentId, $applicantId);

            $sql = "UPDATE enr_applicants SET status = 'converted' WHERE applicant_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$applicantId]);

            require_once __DIR__ . '/Enrollment.php';
            $enrollmentModel = new Enrollment();

            $enrollmentResult = $enrollmentModel->enrollStudentWithSubjects(
                $studentId, $sectionId, $schoolYear, $scheduleIds
            );
            if (!isset($enrollmentResult['success']) || !$enrollmentResult['success']) {
                throw new Exception($enrollmentResult['message'] ?? 'Failed to enroll student subjects.');
            }

            $studentData = $studentModel->findById($studentId);
            if (!$studentData) {
                throw new Exception('Student record could not be retrieved.');
            }

            $nameParts = [];
            if (!empty($applicant['first_name']))  $nameParts[] = $applicant['first_name'];
            if (!empty($applicant['middle_name'])) $nameParts[] = $applicant['middle_name'];
            if (!empty($applicant['surname']))     $nameParts[] = $applicant['surname'];
            $fullName = implode(' ', $nameParts);
            if (!empty($applicant['suffix'])) $fullName .= ' ' . $applicant['suffix'];

            $user = new User();
            $accountResult = $user->createStudentAccount(
                $studentId, $studentData['student_number'], $applicant['email'], $fullName
            );
            if (!isset($accountResult['success']) || !$accountResult['success']) {
                throw new Exception($accountResult['message'] ?? 'Failed to create user account.');
            }

            $accountCreated = $accountResult['is_new']  ?? false;
            $username       = $accountResult['username'] ?? $studentData['student_number'];
            $password       = $accountResult['password'] ?? null;
            $userId         = $accountResult['user_id']  ?? null;

            if ($transactionStarted) {
                if ($this->connection->inTransaction()) $this->connection->commit();
            } else {
                try { $this->connection->exec('RELEASE SAVEPOINT convert_to_student'); }
                catch (Exception $e) { error_log('Savepoint release error: ' . $e->getMessage()); }
            }

            $emailSent  = false;
            $emailError = null;

            if ($accountCreated && !empty($password)) {
                try {
                    $emailSender = new EmailSender();
                    $emailResult = $emailSender->sendAccountEmail(
                        $fullName, $studentData['student_number'], $password, $applicant['email']
                    );
                    $emailSent = $emailResult['success'] ?? false;
                    if (!$emailSent) $emailError = $emailResult['message'] ?? 'Unknown email error.';
                } catch (Exception $e) {
                    $emailSent  = false;
                    $emailError = $e->getMessage();
                }
            } else {
                if (!$accountCreated) {
                    $emailError = 'Email not sent because the student account already exists.';
                } elseif (empty($password)) {
                    $emailError = 'Email not sent because no password was generated.';
                }
            }

            return [
                'success'          => true,
                'message'          => $emailSent
                    ? 'Student enrolled successfully and account credentials were sent by email.'
                    : 'Student enrolled successfully, but the account email was not sent.',
                'student_id'       => $studentId,
                'student_number'   => $studentData['student_number'],
                'enrollment_count' => $enrollmentResult['enrolled_count'] ?? 0,
                'enrollment_ids'   => $enrollmentResult['enrollment_ids'] ?? [],
                'account_created'  => $accountCreated,
                'user_id'          => $userId,
                'username'         => $username,
                'password'         => $password,
                'is_new_account'   => $accountCreated,
                'email_sent'       => $emailSent,
                'email_error'      => $emailError
            ];

        } catch (Exception $e) {
            if ($transactionStarted) {
                if ($this->connection->inTransaction()) $this->connection->rollBack();
            } else {
                try { $this->connection->exec('ROLLBACK TO SAVEPOINT convert_to_student'); }
                catch (Exception $e2) { error_log('Savepoint rollback error: ' . $e2->getMessage()); }
            }

            error_log('ERROR in convertToStudentAndEnroll: ' . $e->getMessage());

            return [
                'success'          => false,
                'message'          => 'Enrollment failed: ' . $e->getMessage(),
                'student_id'       => null,
                'student_number'   => null,
                'enrollment_count' => 0,
                'enrollment_ids'   => [],
                'account_created'  => false,
                'user_id'          => null,
                'username'         => null,
                'password'         => null,
                'is_new_account'   => false,
                'email_sent'       => false,
                'email_error'      => null
            ];
        }
    }

    public function convertToStudentAndEnrollWithSubjects($applicantId, $sectionId, $schoolYear, $scheduleIds = []) {
        return $this->convertToStudentAndEnroll($applicantId, $sectionId, $schoolYear, $scheduleIds);
    }

    private function convertGradeLevelToNumeric($gradeLevel) {
        $map = ['1st Year' => 1, '2nd Year' => 2, '3rd Year' => 3, '4th Year' => 4];
        return $map[$gradeLevel] ?? 1;
    }

    public function getApplicationStats() {
        $sql = "SELECT status, COUNT(*) as count FROM enr_applicants GROUP BY status";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteOldApplications() {
        $sql = "DELETE FROM enr_applicants
                WHERE submitted_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)
                  AND status != 'converted'";
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute();
    }

    public function searchPendingApplications($keyword) {
        $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                FROM enr_applicants a
                LEFT JOIN rgr_courses c ON a.course_id = c.id
                WHERE a.status = 'pending'
                  AND (a.first_name LIKE ? OR a.surname LIKE ?
                       OR a.email LIKE ? OR a.contact_number LIKE ?)
                ORDER BY a.submitted_at DESC";
        $stmt = $this->connection->prepare($sql);
        $search = '%' . $keyword . '%';
        $stmt->execute([$search, $search, $search, $search]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchApplications($keyword) {
        $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                FROM enr_applicants a
                LEFT JOIN rgr_courses c ON a.course_id = c.id
                WHERE a.first_name LIKE ? OR a.surname LIKE ?
                   OR a.email LIKE ? OR a.contact_number LIKE ?
                ORDER BY a.submitted_at DESC";
        $stmt = $this->connection->prepare($sql);
        $search = '%' . $keyword . '%';
        $stmt->execute([$search, $search, $search, $search]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getApplicationsByStatus($status) {
        try {
            $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                    FROM enr_applicants a
                    LEFT JOIN rgr_courses c ON a.course_id = c.id
                    WHERE a.status = ?
                    ORDER BY a.submitted_at DESC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$status]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting applications by status: ' . $e->getMessage());
            return [];
        }
    }

    public function countApplicationsByStatus($status) {
        try {
            $sql = "SELECT COUNT(*) as count FROM enr_applicants WHERE status = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$status]);
            $result = $stmt->fetch();
            return (int) $result['count'];
        } catch (Exception $e) {
            error_log('Error counting applications by status: ' . $e->getMessage());
            return 0;
        }
    }

    public function getRecentApplications($limit = 5) {
        try {
            $sql = "SELECT a.*, c.code as course_code, c.name as course_name
                    FROM enr_applicants a
                    LEFT JOIN rgr_courses c ON a.course_id = c.id
                    ORDER BY a.submitted_at DESC
                    LIMIT ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting recent applications: ' . $e->getMessage());
            return [];
        }
    }

    public function getApplicationsByMonth($year = null) {
        try {
            if (!$year) $year = date('Y');
            $sql = "SELECT MONTH(submitted_at) as month, COUNT(*) as count,
                           SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) as pending,
                           SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted,
                           SUM(CASE WHEN status = 'rejected'  THEN 1 ELSE 0 END) as rejected
                    FROM enr_applicants
                    WHERE YEAR(submitted_at) = ?
                    GROUP BY MONTH(submitted_at)
                    ORDER BY month";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$year]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting applications by month: ' . $e->getMessage());
            return [];
        }
    }

    public function getApplicantByStudentNumber($studentNumber) {
        try {
            $sql = "SELECT a.*, s.student_number
                    FROM enr_applicants a
                    INNER JOIN enr_students s ON a.applicant_id = s.applicant_id
                    WHERE s.student_number = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentNumber]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting applicant by student number: ' . $e->getMessage());
            return null;
        }
    }

    public function getTotalApplications() {
        try {
            $sql = "SELECT COUNT(*) as total FROM enr_applicants";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            return (int) $result['total'];
        } catch (Exception $e) {
            error_log('Error getting total applications: ' . $e->getMessage());
            return 0;
        }
    }

    public function getStats() {
        try {
            $sql = "SELECT COUNT(*) as total,
                           SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) as pending,
                           SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted,
                           SUM(CASE WHEN status = 'rejected'  THEN 1 ELSE 0 END) as rejected
                    FROM enr_applicants";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error in getStats: ' . $e->getMessage());
            return ['total' => 0, 'pending' => 0, 'converted' => 0, 'rejected' => 0];
        }
    }
}