<?php
// pages/semesters.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • rgr_school_years uses `name`, NOT `school_year`
//   • rgr_semesters uses `name`, NOT `semester_name` / `semester_code`
//   • Removed references to non-existent start_date / end_date columns
//   • Joins semesters → school_years to show which SY each semester belongs to
//   • Escaped values in onclick attributes
//   • is_array() guards on all fetchAll()/fetch() results
//   • FIX: includes now use __DIR__ instead of $basePath

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath = dirname(__DIR__);
require_once $basePath . '/classes/Database.php';

$db = Database::getInstance();

// ============================================================
// All school years — FIX: column is `name`
// ============================================================
$syStmt = $db->prepare("
    SELECT id, name AS school_year, is_active, created_at, updated_at
    FROM rgr_school_years
    ORDER BY name DESC
");
$syStmt->execute();
$schoolYears = $syStmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($schoolYears)) $schoolYears = [];

// ============================================================
// All semesters — FIX: column is `name`; join to SY for context
// ============================================================
$semStmt = $db->prepare("
    SELECT s.id,
           s.name AS semester_name,
           s.school_year_id,
           s.is_active,
           s.created_at,
           s.updated_at,
           sy.name AS school_year_name
    FROM rgr_semesters s
    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
    ORDER BY sy.name DESC, s.id ASC
");
$semStmt->execute();
$semesters = $semStmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($semesters)) $semesters = [];

