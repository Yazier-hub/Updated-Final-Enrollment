<?php
// pages/archived-students.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • onsubmit="confirmRestore(...)" now uses htmlspecialchars(json_encode(...))
//     instead of addslashes() — prevents attribute-breakage XSS
//   • is_array() guard on $archivedStudents
//   • Null-safe reads throughout
//   • Renamed search input ID to match a single canonical name
//   • FIX: includes now use __DIR__ (matches applications.php pattern)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath = dirname(__DIR__);

require_once $basePath . '/classes/Enrollment.php';
require_once $basePath . '/classes/EnrollmentController.php';
require_once $basePath . '/classes/Student.php';
require_once $basePath . '/classes/Course.php';
require_once $basePath . '/classes/Section.php';
require_once $basePath . '/classes/Database.php';

$controller      = new EnrollmentController();
$enrollmentModel = new Enrollment();
$courseModel     = new Course();
$sectionModel    = new Section();

// ============================================================
// POST (restore, etc.)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->handlePostRequest();
}

// ============================================================
// Filters
// ============================================================
$search       = $_GET['search']     ?? '';
$courseFilter = isset($_GET['course_id'])  ? (int) $_GET['course_id']  : '';
$yearFilter   = isset($_GET['year_level']) ? (int) $_GET['year_level'] : '';
$reasonFilter = $_GET['reason']     ?? '';

// ============================================================
// Data
// ============================================================
$archivedStudents = $enrollmentModel->getArchivedStudents($courseFilter, $yearFilter);
if (!is_array($archivedStudents)) $archivedStudents = [];

// Search filter
if ($search !== '') {
    $searchLower = strtolower($search);
    $archivedStudents = array_filter($archivedStudents, function ($student) use ($searchLower) {
        $fullName = strtolower(trim(
            ($student['first_name'] ?? '')
            . ' ' . ($student['middle_name'] ?? '')
            . ' ' . ($student['surname'] ?? '')
            . ' ' . ($student['suffix'] ?? '')
        ));
        $studentNumber = strtolower($student['student_number'] ?? '');
        $email         = strtolower($student['email'] ?? '');

        return strpos($fullName,     $searchLower) !== false
            || strpos($studentNumber, $searchLower) !== false
            || strpos($email,         $searchLower) !== false;
    });
}

// Reason filter
if ($reasonFilter !== '') {
    $archivedStudents = array_filter($archivedStudents, function ($student) use ($reasonFilter) {
        return ($student['archive_reason'] ?? '') === $reasonFilter;
    });
}

$archivedStudents = array_values($archivedStudents);

$courses = $courseModel->findAll();
if (!is_array($courses)) $courses = [];

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ============================================================
// Stats
// ============================================================
$totalArchived = count($archivedStudents);
$reasonCounts  = [
    'Dropped'     => 0,
    'Transferred' => 0,
    'LOA'         => 0,
    'Graduated'   => 0,
    'Other'       => 0
];

foreach ($archivedStudents as $s) {
    $reason = $s['archive_reason'] ?? 'Other';
    if (isset($reasonCounts[$reason])) {
        $reasonCounts[$reason]++;
    } else {
        $reasonCounts['Other']++;
    }
}

