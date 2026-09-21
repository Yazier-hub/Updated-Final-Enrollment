<?php
// pages/dashboard-overview.php - FULLY FIXED for `kms` schema
//
// FIXES IN THIS VERSION:
//   • Monthly-trend loop now matches by NAME ('Jan', 'Feb', ...) — the
//     EnrollmentReport::getMonthlyTrend() returns name strings, NOT integers.
//     Previously the loop did (int) $row['month'] which cast every value to 0
//     and skipped all rows → charts rendered empty.
//   • is_array() guards on all model returns
//   • Null-safe reads throughout

require_once 'classes/Enrollment.php';
require_once 'classes/Application.php';
require_once 'classes/Student.php';
require_once 'classes/Course.php';
require_once 'classes/Section.php';
require_once 'classes/Requirement.php';
require_once 'classes/EnrollmentReport.php';
require_once 'classes/ReportExporter.php';
require_once 'classes/Database.php';

// ============================================================
// EXPORT HANDLER
// ============================================================
if (isset($_GET['export'])) {
    $exporter = new ReportExporter();

    switch ($_GET['export']) {
        case 'analysis': $exporter->exportAnalysisReport(); break;
        case 'summary':  $exporter->exportSummary();        break;
        case 'students': $exporter->exportStudentList();    break;
        case 'archive':  $exporter->exportArchiveReport();  break;
    }
    exit;
}

// ============================================================
// INIT MODELS
// ============================================================
$enrollment  = new Enrollment();
$application = new Application();
$student     = new Student();
$course      = new Course();
$section     = new Section();
$requirement = new Requirement();
$report      = new EnrollmentReport();
$db          = Database::getInstance();

// ============================================================
// STATISTICS
// ============================================================
$totalStudents = $student->getStudentCount();

try {
    $stmt = $db->prepare("SELECT COUNT(*) AS count FROM enr_enrollments WHERE enrollment_status = 'enrolled'");
    $stmt->execute();
    $totalSubjectEnrollments = (int) ($stmt->fetch()['count'] ?? 0);
} catch (Exception $e) {
    $totalSubjectEnrollments = 0;
}

try {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT student_id) AS count FROM enr_enrollments WHERE enrollment_status = 'enrolled'");
    $stmt->execute();
    $activeEnrollments = (int) ($stmt->fetch()['count'] ?? 0);
} catch (Exception $e) {
    $activeEnrollments = 0;
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) AS count FROM enr_students WHERE archived_at IS NOT NULL AND status = 'inactive'");
    $stmt->execute();
    $archivedCount = (int) ($stmt->fetch()['count'] ?? 0);
} catch (Exception $e) {
    $archivedCount = 0;
}

$pendingApps = $application->getPendingApplications();
if (!is_array($pendingApps)) $pendingApps = [];
$pendingCount = count($pendingApps);

$allApplications = $application->getAllApplications();
if (!is_array($allApplications)) $allApplications = [];
$totalApplications = count($allApplications);

$sectionStats = $section->getSectionStats();
if (!is_array($sectionStats)) {
    $sectionStats = ['total_sections' => 0, 'available_slots' => 0];
}

// ============================================================
// REPORT DATA
// ============================================================
$reportSummary = $report->getSummaryStats();
if (!is_array($reportSummary)) $reportSummary = [];

$enrollmentByCourse = $report->getEnrollmentByCourse();
if (!is_array($enrollmentByCourse)) $enrollmentByCourse = [];

$enrollmentByYear = $report->getEnrollmentByYearLevel();
if (!is_array($enrollmentByYear)) $enrollmentByYear = [];

$monthlyTrend = $report->getMonthlyTrend();
if (!is_array($monthlyTrend)) $monthlyTrend = [];

$predictive = $report->getPredictiveAnalytics();
if (!is_array($predictive)) $predictive = [];

$recentEnrollments = $report->getRecentEnrollments(5);
if (!is_array($recentEnrollments)) $recentEnrollments = [];

$studentsWithIncomplete = $report->getStudentsWithIncompleteRequirements();
if (!is_array($studentsWithIncomplete)) $studentsWithIncomplete = [];

