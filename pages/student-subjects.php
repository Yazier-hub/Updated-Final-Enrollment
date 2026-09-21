<?php
// pages/student-subjects.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • subject_type no longer returned by the API → uses ?? 'Lecture'
//   • All $student[...] and $subject[...] reads null-safe
//   • totalUnits sums with (int) cast
//   • Escaped ENUM values in class attributes

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ?page=login');
    exit;
}

require_once 'classes/Student.php';
require_once 'classes/Enrollment.php';
require_once 'classes/Application.php';
require_once 'classes/Course.php';
require_once 'classes/Section.php';

$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($studentId <= 0) {
    $_SESSION['message'] = '❌ Invalid student ID.';
    header('Location: ?page=students');
    exit;
}

$studentModel    = new Student();
$enrollmentModel = new Enrollment();
$application     = new Application();
$courseModel     = new Course();
$sectionModel    = new Section();

$student = $studentModel->getStudentDetails($studentId);

if (!$student) {
    $_SESSION['message'] = '❌ Student not found.';
    header('Location: ?page=students');
    exit;
}

$subjects = $enrollmentModel->getStudentCurrentSemesterEnrollments($studentId);
if (!is_array($subjects)) $subjects = [];

$enrollment = $enrollmentModel->getEnrollmentByStudentId($studentId);

