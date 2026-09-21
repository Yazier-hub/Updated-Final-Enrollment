<?php
// classes/Requirement.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • getStudentsWithIncompleteRequirements(): rewrote IN(subquery) as EXISTS
//     so the outer `a.admission_type` alias is visible (MariaDB 10.4 compatible)

require_once 'Model.php';

class Requirement extends Model {
    protected $table      = 'enr_requirements';
    protected $primaryKey = 'requirement_id';
    protected $fillable   = ['requirement_name', 'requirement_category', 'is_mandatory'];

    private static $admissionTypeCache = [];

    public function __construct() {
        parent::__construct();
    }

    /* ============================================================
       CATEGORY / ADMISSION HELPERS
    ============================================================ */

    public function getRequirementsByCategory($category) {
        try {
            $sql  = "SELECT * FROM enr_requirements
                     WHERE requirement_category = ?
                     ORDER BY requirement_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$category]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getRequirementsByCategory: " . $e->getMessage());
            return [];
        }
    }

    public function getAllCategories() {
        try {
            $sql  = "SELECT DISTINCT requirement_category FROM enr_requirements
                     ORDER BY requirement_category";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetchAll();

            if (empty($result)) {
                return [
                    ['requirement_category' => 'freshmen'],
                    ['requirement_category' => 'transferee'],
                    ['requirement_category' => 'continuing']
                ];
            }
            return $result;
        } catch (Exception $e) {
            error_log("Error in getAllCategories: " . $e->getMessage());
            return [
                ['requirement_category' => 'freshmen'],
                ['requirement_category' => 'transferee'],
                ['requirement_category' => 'continuing']
            ];
        }
    }

    private function getStudentAdmissionType($studentId) {
        try {
            if (isset(self::$admissionTypeCache[$studentId])) {
                return self::$admissionTypeCache[$studentId];
            }

            $sql = "SELECT a.admission_type
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    WHERE s.student_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId]);
            $result = $stmt->fetch();

            $admissionType = $result ? $result['admission_type'] : 'freshmen';

            if ($admissionType === 'returnee') {
                $admissionType = 'continuing';
            }

            self::$admissionTypeCache[$studentId] = $admissionType;
            return $admissionType;
        } catch (Exception $e) {
            error_log("Error in getStudentAdmissionType: " . $e->getMessage());
            return 'freshmen';
        }
    }

    public function clearAdmissionTypeCache() {
        self::$admissionTypeCache = [];
    }

    /* ============================================================
       STUDENT REQUIREMENTS
    ============================================================ */

    public function getStudentRequirements($studentId) {
        try {
            $admissionType = $this->getStudentAdmissionType($studentId);

            $sql = "SELECT sr.*, r.requirement_name, r.requirement_category, r.is_mandatory
                    FROM enr_student_requirements sr
                    JOIN enr_requirements r ON sr.requirement_id = r.requirement_id
                    WHERE sr.student_id = ?
                      AND r.requirement_category = ?
                    ORDER BY r.requirement_category, r.requirement_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $admissionType]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getStudentRequirements: " . $e->getMessage());
            return [];
        }
    }

    public function getStudentRequirementsByCategory($studentId, $category) {
        try {
            $sql = "SELECT sr.*, r.requirement_name, r.requirement_category, r.is_mandatory
                    FROM enr_student_requirements sr
                    JOIN enr_requirements r ON sr.requirement_id = r.requirement_id
                    WHERE sr.student_id = ?
                      AND r.requirement_category = ?
                    ORDER BY r.requirement_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $category]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getStudentRequirementsByCategory: " . $e->getMessage());
            return [];
        }
    }

    public function getStudentRequirementsWithStatus($studentId) {
        try {
            $admissionType   = $this->getStudentAdmissionType($studentId);
            $categoryToCheck = $admissionType === 'continuing' ? 'continuing' : $admissionType;

            $sql = "SELECT
                        r.requirement_id,
                        r.requirement_name,
                        r.requirement_category,
                        r.is_mandatory,
                        COALESCE(sr.is_submitted, 0) as is_submitted,
                        sr.submitted_date,
                        sr.notes,
                        sr.created_at,
                        sr.updated_at,
                        CASE
                            WHEN COALESCE(sr.is_submitted, 0) = 1 THEN '✅ Submitted'
                            WHEN sr.notes IS NOT NULL AND COALESCE(sr.is_submitted, 0) = 0 THEN '⏳ In Progress'
                            ELSE '⏰ Pending'
                        END as status_label,
                        CASE
                            WHEN COALESCE(sr.is_submitted, 0) = 1 THEN 'success'
                            WHEN sr.notes IS NOT NULL AND COALESCE(sr.is_submitted, 0) = 0 THEN 'warning'
                            ELSE 'danger'
                        END as status_class
                    FROM enr_requirements r
                    LEFT JOIN enr_student_requirements sr
                        ON r.requirement_id = sr.requirement_id
                        AND sr.student_id = ?
                    WHERE r.requirement_category = ?
                    ORDER BY r.is_mandatory DESC, r.requirement_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $categoryToCheck]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getStudentRequirementsWithStatus: " . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       INITIALIZE / UPDATE
    ============================================================ */

    public function initializeStudentRequirements($studentId, $applicantId = null) {
        try {
            $category = 'freshmen';

            if ($applicantId) {
                $sql  = "SELECT admission_type FROM enr_applicants WHERE applicant_id = ?";
                $stmt = $this->connection->prepare($sql);
                $stmt->execute([$applicantId]);
                $applicant = $stmt->fetch();
                if ($applicant && !empty($applicant['admission_type'])) {
                    $category = $applicant['admission_type'];
                    if ($category === 'returnee') {
                        $category = 'continuing';
                    }
                }
            } else {
                $category = $this->getStudentAdmissionType($studentId);
            }

            $sql  = "SELECT * FROM enr_requirements WHERE requirement_category = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$category]);
            $requirements = $stmt->fetchAll();

            if (empty($requirements)) {
                error_log("No requirements found for category: " . $category);
                return 0;
            }

            $inserted = 0;
            foreach ($requirements as $req) {
                $checkSql  = "SELECT 1 FROM enr_student_requirements
                              WHERE student_id = ? AND requirement_id = ?";
                $checkStmt = $this->connection->prepare($checkSql);
                $checkStmt->execute([$studentId, $req['requirement_id']]);
                if (!$checkStmt->fetch()) {
                    $insertSql  = "INSERT INTO enr_student_requirements
                                   (student_id, requirement_id, is_submitted)
                                   VALUES (?, ?, 0)";
                    $insertStmt = $this->connection->prepare($insertSql);
                    $insertStmt->execute([$studentId, $req['requirement_id']]);
                    $inserted++;
                }
            }
            return $inserted;
        } catch (Exception $e) {
            error_log("Error in initializeStudentRequirements: " . $e->getMessage());
            return 0;
        }
    }

    public function updateRequirementStatus($studentId, $requirementId, $isSubmitted, $notes = null) {
        try {
            $checkSql  = "SELECT 1 FROM enr_student_requirements
                          WHERE student_id = ? AND requirement_id = ?";
            $checkStmt = $this->connection->prepare($checkSql);
            $checkStmt->execute([$studentId, $requirementId]);
            $exists = $checkStmt->fetch();

            if ($exists) {
                $sql = "UPDATE enr_student_requirements
                        SET is_submitted = ?,
                            submitted_date = ?,
                            notes = ?,
                            updated_at = NOW()
                        WHERE student_id = ? AND requirement_id = ?";
                $stmt = $this->connection->prepare($sql);
                return $stmt->execute([
                    $isSubmitted ? 1 : 0,
                    $isSubmitted ? date('Y-m-d') : null,
                    $notes,
                    $studentId,
                    $requirementId
                ]);
            }

            $sql  = "INSERT INTO enr_student_requirements
                     (student_id, requirement_id, is_submitted, submitted_date, notes)
                     VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([
                $studentId,
                $requirementId,
                $isSubmitted ? 1 : 0,
                $isSubmitted ? date('Y-m-d') : null,
                $notes
            ]);
        } catch (Exception $e) {
            error_log("Error in updateRequirementStatus: " . $e->getMessage());
            return false;
        }
    }

    public function createRequirementStatus($studentId, $requirementId, $isSubmitted = 0, $notes = null) {
        try {
            $sql  = "INSERT INTO enr_student_requirements
                     (student_id, requirement_id, is_submitted, notes, submitted_date)
                     VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([
                $studentId,
                $requirementId,
                $isSubmitted ? 1 : 0,
                $notes,
                $isSubmitted ? date('Y-m-d') : null
            ]);
        } catch (Exception $e) {
            error_log("Error in createRequirementStatus: " . $e->getMessage());
            return false;
        }
    }

    public function updateOrCreateRequirementStatus($studentId, $requirementId, $isSubmitted = 0, $notes = null) {
        try {
            $existing = $this->getStudentRequirementStatus($studentId, $requirementId);

            if ($existing) {
                $sql = "UPDATE enr_student_requirements
                        SET is_submitted = ?,
                            notes = ?,
                            submitted_date = ?,
                            updated_at = NOW()
                        WHERE student_id = ? AND requirement_id = ?";
                $stmt = $this->connection->prepare($sql);
                return $stmt->execute([
                    $isSubmitted ? 1 : 0,
                    $notes,
                    $isSubmitted ? date('Y-m-d') : null,
                    $studentId,
                    $requirementId
                ]);
            }
            return $this->createRequirementStatus($studentId, $requirementId, $isSubmitted, $notes);
        } catch (Exception $e) {
            error_log("Error in updateOrCreateRequirementStatus: " . $e->getMessage());
            return false;
        }
    }

    public function getStudentRequirementStatus($studentId, $requirementId) {
        try {
            $sql  = "SELECT * FROM enr_student_requirements
                     WHERE student_id = ? AND requirement_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $requirementId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error in getStudentRequirementStatus: " . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       COMPLETION / STATS
    ============================================================ */

    public function getRequirementCompletionStatus($studentId) {
        try {
            $admissionType = $this->getStudentAdmissionType($studentId);

            $sql = "SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN COALESCE(sr.is_submitted, 0) = 1 THEN 1 ELSE 0 END) as submitted
                    FROM enr_requirements r
                    LEFT JOIN enr_student_requirements sr
                        ON r.requirement_id = sr.requirement_id
                        AND sr.student_id = ?
                    WHERE r.requirement_category = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $admissionType]);
            $result = $stmt->fetch();

            $total     = (int) ($result['total']     ?? 0);
            $submitted = (int) ($result['submitted'] ?? 0);

            return [
                'total'       => $total,
                'submitted'   => $submitted,
                'percentage'  => $total > 0 ? round(($submitted / $total) * 100) : 0,
                'remaining'   => $total - $submitted,
                'is_complete' => $total > 0 && $submitted === $total
            ];
        } catch (Exception $e) {
            error_log("Error in getRequirementCompletionStatus: " . $e->getMessage());
            return ['total' => 0, 'submitted' => 0, 'percentage' => 0, 'remaining' => 0, 'is_complete' => false];
        }
    }

    public function hasCompletedMandatoryRequirements($studentId) {
        try {
            $admissionType = $this->getStudentAdmissionType($studentId);

            $sql = "SELECT
                        COUNT(*) as mandatory_total,
                        SUM(CASE WHEN COALESCE(sr.is_submitted, 0) = 1 THEN 1 ELSE 0 END) as mandatory_submitted
                    FROM enr_requirements r
                    LEFT JOIN enr_student_requirements sr
                        ON r.requirement_id = sr.requirement_id
                        AND sr.student_id = ?
                    WHERE r.is_mandatory = 1
                      AND r.requirement_category = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $admissionType]);
            $result = $stmt->fetch();

            $mandatoryTotal     = (int) ($result['mandatory_total']     ?? 0);
            $mandatorySubmitted = (int) ($result['mandatory_submitted'] ?? 0);

            return $mandatoryTotal > 0 && $mandatorySubmitted === $mandatoryTotal;
        } catch (Exception $e) {
            error_log("Error in hasCompletedMandatoryRequirements: " . $e->getMessage());
            return false;
        }
    }

    public function getStudentProgressByCategory($studentId) {
        try {
            $admissionType = $this->getStudentAdmissionType($studentId);

            $sql = "SELECT
                        r.requirement_category,
                        COUNT(*) as total,
                        SUM(CASE WHEN COALESCE(sr.is_submitted, 0) = 1 THEN 1 ELSE 0 END) as submitted,
                        SUM(CASE WHEN r.is_mandatory = 1 AND COALESCE(sr.is_submitted, 0) = 1 THEN 1 ELSE 0 END) as mandatory_submitted,
                        SUM(CASE WHEN r.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_total
                    FROM enr_requirements r
                    LEFT JOIN enr_student_requirements sr
                        ON r.requirement_id = sr.requirement_id
                        AND sr.student_id = ?
                    WHERE r.requirement_category = ?
                    GROUP BY r.requirement_category";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $admissionType]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error in getStudentProgressByCategory: " . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       CRUD
    ============================================================ */

    public function addRequirement($data) {
        try {
            if (empty($data['requirement_name']) || empty($data['requirement_category'])) {
                return ['success' => false, 'message' => 'Requirement name and category are required'];
            }

            $sql  = "SELECT 1 FROM enr_requirements
                     WHERE requirement_name = ? AND requirement_category = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([trim($data['requirement_name']), $data['requirement_category']]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Requirement already exists for this category'];
            }

            $insertData = [
                'requirement_name'     => trim($data['requirement_name']),
                'requirement_category' => $data['requirement_category'],
                'is_mandatory'         => isset($data['is_mandatory']) ? (int) $data['is_mandatory'] : 1
            ];

            $result = $this->create($insertData);
            if ($result) {
                $newId = is_array($result)
                    ? ($result[$this->primaryKey] ?? null)
                    : $result;
                return ['success' => true, 'message' => 'Requirement added successfully', 'id' => $newId];
            }
            return ['success' => false, 'message' => 'Failed to add requirement'];
        } catch (Exception $e) {
            error_log("Error in addRequirement: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function updateRequirement($requirementId, $data) {
        try {
            if (empty($requirementId)) {
                return ['success' => false, 'message' => 'Requirement ID is required'];
            }

            $updateData = [];
            if (isset($data['requirement_name'])) {
                $updateData['requirement_name'] = trim($data['requirement_name']);
            }
            if (isset($data['requirement_category'])) {
                $updateData['requirement_category'] = $data['requirement_category'];
            }
            if (isset($data['is_mandatory'])) {
                $updateData['is_mandatory'] = (int) $data['is_mandatory'];
            }

            if (empty($updateData)) {
                return ['success' => false, 'message' => 'No data to update'];
            }

            if (!$this->findById($requirementId)) {
                return ['success' => false, 'message' => 'Requirement not found'];
            }

            $result = $this->update($requirementId, $updateData);
            if ($result) {
                return ['success' => true, 'message' => 'Requirement updated successfully'];
            }
            return ['success' => false, 'message' => 'Failed to update requirement'];
        } catch (Exception $e) {
            error_log("Error in updateRequirement: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function deleteRequirement($requirementId) {
        try {
            if (empty($requirementId)) {
                return ['success' => false, 'message' => 'Requirement ID is required'];
            }

            if (!$this->findById($requirementId)) {
                return ['success' => false, 'message' => 'Requirement not found'];
            }

            $checkSql  = "SELECT COUNT(*) as count FROM enr_student_requirements
                          WHERE requirement_id = ?";
            $checkStmt = $this->connection->prepare($checkSql);
            $checkStmt->execute([$requirementId]);
            $result = $checkStmt->fetch();

            if ((int) ($result['count'] ?? 0) > 0) {
                $deleteSrSql  = "DELETE FROM enr_student_requirements WHERE requirement_id = ?";
                $deleteSrStmt = $this->connection->prepare($deleteSrSql);
                $deleteSrStmt->execute([$requirementId]);
            }

            $deleted = $this->delete($requirementId);
            if ($deleted) {
                return ['success' => true, 'message' => 'Requirement deleted successfully'];
            }
            return ['success' => false, 'message' => 'Failed to delete requirement'];
        } catch (Exception $e) {
            error_log("Error in deleteRequirement: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function getAllRequirements($category = null) {
        try {
            if ($category) {
                return $this->getRequirementsByCategory($category);
            }

            $sql  = "SELECT * FROM enr_requirements ORDER BY requirement_category, requirement_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getAllRequirements: " . $e->getMessage());
            return [];
        }
    }

    public function getRequirementById($requirementId) {
        try {
            return $this->findById($requirementId);
        } catch (Exception $e) {
            error_log("Error in getRequirementById: " . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       GROUPING / ADMISSION MAPPING
    ============================================================ */

    public function getRequirementsGroupedByCategory() {
        try {
            $sql  = "SELECT * FROM enr_requirements ORDER BY requirement_category, requirement_name";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll();

            $grouped = [];
            foreach ($results as $req) {
                $grouped[$req['requirement_category']][] = $req;
            }
            return $grouped;
        } catch (Exception $e) {
            error_log("Error in getRequirementsGroupedByCategory: " . $e->getMessage());
            return [];
        }
    }

    public function getRequirementCountByCategory() {
        try {
            $sql = "SELECT
                        requirement_category,
                        COUNT(*) as count,
                        SUM(is_mandatory) as mandatory_count
                    FROM enr_requirements
                    GROUP BY requirement_category";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getRequirementCountByCategory: " . $e->getMessage());
            return [];
        }
    }

    public function getRequirementsByAdmissionType($admissionType) {
        $categoryMap = [
            'freshmen'    => 'freshmen',
            'transferee'  => 'transferee',
            'returnee'    => 'continuing',
            'senior_high' => 'freshmen'
        ];

        $category = $categoryMap[$admissionType] ?? 'freshmen';
        return $this->getRequirementsByCategory($category);
    }

    public function requirementExistsForCategory($requirementName, $category) {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_requirements
                     WHERE requirement_name = ? AND requirement_category = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([trim($requirementName), $category]);
            $result = $stmt->fetch();
            return ((int) ($result['count'] ?? 0)) > 0;
        } catch (Exception $e) {
            error_log("Error in requirementExistsForCategory: " . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       SUMMARY HELPERS
    ============================================================ */

    public function getStudentRequirementsSummary($studentId) {
        $completion         = $this->getRequirementCompletionStatus($studentId);
        $mandatoryCompleted = $this->hasCompletedMandatoryRequirements($studentId);

        return [
            'total_requirements'  => $completion['total'],
            'completed'           => $completion['submitted'],
            'pending'             => $completion['remaining'],
            'percentage'          => $completion['percentage'],
            'is_complete'         => $completion['is_complete'],
            'mandatory_completed' => $mandatoryCompleted
        ];
    }

    public function getRequirementsWithStudentStatus($studentId, $category = null) {
        try {
            $admissionType   = $this->getStudentAdmissionType($studentId);
            $categoryToCheck = $admissionType === 'continuing' ? 'continuing' : $admissionType;

            $sql = "SELECT
                        r.*,
                        COALESCE(sr.is_submitted, 0) as is_submitted,
                        sr.submitted_date,
                        sr.notes,
                        sr.updated_at as student_updated_at,
                        CASE
                            WHEN COALESCE(sr.is_submitted, 0) = 1 THEN '✅ Submitted'
                            WHEN sr.notes IS NOT NULL AND COALESCE(sr.is_submitted, 0) = 0 THEN '⏳ In Progress'
                            ELSE '⏰ Pending'
                        END as status_display,
                        CASE
                            WHEN COALESCE(sr.is_submitted, 0) = 1 THEN 'success'
                            WHEN sr.notes IS NOT NULL AND COALESCE(sr.is_submitted, 0) = 0 THEN 'warning'
                            ELSE 'danger'
                        END as status_class
                    FROM enr_requirements r
                    LEFT JOIN enr_student_requirements sr
                        ON r.requirement_id = sr.requirement_id
                        AND sr.student_id = ?
                    WHERE r.requirement_category = ?
                    ORDER BY r.is_mandatory DESC, r.requirement_name";

            $params = [$studentId, $category ?: $categoryToCheck];
            $stmt   = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getRequirementsWithStudentStatus: " . $e->getMessage());
            return [];
        }
    }

    public function countRequirementsByCategory() {
        try {
            $sql = "SELECT
                        requirement_category,
                        COUNT(*) as total,
                        SUM(is_mandatory) as mandatory
                    FROM enr_requirements
                    GROUP BY requirement_category";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in countRequirementsByCategory: " . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       INCOMPLETE-STUDENT LOOKUP
       FIX: replaced IN(subquery) with EXISTS so the outer
            `a.admission_type` alias is visible on MariaDB 10.4
    ============================================================ */

    public function getStudentsWithIncompleteRequirements($admissionType = null) {
        try {
            $sql = "SELECT DISTINCT
                        s.student_id,
                        s.student_number,
                        a.first_name, a.middle_name, a.surname, a.suffix,
                        c.code as course_code, c.name as course_name,
                        sec.section_code, sec.grade_level,
                        a.admission_type,
                        (SELECT COUNT(DISTINCT e.schedule_id) FROM enr_enrollments e
                         WHERE e.student_id = s.student_id
                           AND e.enrollment_status = 'enrolled') as enrolled_subjects
                    FROM enr_students s
                    JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    LEFT JOIN cc_sections sec ON s.section_id = sec.id
                    WHERE s.enrollment_status = 'enrolled'";

            $params = [];

            if ($admissionType) {
                $sql      .= " AND a.admission_type = ?";
                $params[] = $admissionType;
            }

            $sql .= " AND EXISTS (
                        SELECT 1
                        FROM enr_student_requirements sr
                        JOIN enr_requirements r ON sr.requirement_id = r.requirement_id
                        WHERE sr.student_id = s.student_id
                          AND r.is_mandatory = 1
                          AND sr.is_submitted = 0
                          AND r.requirement_category = CASE
                                WHEN a.admission_type = 'returnee'    THEN 'continuing'
                                WHEN a.admission_type = 'senior_high' THEN 'freshmen'
                                ELSE a.admission_type
                              END
                      )
                      ORDER BY a.surname, a.first_name";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error in getStudentsWithIncompleteRequirements: " . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
       ENROLLMENT SUMMARY
    ============================================================ */

    public function getStudentEnrolledSubjectsSummary($studentId) {
        try {
            $schoolYear = date('Y') . '-' . (date('Y') + 1);

            $sql = "SELECT
                        COUNT(DISTINCT e.schedule_id) as total_subjects,
                        SUM(CASE WHEN e.enrollment_status = 'enrolled'  THEN 1 ELSE 0 END) as enrolled_count,
                        SUM(CASE WHEN e.enrollment_status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                        SUM(CASE WHEN e.enrollment_status = 'dropped'   THEN 1 ELSE 0 END) as dropped_count
                    FROM enr_enrollments e
                    WHERE e.student_id = ?
                      AND e.school_year = ?";

            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$studentId, $schoolYear]);
            $result = $stmt->fetch();

            return [
                'total_subjects'  => (int) ($result['total_subjects']  ?? 0),
                'enrolled_count'  => (int) ($result['enrolled_count']  ?? 0),
                'completed_count' => (int) ($result['completed_count'] ?? 0),
                'dropped_count'   => (int) ($result['dropped_count']   ?? 0),
                'school_year'     => $schoolYear
            ];
        } catch (Exception $e) {
            error_log("Error in getStudentEnrolledSubjectsSummary: " . $e->getMessage());
            return [
                'total_subjects'  => 0,
                'enrolled_count'  => 0,
                'completed_count' => 0,
                'dropped_count'   => 0,
                'school_year'     => null
            ];
        }
    }
}