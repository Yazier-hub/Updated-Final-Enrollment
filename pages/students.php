<?php
// pages/students.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • Export CSV handler added (was linked but never implemented)
//   • Subject count per row is now pre-fetched in one query (avoids N+1)
//   • Null-safe reads on all $s[...] and $stats[...] fields
//   • viewSchedule() JS now uses faculty_name (matches get_student_schedule.php)
//   • fetch() path resolves relative to window.location.origin
//   • window.onclick replaced with addEventListener

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'classes/Student.php';
require_once 'classes/StudentController.php';
require_once 'classes/Course.php';
require_once 'classes/Section.php';
require_once 'classes/Enrollment.php';

$controller = new StudentController();
$enrollment = new Enrollment();
$db         = Database::getInstance();

// ============================================================
// Filters
// ============================================================
$search        = $_GET['search']     ?? '';
$courseFilter  = isset($_GET['course_id'])  ? (int) $_GET['course_id']  : '';
$sectionFilter = isset($_GET['section_id']) ? (int) $_GET['section_id'] : '';
$statusFilter  = $_GET['status']     ?? '';

// ============================================================
// Data
// ============================================================
$students = $controller->getStudents($search, $courseFilter, $sectionFilter, $statusFilter);
if (!is_array($students)) $students = [];

$stats   = $controller->getStudentStats();
if (!is_array($stats)) $stats = [];

$courses = $controller->getAllCourses();
if (!is_array($courses)) $courses = [];

$sections = $controller->getActiveSections();
if (!is_array($sections)) $sections = [];

// ============================================================
// Prefetch subject counts for all rows in ONE query
// (avoids N+1: previously one query per row)
// ============================================================
$studentIds = array_values(array_filter(
    array_map(fn($s) => (int) ($s['student_id'] ?? 0), $students),
    fn($id) => $id > 0
));

$subjectCountsByStudent = [];
if (!empty($studentIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $sql = "SELECT e.student_id,
                       COUNT(DISTINCT e.schedule_id) AS subject_count
                FROM enr_enrollments e
                JOIN enr_students s ON s.student_id = e.student_id
                WHERE e.student_id IN ($placeholders)
                  AND e.enrollment_status = 'enrolled'
                  AND e.section_id = s.section_id
                GROUP BY e.student_id";
        $stmt = $db->prepare($sql);
        $stmt->execute($studentIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subjectCountsByStudent[(int) $row['student_id']] = (int) $row['subject_count'];
        }
    } catch (Exception $e) {
        error_log('students.php: subject count prefetch failed: ' . $e->getMessage());
    }
}

// ============================================================
// CSV export
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

    fputcsv($out, ['STUDENT LIST REPORT']);
    fputcsv($out, ['Generated:', date('l, F d, Y h:i A')]);
    fputcsv($out, ['']);
    fputcsv($out, [
        'Student #', 'Full Name', 'Course', 'Section', 'Year Level',
        'Admission Type', 'Status', 'Email', 'Contact',
        'Subjects', 'Enrolled At'
    ]);

    foreach ($students as $s) {
        $name = trim(
            ($s['first_name'] ?? '')
            . ' ' . ($s['middle_name'] ?? '')
            . ' ' . ($s['surname'] ?? '')
            . ' ' . ($s['suffix'] ?? '')
        );

        fputcsv($out, [
            $s['student_number']     ?? 'N/A',
            $name,
            $s['course_code']        ?? 'N/A',
            $s['section_code']       ?? 'Not Assigned',
            $s['year_level']         ?? 'N/A',
            ucfirst(str_replace('_', ' ', $s['admission_type'] ?? 'N/A')),
            ucfirst(str_replace('_', ' ', $s['enrollment_status'] ?? 'Unknown')),
            $s['email']              ?? 'N/A',
            $s['contact_number']     ?? 'N/A',
            $subjectCountsByStudent[(int) ($s['student_id'] ?? 0)] ?? 0,
            !empty($s['enrolled_at']) ? date('M d, Y', strtotime($s['enrolled_at'])) : 'N/A'
        ]);
    }

    fclose($out);
    exit;
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$badgeMap = [
    'freshmen'    => 'badge-freshmen',
    'transferee'  => 'badge-transferee',
    'returnee'    => 'badge-returnee',
    'senior_high' => 'badge-senior_high'
];

