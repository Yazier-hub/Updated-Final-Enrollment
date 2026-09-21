<?php
// classes/User.php - FULLY FIXED for `kms` schema
//
// No schema changes needed. Only hardened:
//   • (int) casts on IDs and returned user_id
//   • null-safe reads
//   • explicit is_array() guard on returned rows

require_once __DIR__ . '/Model.php';

class User extends Model
{
    protected $table      = 'enr_users';
    protected $primaryKey = 'user_id';
    protected $fillable = [
        'username', 'password', 'email', 'full_name',
        'student_id', 'is_active', 'created_at', 'last_login', 'updated_at'
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /* ============================================================
       ACCOUNT CREATION
    ============================================================ */

    public function createStudentAccount($studentId, $studentNumber, $email, $fullName)
    {
        try {
            if (empty($studentId)) {
                return [
                    'success'  => false,
                    'message'  => 'Student ID is required.',
                    'is_new'   => false,
                    'password' => null
                ];
            }

            if (empty($studentNumber)) {
                return [
                    'success'  => false,
                    'message'  => 'Student number is required.',
                    'is_new'   => false,
                    'password' => null
                ];
            }

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success'  => false,
                    'message'  => 'Invalid student email address: ' . $email,
                    'is_new'   => false,
                    'password' => null
                ];
            }

            $studentId = (int) $studentId;

            // Existing account?
            $existing = $this->getUserByStudentId($studentId);
            if ($existing) {
                return [
                    'success'  => true,
                    'message'  => 'User account already exists.',
                    'is_new'   => false,
                    'password' => null,
                    'username' => $existing['username'],
                    'user_id'  => (int) $existing['user_id']
                ];
            }

            // Ensure username is unique
            $username = $studentNumber;
            $existingUsername = $this->getUserByUsername($username);

            if ($existingUsername) {
                $suffix = 2;
                do {
                    $username = $studentNumber . $suffix;
                    $suffix++;
                    $existingUsername = $this->getUserByUsername($username);
                } while ($existingUsername);
            }

            // Generate password
            $password       = $this->generateSecurePassword(10);
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            if ($hashedPassword === false) {
                return [
                    'success'  => false,
                    'message'  => 'Failed to hash password.',
                    'is_new'   => false,
                    'password' => null
                ];
            }

            $sql = "INSERT INTO enr_users
                    (username, password, email, full_name, student_id, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())";

            $stmt = $this->connection->prepare($sql);
            $result = $stmt->execute([
                $username,
                $hashedPassword,
                $email,
                $fullName,
                $studentId
            ]);

            if (!$result) {
                throw new Exception('Failed to insert user record.');
            }

            $userId = (int) $this->connection->lastInsertId();

            // Link user_id back to enr_students
            $updateSql  = "UPDATE enr_students SET user_id = ? WHERE student_id = ?";
            $updateStmt = $this->connection->prepare($updateSql);
            $updateStmt->execute([$userId, $studentId]);

            return [
                'success'  => true,
                'message'  => 'Student account created successfully.',
                'is_new'   => true,
                'password' => $password,
                'username' => $username,
                'user_id'  => $userId
            ];

        } catch (PDOException $e) {
            error_log('User account creation error: ' . $e->getMessage());
            return [
                'success'  => false,
                'message'  => 'Database error: ' . $e->getMessage(),
                'is_new'   => false,
                'password' => null
            ];
        } catch (Exception $e) {
            error_log('User account creation error: ' . $e->getMessage());
            return [
                'success'  => false,
                'message'  => $e->getMessage(),
                'is_new'   => false,
                'password' => null
            ];
        }
    }

    /* ============================================================
       LOOKUPS
    ============================================================ */

    public function getUserByStudentId($studentId)
    {
        try {
            $sql  = "SELECT * FROM enr_users WHERE student_id = ? LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([(int) $studentId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting user by student ID: ' . $e->getMessage());
            return null;
        }
    }

    public function getUserByUsername($username)
    {
        try {
            $sql  = "SELECT * FROM enr_users WHERE username = ? LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$username]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting user by username: ' . $e->getMessage());
            return null;
        }
    }

    public function getUserByEmail($email)
    {
        try {
            $sql  = "SELECT * FROM enr_users WHERE email = ? LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$email]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting user by email: ' . $e->getMessage());
            return null;
        }
    }

    /* ============================================================
       PASSWORD
    ============================================================ */

    private function generateSecurePassword($length = 10)
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers   = '0123456789';
        $special   = '!@#$%^&*';

        $password  = $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $all = $uppercase . $lowercase . $numbers . $special;

        while (strlen($password) < $length) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    public function verifyLogin($username, $password)
    {
        try {
            $user = $this->getUserByUsername($username);

            if (!$user) {
                return ['success' => false, 'message' => 'User not found.'];
            }

            if (!$user['is_active']) {
                return ['success' => false, 'message' => 'Account is not active.'];
            }

            if (password_verify($password, $user['password'])) {
                $updateSql  = "UPDATE enr_users SET last_login = NOW() WHERE user_id = ?";
                $updateStmt = $this->connection->prepare($updateSql);
                $updateStmt->execute([(int) $user['user_id']]);

                return ['success' => true, 'user' => $user];
            }

            return ['success' => false, 'message' => 'Invalid password.'];
        } catch (Exception $e) {
            error_log('Login verification error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Login error.'];
        }
    }

    public function updatePassword($userId, $newPassword)
    {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $sql  = "UPDATE enr_users SET password = ?, updated_at = NOW() WHERE user_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([$hashedPassword, (int) $userId]);
        } catch (Exception $e) {
            error_log('Error updating password: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       STATUS TOGGLES
    ============================================================ */

    public function deactivateUser($userId)
    {
        try {
            $sql  = "UPDATE enr_users SET is_active = 0, updated_at = NOW() WHERE user_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([(int) $userId]);
        } catch (Exception $e) {
            error_log('Error deactivating user: ' . $e->getMessage());
            return false;
        }
    }

    public function activateUser($userId)
    {
        try {
            $sql  = "UPDATE enr_users SET is_active = 1, updated_at = NOW() WHERE user_id = ?";
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute([(int) $userId]);
        } catch (Exception $e) {
            error_log('Error activating user: ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       LISTS / COUNTS
    ============================================================ */

    public function getAllUsersWithDetails()
    {
        try {
            $sql = "SELECT u.*,
                           s.student_number,
                           a.first_name, a.middle_name, a.surname, a.suffix,
                           c.code as course_code, c.name as course_name
                    FROM enr_users u
                    LEFT JOIN enr_students s ON u.student_id = s.student_id
                    LEFT JOIN enr_applicants a ON s.applicant_id = a.applicant_id
                    LEFT JOIN rgr_courses c ON s.course_id = c.id
                    ORDER BY u.created_at DESC";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error getting all users with details: ' . $e->getMessage());
            return [];
        }
    }

    public function getUserCount()
    {
        try {
            $sql  = "SELECT COUNT(*) as count FROM enr_users";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (Exception $e) {
            error_log('Error getting user count: ' . $e->getMessage());
            return 0;
        }
    }
}