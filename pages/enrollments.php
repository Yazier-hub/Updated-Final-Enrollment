<?php
// pages/enrollments.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • JS uses `subject.subject_type ?? 'Lecture'` (field no longer returned by API)
//   • All count()/iteration guarded with is_array()
//   • addslashes() replaced with htmlspecialchars(json_encode()) for JS args
//   • Null-safe reads on all $app[...] / $enroll[...] fields
//   • window.onclick replaced with addEventListener

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath = dirname(__DIR__);

require_once $basePath . '/classes/Enrollment.php';
require_once $basePath . '/classes/Student.php';
require_once $basePath . '/classes/Course.php';
require_once $basePath . '/classes/Section.php';
require_once $basePath . '/classes/Application.php';
require_once $basePath . '/classes/Requirement.php';
require_once $basePath . '/classes/EnrollmentController.php';
require_once $basePath . '/classes/StudentProgression.php';
require_once $basePath . '/classes/Database.php';

$controller         = new EnrollmentController();
$studentProgression = new StudentProgression();
$enrollmentModel    = new Enrollment();

// POST is handled by the controller (redirects + exits)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->handlePostRequest();
}

// ============================================================
// Filters
// ============================================================
$search       = $_GET['search']     ?? '';
$courseFilter = isset($_GET['course_id'])  ? (int) $_GET['course_id']  : '';
$yearFilter   = isset($_GET['year_level']) ? (int) $_GET['year_level'] : '';

// ============================================================
// Data
// ============================================================
$pendingApplicants = $controller->getPendingApplicants($search);
if (!is_array($pendingApplicants)) $pendingApplicants = [];

$enrollments = $controller->getEnrolledStudents($courseFilter, $yearFilter);
if (!is_array($enrollments)) $enrollments = [];

$courses = $controller->getAllCourses();
if (!is_array($courses)) $courses = [];

$archivedCount = (int) $controller->getArchivedStudentCount();

$sectionModel      = new Section();
$sections          = $sectionModel->getAllSectionsWithDetails();
if (!is_array($sections)) $sections = [];
$availableSections = count($sections);

$applicationModel = new Application();
$allApplications  = $applicationModel->getAllApplications();
if (!is_array($allApplications)) $allApplications = [];

$noCourseCount = 0;
foreach ($allApplications as $app) {
    if (empty($app['course_id']) && ($app['status'] ?? '') === 'pending') {
        $noCourseCount++;
    }
}

$currentSchoolYear = date('Y') . '-' . (date('Y') + 1);
$nextSchoolYear    = (date('Y') + 1) . '-' . (date('Y') + 2);

// ============================================================
// Flash data
// ============================================================
$message       = $_SESSION['message']        ?? '';
$accountCreated = $_SESSION['account_created'] ?? false;
$username      = $_SESSION['username']       ?? '';
$password      = $_SESSION['password']       ?? '';
$isNewAccount  = $_SESSION['is_new_account'] ?? false;
$emailSent     = $_SESSION['email_sent']     ?? false;
$emailError    = $_SESSION['email_error']    ?? null;

unset(
    $_SESSION['message'],
    $_SESSION['account_created'],
    $_SESSION['username'],
    $_SESSION['password'],
    $_SESSION['is_new_account'],
    $_SESSION['email_sent'],
    $_SESSION['email_error']
);

$stats = $controller->getEnrollmentStats();
if (!is_array($stats)) $stats = [];

// Base URL for API calls
$protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$baseUrl    = $protocol . $host . $scriptPath;

$pageTitle = 'Enrollment Management';
?>