// ============================================================
// CHART DATA
// ============================================================
$months      = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$currentYear = (int) date('Y');

$enrollmentTrend  = array_fill(0, 12, 0);
$applicationTrend = array_fill(0, 12, 0);

// FIX: EnrollmentReport::getMonthlyTrend() returns month NAMES
// ('Jan', 'Feb', ...). We match by name using array_search.
foreach ($monthlyTrend as $row) {
    $monthName = $row['month'] ?? '';
    $idx = array_search($monthName, $months, true);

    if ($idx !== false) {
        $enrollmentTrend[$idx]  = (int) ($row['enrollments']  ?? 0);
        $applicationTrend[$idx] = (int) ($row['applications'] ?? 0);
    }
}

// ============================================================
// PREDICTIVE DATA
// ============================================================
$predictedNextMonth   = (int)   ($predictive['predicted_next_month']   ?? 0);
$predictedNextQuarter = (int)   ($predictive['predicted_next_quarter'] ?? 0);
$avgGrowth            = (float) ($predictive['avg_growth']             ?? 0);
$conversionRate       = (float) ($reportSummary['conversion_rate']     ?? 0);

$currentDateTime = date('l, F d, Y h:i A');
$pageTitle       = 'Dashboard Overview';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;">
            <div>
                <h1>📊 Dashboard Overview</h1>
                <p class="dashboard-subtitle">Welcome to your enrollment management dashboard • <?php echo htmlspecialchars($currentDateTime); ?></p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="?page=dashboard-overview&export=analysis" class="btn btn-success" style="padding:10px 20px;font-weight:600;">
                    📥 Export Analysis Report
                </a>
                <a href="?page=dashboard-overview&export=summary" class="btn btn-info" style="padding:10px 20px;font-weight:600;">
                    📊 Export Summary
                </a>
                <a href="?page=dashboard-overview&export=students" class="btn btn-secondary" style="padding:10px 20px;font-weight:600;">
                    👨‍🎓 Export Students
                </a>
                <a href="?page=dashboard-overview&export=archive" class="btn btn-warning" style="padding:10px 20px;font-weight:600;">
                    📦 Export Archive
                </a>
            </div>
        </div>

        <div class="module-content">

            <!-- STATISTICS -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">👨‍🎓</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo (int) $totalStudents; ?></div>
                        <div class="stat-label">Total Students</div>
                        <span class="stat-change up">↑ <?php echo (int) $activeEnrollments; ?> active</span>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">📚</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $totalSubjectEnrollments; ?></div>
                        <div class="stat-label">Subject Enrollments</div>
                        <span class="stat-change up">↑ <?php echo (int) $activeEnrollments; ?> students</span>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo (int) $activeEnrollments; ?></div>
                        <div class="stat-label">Active Students</div>
                        <span class="stat-change up">✓ Currently enrolled</span>
                    </div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">📝</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo (int) $pendingCount; ?></div>
                        <div class="stat-label">Pending Applications</div>
                        <span class="stat-change <?php echo $pendingCount > 0 ? 'down' : 'up'; ?>">
                            <?php echo $pendingCount > 0 ? '⚠️ Needs attention' : '✅ All clear'; ?>
                        </span>
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">🏫</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo (int) ($sectionStats['total_sections'] ?? 0); ?></div>
                        <div class="stat-label">Total Sections</div>
                        <span class="stat-change up">
                            <?php echo (int) ($sectionStats['available_slots'] ?? 0); ?> slots available
                        </span>
                    </div>
                </div>

                <div class="stat-card secondary">
                    <div class="stat-icon">📦</div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo (int) $archivedCount; ?></div>
                        <div class="stat-label">Archived Students</div>
                        <span class="stat-change <?php echo $archivedCount > 0 ? 'warning' : 'up'; ?>">
                            <?php echo $archivedCount > 0 ? '📦 Pwede i-restore' : '✅ No archived'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>📈 Subject Enrollments Trend (<?php echo $currentYear; ?>)</h3>
                    <div class="chart-container">
                        <canvas id="enrollmentChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>🎯 Enrollment by Course</h3>
                    <div class="chart-container">
                        <canvas id="courseChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card">
                    <h3>📊 Applications Trend (<?php echo $currentYear; ?>)</h3>
                    <div class="chart-container">
                        <canvas id="applicationChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>📚 Enrollment by Year Level</h3>
                    <div class="chart-container">
                        <canvas id="yearChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- PREDICTIVE ANALYTICS -->
            <div class="analytics-section">
                <h2>🔮 Predictive Analytics</h2>
                <p style="color:#666;font-size:14px;margin-bottom:15px;">
                    Based on current trends and historical data for <?php echo $currentYear; ?>
                </p>

                <div class="predictive-grid">
                    <div class="predictive-card green">
                        <div class="number"><?php echo $predictedNextMonth; ?></div>
                        <div class="label">Projected Subject Enrollments (Next Month)</div>
                        <div class="trend">↑ <?php echo round($avgGrowth, 1); ?>% growth</div>
                    </div>
                    <div class="predictive-card orange">
                        <div class="number"><?php echo $predictedNextQuarter; ?></div>
                        <div class="label">Projected Subject Enrollments (Next Quarter)</div>
                        <div class="trend">↑ <?php echo round($avgGrowth * 3, 1); ?>% growth</div>
                    </div>
                    <div class="predictive-card purple">
                        <div class="number"><?php echo round($conversionRate, 1); ?>%</div>
                        <div class="label">Conversion Rate (Applications → Students)</div>
                        <div class="trend"><?php echo $conversionRate > 50 ? '✅ Good conversion' : '📈 Room for improvement'; ?></div>
                    </div>
                    <div class="predictive-card red">
                        <div class="number"><?php echo count($studentsWithIncomplete); ?></div>
                        <div class="label">Students with Incomplete Requirements</div>
                        <div class="trend">
                            <?php echo count($studentsWithIncomplete) > 0 ? '⚡ Needs follow-up' : '✅ All complete'; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top:15px;padding:15px;background:#f8f9fa;border-radius:8px;">
                    <h4 style="color:#1a3c6e;margin-bottom:10px;">📊 Key Insights</h4>
                    <ul style="list-style:none;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <li style="padding:8px 12px;background:white;border-radius:6px;border-left:3px solid #28a745;">
                            <strong>Enrollment Growth:</strong> Expected to grow by <strong><?php echo round($avgGrowth, 1); ?>%</strong> monthly
                        </li>
                        <li style="padding:8px 12px;background:white;border-radius:6px;border-left:3px solid #ffc107;">
                            <strong>Pending Applications:</strong> <strong><?php echo (int) $pendingCount; ?></strong> waiting for processing
                        </li>
                        <li style="padding:8px 12px;background:white;border-radius:6px;border-left:3px solid #17a2b8;">
                            <strong>Available Slots:</strong> <strong><?php echo (int) ($sectionStats['available_slots'] ?? 0); ?></strong> slots available
                        </li>
                        <li style="padding:8px 12px;background:white;border-radius:6px;border-left:3px solid #6c757d;">
                            <strong>Archived Students:</strong> <strong><?php echo (int) $archivedCount; ?></strong> archived (pwede i-restore)
                        </li>
                    </ul>
                </div>
            </div>

            <!-- RECENT ENROLLMENTS -->
            <div class="recent-applications">
                <h2>📋 Recent Subject Enrollments</h2>
                <?php if (!empty($recentEnrollments)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student #</th>
                            <th>Subject</th>
                            <th>Course</th>
                            <th>Section</th>
                            <th>Enrolled</th>
                            <th>Status</th>
                            <th>Schedule</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentEnrollments as $enr): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(trim(
                                ($enr['first_name'] ?? '') . ' ' . ($enr['surname'] ?? '')
                            )); ?></strong></td>
                            <td><?php echo htmlspecialchars($enr['student_number'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($enr['subject_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($enr['course_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($enr['section_code'] ?? 'N/A'); ?></td>
                            <td><?php
                                echo !empty($enr['enrolled_at'])
                                    ? htmlspecialchars(date('M d, Y', strtotime($enr['enrolled_at'])))
                                    : 'N/A';
                            ?></td>
                            <td><span class="status-badge status-enrolled">Enrolled</span></td>
                            <td>
                                <?php if (!empty($enr['day_of_week'])): ?>
                                    <small><?php echo htmlspecialchars(
                                        $enr['day_of_week']
                                        . ' ' . ($enr['start_time'] ?? '')
                                        . '-' . ($enr['end_time'] ?? '')
                                    ); ?></small>
                                <?php else: ?>
                                    <small>No schedule</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="view-all">
                    <a href="?page=enrollments" class="btn btn-primary">View All Enrollments</a>
                </div>
                <?php else: ?>
                <div class="text-center">No recent subject enrollments found.</div>
                <?php endif; ?>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="quick-actions">
                <h2>⚡ Quick Actions</h2>
                <div class="action-grid">
                    <a href="?page=applications"      class="action-card"><div class="action-icon">📋</div><div class="action-label">New Application</div></a>
                    <a href="?page=enrollments"       class="action-card"><div class="action-icon">📚</div><div class="action-label">Enroll Students</div></a>
                    <a href="?page=students"          class="action-card"><div class="action-icon">👨‍🎓</div><div class="action-label">View Students</div></a>
                    <a href="?page=sections"          class="action-card"><div class="action-icon">🏫</div><div class="action-label">Manage Sections</div></a>
                    <a href="?page=requirements"      class="action-card"><div class="action-icon">📋</div><div class="action-label">Requirements</div></a>
                    <a href="?page=courses"           class="action-card"><div class="action-icon">📖</div><div class="action-label">Manage Courses</div></a>
                    <a href="?page=archived-students" class="action-card"><div class="action-icon">📦</div><div class="action-label">Archived Students</div></a>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<style>
    .dashboard-subtitle {
        color: #5a7fa8;
        font-size: 14px;
        margin-bottom: 0;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.08);
        display: flex;
        align-items: center;
        gap: 15px;
        transition: all 0.3s ease;
        border-left: 4px solid #1a3c6e;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 4px 12px rgba(26, 60, 110, 0.18);
    }

    .stat-card.primary { border-left-color: #1a3c6e; }
    .stat-card.success { border-left-color: #2a5c9e; }
    .stat-card.warning { border-left-color: #4a90d9; }
    .stat-card.danger { border-left-color: #0f2a4e; }
    .stat-card.info { border-left-color: #6aa8e0; }
    .stat-card.purple { border-left-color: #3a6ea8; }
    .stat-card.teal { border-left-color: #5a9fd4; }
    .stat-card.secondary { border-left-color: #5a7fa8; }

    .stat-icon {
        font-size: 2.2rem;
        width: 55px;
        height: 55px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #e8f0fe;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .stat-content {
        flex: 1;
    }

    .stat-number {
        font-size: 1.8rem;
        font-weight: 700;
        color: #1a3c6e;
        line-height: 1.2;
    }

    .stat-label {
        color: #5a7fa8;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    .stat-change {
        font-size: 12px;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 12px;
        display: inline-block;
        margin-top: 4px;
    }

    .stat-change.up { background: #d4e8fc; color: #1a3c6e; }
    .stat-change.down { background: #dce8f5; color: #1a3c6e; }
    .stat-change.warning { background: #e8f0fe; color: #2a5c9e; }

    .charts-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin: 20px 0;
    }

    .chart-card {
        background: #ffffff;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(26, 60, 110, 0.04),
                    0 8px 24px rgba(26, 60, 110, 0.08);
        border: 1px solid rgba(90, 127, 168, 0.15);
        transition: box-shadow 0.25s ease;
    }

    .chart-card:hover {
        box-shadow: 0 1px 3px rgba(26, 60, 110, 0.04),
                    0 12px 32px rgba(26, 60, 110, 0.14);
    }

    .chart-card h3 {
        color: #1a3c6e;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: 0.2px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .chart-container {
        position: relative;
        height: 300px;
    }

    .predictive-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin: 15px 0;
    }

    .predictive-card {
        background: linear-gradient(135deg, #1a3c6e, #2a5c9e);
        color: white;
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.15);
        transition: transform 0.3s ease;
    }

    .predictive-card:hover {
        transform: translateY(-4px);
    }

    .predictive-card .number {
        font-size: 2rem;
        font-weight: 700;
    }

    .predictive-card .label {
        font-size: 0.85rem;
        opacity: 0.9;
        margin-top: 5px;
    }

    .predictive-card .trend {
        font-size: 0.8rem;
        margin-top: 8px;
        padding: 2px 12px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        display: inline-block;
    }

    .predictive-card.green { background: linear-gradient(135deg, #2a5c9e, #4a90d9); }
    .predictive-card.orange { background: linear-gradient(135deg, #4a90d9, #6aa8e0); }
    .predictive-card.purple { background: linear-gradient(135deg, #1a3c6e, #3a6ea8); }
    .predictive-card.red { background: linear-gradient(135deg, #0f2a4e, #2a5c9e); }

    .recent-applications,
    .quick-actions,
    .analytics-section {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-top: 20px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.08);
    }

    .recent-applications h2,
    .quick-actions h2,
    .analytics-section h2 {
        margin-bottom: 15px;
        color: #1a3c6e;
        font-size: 18px;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .table th {
        background: #f0f5fc;
        padding: 10px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        color: #5a7fa8;
        border-bottom: 2px solid #d0e0f0;
    }

    .table td {
        padding: 10px 12px;
        border-bottom: 1px solid #e8f0fe;
    }

    .table tbody tr:hover {
        background: #f0f5fc;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }

    .status-enrolled { background: #d4e8fc; color: #1a3c6e; }

    .action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-top: 10px;
    }

    .action-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 20px;
        background: #f0f5fc;
        border-radius: 10px;
        text-decoration: none;
        color: #1a3c6e;
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }

    .action-card:hover {
        background: #e0ecf8;
        border-color: #1a3c6e;
        transform: translateY(-4px);
        box-shadow: 0 4px 12px rgba(26, 60, 110, 0.15);
    }

    .action-icon {
        font-size: 2rem;
        margin-bottom: 8px;
    }

    .action-label {
        font-size: 13px;
        font-weight: 500;
        text-align: center;
    }

    .btn {
        padding: 8px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        transition: all 0.2s ease;
        font-size: 14px;
    }

    .btn-primary { background: #1a3c6e; color: white; }
    .btn-primary:hover { background: #2a5c9e; }
    .btn-success { background: #2a5c9e; color: white; }
    .btn-success:hover { background: #1a3c6e; }
    .btn-info { background: #4a90d9; color: white; }
    .btn-info:hover { background: #3a7bc8; }
    .btn-secondary { background: #5a7fa8; color: white; }
    .btn-secondary:hover { background: #4a6a8a; }
    .btn-warning { background: #6aa8e0; color: white; }
    .btn-warning:hover { background: #5a98d0; }

    .view-all {
        text-align: center;
        margin-top: 15px;
    }

    .text-center {
        text-align: center;
        color: #5a7fa8;
        padding: 20px;
    }

    @media (max-width: 992px) {
        .charts-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .action-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .table {
            font-size: 13px;
        }

        .table th,
        .table td {
            padding: 8px;
        }

        .predictive-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .action-grid {
            grid-template-columns: 1fr;
        }

        .predictive-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ============================================================
    // SHARED THEME
    // ============================================================
    Chart.defaults.font.family = "'Segoe UI', 'Helvetica Neue', Arial, sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = '#64748b';
    Chart.defaults.padding     = 10;

    const tooltipStyle = {
        backgroundColor: 'rgba(15, 23, 42, 0.95)',
        titleColor:      '#ffffff',
        bodyColor:       '#e2e8f0',
        borderColor:     'rgba(148, 163, 184, 0.2)',
        borderWidth:     1,
        padding:         12,
        cornerRadius:    8,
        titleFont:       { size: 13, weight: '600' },
        bodyFont:        { size: 12 },
        displayColors:   true,
        boxPadding:      6
    };

    const legendStyle = {
        position: 'bottom',
        labels: {
            padding:       16,
            usePointStyle: true,
            pointStyle:    'circle',
            boxWidth:      8,
            boxHeight:     8,
            font:          { size: 12, weight: '500' },
            color:         '#475569'
        }
    };

    const gridColor     = 'rgba(148, 163, 184, 0.12)';
    const gridColorSoft = 'rgba(148, 163, 184, 0.06)';

    // ============================================================
    // 1. ENROLLMENT TREND — smooth area chart
    // ============================================================
    const enrollmentCtx = document.getElementById('enrollmentChart').getContext('2d');

    const enrollmentGradient = enrollmentCtx.createLinearGradient(0, 0, 0, 320);
    enrollmentGradient.addColorStop(0,   'rgba(59, 130, 246, 0.35)');
    enrollmentGradient.addColorStop(0.6, 'rgba(59, 130, 246, 0.10)');
    enrollmentGradient.addColorStop(1,   'rgba(59, 130, 246, 0)');

    new Chart(enrollmentCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Subject Enrollments',
                data: <?php echo json_encode($enrollmentTrend); ?>,
                borderColor:     '#3b82f6',
                backgroundColor: enrollmentGradient,
                borderWidth:     3,
                fill:            true,
                tension:         0.4,
                pointRadius:     0,
                pointHoverRadius: 7,
                pointHoverBackgroundColor: '#3b82f6',
                pointHoverBorderColor:     '#ffffff',
                pointHoverBorderWidth:     3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode:      'index',
                intersect: false
            },
            plugins: {
                legend:  { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: {
                        title: (items) => items[0].label + ' ' + <?php echo $currentYear; ?>,
                        label: (ctx)   => '  ' + ctx.parsed.y + ' enrollments'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        color: '#94a3b8',
                        padding: 8
                    },
                    grid: {
                        color:       gridColor,
                        drawBorder:  false,
                        drawTicks:   false
                    },
                    border: { display: false }
                },
                x: {
                    ticks: {
                        color:   '#94a3b8',
                        padding: 8
                    },
                    grid:   { display: false },
                    border: { display: false }
                }
            }
        }
    });

    // ============================================================
    // 2. COURSE DISTRIBUTION — modern doughnut with center label
    // ============================================================
    const courseCtx    = document.getElementById('courseChart').getContext('2d');
    const courseData   = <?php echo json_encode($enrollmentByCourse); ?>;
    const courseLabels = courseData.map(item => item.course_code || 'Unknown');
    const courseCounts = courseData.map(item => parseInt(item.student_count, 10) || 0);
    const totalCourses = courseCounts.reduce((a, b) => a + b, 0);
    const hasCourseData = courseCounts.some(v => v > 0);

    const courseColors = [
        '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
        '#06b6d4', '#8b5cf6', '#f97316', '#14b8a6'
    ];

    const centerLabelPlugin = {
        id: 'centerLabel',
        beforeDraw: function (chart) {
            if (!hasCourseData) return;

            const { ctx, chartArea } = chart;
            if (!chartArea) return;

            const cx = (chartArea.left + chartArea.right) / 2;
            const cy = (chartArea.top + chartArea.bottom) / 2;

            ctx.save();
            ctx.fillStyle = '#0f172a';
            ctx.font = 'bold 26px "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(totalCourses, cx, cy - 8);

            ctx.fillStyle = '#64748b';
            ctx.font = '11px "Segoe UI", sans-serif';
            ctx.fillText('STUDENTS', cx, cy + 16);
            ctx.restore();
        }
    };

    new Chart(courseCtx, {
        type: 'doughnut',
        data: {
            labels: courseLabels.length > 0 ? courseLabels : ['No Data'],
            datasets: [{
                data:            hasCourseData ? courseCounts : [1],
                backgroundColor: hasCourseData
                    ? courseColors.slice(0, courseCounts.length)
                    : ['#e2e8f0'],
                borderWidth:  3,
                borderColor:  '#ffffff',
                hoverOffset:  8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: hasCourseData ? legendStyle : { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: {
                        label: (ctx) => {
                            if (!hasCourseData) return '  No data';
                            const value = ctx.parsed;
                            const pct = ((value / totalCourses) * 100).toFixed(1);
                            return '  ' + ctx.label + ': ' + value + ' (' + pct + '%)';
                        }
                    }
                }
            }
        },
        plugins: [centerLabelPlugin]
    });

    // ============================================================
    // 3. APPLICATIONS TREND — styled bar chart
    // ============================================================
    const appCtx = document.getElementById('applicationChart').getContext('2d');

    const appGradient = appCtx.createLinearGradient(0, 0, 0, 320);
    appGradient.addColorStop(0, '#06b6d4');
    appGradient.addColorStop(1, '#0e7490');

    new Chart(appCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Applications',
                data: <?php echo json_encode($applicationTrend); ?>,
                backgroundColor: appGradient,
                hoverBackgroundColor: '#0891b2',
                borderRadius:     6,
                borderSkipped:    false,
                barPercentage:    0.65,
                categoryPercentage: 0.8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode:      'index',
                intersect: false
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: {
                        title: (items) => items[0].label + ' ' + <?php echo $currentYear; ?>,
                        label: (ctx)   => '  ' + ctx.parsed.y + ' applications'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        color: '#94a3b8',
                        padding: 8
                    },
                    grid: {
                        color:       gridColor,
                        drawBorder:  false,
                        drawTicks:   false
                    },
                    border: { display: false }
                },
                x: {
                    ticks: {
                        color:   '#94a3b8',
                        padding: 8
                    },
                    grid:   { display: false },
                    border: { display: false }
                }
            }
        }
    });

    // ============================================================
    // 4. YEAR LEVEL DISTRIBUTION — colored bars with value labels
    // ============================================================
    const yearCtx    = document.getElementById('yearChart').getContext('2d');
    const yearData   = <?php echo json_encode($enrollmentByYear); ?>;
    const yearLabels = yearData.map(item => item.grade_level || 'Unknown');
    const yearCounts = yearData.map(item => parseInt(item.student_count, 10) || 0);
    const hasYearData = yearCounts.some(v => v > 0);

    const valueLabelsPlugin = {
        id: 'valueLabels',
        afterDatasetsDraw: function (chart) {
            if (!hasYearData) return;

            const { ctx } = chart;

            chart.data.datasets.forEach(function (dataset, i) {
                const meta = chart.getDatasetMeta(i);
                meta.data.forEach(function (bar, index) {
                    const value = dataset.data[index];
                    if (value === 0) return;

                    ctx.save();
                    ctx.fillStyle  = '#0f172a';
                    ctx.font       = 'bold 12px "Segoe UI", sans-serif';
                    ctx.textAlign  = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(value, bar.x, bar.y - 4);
                    ctx.restore();
                });
            });
        }
    };

    new Chart(yearCtx, {
        type: 'bar',
        data: {
            labels: yearLabels.length > 0 ? yearLabels : ['No Data'],
            datasets: [{
                label: 'Students',
                data:  hasYearData ? yearCounts : [0],
                backgroundColor: hasYearData
                    ? ['#3b82f6', '#10b981', '#f59e0b', '#ef4444']
                    : ['#e2e8f0'],
                hoverBackgroundColor: hasYearData
                    ? ['#2563eb', '#059669', '#d97706', '#dc2626']
                    : ['#cbd5e1'],
                borderRadius:     8,
                borderSkipped:    false,
                barPercentage:    0.6,
                categoryPercentage: 0.8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 20 }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: {
                        label: (ctx) => '  ' + ctx.parsed.y + ' students'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    suggestedMax: hasYearData ? Math.max(...yearCounts) + 1 : 1,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        color: '#94a3b8',
                        padding: 8
                    },
                    grid: {
                        color:       gridColorSoft,
                        drawBorder:  false,
                        drawTicks:   false
                    },
                    border: { display: false }
                },
                x: {
                    ticks: {
                        color:   '#94a3b8',
                        padding: 8
                    },
                    grid:   { display: false },
                    border: { display: false }
                }
            }
        },
        plugins: [valueLabelsPlugin]
    });

});
</script>