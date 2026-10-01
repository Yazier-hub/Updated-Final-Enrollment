<?php

require_once __DIR__ . '/Database.php';

if (!class_exists('Employee')) {

class Employee {
    private $conn;
    private $employeeid;
    private $firstname;
    private $lastname;
    private $middlename;
    private $department;
    private $position;
    private $status;

    public function __construct($pdo = null) {
        if ($pdo instanceof PDO) {
            $this->conn = $pdo;
        } else {
            $this->conn = Database::getInstance()->getConnection();
        }
    }

    public function getEmployees() {
        $sql = "SELECT
                    em.employee_id,
                    em.employee_code,
                    em.first_name,
                    em.middle_name,
                    em.last_name,
                    em.department_id,
                    em.position_id,
                    em.employment_status,
                    d.department_name,
                    p.position_name
                FROM em_employees em
                LEFT JOIN em_departments d ON em.department_id = d.department_id
                LEFT JOIN em_positions   p ON em.position_id   = p.position_id
                WHERE em.is_archived = 0
                ORDER BY em.employee_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getEmployeeId() {
        return $_SESSION['employee_id'] ?? null;
    }

    public function getEmployeeName() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $employeeId = $_SESSION['employee_id'] ?? null;

        if ($employeeId) {
            $sql = "SELECT first_name, middle_name, last_name
                    FROM em_employees
                    WHERE employee_id = :employee_id
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':employee_id', $employeeId);
            $stmt->execute();
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($employee) {
                $parts = array_filter([
                    $employee['first_name']  ?? '',
                    $employee['middle_name'] ?? '',
                    $employee['last_name']   ?? '',
                ], fn($v) => trim($v) !== '');

                return htmlspecialchars(implode(' ', $parts));
            }
        }
        return 'Unknown User';
    }

    public function getEmployeePosition() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $employeeId = $_SESSION['employee_id'] ?? null;

        if ($employeeId) {
            $sql = "SELECT p.position_name, r.role_name, d.department_name
                    FROM em_employees em
                    LEFT JOIN em_positions   p ON em.position_id   = p.position_id
                    LEFT JOIN em_roles       r ON em.role_id       = r.role_id
                    LEFT JOIN em_departments d ON em.department_id = d.department_id
                    WHERE em.employee_id = :employee_id
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':employee_id', $employeeId);
            $stmt->execute();
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($employee) {
                if (!empty($employee['position_name'])) {
                    return htmlspecialchars($employee['position_name']);
                }
                if (!empty($employee['role_name'])) {
                    return htmlspecialchars($employee['role_name']);
                }
                if (!empty($employee['department_name'])) {
                    return htmlspecialchars($employee['department_name']);
                }
            }
        }
        return 'Unknown Position';
    }

    public function getEmployeeDepartment() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $employeeId = $_SESSION['employee_id'] ?? null;

        if ($employeeId) {
            $sql = "SELECT d.department_name
                    FROM em_employees em
                    LEFT JOIN em_departments d ON em.department_id = d.department_id
                    WHERE em.employee_id = :employee_id
                    LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':employee_id', $employeeId);
            $stmt->execute();
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($employee && !empty($employee['department_name'])) {
                return htmlspecialchars($employee['department_name']);
            }
        }
        return 'Unknown Department';
    }
}

} // end class_exists guard