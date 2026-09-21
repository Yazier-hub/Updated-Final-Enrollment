<?php
// pages/sections.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • Removed all reads of $sec['current_students'] / ['max_students']
//     (those columns don't exist in kms)
//   • Computed capacity on the fly from enr_enrollments
//   • Removed nested <form> inside bulk-action <form> (invalid HTML)
//   • Replaced window.onclick with addEventListener
//   • Null-safe reads throughout
//   • View + Delete buttons and bulk actions removed

require_once 'classes/Section.php';
require_once 'classes/Course.php';
require_once 'classes/SectionController.php';

$controller = new SectionController();
$controller->handleRequest();

$db = Database::getInstance();

// ============================================================
// Load sections with COMPUTED capacity (no cc_sections columns)
// ============================================================
$sections = $controller->getSectionsWithCapacityStatus();
if (!is_array($sections)) $sections = [];

// Compute actual enrolled-student count per section in ONE query,
// then attach it as `current_students` for the UI.
$sectionIds = array_values(array_filter(
    array_map(fn($s) => (int) ($s['id'] ?? 0), $sections),
    fn($id) => $id > 0
));

$countBySection = [];
if (!empty($sectionIds)) {
    try {
        $ph  = implode(',', array_fill(0, count($sectionIds), '?'));
        $sql = "SELECT section_id, COUNT(DISTINCT student_id) AS cnt
                FROM enr_enrollments
                WHERE section_id IN ($ph)
                  AND enrollment_status = 'enrolled'
                GROUP BY section_id";
        $stmt = $db->prepare($sql);
        $stmt->execute($sectionIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $countBySection[(int) $row['section_id']] = (int) $row['cnt'];
        }
    } catch (Exception $e) {
        error_log('sections.php: enrollment count fetch failed: ' . $e->getMessage());
    }
}

// Default per-section capacity (no DB column)
$DEFAULT_CAPACITY = 40;

foreach ($sections as &$sec) {
    $sid = (int) ($sec['id'] ?? 0);
    $sec['current_students'] = $countBySection[$sid] ?? 0;
    $sec['max_students']     = $DEFAULT_CAPACITY;
}
unset($sec);

// ============================================================
// Courses / stats
// ============================================================
$courses = $controller->getCourses();
if (!is_array($courses)) $courses = [];

$stats = $controller->getSectionStats();
if (!is_array($stats)) $stats = [];

// Compute total enrolled students from $sections (avoid relying on
// Section::getSectionStats() which uses cc_sections capacity columns)
$totalStudents = 0;
foreach ($sections as $s) {
    $totalStudents += (int) ($s['current_students'] ?? 0);
}
$stats['total_students'] = $totalStudents;

// Full sections = those at or above default capacity
$fullSections = array_values(array_filter($sections, function ($s) {
    return (int) $s['current_students'] >= (int) $s['max_students'];
}));

// ============================================================
// Filters
// ============================================================
$statusFilter = $_GET['status']     ?? 'all';
$courseFilter = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;

$filteredSections = $sections;

if ($statusFilter === 'available') {
    $filteredSections = array_filter($filteredSections, function ($s) {
        return (int) $s['current_students'] < (int) $s['max_students'];
    });
} elseif ($statusFilter === 'full') {
    $filteredSections = array_filter($filteredSections, function ($s) {
        return (int) $s['current_students'] >= (int) $s['max_students'];
    });
}