$pageTitle = 'Students Management';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>👨‍🎓 Students Management</h1>
            <p class="dashboard-subtitle">Manage student records</p>
        </div>

        <div class="module-content">

            <!-- MESSAGES -->
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo strpos($message, '✅') !== false ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- STATISTICS -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="number"><?php echo (int) ($stats['total_students'] ?? 0); ?></div>
                    <div class="label">📊 Total Students</div>
                </div>
                <div class="stat-card success">
                    <div class="number"><?php echo (int) ($stats['enrolled'] ?? 0); ?></div>
                    <div class="label">✅ Enrolled</div>
                </div>
                <div class="stat-card warning">
                    <div class="number"><?php echo (int) ($stats['on_leave'] ?? 0); ?></div>
                    <div class="label">⏳ On Leave</div>
                </div>
                <div class="stat-card info">
                    <div class="number"><?php echo (int) ($stats['graduated'] ?? 0); ?></div>
                    <div class="label">🎓 Graduated</div>
                </div>
                <div class="stat-card danger">
                    <div class="number"><?php echo (int) ($stats['dropped'] ?? 0); ?></div>
                    <div class="label">❌ Dropped</div>
                </div>
            </div>

            <!-- SEARCH & FILTERS -->
            <div class="filters-section">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="page" value="students">

                    <div class="filter-group search-group">
                        <label for="search_input">Search</label>
                        <input type="text" name="search" id="search_input"
                               placeholder="Search by name, student #, or email..."
                               value="<?php echo htmlspecialchars($search); ?>"
                               class="search-input">
                    </div>

                    <div class="filter-group">
                        <label for="course_filter">Course</label>
                        <select name="course_id" id="course_filter" onchange="this.form.submit()">
                            <option value="">All Courses</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>"
                                    <?php echo $courseFilter == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['code'] ?? ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="section_filter">Section</label>
                        <select name="section_id" id="section_filter" onchange="this.form.submit()">
                            <option value="">All Sections</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?php echo (int) $sec['id']; ?>"
                                    <?php echo $sectionFilter == $sec['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(
                                        ($sec['section_code'] ?? '') . ' (' . ($sec['course_code'] ?? '') . ')'
                                    ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status_filter">Status</label>
                        <select name="status" id="status_filter" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <?php foreach ([
                                'enrolled'  => 'Enrolled',
                                'on_leave'  => 'On Leave',
                                'graduated' => 'Graduated',
                                'dropped'   => 'Dropped'
                            ] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>"
                                    <?php echo $statusFilter === $val ? 'selected' : ''; ?>>
                                    <?php echo $lbl; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 Search</button>
                        <?php if ($search || $courseFilter || $sectionFilter || $statusFilter): ?>
                            <a href="?page=students" class="btn btn-secondary">✖ Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- STUDENTS TABLE -->
            <div class="students-table-container">
                <div class="table-header">
                    <span class="title">Student Records</span>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <span class="badge-count"><?php echo count($students); ?> students</span>
                        <?php if (count($students) > 0): ?>
                            <a href="?page=students&export=csv<?php
                                    echo $search        ? '&search='    . urlencode($search)        : '';
                                    echo $courseFilter  ? '&course_id=' . (int) $courseFilter       : '';
                                    echo $sectionFilter ? '&section_id=' . (int) $sectionFilter     : '';
                                    echo $statusFilter  ? '&status='    . urlencode($statusFilter)  : '';
                                ?>"
                               class="btn btn-sm btn-info">📥 Export CSV</a>
                        <?php endif; ?>
                    </div>
                </div>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Student #</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Section</th>
                            <th>Year</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Enrolled</th>
                            <th>Subjects</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($students) > 0): ?>
                            <?php foreach ($students as $s):
                                $studentId = (int) ($s['student_id'] ?? 0);

                                $name = trim(
                                    ($s['first_name'] ?? '')
                                    . ' ' . ($s['middle_name'] ?? '')
                                    . ' ' . ($s['surname'] ?? '')
                                    . ' ' . ($s['suffix'] ?? '')
                                );

                                $admissionType = $s['admission_type'] ?? 'freshmen';
                                $badgeClass    = $badgeMap[$admissionType] ?? 'badge-default';
                                $badgeLabel    = ucfirst(str_replace('_', ' ', $admissionType));

                                $subjectCount = $subjectCountsByStudent[$studentId] ?? 0;
                            ?>
                            <tr>
                                <td><strong>🎓 <?php echo htmlspecialchars($s['student_number'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($name); ?></td>
                                <td><?php echo htmlspecialchars($s['course_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($s['section_code'] ?? 'Not Assigned'); ?></td>
                                <td><?php echo htmlspecialchars($s['year_level'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge <?php echo htmlspecialchars($badgeClass); ?>">
                                        <?php echo htmlspecialchars($badgeLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $statusVal = $s['enrollment_status'] ?? 'unknown'; ?>
                                    <span class="status-badge status-<?php echo htmlspecialchars($statusVal); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($statusVal))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo !empty($s['enrolled_at'])
                                        ? htmlspecialchars(date('M d, Y', strtotime($s['enrolled_at'])))
                                        : 'N/A'; ?>
                                </td>
                                <td>
                                    <?php if ($subjectCount > 0): ?>
                                        <span class="badge badge-info"><?php echo $subjectCount; ?> subjects</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">0 subjects</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?page=student-details&id=<?php echo $studentId; ?>"
                                           class="btn btn-sm btn-primary">View</a>
                                        <a href="?page=requirements&student_id=<?php echo $studentId; ?>&view=students"
                                           class="btn btn-sm btn-warning">📋</a>
                                        <?php if ($subjectCount > 0): ?>
                                            <button onclick="viewSchedule(<?php echo $studentId; ?>)"
                                                    class="btn btn-sm btn-info">📅</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center">
                                    <?php if ($search || $courseFilter || $sectionFilter || $statusFilter): ?>
                                        No students found matching the selected filters.
                                        <a href="?page=students">Clear filters</a>
                                    <?php else: ?>
                                        No students found.
                                        <a href="?page=applications">Convert an application</a>
                                        to create a student.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- SCHEDULE MODAL -->
<div id="scheduleModal" class="modal">
    <div class="modal-content" style="max-width:900px;">
        <span class="close" onclick="closeScheduleModal()">&times;</span>
        <h2>📅 Student Schedule</h2>
        <div id="scheduleContent">
            <div class="loading-text">Loading schedule...</div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<style>
    /* ============================================================
       STUDENTS MANAGEMENT — Blue & Sky Blue Theme
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

    /* ---------- MODULE HEADER ---------- */
    .dashboard-subtitle {
        color: var(--sky-muted);
        font-size: 14px;
        margin-bottom: 0;
    }

    /* ---------- STATISTICS ---------- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .stat-card {
        background: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        text-align: center;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border-top: 4px solid var(--navy);
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(26, 60, 110, 0.15);
    }

    .stat-card .number {
        font-size: 28px;
        font-weight: 700;
        color: var(--navy);
    }

    .stat-card .label {
        font-size: 13px;
        color: var(--sky-muted);
        margin-top: 5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Stat accent variations — all blue tones */
    .stat-card.primary { border-top-color: var(--navy); }
    .stat-card.primary .number { color: var(--navy); }

    .stat-card.success { border-top-color: var(--blue); }
    .stat-card.success .number { color: var(--blue); }

    .stat-card.warning { border-top-color: var(--sky); }
    .stat-card.warning .number { color: var(--sky); }

    .stat-card.info { border-top-color: var(--sky-light); }
    .stat-card.info .number { color: var(--sky-light); }

    .stat-card.danger { border-top-color: var(--navy-dark); }
    .stat-card.danger .number { color: var(--navy-dark); }

    /* ---------- ALERTS ---------- */
    .alert {
        padding: 12px 20px;
        border-radius: 4px;
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
    .alert-warning {
        background: #e8f0fe;
        color: var(--navy);
        border: 1px solid var(--sky-light);
    }

    /* ---------- FILTERS ---------- */
    .filters-section {
        background: white;
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
    }

    .filter-form {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .filter-group label {
        font-size: 12px;
        font-weight: 600;
        color: var(--sky-muted);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .filter-group select,
    .search-input {
        padding: 8px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        min-width: 150px;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
        background: white;
        color: var(--navy);
        box-sizing: border-box;
    }

    .filter-group select:focus,
    .search-input:focus {
        border-color: var(--navy);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    .search-input::placeholder {
        color: var(--sky-muted);
    }

    .search-group {
        flex: 1;
        min-width: 200px;
    }

    .search-input {
        width: 100%;
        min-width: unset;
    }

    .filter-actions {
        display: flex;
        gap: 10px;
        margin-left: auto;
        align-items: flex-end;
    }

    /* ---------- TABLE CONTAINER ---------- */
    .students-table-container {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        overflow-x: auto;
    }

    .table-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
        flex-wrap: wrap;
        gap: 10px;
    }

    .table-header .title {
        font-weight: 700;
        font-size: 16px;
        color: var(--navy);
    }

    .badge-count {
        background: var(--navy);
        color: white;
        padding: 3px 14px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 700;
    }

    /* ---------- TABLE ---------- */
    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .table th {
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

    .table td {
        padding: 12px;
        border-bottom: 1px solid var(--sky-bg);
        vertical-align: middle;
        color: var(--navy);
    }

    .table tbody tr {
        transition: background 0.2s ease;
    }

    .table tbody tr:hover {
        background: var(--sky-bg-soft);
    }

    .table .text-center {
        text-align: center;
        padding: 30px 20px;
        color: var(--sky-muted);
    }

    .table .text-center a {
        color: var(--blue);
        font-weight: 600;
        text-decoration: underline;
        cursor: pointer;
    }

    .table .text-center a:hover {
        color: var(--navy);
    }

    /* ---------- BADGES (Admission Type) ---------- */
    .badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge-freshmen {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .badge-transferee {
        background: #cce5ff;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .badge-returnee {
        background: #e8f0fe;
        color: var(--blue);
        border: 1px solid var(--sky-border);
    }

    .badge-senior_high {
        background: #dce8f5;
        color: var(--navy-dark);
        border: 1px solid var(--sky-pale);
    }

    .badge-default {
        background: var(--sky-bg);
        color: var(--sky-muted);
        border: 1px solid var(--sky-border);
    }

    .badge-info {
        background: var(--sky-light);
        color: white;
    }

    .badge-secondary {
        background: var(--sky-muted);
        color: white;
    }

    /* ---------- STATUS BADGES ---------- */
    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Statuses differentiated by blue shade intensity */
    .status-enrolled {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .status-on_leave {
        background: #e8f0fe;
        color: var(--blue);
        border: 1px solid var(--sky-border);
    }

    .status-graduated {
        background: #e8f4fd;
        color: var(--navy);
        border: 1px solid var(--sky-border);
    }

    .status-dropped {
        background: #dce8f5;
        color: var(--navy-dark);
        border: 1px solid var(--sky-pale);
    }

    .status-unknown {
        background: var(--sky-bg);
        color: var(--sky-muted);
        border: 1px solid var(--sky-border);
    }

    /* ---------- ACTION BUTTONS ---------- */
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
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

    .btn:hover {
        transform: translateY(-1px);
    }

    .btn-sm {
        padding: 4px 10px;
        font-size: 12px;
        border-radius: 4px;
    }

    .btn-primary {
        background: var(--navy);
        color: white;
    }
    .btn-primary:hover { background: var(--blue); }

    .btn-success {
        background: var(--blue);
        color: white;
    }
    .btn-success:hover { background: var(--navy); }

    .btn-danger {
        background: var(--navy-dark);
        color: white;
    }
    .btn-danger:hover { background: var(--navy); }

    .btn-warning {
        background: var(--sky);
        color: white;
    }
    .btn-warning:hover { background: var(--blue-mid); }

    .btn-secondary {
        background: var(--sky-muted);
        color: white;
    }
    .btn-secondary:hover { background: var(--blue); }

    .btn-info {
        background: var(--sky-light);
        color: white;
    }
    .btn-info:hover { background: var(--sky); }

    /* ---------- MODAL ---------- */
    .modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(26, 60, 110, 0.55);
        overflow-y: auto;
        display: none;
    }

    .modal-content {
        background: white;
        margin: 5% auto;
        padding: 30px;
        width: 90%;
        max-width: 900px;
        border-radius: 10px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(26, 60, 110, 0.3);
        animation: modalSlideIn 0.25s ease;
    }

    @keyframes modalSlideIn {
        from { opacity: 0; transform: translateY(-20px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .modal-content h2 {
        color: var(--navy);
        margin-bottom: 20px;
        font-size: 18px;
    }

    .close {
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        color: var(--sky-muted);
        transition: color 0.2s ease;
        line-height: 1;
    }

    .close:hover {
        color: var(--navy);
    }

    /* ---------- SCHEDULE MODAL ---------- */
    .loading-text {
        text-align: center;
        padding: 30px;
        color: var(--sky-muted);
    }

    .schedule-day {
        margin-bottom: 20px;
    }

    .schedule-day h3 {
        color: var(--navy);
        border-bottom: 2px solid var(--navy);
        padding-bottom: 5px;
        margin-bottom: 10px;
        font-size: 15px;
    }

    .schedule-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 10px 15px;
        background: var(--sky-bg-soft);
        border-radius: 4px;
        border-left: 4px solid var(--navy);
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .schedule-item .time {
        font-weight: 700;
        color: var(--navy);
        min-width: 100px;
    }

    .schedule-item .subject {
        flex: 1;
        min-width: 150px;
        color: var(--navy);
    }

    .schedule-item .faculty {
        color: var(--sky-muted);
        font-size: 13px;
    }

    .schedule-item .room {
        color: var(--sky-muted);
        font-size: 13px;
        background: var(--sky-bg);
        padding: 2px 10px;
        border-radius: 10px;
    }

    .no-schedule {
        text-align: center;
        padding: 30px;
        color: var(--sky-muted);
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 992px) {
        .filter-form {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group {
            width: 100%;
        }

        .filter-group select,
        .search-input {
            width: 100%;
            min-width: unset;
        }

        .filter-actions {
            margin-left: 0;
            flex-wrap: wrap;
        }

        .filter-actions .btn {
            flex: 1;
            text-align: center;
        }
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .table {
            font-size: 12px;
        }

        .table th,
        .table td {
            padding: 8px;
        }

        .action-buttons {
            flex-direction: column;
        }

        .action-buttons .btn {
            width: 100%;
            text-align: center;
        }

        .table-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .modal-content {
            margin: 10% auto;
            padding: 20px;
            width: 95%;
        }

        .schedule-item {
            flex-wrap: wrap;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
// ============================================================
// SCHEDULE VIEW MODAL
// ============================================================

function viewSchedule(studentId) {
    var modal   = document.getElementById('scheduleModal');
    var content = document.getElementById('scheduleContent');
    content.innerHTML = '<div class="loading-text">Loading schedule...</div>';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    // Resolve relative to the app root, not the current path
    var root = window.location.origin
        + window.location.pathname.replace(/\/[^\/]*$/, '');
    var url = root + '/api/get_student_schedule.php?student_id=' + encodeURIComponent(studentId);

    fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.data && data.data.grouped) {
                renderSchedule(data.data.grouped);
            } else {
                content.innerHTML = '<div class="alert alert-info">No schedule found for this student.</div>';
            }
        })
        .catch(function (err) {
            console.error('Error loading schedule:', err);
            content.innerHTML = '<div class="alert alert-danger">Error loading schedule. Please try again.</div>';
        });
}

function renderSchedule(groupedSchedule) {
    var content = document.getElementById('scheduleContent');
    var html = '';
    var totalSubjects = 0;

    var dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    dayOrder.forEach(function (day) {
        if (!groupedSchedule[day] || groupedSchedule[day].length === 0) return;

        html += '<div class="schedule-day"><h3>' + day + '</h3>';

        groupedSchedule[day].sort(function (a, b) {
            return (a.start_time || '00:00:00').localeCompare(b.start_time || '00:00:00');
        });

        groupedSchedule[day].forEach(function (item) {
            totalSubjects++;
            html += '<div class="schedule-item">';
            html += '<span class="time">'
                 +  (item.start_time ? String(item.start_time).substring(0, 5) : '--:--')
                 +  ' - '
                 +  (item.end_time   ? String(item.end_time).substring(0, 5)   : '--:--')
                 +  '</span>';
            html += '<span class="subject"><strong>' + (item.subject_code || '') + '</strong> - ' + (item.subject_name || '') + '</span>';

            // FIX: use faculty_name (get_student_schedule.php returns this)
            var faculty = item.faculty_name
                       || (item.faculty_first ? (item.faculty_first + ' ' + (item.faculty_last || '')) : '');
            if (faculty) {
                html += '<span class="faculty">👨‍🏫 ' + faculty + '</span>';
            }

            // Room (get_student_schedule.php returns room_code / room_name)
            if (item.room_code) {
                html += '<span class="room">🏫 ' + item.room_code + '</span>';
            } else if (item.room_name) {
                html += '<span class="room">🏫 ' + item.room_name + '</span>';
            }

            html += '</div>';
        });

        html += '</div>';
    });

    if (totalSubjects === 0) {
        html = '<div class="no-schedule">📅 No scheduled subjects found.</div>';
    }

    content.innerHTML = html;
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

window.addEventListener('click', function (e) {
    var scheduleModal = document.getElementById('scheduleModal');
    if (e.target === scheduleModal) closeScheduleModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeScheduleModal();
});
</script>