<?php
/**
 * ajax/search_students.php
 *
 * Search students with their subject enrollments.
 *
 * RULE:
 * ONE STUDENT + ONE SCHEDULE + ONE SCHOOL YEAR = ONE ENROLLMENT
 */

session_start();
require_once '../classes/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_GET['q']) || strlen($_GET['q']) < 2) {
    echo json_encode(['success' => false, 'message' => 'Search term too short']);
    exit;
}

$search = '%' . $_GET['q'] . '%';

try {

    $db = Database::getInstance();

    // Search students with subject enrollment count from enr_enrollments
    $sql = "
        SELECT
            s.student_id,
            s.student_number,
            a.first_name,
            a.middle_name,
            a.surname,
            a.suffix,
            a.email,
            a.contact_number,

            (
                SELECT COUNT(DISTINCT e.schedule_id)
                FROM enr_enrollments e
                WHERE e.student_id = s.student_id
                  AND e.enrollment_status = 'enrolled'
            ) AS subject_count,

            sec.section_code,
            sec.id AS section_id,

            c.code AS course_code,
            c.name AS course_name,

            s.followup_date,
            s.followup_notes,
            s.followup_status,
            s.enrollment_status AS student_status

        FROM enr_students s
        JOIN enr_applicants a
            ON s.applicant_id = a.applicant_id
        LEFT JOIN cc_sections sec
            ON s.section_id = sec.id
        LEFT JOIN rgr_courses c
            ON s.course_id = c.id

        WHERE (
                a.first_name LIKE ?
             OR a.surname LIKE ?
             OR s.student_number LIKE ?
             OR a.email LIKE ?
        )

        ORDER BY a.surname, a.first_name
        LIMIT 50
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$search, $search, $search, $search]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($students)) {
        $students = [];
    }

    // Get enrolled subjects for each student using schedule_id
    $enrollSql = "
        SELECT
            e.*,
            rs.code AS subject_code,
            rs.name AS subject_name,
            rs.units,
            cs.start_time,
            cs.end_time,
            cs.day_of_week,
            r.room_code,
            r.room_name,
            f.first_name AS faculty_first_name,
            f.last_name  AS faculty_last_name
        FROM enr_enrollments e
        INNER JOIN cc_schedule cs
            ON e.schedule_id = cs.id
        INNER JOIN rgr_subjects rs
            ON cs.subject_id = rs.id
        LEFT JOIN cc_room r
            ON cs.room_id = r.id
        LEFT JOIN cc_faculty f
            ON cs.faculty_id = f.id
        WHERE e.student_id = ?
          AND e.enrollment_status = 'enrolled'
        ORDER BY rs.code ASC
    ";

    $enrollStmt = $db->prepare($enrollSql);

    foreach ($students as &$student) {
        $enrollStmt->execute([$student['student_id']]);
        $student['enrolled_subjects'] = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    unset($student);

    echo json_encode([
        'success'  => true,
        'students' => $students
    ]);

} catch (PDOException $e) {

    error_log('search_students.php PDO Error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'error'   => $e->getMessage()
    ]);

} catch (Throwable $e) {

    error_log('search_students.php Error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error'   => $e->getMessage()
    ]);
}