if ($courseFilter > 0) {
    $filteredSections = array_filter($filteredSections, function ($s) use ($courseFilter) {
        return (int) ($s['program_id'] ?? 0) === $courseFilter;
    });
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$pageTitle = 'Sections Management';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>📚 Sections Management</h1>
            <p class="dashboard-subtitle">Manage academic sections</p>
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
                    <div class="number"><?php echo (int) ($stats['total_sections'] ?? count($sections)); ?></div>
                    <div class="label">📚 Total Sections</div>
                </div>
                <div class="stat-card success">
                    <div class="number"><?php echo (int) ($stats['total_students'] ?? 0); ?></div>
                    <div class="label">👨‍🎓 Enrolled Students</div>
                </div>
                <div class="stat-card info">
                    <div class="number">
                        <?php
                            $totalSlots = 0;
                            foreach ($sections as $s) {
                                $totalSlots += max(0, (int) $s['max_students'] - (int) $s['current_students']);
                            }
                            echo $totalSlots;
                        ?>
                    </div>
                    <div class="label">📊 Available Slots</div>
                </div>
                <div class="stat-card danger">
                    <div class="number">
                        <?php
                            $totalCap = count($sections) * $DEFAULT_CAPACITY;
                            $used     = (int) ($stats['total_students'] ?? 0);
                            echo $totalCap > 0 ? round(($used / $totalCap) * 100) : 0;
                        ?>%
                    </div>
                    <div class="label">📈 Average Utilization</div>
                </div>
            </div>

            <!-- WARNINGS -->
            <?php if (count($fullSections) > 0): ?>
                <div class="alert alert-warning">
                    <strong>⚠️ <?php echo count($fullSections); ?> section(s) are at full capacity!</strong>
                    <a href="#full-sections">View full sections</a>
                </div>
            <?php endif; ?>

            <!-- FILTERS -->
            <div class="filters-section">
                <form method="GET" style="display:flex;gap:15px;flex-wrap:wrap;align-items:flex-end;width:100%;">
                    <input type="hidden" name="page" value="sections">

                    <div class="filter-group">
                        <label for="status_filter">Status</label>
                        <select name="status" id="status_filter" onchange="this.form.submit()">
                            <option value="all"       <?php echo $statusFilter === 'all'       ? 'selected' : ''; ?>>All Sections</option>
                            <option value="available" <?php echo $statusFilter === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="full"      <?php echo $statusFilter === 'full'      ? 'selected' : ''; ?>>Full</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="course_filter">Course</label>
                        <select name="course_id" id="course_filter" onchange="this.form.submit()">
                            <option value="0">All Courses</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>"
                                    <?php echo $courseFilter == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($c['code'] ?? '') . ' - ' . ($c['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <?php if ($statusFilter !== 'all' || $courseFilter > 0): ?>
                            <a href="?page=sections" class="btn btn-secondary btn-sm">Clear Filters</a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-primary" onclick="showAddSection()">+ Add Section</button>
                    </div>
                </form>
            </div>

            <!-- SECTIONS TABLE -->
            <div class="sections-table-container">
                <div class="table-header">
                    <span class="title">
                        Sections
                        <?php if ($statusFilter !== 'all'): ?>
                            (<?php echo htmlspecialchars(ucfirst($statusFilter)); ?>)
                        <?php endif; ?>
                        <?php if ($courseFilter > 0): ?>
                            <?php
                                $courseName = '';
                                foreach ($courses as $c) {
                                    if ((int) $c['id'] === $courseFilter) { $courseName = $c['code'] ?? ''; break; }
                                }
                                echo ' - ' . htmlspecialchars($courseName);
                            ?>
                        <?php endif; ?>
                    </span>
                    <span class="badge-count"><?php echo count($filteredSections); ?></span>
                </div>

                <?php if (count($filteredSections) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Course</th>
                                <th>Grade Level</th>
                                <th>Semester</th>
                                <th>Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filteredSections as $sec):
                                $current   = (int) ($sec['current_students'] ?? 0);
                                $max       = max(1, (int) ($sec['max_students'] ?? $DEFAULT_CAPACITY));
                                $utilization = $max > 0 ? round(($current / $max) * 100) : 0;

                                if ($utilization >= 100)     { $capClass = 'capacity-full';       $capLabel = 'Full'; }
                                elseif ($utilization >= 80)  { $capClass = 'capacity-near-full';  $capLabel = 'Near Full'; }
                                elseif ($utilization >= 50)  { $capClass = 'capacity-half';       $capLabel = 'Half Full'; }
                                elseif ($utilization > 0)    { $capClass = 'capacity-available';  $capLabel = 'Available'; }
                                else                         { $capClass = 'capacity-empty';      $capLabel = 'Empty'; }

                                $fillColor = $utilization >= 100 ? '#0f2a4e'
                                           : ($utilization >= 80  ? '#4a90d9'
                                           : ($utilization >= 50  ? '#6aa8e0'
                                           : '#2a5c9e'));
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($sec['section_code'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars(
                                    ($sec['course_code'] ?? '') . ' - ' . ($sec['course_name'] ?? '')
                                ); ?></td>
                                <td><?php echo htmlspecialchars($sec['grade_level'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($sec['semester'] ?? ''); ?></td>
                                <td>
                                    <span class="capacity-status <?php echo htmlspecialchars($capClass); ?>">
                                        <?php echo $current; ?>/<?php echo $max; ?>
                                    </span>
                                    <div class="capacity-bar">
                                        <div class="capacity-fill"
                                             style="width:<?php echo $utilization; ?>%;background:<?php echo $fillColor; ?>">
                                        </div>
                                    </div>
                                    <small><?php echo htmlspecialchars($capLabel); ?> (<?php echo $utilization; ?>%)</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center">
                        <?php if ($statusFilter !== 'all' || $courseFilter > 0): ?>
                            No sections found matching the selected filters.
                            <a href="?page=sections">Clear filters</a>
                        <?php else: ?>
                            No sections found.
                            <a href="#" onclick="showAddSection(); return false;">Create one now</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- FULL SECTIONS -->
            <?php if (count($fullSections) > 0): ?>
            <div id="full-sections" style="margin-top:20px;background:white;border-radius:8px;padding:20px;box-shadow:0 2px 4px rgba(26,60,110,0.08);">
                <h3 style="color:#0f2a4e;margin-bottom:10px;">⚠️ Full Sections (<?php echo count($fullSections); ?>)</h3>
                <p class="text-muted">These sections have reached maximum capacity (<?php echo $DEFAULT_CAPACITY; ?> students). Consider adding new sections.</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px;margin-top:10px;">
                    <?php foreach ($fullSections as $fs): ?>
                    <div style="background:#f0f7ff;padding:10px 15px;border-radius:6px;border-left:4px solid #0f2a4e;">
                        <strong><?php echo htmlspecialchars($fs['section_code'] ?? ''); ?></strong>
                        <span style="color:#5a7fa8;font-size:13px;"><?php echo htmlspecialchars($fs['course_code'] ?? ''); ?></span>
                        <div style="font-size:13px;color:#0f2a4e;">
                            <?php echo (int) $fs['current_students']; ?>/<?php echo (int) $fs['max_students']; ?> students
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- ADD SECTION MODAL -->
<div id="addSectionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>➕ Add New Section</h2>
            <span class="close" onclick="hideAddSection()">&times;</span>
        </div>
        <form method="POST" class="section-form">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="redirect" value="?page=sections">

            <div class="form-group">
                <label>Course <span class="required">*</span></label>
                <select name="program_id" required>
                    <option value="">Select Course</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>">
                            <?php echo htmlspecialchars(($c['code'] ?? '') . ' - ' . ($c['name'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Year Level <span class="required">*</span></label>
                <select name="year_level" required>
                    <option value="1">1st Year</option>
                    <option value="2">2nd Year</option>
                    <option value="3">3rd Year</option>
                    <option value="4">4th Year</option>
                </select>
            </div>
            <div class="form-group">
                <label>Semester <span class="required">*</span></label>
                <select name="semester" required>
                    <option value="1st Semester">1st Semester</option>
                    <option value="2nd Semester">2nd Semester</option>
                </select>
            </div>
            <div class="form-group">
                <label>Section Number <span class="required">*</span></label>
                <input type="number" name="section_number" min="1" max="99" required>
                <small>Example: 1 = Section 1, 2 = Section 2, etc.</small>
            </div>
            <div class="form-group">
                <label>School Year <span class="required">*</span></label>
                <input type="text" name="school_year"
                       value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" required>
                <small>Format: YYYY-YYYY (e.g., 2025-2026)</small>
            </div>
            <!-- NOTE: no max_students field — column doesn't exist in kms -->
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="hideAddSection()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Section</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       SECTIONS MANAGEMENT — Blue & Sky Blue Theme
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

    /* ---------- STATS ---------- */
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
    .stat-card.primary { border-top-color: var(--navy); }
    .stat-card.primary .number { color: var(--navy); }
    .stat-card.success { border-top-color: var(--blue); }
    .stat-card.success .number { color: var(--blue); }
    .stat-card.warning { border-top-color: var(--sky); }
    .stat-card.warning .number { color: var(--sky); }
    .stat-card.danger  { border-top-color: var(--navy-dark); }
    .stat-card.danger .number  { color: var(--navy-dark); }
    .stat-card.info    { border-top-color: var(--sky-light); }
    .stat-card.info .number    { color: var(--sky-light); }

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
    .alert a {
        color: var(--blue);
        font-weight: 600;
        text-decoration: underline;
    }

    /* ---------- FILTERS ---------- */
    .filters-section {
        background: white;
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
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
    .filter-group select {
        padding: 8px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        min-width: 150px;
        background: white;
        color: var(--navy);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .filter-group select:focus {
        border-color: var(--navy);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }
    .filter-actions {
        display: flex;
        gap: 10px;
        margin-left: auto;
        align-items: flex-end;
    }

    /* ---------- TABLE CONTAINER ---------- */
    .sections-table-container {
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

    /* ---------- CAPACITY ---------- */
    .capacity-bar {
        width: 100px;
        height: 8px;
        background: var(--sky-bg);
        border-radius: 4px;
        overflow: hidden;
        display: inline-block;
        vertical-align: middle;
        margin-right: 8px;
    }
    .capacity-fill {
        height: 100%;
        transition: width 0.3s ease;
    }

    .capacity-status {
        font-size: 12px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 12px;
        border: 1px solid transparent;
    }
    .capacity-full {
        background: #dce8f5;
        color: var(--navy-dark);
        border-color: var(--sky-pale);
    }
    .capacity-near-full {
        background: #e8f0fe;
        color: var(--blue);
        border-color: var(--sky-border);
    }
    .capacity-half {
        background: var(--sky-bg);
        color: var(--navy);
        border-color: var(--sky-border);
    }
    .capacity-available {
        background: #d4e8fc;
        color: var(--navy);
        border-color: var(--sky-pale);
    }
    .capacity-empty {
        background: var(--sky-bg-soft);
        color: var(--sky-muted);
        border-color: var(--sky-bg);
    }

    /* ---------- BUTTONS ---------- */
    .btn {
        padding: 6px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        text-decoration: none;
        display: inline-block;
        transition: all 0.2s ease;
        font-weight: 500;
        font-family: inherit;
        line-height: 1.4;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn-sm { padding: 4px 10px; font-size: 11px; }
    .btn-primary { background: var(--navy); color: white; }
    .btn-primary:hover { background: var(--blue); }
    .btn-success { background: var(--blue); color: white; }
    .btn-success:hover { background: var(--navy); }
    .btn-danger { background: var(--navy-dark); color: white; }
    .btn-danger:hover { background: var(--navy); }
    .btn-warning { background: var(--sky); color: white; }
    .btn-warning:hover { background: var(--blue-mid); }
    .btn-info { background: var(--sky-light); color: white; }
    .btn-info:hover { background: var(--sky); }
    .btn-secondary { background: var(--sky-muted); color: white; }
    .btn-secondary:hover { background: var(--blue); }

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
        max-width: 500px;
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
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
    }
    .modal-header h2 {
        color: var(--navy);
        font-size: 18px;
        margin: 0;
    }
    .close {
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        color: var(--sky-muted);
        transition: color 0.2s ease;
        line-height: 1;
    }
    .close:hover { color: var(--navy); }

    /* ---------- FORM ---------- */
    .section-form .form-group { margin-bottom: 15px; }
    .section-form label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: var(--navy);
        font-size: 13px;
    }
    .section-form label .required {
        color: var(--navy-dark);
        font-weight: 700;
    }
    .section-form input,
    .section-form select {
        width: 100%;
        padding: 8px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        color: var(--navy);
        background: white;
        box-sizing: border-box;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .section-form input:focus,
    .section-form select:focus {
        border-color: var(--navy);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }
    .section-form small {
        color: var(--sky-muted);
        font-size: 12px;
        display: block;
        margin-top: 4px;
    }
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    /* ---------- MISC ---------- */
    .text-center {
        text-align: center;
        padding: 30px 20px;
        color: var(--sky-muted);
    }
    .text-center a {
        color: var(--blue);
        font-weight: 600;
        text-decoration: underline;
        cursor: pointer;
    }
    .text-center a:hover { color: var(--navy); }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
        .modal-content { margin: 10% auto; padding: 20px; width: 95%; }
        .filters-section { flex-direction: column; align-items: stretch; }
        .filter-actions { margin-left: 0; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .table { font-size: 12px; }
        .table th,
        .table td { padding: 8px; }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
    }
</style>

<script>
function showAddSection() {
    document.getElementById('addSectionModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function hideAddSection() {
    document.getElementById('addSectionModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

window.addEventListener('click', function (event) {
    var modal = document.getElementById('addSectionModal');
    if (event.target === modal) hideAddSection();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') hideAddSection();
});

document.querySelector('.section-form')?.addEventListener('submit', function (e) {
    var programId     = this.querySelector('select[name="program_id"]').value;
    var sectionNumber = this.querySelector('input[name="section_number"]').value;
    var schoolYear    = this.querySelector('input[name="school_year"]').value;

    if (!programId) {
        e.preventDefault(); alert('⚠️ Please select a course.'); return false;
    }
    if (!sectionNumber || sectionNumber < 1) {
        e.preventDefault(); alert('⚠️ Please enter a valid section number.'); return false;
    }
    if (!schoolYear || !schoolYear.match(/^\d{4}-\d{4}$/)) {
        e.preventDefault(); alert('⚠️ Please enter a valid school year format (YYYY-YYYY).'); return false;
    }
});
</script>