$pageTitle = 'Archived Students';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>📦 Archived Students</h1>
            <p class="dashboard-subtitle">Listahan ng archived students — pwedeng i-restore</p>
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
                <div class="stat-card warning">
                    <div class="number"><?php echo $totalArchived; ?></div>
                    <div class="label">📦 Total Archived</div>
                </div>
                <div class="stat-card danger">
                    <div class="number"><?php echo (int) $reasonCounts['Dropped']; ?></div>
                    <div class="label">❌ Dropped</div>
                </div>
                <div class="stat-card info">
                    <div class="number"><?php echo (int) $reasonCounts['Transferred']; ?></div>
                    <div class="label">🔄 Transferred</div>
                </div>
                <div class="stat-card primary">
                    <div class="number"><?php echo (int) $reasonCounts['LOA']; ?></div>
                    <div class="label">⏸️ LOA</div>
                </div>
                <div class="stat-card success">
                    <div class="number"><?php echo (int) $reasonCounts['Graduated']; ?></div>
                    <div class="label">🎓 Graduated</div>
                </div>
            </div>



            <!-- SEARCH & FILTERS -->
            <div class="filters-section">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="page" value="archived-students">

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
                        <label for="year_filter">Year Level</label>
                        <select name="year_level" id="year_filter" onchange="this.form.submit()">
                            <option value="">All Years</option>
                            <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'] as $y => $lbl): ?>
                                <option value="<?php echo $y; ?>" <?php echo $yearFilter == $y ? 'selected' : ''; ?>>
                                    <?php echo $lbl; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="reason_filter">Reason</label>
                        <select name="reason" id="reason_filter" onchange="this.form.submit()">
                            <option value="">All Reasons</option>
                            <?php foreach ([
                                'Dropped'     => 'Dropped',
                                'Transferred' => 'Transferred',
                                'LOA'         => 'Leave of Absence',
                                'Graduated'   => 'Graduated',
                                'Other'       => 'Other'
                            ] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>"
                                    <?php echo $reasonFilter === $val ? 'selected' : ''; ?>>
                                    <?php echo $lbl; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">🔍 Search</button>
                        <?php if ($search || $courseFilter || $yearFilter || $reasonFilter): ?>
                            <a href="?page=archived-students" class="btn btn-secondary">✖ Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- ARCHIVED STUDENTS TABLE -->
            <div class="enrollments-table-container">
                <div class="table-header">
                    <h2 style="margin:0;">📦 Archived Students (<?php echo $totalArchived; ?>)</h2>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="?page=enrollments" class="btn btn-sm btn-info">← Back to Enrollments</a>
                        <a href="?page=students" class="btn btn-sm btn-secondary">All Students</a>
                    </div>
                </div>

                <?php if (count($archivedStudents) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student #</th>
                            <th>Name</th>
                            <th>Section</th>
                            <th>Course</th>
                            <th>Year</th>
                            <th>Archived At</th>
                            <th>Reason</th>
                            <th>Archived By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivedStudents as $s):
                            $studentId = (int) ($s['student_id'] ?? 0);
                            $fullName  = trim(
                                ($s['first_name'] ?? '')
                                . ' ' . ($s['middle_name'] ?? '')
                                . ' ' . ($s['surname'] ?? '')
                                . ' ' . ($s['suffix'] ?? '')
                            );

                            $archivedDate = !empty($s['archived_at'])
                                ? date('M d, Y', strtotime($s['archived_at']))
                                : 'N/A';
                            $archivedTime = !empty($s['archived_at'])
                                ? date('h:i A', strtotime($s['archived_at']))
                                : '';

                            $reason = $s['archive_reason'] ?? 'Other';
                            switch ($reason) {
                                case 'Dropped':     $reasonBadge = 'badge-danger';    $reasonIcon = '❌'; break;
                                case 'Transferred': $reasonBadge = 'badge-warning';   $reasonIcon = '🔄'; break;
                                case 'LOA':         $reasonBadge = 'badge-info';      $reasonIcon = '⏸️'; break;
                                case 'Graduated':   $reasonBadge = 'badge-success';   $reasonIcon = '🎓'; break;
                                default:            $reasonBadge = 'badge-secondary'; $reasonIcon = '📦'; break;
                            }

                            $yearLevelText = 'N/A';
                            if (!empty($s['grade_level'])) {
                                $yearLevelText = $s['grade_level'];
                            } elseif (!empty($s['year_level'])) {
                                $yearLevelText = (int) $s['year_level'] . 'th Year';
                            }
                        ?>
                        <tr>
                            <td><strong>🎓 <?php echo htmlspecialchars($s['student_number'] ?? ''); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($fullName); ?>
                                <?php if (!empty($s['email'])): ?>
                                    <br><small style="color:#666;">✉️ <?php echo htmlspecialchars($s['email']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($s['section_code'])): ?>
                                    <?php echo htmlspecialchars($s['section_code']); ?>
                                    <br><small style="color:#666;"><?php echo htmlspecialchars($s['section_semester'] ?? ''); ?></small>
                                <?php else: ?>
                                    <em style="color:#999;">Not Assigned</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($s['course_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($yearLevelText); ?></td>
                            <td>
                                <?php echo htmlspecialchars($archivedDate); ?>
                                <?php if ($archivedTime): ?>
                                    <br><small style="color:#666;"><?php echo htmlspecialchars($archivedTime); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo htmlspecialchars($reasonBadge); ?>">
                                    <?php echo $reasonIcon; ?> <?php echo htmlspecialchars($reason); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($s['archived_by_name'])): ?>
                                    <?php echo htmlspecialchars($s['archived_by_name']); ?>
                                <?php elseif (!empty($s['archived_by_username'])): ?>
                                    <?php echo htmlspecialchars($s['archived_by_username']); ?>
                                <?php else: ?>
                                    <em style="color:#999;">System</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="?page=student-details&id=<?php echo $studentId; ?>"
                                       class="btn btn-sm btn-info" title="View student details">
                                        👁️ View
                                    </a>
                                    <form method="POST"
                                          style="display:inline-block;"
                                          onsubmit="return confirmRestore(
                                              <?php echo htmlspecialchars(json_encode($fullName), ENT_QUOTES); ?>,
                                              <?php echo htmlspecialchars(json_encode($s['student_number'] ?? ''), ENT_QUOTES); ?>
                                          )">
                                        <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="redirect" value="?page=archived-students">
                                        <button type="submit" class="btn btn-sm btn-success" title="Restore student">
                                            ♻️ Restore
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="text-center">
                        <div style="font-size:64px;margin-bottom:15px;">📦</div>
                        <h3 style="color:#666;margin-bottom:10px;">
                            <?php if ($search || $courseFilter || $yearFilter || $reasonFilter): ?>
                                Walang nakitang archived students
                            <?php else: ?>
                                Walang archived students
                            <?php endif; ?>
                        </h3>
                        <p style="color:#999;margin-bottom:20px;">
                            <?php if ($search || $courseFilter || $yearFilter || $reasonFilter): ?>
                                Walang match sa iyong filters. Subukan i-clear ang filters.
                            <?php else: ?>
                                Kapag nag-archive ka ng student, lalabas sila dito.
                            <?php endif; ?>
                        </p>
                        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                            <?php if ($search || $courseFilter || $yearFilter || $reasonFilter): ?>
                                <a href="?page=archived-students" class="btn btn-secondary">Clear Filters</a>
                            <?php endif; ?>
                            <a href="?page=enrollments" class="btn btn-primary">← Back to Enrollments</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       ARCHIVED STUDENTS — Blue & Sky Blue Theme
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
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
        display: block;
        font-size: 28px;
        font-weight: 700;
        color: var(--navy);
    }

    .stat-card .label {
        display: block;
        font-size: 13px;
        color: var(--sky-muted);
        margin-top: 5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Stat accent variations — all blue tones */
    .stat-card.primary   { border-top-color: var(--navy); }
    .stat-card.primary .number   { color: var(--navy); }

    .stat-card.success   { border-top-color: var(--blue); }
    .stat-card.success .number   { color: var(--blue); }

    .stat-card.warning   { border-top-color: var(--sky); }
    .stat-card.warning .number   { color: var(--sky); }

    .stat-card.danger    { border-top-color: var(--navy-dark); }
    .stat-card.danger .number    { color: var(--navy-dark); }

    .stat-card.info      { border-top-color: var(--sky-light); }
    .stat-card.info .number      { color: var(--sky-light); }

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

    /* ---------- FILTERS SECTION ---------- */
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
    .enrollments-table-container {
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

    .table-header h2 {
        color: var(--navy);
        font-size: 18px;
        margin: 0;
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

    .table tbody tr small {
        color: var(--sky-muted);
        font-size: 12px;
    }

    .table tbody tr em {
        color: var(--sky-muted);
        font-style: italic;
    }

    /* ---------- BADGES ---------- */
    .badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Reason badges — differentiated by blue shade intensity */
    .badge-success   { background: var(--blue);      color: white; }   /* Graduated */
    .badge-secondary { background: var(--sky-muted); color: white; }   /* Other */
    .badge-warning   { background: var(--sky);       color: white; }   /* Transferred */
    .badge-danger    { background: var(--navy-dark); color: white; }   /* Dropped */
    .badge-info      { background: var(--sky-light); color: white; }   /* LOA */

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

    /* ---------- ACTION BUTTONS ---------- */
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    /* ---------- EMPTY STATE ---------- */
    .text-center {
        text-align: center;
        padding: 40px 20px;
        color: var(--sky-muted);
    }

    .text-center h3 {
        color: var(--navy);
        margin-bottom: 10px;
    }

    .text-center p {
        color: var(--sky-muted);
        margin-bottom: 20px;
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
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
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
/**
 * Confirm restore with student details.
 */
function confirmRestore(studentName, studentNumber) {
    var message = '♻️ RESTORE STUDENT\n\n'
                + 'Student: '   + studentName   + '\n'
                + 'Student #: ' + studentNumber + '\n\n'
                + '⚠️ Kapag na-restore:\n'
                + '  • Ibabalik ang enrollment status sa "Enrolled"\n'
                + '  • Ibabalik ang lahat ng subjects\n'
                + '  • Ma-clear ang archive record\n\n'
                + 'Sigurado ka bang gusto mong i-restore ang student na ito?';
    return confirm(message);
}

// Keyboard shortcut: Focus search input on '/' key
document.addEventListener('keydown', function (e) {
    if (e.key === '/'
        && e.target.tagName !== 'INPUT'
        && e.target.tagName !== 'TEXTAREA') {
        e.preventDefault();
        var searchInput = document.getElementById('search_input');
        if (searchInput) searchInput.focus();
    }
});

// Highlight rows with Graduated status
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.table tbody tr').forEach(function (row) {
        var badge = row.querySelector('.badge-success');
        if (badge && badge.textContent.includes('Graduated')) {
            row.style.background = '#f0fdf0';
        }
    });
});
</script>