// ============================================================
// Current active school year — FIX: column is `name`
// ============================================================
$activeSyStmt = $db->prepare("
    SELECT id, name AS school_year, is_active
    FROM rgr_school_years
    WHERE is_active = 1
    LIMIT 1
");
$activeSyStmt->execute();
$activeSchoolYear = $activeSyStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// Current active semester — FIX: column is `name`
// ============================================================
$activeSemStmt = $db->prepare("
    SELECT s.id, s.name AS semester_name, s.is_active, sy.name AS school_year_name
    FROM rgr_semesters s
    LEFT JOIN rgr_school_years sy ON s.school_year_id = sy.id
    WHERE s.is_active = 1
    LIMIT 1
");
$activeSemStmt->execute();
$activeSemester = $activeSemStmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Semester Management';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>📅 Semester Management</h1>
            <p class="dashboard-subtitle">I-set kung aling semester at school year ang active ngayon</p>
        </div>

        <div class="module-content">
            <!-- MESSAGES -->
            <div id="messageBox" style="display:none;"></div>

            <!-- CURRENT ACTIVE INFO -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="number" id="activeSyDisplay">
                        <?php echo $activeSchoolYear
                            ? htmlspecialchars($activeSchoolYear['school_year'] ?? 'N/A')
                            : 'N/A'; ?>
                    </div>
                    <div class="label">Active School Year</div>
                </div>
                <div class="stat-card success">
                    <div class="number" id="activeSemDisplay">
                        <?php echo $activeSemester
                            ? htmlspecialchars($activeSemester['semester_name'] ?? 'N/A')
                            : 'N/A'; ?>
                    </div>
                    <div class="label">Active Semester</div>
                    <?php if ($activeSemester && !empty($activeSemester['school_year_name'])): ?>
                        <div class="sub-info">
                            SY: <?php echo htmlspecialchars($activeSemester['school_year_name']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SCHOOL YEARS -->
            <div class="table-container">
                <h2>📆 School Years</h2>
                <p class="section-note">I-click ang "Set Active" para gawing current school year.</p>

                <?php if (count($schoolYears) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>School Year</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schoolYears as $sy):
                            $syId      = (int) ($sy['id'] ?? 0);
                            $syName    = $sy['school_year'] ?? '';
                            $isActive  = !empty($sy['is_active']);
                        ?>
                        <tr>
                            <td><?php echo $syId; ?></td>
                            <td><strong><?php echo htmlspecialchars($syName); ?></strong></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-success">✅ Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$isActive): ?>
                                    <button class="btn btn-sm btn-success"
                                            onclick="setActiveSchoolYear(
                                                <?php echo $syId; ?>,
                                                <?php echo htmlspecialchars(json_encode($syName), ENT_QUOTES); ?>
                                            )">
                                        ✅ Set Active
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-secondary" disabled>Current</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="alert alert-info">No school years found in the database.</div>
                <?php endif; ?>
            </div>

            <!-- SEMESTERS -->
            <div class="table-container" style="margin-top:20px;">
                <h2>📅 Semesters</h2>
                <p class="section-note">I-click ang "Set Active" para gawing current semester.</p>

                <?php if (count($semesters) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Semester</th>
                            <th>School Year</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($semesters as $sem):
                            $semId      = (int) ($sem['id'] ?? 0);
                            $semName    = $sem['semester_name'] ?? '';
                            $syName     = $sem['school_year_name'] ?? '—';
                            $isActive   = !empty($sem['is_active']);
                        ?>
                        <tr>
                            <td><?php echo $semId; ?></td>
                            <td><strong><?php echo htmlspecialchars($semName); ?></strong></td>
                            <td><?php echo htmlspecialchars($syName); ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-success">✅ Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$isActive): ?>
                                    <button class="btn btn-sm btn-success"
                                            onclick="setActiveSemester(
                                                <?php echo $semId; ?>,
                                                <?php echo htmlspecialchars(json_encode($semName), ENT_QUOTES); ?>
                                            )">
                                        ✅ Set Active
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-secondary" disabled>Current</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="alert alert-info">No semesters found in the database.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       SEMESTER MANAGEMENT — Blue & Sky Blue Theme
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
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        font-size: 24px;
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

    .stat-card.info { border-top-color: var(--sky); }
    .stat-card.info .number { color: var(--sky); }

    .stat-card .sub-info {
        font-size: 12px;
        color: var(--sky-muted);
        margin-top: 3px;
    }

    /* ---------- TABLE CONTAINER ---------- */
    .table-container {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
    }

    .table-container h2 {
        margin-bottom: 10px;
        color: var(--navy);
        font-size: 18px;
    }

    .section-note {
        color: var(--sky-muted);
        font-size: 13px;
        margin-bottom: 15px;
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

    .badge-success {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .badge-secondary {
        background: var(--sky-bg);
        color: var(--sky-muted);
        border: 1px solid var(--sky-border);
    }

    /* ---------- BUTTONS ---------- */
    .btn {
        padding: 8px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        text-decoration: none;
        display: inline-block;
        font-weight: 500;
        font-family: inherit;
        transition: all 0.2s ease;
        line-height: 1.4;
    }

    .btn:hover:not(:disabled) {
        transform: translateY(-1px);
    }

    .btn-sm {
        padding: 4px 10px;
        font-size: 12px;
    }

    .btn-success {
        background: var(--blue);
        color: white;
    }
    .btn-success:hover:not(:disabled) {
        background: var(--navy);
    }

    .btn-secondary {
        background: var(--sky-muted);
        color: white;
    }
    .btn-secondary:hover:not(:disabled) {
        background: var(--blue);
    }
    .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

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

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .table {
            font-size: 12px;
        }

        .table th,
        .table td {
            padding: 8px;
        }
    }
</style>
<script>
function setActiveSemester(semesterId, semesterName) {
    if (!confirm('Gawing active ang "' + semesterName + '"?\n\nAwtomatikong ma-deactivate ang ibang semesters.')) {
        return;
    }

    var formData = new FormData();
    formData.append('semester_id', semesterId);

    fetch('api/set_active_semester.php', {
        method: 'POST',
        body: formData
    })
    .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(function (data) {
        if (data.success) {
            showMessage('✅ ' + data.message, 'success');
            setTimeout(function () { window.location.reload(); }, 1500);
        } else {
            showMessage('❌ ' + data.message, 'danger');
        }
    })
    .catch(function (err) {
        console.error('Error:', err);
        showMessage('❌ Error: ' + err.message, 'danger');
    });
}

function setActiveSchoolYear(syId, syName) {
    if (!confirm('Gawing active ang School Year "' + syName + '"?\n\nAwtomatikong ma-deactivate ang ibang school years.')) {
        return;
    }

    var formData = new FormData();
    formData.append('school_year_id', syId);

    fetch('api/set_active_school_year.php', {
        method: 'POST',
        body: formData
    })
    .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    })
    .then(function (data) {
        if (data.success) {
            showMessage('✅ ' + data.message, 'success');
            setTimeout(function () { window.location.reload(); }, 1500);
        } else {
            showMessage('❌ ' + data.message, 'danger');
        }
    })
    .catch(function (err) {
        console.error('Error:', err);
        showMessage('❌ Error: ' + err.message, 'danger');
    });
}

function showMessage(message, type) {
    var box = document.getElementById('messageBox');
    box.className = 'alert alert-' + type;
    box.textContent = message;
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>