<?php include $basePath . '/includes/header.php'; ?>
<?php include $basePath . '/includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>🎯 Enrollment Management</h1>
            <p class="dashboard-subtitle">Enroll ONE student at a time with selected subjects</p>
        </div>

        <div class="module-content">

            <!-- MESSAGES -->
            <?php if ($message !== ''): ?>
                <?php
                    $alertClass = 'danger';
                    if (strpos($message, '✅') !== false) $alertClass = 'success';
                    elseif (strpos($message, '⚠️') !== false) $alertClass = 'warning';
                ?>
                <div class="alert alert-<?php echo $alertClass; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- ACCOUNT CREDENTIALS DISPLAY -->
            <?php if ($accountCreated && $username !== '' && $password !== ''): ?>
                <div class="alert alert-success" style="border-left:4px solid #28a745;">
                    <div style="display:flex;align-items:flex-start;gap:15px;flex-wrap:wrap;">
                        <div style="font-size:24px;">🔐</div>
                        <div style="flex:1;">
                            <strong>Student Account Created!</strong>
                            <div style="margin-top:8px;padding:10px;background:#f8f9fa;border-radius:5px;border:1px solid #c8e6c9;max-width:600px;">
                                <div style="display:grid;grid-template-columns:100px 1fr;gap:5px;">
                                    <span style="font-weight:600;">Username:</span>
                                    <span><code style="background:#e9ecef;padding:2px 8px;border-radius:3px;"><?php echo htmlspecialchars($username); ?></code></span>
                                    <span style="font-weight:600;">Password:</span>
                                    <span><code style="background:#e9ecef;padding:2px 8px;border-radius:3px;"><?php echo htmlspecialchars($password); ?></code></span>
                                </div>
                                <?php if ($isNewAccount): ?>
                                    <small style="color:#28a745;display:block;margin-top:5px;">✅ New account created.</small>
                                <?php else: ?>
                                    <small style="color:#ffc107;display:block;margin-top:5px;">⚠️ Account already existed.</small>
                                <?php endif; ?>
                                <?php if ($emailSent): ?>
                                    <small style="color:#28a745;display:block;">📧 ✅ Credentials sent to student's email</small>
                                <?php else: ?>
                                    <small style="color:#dc3545;display:block;">📧 ⚠️ Email not sent. Please provide credentials manually.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STATISTICS -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="number"><?php echo (int) ($stats['total_enrolled']         ?? 0); ?></div>
                    <div class="label">Total Enrolled Students</div>
                </div>
                <div class="stat-card success">
                    <div class="number"><?php echo (int) ($stats['total_sections']         ?? 0); ?></div>
                    <div class="label">Total Sections</div>
                </div>
                <div class="stat-card warning">
                    <div class="number"><?php echo (int) ($stats['pending_applications']   ?? 0); ?></div>
                    <div class="label">Pending Applications</div>
                </div>
                <div class="stat-card danger">
                    <div class="number"><?php echo (int) ($stats['recent_enrollments']     ?? 0); ?></div>
                    <div class="label">Recent Enrollments (30 days)</div>
                </div>
            </div>

            <!-- WARNINGS -->
            <?php if ($availableSections === 0): ?>
                <div class="alert alert-warning">
                    <strong>⚠️ No available sections!</strong>
                    Please <a href="?page=sections">add a section</a> before enrolling students.
                </div>
            <?php endif; ?>

            <?php if ($noCourseCount > 0): ?>
                <div class="alert alert-warning">
                    <strong>⚠️ <?php echo $noCourseCount; ?> applicant(s) have no course assigned!</strong>
                    Please <a href="?page=applications">edit the applications</a> to assign a course.
                </div>
            <?php endif; ?>

            <!-- SEARCH -->
            <div class="search-section">
                <form method="GET" class="search-form">
                    <input type="hidden" name="page" value="enrollments">
                    <input type="text" name="search"
                           placeholder="Search applicants by name, email, or contact..."
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="?page=enrollments" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- PENDING APPLICANTS -->
            <div class="students-ready-section">
                <h2>📋 Pending Applicants (<?php echo count($pendingApplicants); ?>)</h2>
                <p class="section-note">Click "Enroll" to enroll ONE student at a time with selected subjects</p>

                <?php if (count($pendingApplicants) > 0): ?>
                    <div class="students-ready-grid">
                        <?php foreach ($pendingApplicants as $app):
                            $name = trim(
                                ($app['first_name'] ?? '')
                                . ' ' . ($app['middle_name'] ?? '')
                                . ' ' . ($app['surname'] ?? '')
                            );
                            if (!empty($app['suffix'])) $name .= ' ' . $app['suffix'];

                            $admissionType = $app['admission_type'] ?? 'freshmen';
                            $badgeMap = [
                                'freshmen'    => 'badge-freshmen',
                                'transferee'  => 'badge-transferee',
                                'returnee'    => 'badge-returnee',
                                'senior_high' => 'badge-senior_high'
                            ];
                            $badgeClass = $badgeMap[$admissionType] ?? 'badge-default';
                            $badgeLabel = ucfirst(str_replace('_', ' ', $admissionType));

                            $canEnroll = !empty($app['course_id']) && $availableSections > 0;
                        ?>
                        <div class="student-card">
                            <div class="student-info">
                                <span class="student-name">
                                    <?php echo htmlspecialchars($name); ?>
                                    <span class="badge <?php echo htmlspecialchars($badgeClass); ?>">
                                        <?php echo htmlspecialchars($badgeLabel); ?>
                                    </span>
                                </span>
                                <span class="student-course">📚 <?php echo htmlspecialchars($app['course_code'] ?? 'No Course'); ?></span>
                                <span class="student-email">✉️ <?php echo htmlspecialchars($app['email'] ?? ''); ?></span>
                                <span class="student-contact">📱 <?php echo htmlspecialchars($app['contact_number'] ?? ''); ?></span>
                            </div>
                            <div class="student-actions">
                                <?php if ($canEnroll): ?>
                                    <button class="btn btn-success"
                                        onclick="openEnrollmentModal(
                                            <?php echo (int) $app['applicant_id']; ?>,
                                            <?php echo htmlspecialchars(json_encode($name), ENT_QUOTES); ?>,
                                            <?php echo htmlspecialchars(json_encode($app['course_code'] ?? 'N/A'), ENT_QUOTES); ?>,
                                            <?php echo htmlspecialchars(json_encode($admissionType), ENT_QUOTES); ?>,
                                            <?php echo (int) $app['course_id']; ?>
                                        )">
                                        🎓 Enroll
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-secondary" disabled
                                        title="<?php echo !empty($app['course_id']) ? 'No available sections' : 'No course assigned'; ?>">
                                        ⚠️ Cannot Enroll
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <?php if ($search !== ''): ?>
                            No pending applications found for
                            "<strong><?php echo htmlspecialchars($search); ?></strong>".
                            <a href="?page=enrollments">Clear search</a>
                        <?php else: ?>
                            No pending applications.
                            <a href="?page=applications">Submit an application</a> first.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ENROLLED STUDENTS -->
            <div class="enrollments-table-container">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:15px;">
                    <h2 style="margin:0;">✅ Enrolled Students (<?php echo count($enrollments); ?>)</h2>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="?page=enrollments" class="btn btn-sm btn-info">View All</a>
                        <a href="?page=students" class="btn btn-sm btn-secondary">All Students</a>
                        <a href="?page=archived-students" class="btn btn-sm btn-warning">
                            📦 Archived (<?php echo $archivedCount; ?>)
                        </a>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="filters-section">
                    <form method="GET" style="display:flex;gap:15px;flex-wrap:wrap;align-items:flex-end;width:100%;">
                        <input type="hidden" name="page" value="enrollments">

                        <div class="filter-group">
                            <label for="course_filter">Course</label>
                            <select name="course_id" id="course_filter" onchange="this.form.submit()">
                                <option value="">All Courses</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>"
                                        <?php echo $courseFilter == $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(($c['code'] ?? '') . ' - ' . ($c['name'] ?? '')); ?>
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

                        <div class="filter-actions">
                            <?php if ($courseFilter || $yearFilter): ?>
                                <a href="?page=enrollments" class="btn btn-secondary btn-sm">Clear Filters</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <?php if (count($enrollments) > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student #</th>
                            <th>Name</th>
                            <th>Section</th>
                            <th>Course</th>
                            <th>Current</th>
                            <th>Next</th>
                            <th>Subjects</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $enroll):
                            $fullName = trim(
                                ($enroll['first_name'] ?? '')
                                . ' ' . ($enroll['middle_name'] ?? '')
                                . ' ' . ($enroll['surname'] ?? '')
                            );
                            if (!empty($enroll['suffix'])) $fullName .= ' ' . $enroll['suffix'];

                            $subjectCount = isset($enroll['subject_count']) ? (int) $enroll['subject_count'] : 0;

                            $current = $enrollmentModel->getStudentCurrentProgression((int) $enroll['student_id']);
                            $next    = $enrollmentModel->getStudentNextProgression((int) $enroll['student_id']);

                            $currentYearLevel = $current ? (int) $current['year_level'] : 1;
                            $nextYearLevel    = $next    ? (int) $next['year_level']    : 1;
                            $academicYearChanges = ($currentYearLevel != $nextYearLevel && $nextYearLevel > $currentYearLevel);
                            $currentSchoolYearDisplay = $currentSchoolYear;
                            $nextSchoolYearDisplay    = $academicYearChanges ? $nextSchoolYear : $currentSchoolYear;

                            $canProgress   = $next && empty($next['is_completed']) && !empty($next['can_progress']);
                            $waitForGrades = $next && empty($next['is_completed']) && empty($next['can_progress']);
                        ?>
                        <tr>
                            <td><strong>🎓 <?php echo htmlspecialchars($enroll['student_number'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($fullName); ?></td>
                            <td><?php echo htmlspecialchars($enroll['section_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($enroll['course_code'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if ($current): ?>
                                    <span class="badge badge-primary">
                                        <?php echo htmlspecialchars($studentProgression->getYearLevelText($current['year_level'])); ?>
                                        <?php echo htmlspecialchars($current['semester_name'] ?? ''); ?>
                                    </span>
                                    <br><small style="color:#666;">SY: <?php echo htmlspecialchars($currentSchoolYearDisplay); ?></small>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($next && empty($next['is_completed'])): ?>
                                    <span class="badge badge-warning">
                                        <?php echo htmlspecialchars($studentProgression->getYearLevelText($next['year_level'])); ?>
                                        <?php echo htmlspecialchars($studentProgression->getSemesterName($next['semester'])); ?>
                                    </span>
                                    <?php if ($academicYearChanges): ?>
                                        <br><small style="color:#dc3545;font-weight:bold;">📅 New SY: <?php echo htmlspecialchars($nextSchoolYearDisplay); ?></small>
                                    <?php else: ?>
                                        <br><small style="color:#666;">Same SY</small>
                                    <?php endif; ?>
                                <?php elseif ($next && !empty($next['is_completed'])): ?>
                                    <span class="badge badge-success">🎓 Graduated</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Completed</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-info"><?php echo $subjectCount; ?> Subjects</span></td>
                            <td><span class="status-badge status-enrolled">Enrolled</span></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="?page=student-details&id=<?php echo (int) $enroll['student_id']; ?>"
                                       class="btn btn-sm btn-info">View</a>
                                    <button onclick="viewSchedule(<?php echo (int) $enroll['student_id']; ?>)"
                                            class="btn btn-sm btn-primary">📅 Schedule</button>

                                    <?php if ($canProgress): ?>
                                        <button class="btn btn-sm btn-success"
                                            onclick="openProgressionEnrollment(
                                                <?php echo (int) $enroll['student_id']; ?>,
                                                <?php echo htmlspecialchars(json_encode($fullName), ENT_QUOTES); ?>,
                                                <?php echo (int) $next['year_level']; ?>,
                                                <?php echo (int) $next['semester']; ?>,
                                                <?php echo htmlspecialchars(json_encode($nextSchoolYearDisplay), ENT_QUOTES); ?>
                                            )">
                                            📈 Next Sem
                                        </button>
                                    <?php elseif ($waitForGrades): ?>
                                        <button class="btn btn-sm btn-secondary" disabled
                                            title="Kailangan munang tapusin ang current semester at may grades bago makapag-enroll sa susunod na semester">
                                            ⏳ Wait for Grades
                                        </button>
                                    <?php endif; ?>

                                    <button class="btn btn-sm btn-warning"
                                        onclick="openArchiveModal(
                                            <?php echo (int) $enroll['student_id']; ?>,
                                            <?php echo htmlspecialchars(json_encode($fullName), ENT_QUOTES); ?>
                                        )">
                                        📦 Archive
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="text-center">
                        <?php if ($courseFilter || $yearFilter): ?>
                            No enrolled students found matching the selected filters.
                            <a href="?page=enrollments">Clear filters</a>
                        <?php else: ?>
                            No enrollments found.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- ENROLLMENT MODAL -->
<div id="enrollmentModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeEnrollmentModal()">&times;</span>
        <h2>🎯 Enroll New Student</h2>

        <div class="enroll-info">
            <p><strong>Student:</strong> <span id="applicantNameDisplay"></span></p>
            <p><strong>Course:</strong> <span id="applicantCourseDisplay"></span></p>
            <p><strong>Admission Type:</strong> <span id="applicantTypeDisplay"></span></p>
            <p style="color:#1a3c6e;font-weight:bold;">⚠️ Student number will be generated upon enrollment</p>
            <p style="color:#28a745;font-weight:bold;">📚 Select subjects individually - ONE enrollment per subject</p>
        </div>

        <form method="POST" class="enrollment-form" id="enrollmentForm">
            <input type="hidden" name="applicant_id" id="applicantId">
            <input type="hidden" name="action" value="enroll">
            <input type="hidden" name="redirect" value="?page=enrollments">
            <input type="hidden" name="course_id" id="courseId">
            <input type="hidden" name="schedule_ids" id="scheduleIdsInput" value="">

            <div class="form-group">
                <label>Select Section *</label>
                <select name="section_id" id="sectionSelect" required onchange="loadSubjectsForSection(this.value)">
                    <option value="">Choose a section</option>
                </select>
                <small id="sectionInfo" style="color:#666;display:block;margin-top:5px;"></small>
            </div>

            <div class="form-group">
                <label>School Year *</label>
                <input type="text" name="school_year" value="<?php echo htmlspecialchars($currentSchoolYear); ?>" required>
                <small style="color:#666;">Current Academic Year: <?php echo htmlspecialchars($currentSchoolYear); ?></small>
            </div>

            <div class="subjects-section" id="subjectsSection" style="display:none;">
                <div class="subjects-header">
                    <h3>📚 Select Subjects to Enroll</h3>
                    <label class="select-all-label">
                        <input type="checkbox" id="selectAllSubjects" onchange="toggleSelectAllSubjects(this)">
                        <span>Select All Available</span>
                    </label>
                </div>
                <p class="subjects-note">Check the subjects you want to enroll this student in. Each subject = one enrollment record.</p>
                <div id="subjectsList">
                    <div class="loading-text">Select a section first...</div>
                </div>
                <p style="margin-top:10px;color:#666;font-size:13px;">
                    Selected: <span id="selectedSubjectsCount" class="selected-count">0</span> subjects
                </p>
                <p style="margin-top:5px;color:#666;font-size:13px;">
                    <span id="scheduleInfo" style="color:#1a3c6e;font-weight:bold;"></span>
                </p>
            </div>

            <div class="requirements-section">
                <div class="requirements-header">
                    <h3>📋 Requirements</h3>
                    <label class="select-all-label">
                        <input type="checkbox" id="selectAllRequirements" onchange="toggleSelectAllRequirements(this)">
                        <span>Select All Requirements</span>
                    </label>
                </div>
                <p class="requirements-note">Check off the requirements that the applicant has already submitted.</p>
                <div id="requirementsList">
                    <div class="loading-text">Loading requirements...</div>
                </div>
                <p style="margin-top:10px;color:#666;font-size:13px;">
                    Selected: <span id="selectedRequirementsCount" class="selected-count">0</span> requirements
                </p>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEnrollmentModal()">Cancel</button>
                <button type="submit" class="btn btn-success" style="font-size:16px;padding:10px 30px;">
                    🎓 Enroll Student
                </button>
            </div>
        </form>
    </div>
</div>

<!-- PROGRESSION MODAL -->
<div id="progressionModal" class="modal">
    <div class="modal-content" style="max-width:800px;">
        <span class="close" onclick="closeProgressionModal()">&times;</span>
        <h2>📈 Enroll for Next Semester</h2>

        <div class="enroll-info" id="progressionInfo">
            <p><strong>Student:</strong> <span id="progressionStudentName"></span></p>
            <p><strong>Next:</strong> <span id="progressionNextLevel"></span></p>
            <p><strong>Academic Year:</strong> <span id="progressionSchoolYear"></span></p>
            <p style="color:#ffc107;font-weight:bold;">🟡 Failed subjects appear as RETAKE</p>
            <p style="color:#dc3545;font-weight:bold;">🔴 Subjects with unmet prerequisites are BLOCKED</p>
        </div>

        <form method="POST" class="enrollment-form" id="progressionForm">
            <input type="hidden" name="student_id" id="progressionStudentId">
            <input type="hidden" name="action" value="progression_enroll">
            <input type="hidden" name="redirect" value="?page=enrollments">
            <input type="hidden" name="schedule_ids" id="progressionScheduleIds" value="">

            <div class="form-group">
                <label>Select Section *</label>
                <select name="section_id" id="progressionSectionSelect" required
                        onchange="loadProgressionSubjects(this.value)">
                    <option value="">Loading sections...</option>
                </select>
                <small id="progressionSectionInfo" style="color:#666;display:block;margin-top:5px;"></small>
            </div>

            <div class="form-group">
                <label>School Year *</label>
                <input type="text" name="school_year" id="progressionSchoolYearInput"
                       value="<?php echo htmlspecialchars($currentSchoolYear); ?>" required>
                <small id="progressionSchoolYearHint" style="color:#666;"></small>
            </div>

            <div class="subjects-section" id="progressionSubjectsSection" style="display:none;">
                <div class="subjects-header">
                    <h3>📚 Subjects for Next Semester</h3>
                    <label class="select-all-label">
                        <input type="checkbox" id="selectAllProgressionSubjects" onchange="toggleSelectAllProgressionSubjects(this)">
                        <span>Select All Available</span>
                    </label>
                </div>
                <div class="subject-status-legend">
                    <span class="legend-item available">🟢 Available</span>
                    <span class="legend-item retake">🟡 Retake</span>
                    <span class="legend-item blocked">🔴 Blocked</span>
                    <span class="legend-item completed">✅ Completed</span>
                </div>
                <div id="progressionSubjectsList">
                    <div class="loading-text">Loading subjects...</div>
                </div>
                <p style="margin-top:10px;color:#666;font-size:13px;">
                    Selected: <span id="progressionSelectedCount" class="selected-count">0</span> subjects
                </p>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeProgressionModal()">Cancel</button>
                <button type="submit" class="btn btn-success" style="font-size:16px;padding:10px 30px;">
                    🎓 Enroll Next Semester
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ARCHIVE MODAL -->
<div id="archiveModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <span class="close" onclick="closeArchiveModal()">&times;</span>
        <h2>📦 Archive Student</h2>

        <div class="enroll-info">
            <p><strong>Student:</strong> <span id="archiveStudentName"></span></p>
            <p style="color:#856404;font-weight:bold;">⚠️ Ang student ay hindi made-delete — ma-archive lang. Pwede pang i-restore.</p>
        </div>

        <form method="POST" class="enrollment-form" id="archiveForm">
            <input type="hidden" name="student_id" id="archiveStudentId">
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="redirect" value="?page=enrollments">

            <div class="form-group">
                <label>Reason for Archiving *</label>
                <select name="archive_reason" id="archiveReason" required>
                    <option value="Dropped">Dropped</option>
                    <option value="Transferred">Transferred</option>
                    <option value="LOA">Leave of Absence (LOA)</option>
                    <option value="Graduated">Graduated</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeArchiveModal()">Cancel</button>
                <button type="submit" class="btn btn-warning" style="font-size:16px;padding:10px 30px;">
                    📦 Archive Student
                </button>
            </div>
        </form>
    </div>
</div>

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

<?php include $basePath . '/includes/footer.php'; ?>

<style>
.dashboard-subtitle { color: #5a7fa8; font-size: 14px; margin-bottom: 0; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
.stat-card { background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(26,60,110,0.1); text-align: center; transition: transform 0.2s ease; }
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(26,60,110,0.2); }
.stat-card .number { font-size: 28px; font-weight: 700; }
.stat-card .label { font-size: 13px; color: #5a7fa8; margin-top: 5px; }
.stat-card.primary .number { color: #1a3c6e; }
.stat-card.success .number { color: #2a5c9e; }
.stat-card.warning .number { color: #4a90d9; }
.stat-card.danger .number { color: #1a3c6e; }

.alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; }
.alert-success { background: #e8f0fe; color: #1a3c6e; border: 1px solid #b8d4e8; }
.alert-danger { background: #dce8f5; color: #1a3c6e; border: 1px solid #a8c8e8; }
.alert-info { background: #d1ecf1; color: #1a3c6e; border: 1px solid #b8d4e8; }
.alert-warning { background: #e8f4fd; color: #1a3c6e; border: 1px solid #4a90d9; }

.students-ready-section { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(26,60,110,0.1); }
.students-ready-section h2 { margin-bottom: 15px; color: #1a3c6e; font-size: 18px; }
.students-ready-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 15px; }
.student-card { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #f0f5fc; border-radius: 6px; border: 1px solid #d0e0f0; gap: 10px; }
.student-card:hover { background: #e0ecf8; border-color: #1a3c6e; transform: translateY(-2px); }
.student-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.student-name { font-weight: 600; color: #1a3c6e; font-size: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.student-course { font-size: 13px; color: #2a5c9e; }
.student-email, .student-contact { font-size: 12px; color: #5a7fa8; word-break: break-all; }
.student-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; flex-shrink: 0; }

.badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.badge-freshmen { background: #d4e8fc; color: #1a3c6e; }
.badge-transferee { background: #cce5ff; color: #1a3c6e; }
.badge-returnee { background: #e8f0fe; color: #2a5c9e; }
.badge-senior_high { background: #dce8f5; color: #1a3c6e; }
.badge-default { background: #e2e8f0; color: #4a6a8a; }
.badge-info { background: #4a90d9; color: white; }
.badge-secondary { background: #5a7fa8; color: white; }
.badge-primary { background: #1a3c6e; color: white; }
.badge-warning { background: #4a90d9; color: white; }
.badge-success { background: #2a5c9e; color: white; }

.filters-section { background: white; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(26,60,110,0.1); display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-group label { font-size: 12px; font-weight: 600; color: #5a7fa8; text-transform: uppercase; }
.filter-group select { padding: 8px 12px; border: 2px solid #d0e0f0; border-radius: 4px; font-size: 14px; min-width: 150px; background: white; transition: border-color 0.3s ease; }
.filter-group select:focus { border-color: #1a3c6e; outline: none; }
.filter-actions { display: flex; gap: 10px; margin-left: auto; align-items: flex-end; }

.enrollments-table-container { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(26,60,110,0.1); overflow-x: auto; }
.enrollments-table-container h2 { margin-bottom: 15px; color: #1a3c6e; font-size: 18px; }
.table { width: 100%; border-collapse: collapse; font-size: 14px; }
.table th { background: #f0f5fc; padding: 12px; text-align: left; font-weight: 600; font-size: 12px; text-transform: uppercase; color: #5a7fa8; border-bottom: 2px solid #d0e0f0; white-space: nowrap; }
.table td { padding: 12px; border-bottom: 1px solid #e8f0fe; vertical-align: middle; }
.table tbody tr:hover { background: #f0f5fc; }
.status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; display: inline-block; }
.status-enrolled { background: #d4e8fc; color: #1a3c6e; }
.status-dropped { background: #dce8f5; color: #1a3c6e; }
.status-completed { background: #d1ecf1; color: #1a3c6e; }
.action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
.text-center { text-align: center; padding: 20px; color: #5a7fa8; }

.btn { padding: 8px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; transition: all 0.2s ease; text-decoration: none; display: inline-block; font-weight: 500; }
.btn-sm { padding: 4px 10px; font-size: 12px; }
.btn-primary { background: #1a3c6e; color: white; }
.btn-primary:hover { background: #2a5c9e; }
.btn-success { background: #2a5c9e; color: white; }
.btn-success:hover { background: #1a3c6e; }
.btn-danger { background: #1a3c6e; color: white; }
.btn-danger:hover { background: #0f2a4e; }
.btn-warning { background: #4a90d9; color: white; }
.btn-warning:hover { background: #3a7bc8; }
.btn-secondary { background: #5a7fa8; color: white; }
.btn-secondary:hover { background: #4a6a8a; }
.btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-info { background: #4a90d9; color: white; }
.btn-info:hover { background: #3a7bc8; }

.modal { position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(26,60,110,0.5); overflow-y: auto; display: none; }
.modal-content { background: white; margin: 3% auto; padding: 30px; width: 90%; max-width: 750px; border-radius: 8px; max-height: 90vh; overflow-y: auto; }
.close { float: right; font-size: 28px; font-weight: bold; cursor: pointer; color: #a8c8e8; transition: color 0.3s; }
.close:hover { color: #1a3c6e; }

.enroll-info { background: #f0f5fc; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
.enroll-info p { margin: 5px 0; }
.enrollment-form .form-group { margin-bottom: 15px; }
.enrollment-form label { display: block; margin-bottom: 5px; font-weight: 500; }
.enrollment-form select, .enrollment-form input { width: 100%; padding: 8px 12px; border: 2px solid #d0e0f0; border-radius: 4px; font-size: 14px; transition: border-color 0.3s ease; box-sizing: border-box; }
.enrollment-form select:focus, .enrollment-form input:focus { border-color: #1a3c6e; outline: none; }
.form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e8f0fe; flex-wrap: wrap; }

.subjects-section { background: #e8f4fd; border: 1px solid #b8d4e8; border-radius: 8px; padding: 15px; margin: 20px 0; }
.subjects-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 5px; }
.subjects-header h3 { color: #1a3c6e; margin: 0; font-size: 16px; }
.select-all-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #1a3c6e; background: white; padding: 6px 12px; border-radius: 20px; border: 2px solid #b8d4e8; transition: all 0.3s ease; }
.select-all-label:hover { background: #1a3c6e; color: white; border-color: #1a3c6e; }
.select-all-label:hover input[type="checkbox"] { accent-color: white; }
.select-all-label input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #1a3c6e; }
.select-all-label input[type="checkbox"]:disabled { opacity: 0.5; cursor: not-allowed; }
.subjects-note { color: #5a7fa8; font-size: 13px; margin-bottom: 15px; }

.subject-item { display: flex; align-items: center; gap: 15px; padding: 8px 12px; background: white; border-radius: 4px; border: 1px solid #d0e0f0; margin-bottom: 5px; flex-wrap: wrap; }
.subject-item .subject-code { font-weight: 600; color: #1a3c6e; min-width: 80px; }
.subject-item .subject-name { flex: 1; min-width: 120px; }
.subject-item .subject-units { color: #5a7fa8; font-size: 12px; }
.subject-item .subject-type { color: #5a7fa8; font-size: 12px; background: #e8f0fe; padding: 2px 8px; border-radius: 10px; }
.subject-item .schedule-days { color: #4a90d9; font-size: 11px; background: #d1ecf1; padding: 2px 8px; border-radius: 10px; }
.subject-item .no-schedule { color: #1a3c6e; font-size: 11px; }
.subject-item .already-enrolled { color: #2a5c9e; font-weight: bold; font-size: 12px; }
.subject-item.checkbox-item { cursor: pointer; transition: background 0.2s; }
.subject-item.checkbox-item:hover { background: #e0ecf8; }
.subject-item.checkbox-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; flex-shrink: 0; accent-color: #1a3c6e; }
.subject-item.checkbox-item input[type="checkbox"]:disabled { cursor: not-allowed; opacity: 0.5; }

.selected-count { background: #1a3c6e; color: white; padding: 2px 10px; border-radius: 12px; font-weight: 700; font-size: 12px; }

.requirements-section { background: #f0f5fc; border: 1px solid #d0e0f0; border-radius: 8px; padding: 15px; margin: 20px 0; }
.requirements-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 5px; }
.requirements-header h3 { color: #1a3c6e; margin: 0; font-size: 16px; }
.requirements-note { color: #5a7fa8; font-size: 13px; margin-bottom: 15px; }
.loading-text { text-align: center; padding: 20px; color: #5a7fa8; }
.req-category { margin-bottom: 15px; }
.req-category h4 { color: #1a3c6e; font-size: 14px; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid #d0e0f0; display: flex; justify-content: space-between; align-items: center; }
.req-category .select-all-category { font-size: 12px; font-weight: 500; color: #1a3c6e; cursor: pointer; display: flex; align-items: center; gap: 5px; }
.req-category .select-all-category input[type="checkbox"] { width: 14px; height: 14px; cursor: pointer; accent-color: #1a3c6e; }
.req-items { display: grid; grid-template-columns: 1fr; gap: 8px; }
.req-item { display: flex; align-items: center; gap: 10px; padding: 6px 10px; background: white; border-radius: 4px; border: 1px solid #d0e0f0; flex-wrap: wrap; }
.req-item input[type="checkbox"] { width: 17px; height: 17px; cursor: pointer; flex-shrink: 0; accent-color: #1a3c6e; }
.req-item label { flex: 1; cursor: pointer; font-weight: 400; font-size: 13px; margin: 0; min-width: 150px; }
.mandatory-badge { color: #1a3c6e; font-weight: bold; }
.req-note-input { flex: 1; min-width: 120px; padding: 4px 8px; border: 1px solid #d0e0f0; border-radius: 3px; font-size: 12px; }

.schedule-day { margin-bottom: 20px; }
.schedule-day h3 { color: #1a3c6e; border-bottom: 2px solid #1a3c6e; padding-bottom: 5px; margin-bottom: 10px; }
.schedule-item { display: flex; align-items: center; gap: 15px; padding: 10px 15px; background: #f0f5fc; border-radius: 4px; border-left: 4px solid #1a3c6e; margin-bottom: 8px; flex-wrap: wrap; }
.schedule-item .time { font-weight: 600; color: #1a3c6e; min-width: 80px; }
.schedule-item .subject { flex: 1; min-width: 150px; }
.schedule-item .faculty { color: #5a7fa8; font-size: 13px; }
.schedule-item .room { color: #5a7fa8; font-size: 13px; background: #e8f0fe; padding: 2px 10px; border-radius: 10px; }
.no-schedule { text-align: center; padding: 30px; color: #5a7fa8; }

.subject-status-legend { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
.legend-item { font-size: 13px; padding: 2px 10px; border-radius: 10px; }
.legend-item.available { background: #d4e8fc; color: #1a3c6e; }
.legend-item.retake { background: #e8f0fe; color: #2a5c9e; }
.legend-item.blocked { background: #dce8f5; color: #1a3c6e; }
.legend-item.completed { background: #d1ecf1; color: #1a3c6e; }

.subject-item.retake { border-left: 4px solid #4a90d9; background: #f0f5fc; }
.subject-item.blocked { border-left: 4px solid #1a3c6e; background: #e8f0fe; opacity: 0.7; }
.subject-item.completed { border-left: 4px solid #2a5c9e; background: #e8f4fd; opacity: 0.7; }
.subject-item.available { border-left: 4px solid #4a90d9; }
.subject-status-badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.subject-status-badge.retake { background: #e8f0fe; color: #2a5c9e; }
.subject-status-badge.blocked { background: #dce8f5; color: #1a3c6e; }
.subject-status-badge.completed { background: #d1ecf1; color: #1a3c6e; }
.subject-status-badge.available { background: #d4e8fc; color: #1a3c6e; }

.subject-counts { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
.count-available { color: #4a90d9; }
.count-retake { color: #2a5c9e; font-weight: 600; }
.count-blocked { color: #1a3c6e; }
.count-completed { color: #4a90d9; }
.section-note { color: #5a7fa8; font-size: 13px; margin-bottom: 15px; }
.retake-section-header { color: #2a5c9e; margin-bottom: 10px; background: #e8f0fe; padding: 8px 12px; border-radius: 6px; border-left: 4px solid #4a90d9; }
.retake-note { color: #5a7fa8; font-size: 12px; margin-bottom: 10px; }

@media (max-width: 768px) {
    .modal-content { margin: 10% auto; padding: 20px; width: 95%; }
    .students-ready-grid { grid-template-columns: 1fr; }
    .search-form { flex-direction: column; }
    .search-form input { width: 100%; min-width: unset; }
    .student-card { flex-direction: column; align-items: stretch; }
    .student-actions { justify-content: center; }
    .req-item { flex-direction: column; align-items: flex-start; }
    .req-item label { min-width: unset; }
    .req-note-input { width: 100%; min-width: unset; }
    .filters-section { flex-direction: column; align-items: stretch; }
    .filter-actions { margin-left: 0; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .table { font-size: 12px; }
    .table th, .table td { padding: 8px; }
    .subject-item { flex-wrap: wrap; }
    .schedule-item { flex-wrap: wrap; }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
}
</style>
<script>
// ============================================================
// GLOBALS
// ============================================================
var currentApplicantId = null;
var currentCourseId    = null;
var baseUrl            = <?php echo json_encode($baseUrl, JSON_UNESCAPED_SLASHES); ?>;

console.log('Base URL:', baseUrl);

// ============================================================
// SELECT ALL HELPERS
// ============================================================

function updateSelectAllState(containerId, selectAllId, checkboxSelector) {
    var selectAll = document.getElementById(selectAllId);
    if (!selectAll) return;

    var checkboxes    = document.querySelectorAll(containerId + ' ' + checkboxSelector + ':not(:disabled)');
    var checkedBoxes  = document.querySelectorAll(containerId + ' ' + checkboxSelector + ':not(:disabled):checked');

    if (checkboxes.length === 0) {
        selectAll.checked = false;
        selectAll.disabled = true;
        selectAll.indeterminate = false;
    } else if (checkedBoxes.length === checkboxes.length) {
        selectAll.checked = true;
        selectAll.disabled = false;
        selectAll.indeterminate = false;
    } else if (checkedBoxes.length > 0) {
        selectAll.checked = false;
        selectAll.disabled = false;
        selectAll.indeterminate = true;
    } else {
        selectAll.checked = false;
        selectAll.disabled = false;
        selectAll.indeterminate = false;
    }
}

function toggleSelectAllSubjects(cb) {
    document.querySelectorAll('#subjectsList input[type="checkbox"]:not(:disabled)').forEach(function (x) {
        x.checked = cb.checked;
    });
    updateSubjectCount();
}

function toggleSelectAllProgressionSubjects(cb) {
    document.querySelectorAll('#progressionSubjectsList input[type="checkbox"]:not(:disabled)').forEach(function (x) {
        x.checked = cb.checked;
    });
    updateProgressionCount();
}

function toggleSelectAllRequirements(cb) {
    document.querySelectorAll('#requirementsList input[type="checkbox"]').forEach(function (x) {
        x.checked = cb.checked;
    });
    updateRequirementsCount();
}

function toggleCategoryRequirements(categoryCheckbox, categoryName) {
    document.querySelectorAll('#requirementsList .req-category[data-category="' + categoryName + '"] input[type="checkbox"]').forEach(function (cb) {
        cb.checked = categoryCheckbox.checked;
    });
    updateRequirementsCount();
}

// ============================================================
// ARCHIVE
// ============================================================

function openArchiveModal(studentId, studentName) {
    document.getElementById('archiveStudentId').value = studentId;
    document.getElementById('archiveStudentName').textContent = studentName;
    document.getElementById('archiveReason').value = 'Dropped';

    document.getElementById('archiveModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeArchiveModal() {
    document.getElementById('archiveModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// ============================================================
// ENROLLMENT MODAL
// ============================================================

function openEnrollmentModal(applicantId, name, course, admissionType, courseId) {
    currentApplicantId = applicantId;
    currentCourseId    = courseId;

    document.getElementById('applicantId').value              = applicantId;
    document.getElementById('applicantNameDisplay').textContent  = name;
    document.getElementById('applicantCourseDisplay').textContent = course;

    var typeLabel = (admissionType || '').charAt(0).toUpperCase()
        + (admissionType || '').slice(1).replace('_', ' ');
    document.getElementById('applicantTypeDisplay').textContent = typeLabel;
    document.getElementById('courseId').value = courseId;

    document.getElementById('sectionSelect').innerHTML = '<option value="">Loading sections...</option>';
    document.getElementById('sectionInfo').textContent = 'Loading sections...';
    document.getElementById('subjectsSection').style.display = 'none';
    document.getElementById('subjectsList').innerHTML = '<div class="loading-text">Select a section first...</div>';
    document.getElementById('selectedSubjectsCount').textContent = '0';
    document.getElementById('scheduleIdsInput').value = '';
    document.getElementById('scheduleInfo').textContent = '';

    var sa = document.getElementById('selectAllSubjects');
    if (sa) { sa.checked = false; sa.disabled = true; sa.indeterminate = false; }

    var sr = document.getElementById('selectAllRequirements');
    if (sr) { sr.checked = false; sr.disabled = true; sr.indeterminate = false; }

    document.getElementById('enrollmentModal').style.display = 'block';
    document.body.style.overflow = 'hidden';

    // ✅ Infer year level + semester from admission_type
    var yearLevel = 1;
    var semester  = 1;

    switch ((admissionType || '').toLowerCase()) {
        case 'freshmen':
        case 'senior_high':
        case 'transferee':
            yearLevel = 1;
            semester  = 1;
            break;
        case 'returnee':
            // Returnee — default sa 1st Year / 1st Sem (pwedeng i-override sa server)
            yearLevel = 1;
            semester  = 1;
            break;
        default:
            yearLevel = 1;
            semester  = 1;
    }

    loadAvailableSections(courseId, yearLevel, semester);
    loadRequirements(admissionType);
}

function closeEnrollmentModal() {
    document.getElementById('enrollmentModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentApplicantId = null;
    currentCourseId = null;
}

function loadAvailableSections(courseId, yearLevel, semester) {
    var select = document.getElementById('sectionSelect');
    var info   = document.getElementById('sectionInfo');

    select.innerHTML = '<option value="">Loading sections...</option>';
    info.textContent = 'Loading sections...';

    // ✅ Pass year_level + semester + only_available=1
    var url = baseUrl + '/api/get_sections_by_course.php'
            + '?course_id='       + encodeURIComponent(courseId)
            + '&year_level='      + encodeURIComponent(yearLevel || 1)
            + '&semester='        + encodeURIComponent(semester || 1)
            + '&only_available=1';

    fetch(url)
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            select.innerHTML = '<option value="">Choose a section</option>';

            if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                var yearText = (yearLevel === 1) ? '1st Year'
                             : (yearLevel === 2) ? '2nd Year'
                             : (yearLevel === 3) ? '3rd Year' : '4th Year';
                var semText  = (semester === 1) ? '1st Semester' : '2nd Semester';

                data.data.forEach(function (section) {
                    var opt = document.createElement('option');
                    opt.value = section.id;
                    var availText = (section.available_slots !== undefined)
                        ? ' (' + section.available_slots + ' slots available)'
                        : '';
                    opt.textContent = (section.section_code || '')
                        + ' - ' + (section.grade_level || 'N/A')
                        + ' ' + (section.semester || '')
                        + availText;
                    select.appendChild(opt);
                });

                info.innerHTML = '✅ Found ' + data.data.length + ' section(s) for <strong>'
                    + yearText + ' - ' + semText + '</strong>. Auto-selected: <strong>'
                    + data.data[0].section_code + '</strong>';
                info.style.color = '#28a745';

                // ✅ AUTO-SELECT first section (pinaka-maluwag)
                select.value = data.data[0].id;
                loadSubjectsForSection(data.data[0].id);
            } else {
                select.innerHTML = '<option value="">No sections available</option>';
                info.innerHTML = '⚠️ Walang section na may bakante para sa <strong>'
                    + ((yearLevel === 1) ? '1st Year'
                        : (yearLevel === 2) ? '2nd Year'
                        : (yearLevel === 3) ? '3rd Year' : '4th Year')
                    + ' - ' + ((semester === 1) ? '1st Semester' : '2nd Semester')
                    + '</strong>. Kailangan mag-add ng bagong section.';
                info.style.color = '#dc3545';
                document.getElementById('subjectsSection').style.display = 'none';
            }
        })
        .catch(function (err) {
            console.error('Error loading sections:', err);
            select.innerHTML = '<option value="">Error loading sections</option>';
            info.textContent = 'Could not load sections. Please refresh and try again.';
            info.style.color = '#dc3545';
        });
}

function loadSubjectsForSection(sectionId) {
    if (!sectionId) {
        document.getElementById('subjectsSection').style.display = 'none';
        return;
    }

    var applicantId = document.getElementById('applicantId').value;
    if (!applicantId) {
        document.getElementById('subjectsSection').style.display = 'none';
        return;
    }

    var container = document.getElementById('subjectsList');
    var section   = document.getElementById('subjectsSection');
    var countEl   = document.getElementById('selectedSubjectsCount');
    var scheduleInput = document.getElementById('scheduleIdsInput');
    var scheduleInfo  = document.getElementById('scheduleInfo');
    var sa            = document.getElementById('selectAllSubjects');

    container.innerHTML = '<div class="loading-text">Loading subjects...</div>';
    section.style.display = 'block';
    countEl.textContent = '0';
    scheduleInput.value = '';
    scheduleInfo.textContent = '';
    if (sa) { sa.checked = false; sa.disabled = true; sa.indeterminate = false; }

    var url = baseUrl + '/api/get_subjects_for_section.php?section_id='
        + encodeURIComponent(sectionId) + '&applicant_id=' + encodeURIComponent(applicantId);

    fetch(url)
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + r.statusText); return r.json(); })
        .then(function (data) {
            if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                renderSubjectsWithCheckboxes(data.data);
            } else {
                container.innerHTML = '<div class="alert alert-info">' + (data.message || 'No subjects available for this section.') + '</div>';
                scheduleInput.value = '';
                scheduleInfo.textContent = '';
            }
        })
        .catch(function (err) {
            console.error('Error loading subjects:', err);
            container.innerHTML = '<div class="alert alert-danger">Error loading subjects: ' + err.message + '</div>';
            scheduleInput.value = '';
            scheduleInfo.textContent = '';
        });
}

function renderSubjectsWithCheckboxes(subjects) {
    var container = document.getElementById('subjectsList');
    if (!container) return;

    var html = '';
    var enrollableCount = 0;
    var scheduleIds = [];

    subjects.forEach(function (subject) {
        var hasSchedule = (subject.has_schedule === true || subject.has_schedule === 1);
        var scheduleId  = subject.representative_schedule_id || null;
        var isEnrolled  = subject.is_enrolled === true;

        // subject_type is NOT returned by get_subjects_for_section.php after our fix.
        // Use ?? fallback so it renders "Lecture" without breaking.
        var typeLabel = subject.subject_type || 'Lecture';

        var scheduleInfoText = '';
        if (hasSchedule && Array.isArray(subject.schedule_details) && subject.schedule_details.length > 0) {
            var days = subject.schedule_details.map(function (s) {
                return (s.day || '') + ' ' + String(s.start_time || '').substring(0, 5);
            }).join(', ');
            scheduleInfoText = '<span class="schedule-days">📅 ' + days + '</span>';
        } else if (scheduleId) {
            scheduleInfoText = '<span class="schedule-days">📅 Has schedule</span>';
        } else {
            scheduleInfoText = '<span class="no-schedule">⚠️ No schedule</span>';
        }

        var canEnroll = hasSchedule && !isEnrolled && scheduleId;
        if (canEnroll) { enrollableCount++; scheduleIds.push(scheduleId); }

        html += '<div class="subject-item checkbox-item">';
        html += '<input type="checkbox" name="schedule_ids_array[]" value="' + (scheduleId || '') + '" '
             +  (canEnroll ? '' : 'disabled') + ' onchange="updateSubjectCount()">';
        html += '<span class="subject-code">' + (subject.subject_code || 'N/A') + '</span>';
        html += '<span class="subject-name">' + (subject.subject_name || 'Unknown') + '</span>';
        html += '<span class="subject-units">' + (subject.units || 0) + ' units</span>';
        html += '<span class="subject-type">' + typeLabel + '</span>';
        html += scheduleInfoText;
        if (isEnrolled) html += '<span class="already-enrolled">✅ Enrolled</span>';
        html += '</div>';
    });

    if (enrollableCount === 0) {
        html += '<div class="alert alert-warning">No subjects available for enrollment. All subjects are either already enrolled or have no schedule.</div>';
    }

    container.innerHTML = html;
    document.getElementById('selectedSubjectsCount').textContent = '0';
    document.getElementById('scheduleIdsInput').value = scheduleIds.join(',');

    var scheduleInfoEl = document.getElementById('scheduleInfo');
    if (scheduleIds.length > 0) {
        scheduleInfoEl.textContent = '📅 ' + scheduleIds.length + ' subject(s) available for enrollment.';
        scheduleInfoEl.style.color = '#28a745';
    } else {
        scheduleInfoEl.textContent = '⚠️ No subjects available for enrollment.';
        scheduleInfoEl.style.color = '#dc3545';
    }

    updateSelectAllState('#subjectsList', 'selectAllSubjects', 'input[type="checkbox"]');
}

function updateSubjectCount() {
    var count = document.querySelectorAll('#subjectsList input[type="checkbox"]:checked').length;
    document.getElementById('selectedSubjectsCount').textContent = count;
    updateSelectAllState('#subjectsList', 'selectAllSubjects', 'input[type="checkbox"]');
}

// ============================================================
// PROGRESSION ENROLLMENT
// ============================================================

function openProgressionEnrollment(studentId, studentName, yearLevel, semester, schoolYear) {
    document.getElementById('progressionStudentId').value = studentId;
    document.getElementById('progressionStudentName').textContent = studentName;

    var semesterName = semester === 1 ? '1st Semester' : '2nd Semester';
    var yearText = yearLevel === 1 ? '1st Year'
                 : yearLevel === 2 ? '2nd Year'
                 : yearLevel === 3 ? '3rd Year' : '4th Year';

    document.getElementById('progressionNextLevel').textContent = yearText + ' - ' + semesterName;
    document.getElementById('progressionSchoolYear').textContent = schoolYear || '<?php echo htmlspecialchars($currentSchoolYear); ?>';
    document.getElementById('progressionSchoolYearInput').value = schoolYear || '<?php echo htmlspecialchars($currentSchoolYear); ?>';

    if (schoolYear && schoolYear !== '<?php echo htmlspecialchars($currentSchoolYear); ?>') {
        document.getElementById('progressionSchoolYearHint').textContent = '📅 New Academic Year: ' + schoolYear;
        document.getElementById('progressionSchoolYearHint').style.color = '#dc3545';
    } else {
        document.getElementById('progressionSchoolYearHint').textContent = 'Same Academic Year: <?php echo htmlspecialchars($currentSchoolYear); ?>';
        document.getElementById('progressionSchoolYearHint').style.color = '#28a745';
    }

    document.getElementById('progressionSectionSelect').innerHTML = '<option value="">Loading sections...</option>';
    document.getElementById('progressionSectionInfo').textContent = '';
    document.getElementById('progressionSubjectsSection').style.display = 'none';
    document.getElementById('progressionSubjectsList').innerHTML = '';
    document.getElementById('progressionSelectedCount').textContent = '0';
    document.getElementById('progressionScheduleIds').value = '';

    var sa = document.getElementById('selectAllProgressionSubjects');
    if (sa) { sa.checked = false; sa.disabled = true; sa.indeterminate = false; }

    document.getElementById('progressionModal').style.display = 'block';
    document.body.style.overflow = 'hidden';

    loadProgressionSections(studentId, yearLevel, semester);
}

function closeProgressionModal() {
    document.getElementById('progressionModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function loadProgressionSections(studentId, yearLevel, semester) {
    var select = document.getElementById('progressionSectionSelect');
    select.innerHTML = '<option value="">Loading sections...</option>';

    fetch(baseUrl + '/api/get_student_progression.php?student_id=' + encodeURIComponent(studentId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success || !data.data || !data.data.student) {
                select.innerHTML = '<option value="">Student data not found</option>';
                return;
            }

            var studentCourseId = data.data.student.course_id;

            // ✅ Pass year_level + semester + only_available
            var url = baseUrl + '/api/get_sections_by_course.php'
                    + '?course_id='       + encodeURIComponent(studentCourseId)
                    + '&year_level='      + encodeURIComponent(yearLevel)
                    + '&semester='        + encodeURIComponent(semester)
                    + '&only_available=1';

            return fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (sectionData) {
                    select.innerHTML = '<option value="">Choose a section</option>';
                    var info = document.getElementById('progressionSectionInfo');

                    if (sectionData.success && Array.isArray(sectionData.data) && sectionData.data.length > 0) {
                        var yearText = yearLevel === 1 ? '1st Year'
                                     : yearLevel === 2 ? '2nd Year'
                                     : yearLevel === 3 ? '3rd Year' : '4th Year';
                        var semText  = semester === 1 ? '1st Semester' : '2nd Semester';

                        sectionData.data.forEach(function (section) {
                            var opt = document.createElement('option');
                            opt.value = section.id;
                            var slots = (section.available_slots !== undefined)
                                ? ' [' + section.available_slots + '/40 slots]'
                                : '';
                            opt.textContent = section.section_code
                                + ' - ' + section.grade_level
                                + ' ' + (section.semester || semText)
                                + slots;
                            opt.dataset.availableSlots = section.available_slots || 0;
                            select.appendChild(opt);
                        });

                        info.textContent = '✅ ' + sectionData.data.length
                            + ' section(s) for ' + yearText + ' - ' + semText
                            + ' (may bakante pa)';
                        info.style.color = '#28a745';

                        // ✅ AUTO-SELECT first section (pinaka-maluwag)
                        select.value = sectionData.data[0].id;
                        loadProgressionSubjects(sectionData.data[0].id);

                        // ✅ Show auto-fill notice
                        var autoMsg = document.getElementById('progressionSectionInfo');
                        autoMsg.innerHTML = '✅ ' + sectionData.data.length + ' section(s) for '
                            + yearText + ' - ' + semText
                            + ' — <strong>Auto-selected: ' + sectionData.data[0].section_code
                            + '</strong> (' + sectionData.data[0].available_slots + ' slots left)';
                    } else {
                        select.innerHTML = '<option value="">No sections with available slots</option>';
                        info.innerHTML = '⚠️ Walang section na may bakante para sa <strong>'
                            + (semester === 1 ? '1st Semester' : '2nd Semester')
                            + '</strong> (' + (yearLevel === 1 ? '1st Year'
                                : yearLevel === 2 ? '2nd Year'
                                : yearLevel === 3 ? '3rd Year' : '4th Year') + '). '
                            + 'Kailangan mag-add ng bagong section.';
                        info.style.color = '#dc3545';
                        document.getElementById('progressionSubjectsSection').style.display = 'none';
                    }
                });
        })
        .catch(function (err) {
            console.error('Error:', err);
            select.innerHTML = '<option value="">Error loading sections</option>';
        });
}

function loadProgressionSubjects(sectionId) {
    if (!sectionId) {
        document.getElementById('progressionSubjectsSection').style.display = 'none';
        return;
    }

    var studentId = document.getElementById('progressionStudentId').value;
    var container = document.getElementById('progressionSubjectsList');
    container.innerHTML = '<div class="loading-text">Loading subjects...</div>';
    document.getElementById('progressionSubjectsSection').style.display = 'block';
    document.getElementById('progressionSelectedCount').textContent = '0';
    document.getElementById('progressionScheduleIds').value = '';

    var sa = document.getElementById('selectAllProgressionSubjects');
    if (sa) { sa.checked = false; sa.disabled = true; sa.indeterminate = false; }

    var url = baseUrl + '/api/get_available_subjects_with_status.php?student_id='
        + encodeURIComponent(studentId) + '&section_id=' + encodeURIComponent(sectionId);

    fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.data) {
                renderProgressionSubjectsWithCheckboxes(data.data);
            } else {
                container.innerHTML = '<div class="alert alert-info">No subjects available.</div>';
            }
        })
        .catch(function (err) {
            console.error('Error:', err);
            container.innerHTML = '<div class="alert alert-danger">Error loading subjects.</div>';
        });
}

function renderProgressionSubjectsWithCheckboxes(data) {
    var container   = document.getElementById('progressionSubjectsList');
    var allSubjects = data.all_subjects || [];
    var counts      = data.counts || {};
    var scheduleIds = [];
    var html = '';

    html += '<div class="subject-counts">';
    html += '<span class="count-available">🟢 Available: ' + (counts.available || 0) + '</span>';
    html += '<span class="count-retake">🟡 Retake: ' + (counts.retake || 0) + '</span>';
    html += '<span class="count-blocked">🔴 Blocked: ' + (counts.blocked || 0) + '</span>';
    html += '<span class="count-completed">✅ Completed: ' + (counts.completed || 0) + '</span>';
    html += '</div>';

    if (allSubjects.length === 0) {
        html += '<div class="alert alert-info">No subjects found for this semester.</div>';
    } else {
        var retakeSubjects = allSubjects.filter(function (s) { return s.status === 'RETAKE'; });
        var otherSubjects  = allSubjects.filter(function (s) { return s.status !== 'RETAKE'; });

        if (retakeSubjects.length > 0) {
            html += '<div style="margin-bottom:20px;">';
            html += '<h4 class="retake-section-header">🟡 Retake Subjects (Failed Previously)</h4>';
            html += '<p class="retake-note">I-check ang mga subject na nais i-retake.</p>';

            retakeSubjects.forEach(function (subject) {
                var scheduleId = subject.representative_schedule_id || null;
                var canCheck   = scheduleId !== null && scheduleId > 0;

                if (canCheck) scheduleIds.push(scheduleId);

                html += '<div class="subject-item retake">';
                html += '<input type="checkbox" name="schedule_ids_array[]" value="' + (scheduleId || '') + '" '
                     +  (canCheck ? '' : 'disabled') + ' onchange="updateProgressionCount()">';
                html += '<span class="subject-code">' + (subject.subject_code || 'N/A') + '</span>';
                html += '<span class="subject-name">' + (subject.subject_name || 'Unknown');
                if (subject.previous_grade) {
                    html += ' <small style="color:#856404;font-size:11px;">(Previous Grade: ' + subject.previous_grade + ')</small>';
                }
                html += '</span>';
                html += '<span class="subject-units">' + (subject.units || 0) + ' units</span>';
                html += '<span class="subject-status-badge retake">🔄 Retake</span>';
                if (!canCheck) {
                    html += '<span style="color:#dc3545;font-size:11px;">⚠️ Walang schedule sa active semester</span>';
                }
                html += '</div>';
            });
            html += '</div>';
        }

        if (otherSubjects.length > 0) {
            html += '<div>';
            html += '<h4 style="color:#1a3c6e;margin-bottom:10px;">📚 Regular Subjects</h4>';

            otherSubjects.forEach(function (subject) {
                var status        = subject.status || 'AVAILABLE';
                var statusClass   = status.toLowerCase();
                var scheduleId    = subject.representative_schedule_id || null;
                var statusLabel   = status;
                var badgeClass    = 'available';
                var canEnroll     = false;

                switch (status) {
                    case 'AVAILABLE':
                        statusLabel = '✅ Available';
                        badgeClass  = 'available';
                        canEnroll   = true;
                        break;
                    case 'BLOCKED':
                        statusLabel = '🚫 Blocked';
                        badgeClass  = 'blocked';
                        canEnroll   = false;
                        break;
                    case 'COMPLETED':
                    case 'PASSED':
                        statusLabel = '✅ Completed';
                        badgeClass  = 'completed';
                        canEnroll   = false;
                        break;
                }

                var message  = subject.message || '';
                var canCheck = canEnroll && scheduleId && scheduleId > 0;

                if (canCheck) scheduleIds.push(scheduleId);

                html += '<div class="subject-item ' + statusClass + '">';
                html += '<input type="checkbox" name="schedule_ids_array[]" value="' + (scheduleId || '') + '" '
                     +  (canCheck ? '' : 'disabled') + ' onchange="updateProgressionCount()">';
                html += '<span class="subject-code">' + (subject.subject_code || 'N/A') + '</span>';
                html += '<span class="subject-name">' + (subject.subject_name || 'Unknown');
                if (message) {
                    html += ' <small style="color:#666;font-size:11px;">(' + message + ')</small>';
                }
                html += '</span>';
                html += '<span class="subject-units">' + (subject.units || 0) + ' units</span>';
                html += '<span class="subject-status-badge ' + badgeClass + '">' + statusLabel + '</span>';
                html += '</div>';
            });
            html += '</div>';
        }
    }

    container.innerHTML = html;
    document.getElementById('progressionSelectedCount').textContent = '0';
    document.getElementById('progressionScheduleIds').value = scheduleIds.join(',');

    updateSelectAllState('#progressionSubjectsList', 'selectAllProgressionSubjects', 'input[type="checkbox"]');
}

function updateProgressionCount() {
    var count = document.querySelectorAll('#progressionSubjectsList input[type="checkbox"]:checked').length;
    document.getElementById('progressionSelectedCount').textContent = count;
    updateSelectAllState('#progressionSubjectsList', 'selectAllProgressionSubjects', 'input[type="checkbox"]');
}

// ============================================================
// SCHEDULE VIEW
// ============================================================

function viewSchedule(studentId) {
    var modal   = document.getElementById('scheduleModal');
    var content = document.getElementById('scheduleContent');
    content.innerHTML = '<div class="loading-text">Loading schedule...</div>';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    fetch(baseUrl + '/api/get_student_schedule.php?student_id=' + encodeURIComponent(studentId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.data && data.data.grouped) {
                renderSchedule(data.data.grouped);
            } else {
                content.innerHTML = '<div class="alert alert-info">No schedule found.</div>';
            }
        })
        .catch(function (err) {
            console.error('Error:', err);
            content.innerHTML = '<div class="alert alert-danger">Error loading schedule.</div>';
        });
}

function renderSchedule(groupedSchedule) {
    var content = document.getElementById('scheduleContent');
    var html    = '';
    var total   = 0;
    var dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    dayOrder.forEach(function (day) {
        if (!groupedSchedule[day] || groupedSchedule[day].length === 0) return;

        html += '<div class="schedule-day"><h3>' + day + '</h3>';

        groupedSchedule[day].sort(function (a, b) {
            return (a.start_time || '00:00:00').localeCompare(b.start_time || '00:00:00');
        });

        groupedSchedule[day].forEach(function (item) {
            total++;
            html += '<div class="schedule-item">';
            html += '<span class="time">' + String(item.start_time || '--:--').substring(0, 5) + ' - '
                 +  String(item.end_time || '--:--').substring(0, 5) + '</span>';
            html += '<span class="subject"><strong>' + (item.subject_code || '') + '</strong> - ' + (item.subject_name || '') + '</span>';
            if (item.faculty_name) html += '<span class="faculty">👨‍🏫 ' + item.faculty_name + '</span>';
            if (item.room_code)    html += '<span class="room">🏫 ' + item.room_code + '</span>';
            html += '</div>';
        });

        html += '</div>';
    });

    if (total === 0) html = '<div class="no-schedule">📅 No scheduled subjects found.</div>';

    content.innerHTML = html;
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// ============================================================
// REQUIREMENTS
// ============================================================

function loadRequirements(admissionType) {
    var container = document.getElementById('requirementsList');
    container.innerHTML = '<div class="loading-text">Loading requirements...</div>';

    var url = baseUrl + '/api/get_requirements_by_type.php?admission_type='
        + encodeURIComponent(admissionType);

    fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && Array.isArray(data.requirements) && data.requirements.length > 0) {
                renderRequirements(data.requirements);
            } else {
                container.innerHTML = '<div class="alert alert-info">No specific requirements found.</div>';
            }
        })
        .catch(function (err) {
            console.error('Error:', err);
            container.innerHTML = '<div class="alert alert-danger">Error loading requirements.</div>';
        });
}

function renderRequirements(requirements) {
    var container = document.getElementById('requirementsList');
    var grouped = {};

    requirements.forEach(function (req) {
        var cat = req.requirement_category || 'General';
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(req);
    });

    var html = '';
    Object.keys(grouped).forEach(function (category) {
        html += '<div class="req-category" data-category="' + category + '">';
        html += '<h4>' + category.charAt(0).toUpperCase() + category.slice(1) + ' Requirements';
        html += '<label class="select-all-category">';
        html += '<input type="checkbox" onchange="toggleCategoryRequirements(this, \'' + category + '\')">';
        html += '<span>Select All</span>';
        html += '</label></h4>';
        html += '<div class="req-items">';

        grouped[category].forEach(function (req) {
            html += '<div class="req-item">';
            html += '<input type="checkbox" name="requirements[' + req.requirement_id + ']" value="1" id="req_' + req.requirement_id + '" onchange="updateRequirementsCount()">';
            html += '<label for="req_' + req.requirement_id + '">' + req.requirement_name;
            if (req.is_mandatory) html += ' <span class="mandatory-badge">*</span>';
            html += '</label>';
            html += '<input type="text" name="req_notes[' + req.requirement_id + ']" placeholder="Notes (optional)" class="req-note-input">';
            html += '</div>';
        });

        html += '</div></div>';
    });

    container.innerHTML = html;

    var sa = document.getElementById('selectAllRequirements');
    if (sa) { sa.disabled = false; sa.checked = false; sa.indeterminate = false; }

    updateRequirementsCount();
}

function updateRequirementsCount() {
    var count = document.querySelectorAll('#requirementsList input[type="checkbox"]:checked').length;
    var el    = document.getElementById('selectedRequirementsCount');
    if (el) el.textContent = count;

    var selectAll = document.getElementById('selectAllRequirements');
    if (selectAll) {
        var all     = document.querySelectorAll('#requirementsList input[type="checkbox"]');
        var checked = document.querySelectorAll('#requirementsList input[type="checkbox"]:checked');

        if (all.length === 0) {
            selectAll.checked = false; selectAll.disabled = true; selectAll.indeterminate = false;
        } else if (checked.length === all.length) {
            selectAll.checked = true; selectAll.disabled = false; selectAll.indeterminate = false;
        } else if (checked.length > 0) {
            selectAll.checked = false; selectAll.disabled = false; selectAll.indeterminate = true;
        } else {
            selectAll.checked = false; selectAll.disabled = false; selectAll.indeterminate = false;
        }
    }

    document.querySelectorAll('#requirementsList .req-category').forEach(function (cat) {
        var catBoxes    = cat.querySelectorAll('input[type="checkbox"]');
        var catChecked  = cat.querySelectorAll('input[type="checkbox"]:checked');
        var catSelectAll = cat.querySelector('.select-all-category input[type="checkbox"]');
        if (catSelectAll) {
            if (catChecked.length === catBoxes.length && catBoxes.length > 0) {
                catSelectAll.checked = true; catSelectAll.indeterminate = false;
            } else if (catChecked.length > 0) {
                catSelectAll.checked = false; catSelectAll.indeterminate = true;
            } else {
                catSelectAll.checked = false; catSelectAll.indeterminate = false;
            }
        }
    });
}

// ============================================================
// MODAL CLOSE HANDLERS
// ============================================================

window.addEventListener('click', function (e) {
    if (e.target === document.getElementById('enrollmentModal'))  closeEnrollmentModal();
    if (e.target === document.getElementById('progressionModal')) closeProgressionModal();
    if (e.target === document.getElementById('scheduleModal'))    closeScheduleModal();
    if (e.target === document.getElementById('archiveModal'))     closeArchiveModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeEnrollmentModal();
        closeProgressionModal();
        closeScheduleModal();
        closeArchiveModal();
    }
});
</script>