$pageTitle = 'Student Subjects - '
    . trim(($student['first_name'] ?? '') . ' ' . ($student['surname'] ?? ''));
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;">
                <div>
                    <h1>📚 Student Subjects</h1>
                    <p class="dashboard-subtitle">
                        <?php echo htmlspecialchars(trim(
                            ($student['first_name'] ?? '') . ' ' . ($student['surname'] ?? '')
                        )); ?>
                        (<?php echo htmlspecialchars($student['student_number'] ?? ''); ?>)
                    </p>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a href="?page=student-details&id=<?php echo $studentId; ?>" class="btn btn-info">👤 Student Details</a>
                    <a href="?page=students" class="btn btn-secondary">← Back to Students</a>
                </div>
            </div>
        </div>

        <div class="module-content">
            <!-- Student Info Card -->
            <div class="student-info-card">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Student Number</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['student_number'] ?? ''); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Name</span>
                        <span class="info-value"><?php echo htmlspecialchars(trim(
                            ($student['first_name'] ?? '') . ' ' . ($student['surname'] ?? '')
                        )); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Course</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['course_code'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Section</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['section_code'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Year Level</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Semester</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['semester'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">School Year</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['school_year'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <?php $sStatus = $student['enrollment_status'] ?? 'enrolled'; ?>
                            <span class="status-badge status-<?php echo htmlspecialchars($sStatus); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($sStatus))); ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo strpos($_SESSION['message'], '✅') !== false ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($_SESSION['message']); ?>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>

            <!-- Subjects Section -->
            <div class="subjects-container">
                <div class="subjects-header">
                    <h2>📖 Enrolled Subjects (Current Semester)</h2>
                    <span class="badge-count"><?php echo count($subjects); ?> Subjects</span>
                </div>

                <?php if (count($subjects) > 0): ?>
                    <div class="table-responsive">
                        <table class="table subjects-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Subject Code</th>
                                    <th>Subject Name</th>
                                    <th>Units</th>
                                    <th>Type</th>
                                    <th>Schedule</th>
                                    <th>Faculty</th>
                                    <th>Room</th>
                                    <th>Status</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter    = 1;
                                $totalUnits = 0;
                                foreach ($subjects as $subject):
                                    $totalUnits += (int) ($subject['units'] ?? 0);

                                    // Schedule display
                                    $scheduleDisplay = 'No schedule';
                                    if (!empty($subject['day_of_week']) && !empty($subject['start_time'])) {
                                        $scheduleDisplay = $subject['day_of_week']
                                            . ' ' . date('h:i A', strtotime($subject['start_time']));
                                        if (!empty($subject['end_time'])) {
                                            $scheduleDisplay .= ' - ' . date('h:i A', strtotime($subject['end_time']));
                                        }
                                    }

                                    // Faculty
                                    $facultyDisplay = trim(
                                        ($subject['faculty_first'] ?? '') . ' ' . ($subject['faculty_last'] ?? '')
                                    );
                                    if ($facultyDisplay === '') $facultyDisplay = 'N/A';

                                    // Room
                                    $roomDisplay = $subject['room_name'] ?? '';
                                    if ($roomDisplay !== '' && !empty($subject['room_code'])) {
                                        $roomDisplay .= ' (' . $subject['room_code'] . ')';
                                    }
                                    if ($roomDisplay === '') $roomDisplay = 'N/A';

                                    // Enrollment status
                                    $enrollmentStatus = $subject['enrollment_status'] ?? 'enrolled';
                                    $gradeDisplay     = $subject['grade'] ?? null;

                                    // subject_type is NOT returned by the API anymore.
                                    $subjectType = $subject['subject_type'] ?? 'Lecture';
                                    $typeClass   = 'subject-' . strtolower($subjectType);
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($subject['subject_code'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subject['subject_name'] ?? 'N/A'); ?></td>
                                    <td class="text-center"><?php echo (int) ($subject['units'] ?? 0); ?></td>
                                    <td>
                                        <span class="subject-type-badge <?php echo htmlspecialchars($typeClass); ?>">
                                            <?php echo htmlspecialchars($subjectType); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($subject['schedule_id'])): ?>
                                            <span class="schedule-badge has-schedule">
                                                📅 <?php echo htmlspecialchars($scheduleDisplay); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="schedule-badge no-schedule">
                                                ⏳ <?php echo htmlspecialchars($scheduleDisplay); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($facultyDisplay !== 'N/A'): ?>
                                            <span class="faculty-name">👨‍🏫 <?php echo htmlspecialchars($facultyDisplay); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($roomDisplay !== 'N/A'): ?>
                                            <span class="room-name">🏫 <?php echo htmlspecialchars($roomDisplay); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($enrollmentStatus); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($enrollmentStatus))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($gradeDisplay !== null && $gradeDisplay !== ''): ?>
                                            <span class="grade-badge grade-<?php echo ((float) $gradeDisplay >= 75) ? 'passed' : 'failed'; ?>">
                                                <?php echo number_format((float) $gradeDisplay, 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="grade-badge grade-pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3"><strong>Total Units</strong></td>
                                    <td class="text-center"><strong><?php echo $totalUnits; ?></strong></td>
                                    <td colspan="6"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Summary Cards -->
                    <div class="summary-cards">
                        <div class="summary-card">
                            <div class="summary-number"><?php echo count($subjects); ?></div>
                            <div class="summary-label">Total Subjects</div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-number"><?php echo $totalUnits; ?></div>
                            <div class="summary-label">Total Units</div>
                        </div>
                        <div class="summary-card">
                            <?php
                                $completed = 0;
                                foreach ($subjects as $s) {
                                    if (($s['enrollment_status'] ?? '') === 'completed') $completed++;
                                }
                            ?>
                            <div class="summary-number"><?php echo $completed; ?></div>
                            <div class="summary-label">Completed</div>
                        </div>
                        <div class="summary-card">
                            <?php
                                $withSchedule = 0;
                                foreach ($subjects as $s) {
                                    if (!empty($s['schedule_id'])) $withSchedule++;
                                }
                            ?>
                            <div class="summary-number"><?php echo $withSchedule; ?></div>
                            <div class="summary-label">With Schedule</div>
                        </div>
                        <div class="summary-card">
                            <?php
                                $inProgress = 0;
                                foreach ($subjects as $s) {
                                    if (($s['enrollment_status'] ?? '') === 'enrolled') $inProgress++;
                                }
                            ?>
                            <div class="summary-number"><?php echo $inProgress; ?></div>
                            <div class="summary-label">In Progress</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <p>No subjects found for this student's current semester.</p>
                        <p style="margin-top:10px;">
                            <a href="?page=enrollments" class="btn btn-primary">Go to Enrollments</a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Subject Enrollment History -->
            <?php if (count($subjects) > 0 && is_array($enrollment)): ?>
            <div class="enrollment-history">
                <h3>📋 Enrollment Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Enrollment Date</span>
                        <span class="info-value"><?php
                            echo !empty($enrollment['enrollment_date'])
                                ? htmlspecialchars(date('M d, Y', strtotime($enrollment['enrollment_date'])))
                                : 'N/A';
                        ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Enrollment Status</span>
                        <span class="info-value">
                            <?php $eStatus = $enrollment['enrollment_status'] ?? 'pending'; ?>
                            <span class="status-badge status-<?php echo htmlspecialchars($eStatus); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($eStatus))); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Academic Standing</span>
                        <span class="info-value"><?php echo htmlspecialchars($enrollment['academic_standing'] ?? 'Good Standing'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">School Year</span>
                        <span class="info-value"><?php echo htmlspecialchars($enrollment['school_year'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       STUDENT SUBJECTS — Blue & Sky Blue Theme
       ============================================================ */

    :root {
        --navy:        #1a3c6e;
        --navy-dark:   #0f2a4e;
        --blue:        #2a5c9e;
        --blue-mid:    #3a7bc8;
        --sky:         #4a90d9;
        --sky-light:   #6aa8e0;
        --sky-pale:    #a8d0f0;
        --sky-bg:      #e8f4fd;
        --sky-bg-soft: #f0f7ff;
        --sky-border:  #b8d4e8;
        --sky-muted:   #5a7fa8;
    }

    .dashboard-subtitle {
        color: var(--sky-muted);
        font-size: 14px;
        margin-bottom: 0;
    }

    /* ---------- INFO CARDS ---------- */
    .student-info-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        margin-bottom: 20px;
        border-left: 4px solid var(--navy);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .info-label {
        font-size: 11px;
        text-transform: uppercase;
        color: var(--sky-muted);
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 15px;
        color: var(--navy);
        font-weight: 500;
    }

    /* ---------- SUBJECTS CONTAINER ---------- */
    .subjects-container {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        margin-bottom: 20px;
    }

    .subjects-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .subjects-header h2 {
        margin: 0;
        color: var(--navy);
        font-size: 18px;
    }

    .badge-count {
        background: var(--navy);
        color: white;
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 700;
    }

    /* ---------- TABLE ---------- */
    .table-responsive { overflow-x: auto; }

    .subjects-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .subjects-table th {
        background: var(--sky-bg-soft);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        color: var(--sky-muted);
        border-bottom: 2px solid var(--sky-border);
        white-space: nowrap;
        letter-spacing: 0.3px;
    }

    .subjects-table td {
        padding: 12px;
        border-bottom: 1px solid var(--sky-bg);
        vertical-align: middle;
        color: var(--navy);
    }

    .subjects-table tbody tr {
        transition: background 0.2s ease;
    }

    .subjects-table tbody tr:hover {
        background: var(--sky-bg-soft);
    }

    .subjects-table tfoot td {
        background: var(--sky-bg);
        font-weight: 600;
        border-top: 2px solid var(--sky-border);
        padding: 12px;
    }

    .text-center { text-align: center; }
    .text-muted {
        color: var(--sky-muted);
        font-size: 13px;
    }

    /* ---------- SUBJECT TYPE BADGES ---------- */
    .subject-type-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    .subject-lecture     { background: #d4e8fc; color: var(--navy); }
    .subject-laboratory  { background: #e8f0fe; color: var(--blue); }
    .subject-both        { background: #cce5ff; color: var(--navy); }
    .subject-lab         { background: #e8f0fe; color: var(--blue); }
    .subject-seminar     { background: var(--sky-bg); color: var(--navy); }

    /* ---------- SCHEDULE BADGES ---------- */
    .schedule-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }

    .schedule-badge.has-schedule {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .schedule-badge.no-schedule {
        background: var(--sky-bg-soft);
        color: var(--sky-muted);
        border: 1px solid var(--sky-border);
    }

    /* ---------- FACULTY / ROOM ---------- */
    .faculty-name,
    .room-name {
        color: var(--navy);
        font-size: 13px;
        white-space: nowrap;
    }

    /* ---------- STATUS BADGES ---------- */
    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border: 1px solid transparent;
    }

    .status-enrolled  { background: #d4e8fc; color: var(--navy);      border-color: var(--sky-pale); }
    .status-completed { background: #e8f4fd; color: var(--navy);      border-color: var(--sky-border); }
    .status-dropped   { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }
    .status-failed    { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }
    .status-pending   { background: var(--sky-bg); color: var(--sky-muted); border-color: var(--sky-border); }

    /* ---------- GRADE BADGES ---------- */
    .grade-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        min-width: 60px;
        text-align: center;
        border: 1px solid transparent;
    }

    .grade-passed  { background: #d4e8fc; color: var(--navy);      border-color: var(--sky-pale); }
    .grade-failed  { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }
    .grade-pending { background: #e8f0fe; color: var(--blue);      border-color: var(--sky-border); }

    /* ---------- SUMMARY CARDS ---------- */
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--sky-bg);
    }

    .summary-card {
        background: var(--sky-bg-soft);
        padding: 15px;
        border-radius: 8px;
        text-align: center;
        border-top: 3px solid var(--navy);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(26, 60, 110, 0.12);
    }

    .summary-number {
        font-size: 28px;
        font-weight: 700;
        color: var(--navy);
    }

    .summary-label {
        font-size: 13px;
        color: var(--sky-muted);
        margin-top: 5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        font-weight: 600;
    }

    /* ---------- ENROLLMENT HISTORY ---------- */
    .enrollment-history {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        border-left: 4px solid var(--blue);
    }

    .enrollment-history h3 {
        color: var(--navy);
        font-size: 16px;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
    }

    /* ---------- ALERTS ---------- */
    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .alert-success {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .alert-danger {
        background: #dce8f5;
        color: var(--navy-dark);
        border: 1px solid var(--sky-pale);
    }

    .alert-info {
        background: var(--sky-bg);
        color: var(--navy);
        border: 1px solid var(--sky-border);
    }

    /* ---------- BUTTONS ---------- */
    .btn {
        padding: 8px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-block;
        font-weight: 500;
        font-family: inherit;
        line-height: 1.4;
    }

    .btn:hover { transform: translateY(-1px); }

    .btn-primary {
        background: var(--navy);
        color: white;
    }
    .btn-primary:hover { background: var(--blue); }

    .btn-info {
        background: var(--sky-light);
        color: white;
    }
    .btn-info:hover { background: var(--sky); }

    .btn-secondary {
        background: var(--sky-muted);
        color: white;
    }
    .btn-secondary:hover { background: var(--blue); }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
        .info-grid { grid-template-columns: 1fr 1fr; }
        .summary-cards { grid-template-columns: 1fr 1fr; }
        .subjects-table { font-size: 12px; }
        .subjects-table th,
        .subjects-table td { padding: 8px; }
    }

    @media (max-width: 480px) {
        .info-grid { grid-template-columns: 1fr; }
        .summary-cards { grid-template-columns: 1fr; }
    }
</style>