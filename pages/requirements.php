<?php
// pages/requirements.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • Removed duplicate onchange handler on student select (was firing twice)
//   • addslashes() in onclick= attributes replaced with json_encode + htmlspecialchars
//   • is_array() guards on all controller returns
//   • Null-safe reads throughout
//   • window.onclick replaced with addEventListener

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'classes/Database.php';
require_once 'classes/Requirement.php';
require_once 'classes/Student.php';
require_once 'classes/Application.php';
require_once 'classes/Enrollment.php';
require_once 'classes/RequirementController.php';

$controller = new RequirementController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->handlePostRequest();
}

$view           = $_GET['view']     ?? 'students';
$studentId      = isset($_GET['student_id']) ? (int) $_GET['student_id'] : null;
$categoryFilter = $_GET['category'] ?? null;
$searchQuery    = isset($_GET['search']) ? trim($_GET['search']) : '';

$allStudents = $controller->getAllStudentsWithSearch($searchQuery);
if (!is_array($allStudents)) $allStudents = [];

// Auto-select handling
if (!empty($searchQuery) && count($allStudents) === 1 && empty($studentId) && $view === 'students') {
    $redirectKey = 'auto_select_' . md5($searchQuery . '_' . $categoryFilter);

    if (!isset($_SESSION[$redirectKey]) || $_SESSION[$redirectKey] < time() - 5) {
        $_SESSION[$redirectKey] = time();
        $autoSelectedId = $allStudents[0]['student_id'];
        $redirectUrl = '?page=requirements&view=students&student_id=' . $autoSelectedId;
        if ($categoryFilter) {
            $redirectUrl .= '&category=' . urlencode($categoryFilter);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    unset($_SESSION[$redirectKey]);
    $currentUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $params     = $_GET;
    unset($params['search']);
    $newUrl     = $currentUrl . '?' . http_build_query($params);
    if ($_SERVER['REQUEST_URI'] !== $newUrl) {
        header('Location: ' . $newUrl);
        exit;
    }
}

if (!empty($searchQuery)) {
    $redirectKey = 'auto_select_' . md5($searchQuery . '_' . $categoryFilter);
    unset($_SESSION[$redirectKey]);
}

$studentDetails      = null;
$studentRequirements = [];
$completionStatus    = null;

if ($studentId) {
    $studentDetails = $controller->getStudentDetails($studentId);
    if (!$studentDetails) {
        $studentId = null;
    }
}

$categories = $controller->getAllCategories();
if (!is_array($categories)) $categories = [];

if ($studentId && $studentDetails) {
    $studentRequirements = $controller->getStudentRequirements($studentId, $categoryFilter);
    if (!is_array($studentRequirements)) $studentRequirements = [];

    $completionStatus = $controller->getCompletionStatus($studentId);
    if (!is_array($completionStatus)) {
        $completionStatus = ['submitted' => 0, 'total' => 0, 'percentage' => 0];
    }
}

$allRequirements        = $controller->getAllRequirementsGrouped();
if (!is_array($allRequirements)) $allRequirements = [];

$studentsWithIncomplete = $controller->getStudentsWithIncomplete();
if (!is_array($studentsWithIncomplete)) $studentsWithIncomplete = [];

$followupStudents       = $controller->getFollowupStudents();
if (!is_array($followupStudents)) $followupStudents = [];

$pendingFollowups = count($followupStudents);
$totalIncomplete  = count($studentsWithIncomplete);

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$pageTitle = 'Requirements Management';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>📋 Requirements Management</h1>
            <p class="dashboard-subtitle">Manage student requirements</p>
        </div>

        <div class="module-content">
            <!-- View Navigation -->
            <div class="view-nav">
                <div class="btn-group">
                    <a href="?page=requirements&view=students" class="btn <?php echo $view === 'students' ? 'btn-primary' : 'btn-secondary'; ?>">
                        👥 Student Requirements
                    </a>
                    <a href="?page=requirements&view=followup" class="btn <?php echo $view === 'followup' ? 'btn-primary' : 'btn-secondary'; ?>">
                        📞 Follow-ups
                        <?php if ($pendingFollowups > 0): ?>
                            <span class="badge"><?php echo $pendingFollowups; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?page=requirements&view=manage" class="btn <?php echo $view === 'manage' ? 'btn-primary' : 'btn-secondary'; ?>">
                        ⚙️ Manage
                    </a>
                </div>
                <?php if ($view === 'manage'): ?>
                    <button class="btn btn-success" onclick="openModal('addRequirementModal')">
                        ➕ Add Requirement
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo strpos($message, '✅') !== false ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- FOLLOW-UP VIEW -->
            <?php if ($view === 'followup'): ?>
                <div class="followup-view">
                    <div class="followup-header">
                        <h2>📞 Students Needing Follow-up</h2>
                        <span class="text-muted">Students who need to submit their remaining requirements</span>
                    </div>

                    <?php if (count($followupStudents) > 0): ?>
                        <div class="followup-grid">
                            <?php foreach ($followupStudents as $s):
                                $name = trim(
                                    ($s['first_name'] ?? '')
                                    . ' ' . ($s['middle_name'] ?? '')
                                    . ' ' . ($s['surname'] ?? '')
                                    . ' ' . ($s['suffix'] ?? '')
                                );
                                $missing      = (int) (($s['total_mandatory'] ?? 0) - ($s['submitted_mandatory'] ?? 0));
                                $followupDate = !empty($s['followup_date'])
                                    ? date('M d, Y', strtotime($s['followup_date']))
                                    : 'Not scheduled';
                                $statusClass  = ($s['followup_status'] ?? '') === 'pending' ? 'pending' : 'done';
                            ?>
                            <div class="followup-card <?php echo $statusClass; ?>">
                                <div class="followup-info">
                                    <div class="student-header">
                                        <span class="student-name"><?php echo htmlspecialchars($name); ?></span>
                                        <span class="student-number">🎓 <?php echo htmlspecialchars($s['student_number'] ?? ''); ?></span>
                                    </div>
                                    <div class="student-details">
                                        <span>📚 <?php echo htmlspecialchars($s['course_code'] ?? 'No Course'); ?></span>
                                        <span class="missing-requirements">⚠️ Missing <?php echo $missing; ?> requirement(s)</span>
                                    </div>
                                    <div class="followup-meta">
                                        <span>📅 Follow-up: <?php echo htmlspecialchars($followupDate); ?></span>
                                        <?php if (!empty($s['followup_notes'])): ?>
                                            <span class="followup-notes">📝 <?php echo htmlspecialchars($s['followup_notes']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="followup-actions">
                                    <a href="?page=requirements&student_id=<?php echo (int) $s['student_id']; ?>&view=students"
                                       class="btn btn-primary">✏️ Update</a>
                                    <form method="POST" style="display:inline-block;">
                                        <input type="hidden" name="student_id" value="<?php echo (int) $s['student_id']; ?>">
                                        <input type="hidden" name="action" value="mark_followup_done">
                                        <button type="submit" class="btn btn-success"
                                                onclick="return confirm('Mark this follow-up as completed?')">
                                            ✅ Done
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            ✅ <strong>No pending follow-ups!</strong> All students have completed their requirements.
                        </div>
                    <?php endif; ?>

                    <!-- All Incomplete Students -->
                    <div class="incomplete-students">
                        <h3>📋 All Students with Incomplete Requirements</h3>
                        <p class="text-muted">Students who still need to submit requirements (including those with follow-up scheduled)</p>

                        <?php if (count($studentsWithIncomplete) > 0): ?>
                            <div class="incomplete-grid">
                                <?php foreach ($studentsWithIncomplete as $s):
                                    $name = trim(
                                        ($s['first_name'] ?? '')
                                        . ' ' . ($s['middle_name'] ?? '')
                                        . ' ' . ($s['surname'] ?? '')
                                        . ' ' . ($s['suffix'] ?? '')
                                    );
                                    $missing     = (int) (($s['total_mandatory'] ?? 0) - ($s['submitted_mandatory'] ?? 0));
                                    $hasFollowup = !empty($s['followup_date']);
                                ?>
                                <div class="incomplete-card <?php echo $hasFollowup ? 'has-followup' : 'no-followup'; ?>">
                                    <div class="incomplete-info">
                                        <span class="student-name"><?php echo htmlspecialchars($name); ?></span>
                                        <span class="student-number">🎓 <?php echo htmlspecialchars($s['student_number'] ?? ''); ?></span>
                                        <span class="student-course">📚 <?php echo htmlspecialchars($s['course_code'] ?? 'No Course'); ?></span>
                                        <span class="missing-count">⚠️ Missing <?php echo $missing; ?> requirement(s)</span>
                                        <?php if ($hasFollowup): ?>
                                            <span class="followup-badge">
                                                📅 Follow-up: <?php echo htmlspecialchars(date('M d, Y', strtotime($s['followup_date']))); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="incomplete-actions">
                                        <a href="?page=requirements&student_id=<?php echo (int) $s['student_id']; ?>&view=students"
                                           class="btn btn-primary">✏️ Update</a>
                                        <?php if (!$hasFollowup): ?>
                                            <button class="btn btn-warning"
                                                    onclick="openFollowupModal(
                                                        <?php echo (int) $s['student_id']; ?>,
                                                        <?php echo htmlspecialchars(json_encode($name), ENT_QUOTES); ?>
                                                    )">
                                                📞 Schedule Follow-up
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                ✅ All students have completed their requirements!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- MANAGE VIEW -->
            <?php elseif ($view === 'manage'): ?>
                <div class="manage-requirements-view">
                    <?php foreach ($categories as $cat):
                        $category = $cat['requirement_category'] ?? '';
                        $reqList  = $allRequirements[$category] ?? [];
                    ?>
                        <div class="category-management">
                            <h3><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $category))); ?> Requirements</h3>
                            <div class="requirement-list">
                                <?php if (!empty($reqList)): ?>
                                    <?php foreach ($reqList as $req):
                                        $reqId       = (int) ($req['requirement_id'] ?? 0);
                                        $reqName     = $req['requirement_name'] ?? '';
                                        $isMandatory = !empty($req['is_mandatory']);
                                    ?>
                                        <div class="requirement-manage-item">
                                            <div class="req-info">
                                                <span class="req-name"><?php echo htmlspecialchars($reqName); ?></span>
                                                <span class="req-mandatory">
                                                    <?php echo $isMandatory ? 'Required *' : 'Optional'; ?>
                                                </span>
                                            </div>
                                            <div class="req-actions">
                                                <button class="btn btn-sm btn-primary"
                                                        onclick="editRequirement(
                                                            <?php echo $reqId; ?>,
                                                            <?php echo htmlspecialchars(json_encode($reqName), ENT_QUOTES); ?>,
                                                            <?php echo htmlspecialchars(json_encode($category), ENT_QUOTES); ?>,
                                                            <?php echo $isMandatory ? '1' : '0'; ?>
                                                        )">
                                                    ✏️
                                                </button>
                                                <form method="POST" style="display:inline-block;">
                                                    <input type="hidden" name="requirement_id" value="<?php echo $reqId; ?>">
                                                    <input type="hidden" name="action" value="delete_requirement">
                                                    <button type="submit" class="btn btn-sm btn-danger"
                                                            onclick="return confirm('Are you sure you want to delete this requirement?')">
                                                        🗑️
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="alert alert-info" style="margin:10px 0;">No requirements in this category.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <!-- STUDENT VIEW -->
            <?php else: ?>
                <div class="student-requirements-view">
                    <!-- Student Selector -->
                    <div class="student-selector">
                        <div class="selector-form">
                            <div class="selector-row">
                                <div class="selector-row-left">
                                    <label for="student_id">Select Student:</label>
                                    <select name="student_id" id="student_id">
                                        <option value="">-- Choose a student --</option>
                                        <?php foreach ($allStudents as $s):
                                            $sid          = (int) ($s['student_id'] ?? 0);
                                            $applicant    = $controller->getApplicationById($s['applicant_id'] ?? 0);
                                            $name         = $applicant
                                                ? trim(
                                                    ($applicant['first_name'] ?? '')
                                                    . ' ' . ($applicant['surname'] ?? '')
                                                )
                                                : 'Student #' . ($s['student_number'] ?? '');
                                        ?>
                                            <option value="<?php echo $sid; ?>"
                                                <?php echo $studentId === $sid ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(($s['student_number'] ?? '') . ' - ' . $name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <form method="GET" class="search-form" id="searchForm" autocomplete="off">
                                    <input type="hidden" name="page" value="requirements">
                                    <input type="hidden" name="view" value="students">
                                    <?php if ($studentId): ?>
                                        <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
                                    <?php endif; ?>
                                    <?php if ($categoryFilter): ?>
                                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($categoryFilter); ?>">
                                    <?php endif; ?>
                                    <div style="position:relative;display:flex;align-items:center;gap:8px;flex-wrap:wrap;width:100%;max-width:350px;">
                                        <input type="text"
                                               name="search"
                                               id="searchInput"
                                               placeholder="🔍 Type to search by Name or ID..."
                                               value="<?php echo htmlspecialchars($searchQuery); ?>"
                                               autocomplete="off"
                                               style="flex:1;min-width:150px;">
                                        <span class="search-loading" id="searchLoading">⏳ Searching...</span>
                                    </div>
                                    <button type="submit" class="btn btn-primary" id="searchButton" style="display:none;">Search</button>
                                    <?php if ($searchQuery !== ''): ?>
                                        <a href="?page=requirements&view=students<?php echo $studentId ? '&student_id=' . $studentId : ''; ?><?php echo $categoryFilter ? '&category=' . urlencode($categoryFilter) : ''; ?>"
                                           class="btn btn-outline-secondary" id="clearSearchBtn">✖ Clear</a>
                                    <?php endif; ?>
                                </form>
                            </div>

                            <?php if ($searchQuery !== ''): ?>
                                <div class="search-results-info" id="searchResultsInfo">
                                    Found <strong><?php echo count($allStudents); ?></strong> student(s) matching
                                    "<strong><?php echo htmlspecialchars($searchQuery); ?></strong>"
                                    (searching by <strong>Name</strong> or <strong>Student ID</strong>)
                                    <?php if (count($allStudents) === 0): ?>
                                        <span class="text-muted"> - Try a different search term</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($studentId && $studentDetails): ?>
                            <div class="student-summary">
                                <span class="student-name">
                                    <?php echo htmlspecialchars(trim(
                                        ($studentDetails['first_name'] ?? '')
                                        . ' ' . ($studentDetails['surname'] ?? '')
                                    )); ?>
                                </span>
                                <span class="student-number">🎓 <?php echo htmlspecialchars($studentDetails['student_number'] ?? ''); ?></span>
                                <span class="student-course">📚 <?php echo htmlspecialchars($studentDetails['course_code'] ?? 'No Course'); ?></span>
                                <?php if (!empty($studentDetails['section_code'])): ?>
                                    <span class="student-section">🏫 <?php echo htmlspecialchars($studentDetails['section_code']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($studentId && $studentDetails): ?>
                        <!-- Progress Bar -->
                        <?php $pct = (int) ($completionStatus['percentage'] ?? 0); ?>
                        <div class="completion-progress">
                            <div class="progress-label">
                                <span>Requirements Completion</span>
                                <span>
                                    <?php echo (int) ($completionStatus['submitted'] ?? 0); ?>/<?php echo (int) ($completionStatus['total'] ?? 0); ?>
                                    (<?php echo $pct; ?>%)
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill"
                                     style="width:<?php echo $pct; ?>%;background:<?php echo $pct >= 80 ? '#28a745' : ($pct >= 50 ? '#ffc107' : '#dc3545'); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Category Filter -->
                        <div class="category-filter">
                            <div class="filter-buttons">
                                <a href="?page=requirements&student_id=<?php echo $studentId; ?>&view=students<?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?>"
                                   class="filter-btn <?php echo !$categoryFilter ? 'active' : ''; ?>">All</a>
                                <?php foreach ($categories as $cat):
                                    $catVal = $cat['requirement_category'] ?? '';
                                ?>
                                    <a href="?page=requirements&student_id=<?php echo $studentId; ?>&view=students&category=<?php echo urlencode($catVal); ?><?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?>"
                                       class="filter-btn <?php echo $categoryFilter === $catVal ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $catVal))); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Requirements Form -->
                        <form method="POST" class="requirements-form" id="studentRequirementsForm">
                            <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
                            <input type="hidden" name="action" value="update_requirements">

                            <div class="requirements-grid">
                                <?php
                                $currentCategory = '';
                                foreach ($studentRequirements as $req):
                                    $reqCat = $req['requirement_category'] ?? '';
                                    $reqId  = (int) ($req['requirement_id'] ?? 0);
                                    $isSub  = !empty($req['is_submitted']);
                                    $isMan  = !empty($req['is_mandatory']);

                                    if ($currentCategory !== $reqCat):
                                        if ($currentCategory !== ''): ?>
                                            </div><!-- /.requirement-items -->
                                        </div><!-- /.category-section -->
                                        <?php endif; ?>

                                        <div class="category-section">
                                            <h3 class="category-title">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $reqCat))); ?> Requirements
                                            </h3>
                                            <div class="requirement-items">
                                        <?php
                                        $currentCategory = $reqCat;
                                    endif;
                                ?>
                                    <div class="requirement-item <?php echo $isSub ? 'submitted' : 'pending'; ?>">
                                        <div class="requirement-check">
                                            <input type="checkbox"
                                                   name="requirements[<?php echo $reqId; ?>]"
                                                   value="1"
                                                   <?php echo $isSub ? 'checked' : ''; ?>
                                                   id="req_<?php echo $reqId; ?>">
                                            <label for="req_<?php echo $reqId; ?>">
                                                <?php echo htmlspecialchars($req['requirement_name'] ?? ''); ?>
                                                <?php if ($isMan): ?>
                                                    <span class="mandatory-badge">*</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        <div class="requirement-notes">
                                            <input type="text"
                                                   name="req_notes[<?php echo $reqId; ?>]"
                                                   placeholder="Add notes..."
                                                   value="<?php echo htmlspecialchars($req['notes'] ?? ''); ?>"
                                                   class="note-input">
                                        </div>
                                        <div class="requirement-status">
                                            <?php if ($isSub): ?>
                                                <span class="status-badge submitted">✅ Submitted</span>
                                                <?php if (!empty($req['submitted_date'])): ?>
                                                    <small><?php echo htmlspecialchars(date('M d, Y', strtotime($req['submitted_date']))); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="status-badge pending">⏳ Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($currentCategory !== ''): ?>
                                            </div><!-- /.requirement-items -->
                                        </div><!-- /.category-section -->
                                <?php endif; ?>
                            </div><!-- /.requirements-grid -->

                            <div class="form-actions">
                                <button type="submit" class="btn btn-success btn-large">
                                    💾 Save Requirements
                                </button>
                            </div>
                        </form>

                    <?php else: ?>
                        <div class="alert alert-info">
                            ℹ️ Please select a student to manage their requirements.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- FOLLOW-UP MODAL -->
<div id="followupModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('followupModal')">&times;</span>
        <h2>📞 Schedule Follow-up</h2>
        <div class="followup-info-box">
            <p><strong>Student:</strong> <span id="followupStudentName"></span></p>
        </div>
        <form method="POST">
            <input type="hidden" name="student_id" id="followupStudentId">
            <input type="hidden" name="action" value="update_followup">
            <div class="form-group">
                <label>Follow-up Date *</label>
                <input type="date" name="followup_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="followup_notes" rows="3" placeholder="Enter notes about the follow-up..." class="form-textarea"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('followupModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Schedule Follow-up</button>
            </div>
        </form>
    </div>
</div>

<!-- ADD REQUIREMENT MODAL -->
<div id="addRequirementModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addRequirementModal')">&times;</span>
        <h2>➕ Add New Requirement</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_requirement">
            <div class="form-group">
                <label>Requirement Name *</label>
                <input type="text" name="requirement_name" required>
            </div>
            <div class="form-group">
                <label>Category *</label>
                <select name="requirement_category" required>
                    <option value="freshmen">Freshmen</option>
                    <option value="transferee">Transferee</option>
                    <option value="continuing">Continuing</option>
                </select>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_mandatory" checked>
                    Mandatory Requirement
                </label>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addRequirementModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Requirement</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT REQUIREMENT MODAL -->
<div id="editRequirementModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editRequirementModal')">&times;</span>
        <h2>✏️ Edit Requirement</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_requirement">
            <input type="hidden" name="requirement_id" id="edit_req_id">
            <div class="form-group">
                <label>Requirement Name *</label>
                <input type="text" name="requirement_name" id="edit_req_name" required>
            </div>
            <div class="form-group">
                <label>Category *</label>
                <select name="requirement_category" id="edit_req_category" required>
                    <option value="freshmen">Freshmen</option>
                    <option value="transferee">Transferee</option>
                    <option value="continuing">Continuing</option>
                </select>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_mandatory" id="edit_req_mandatory">
                    Mandatory Requirement
                </label>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editRequirementModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Requirement</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       REQUIREMENTS MANAGEMENT — Blue & Sky Blue Theme
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

    .view-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .btn-group {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        color: white;
        background: var(--navy-dark);
        margin-left: 4px;
    }

    .text-muted {
        color: var(--sky-muted);
        font-size: 14px;
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

    /* ---------- BUTTONS ---------- */
    .btn {
        padding: 8px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-weight: 500;
        font-family: inherit;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn-sm { padding: 4px 10px; font-size: 12px; }
    .btn-large { padding: 12px 30px; font-size: 16px; }

    .btn-primary { background: var(--navy); color: white; }
    .btn-primary:hover { background: var(--blue); }

    .btn-success { background: var(--blue); color: white; }
    .btn-success:hover { background: var(--navy); }

    .btn-secondary { background: var(--sky-muted); color: white; }
    .btn-secondary:hover { background: var(--blue); }

    .btn-danger { background: var(--navy-dark); color: white; }
    .btn-danger:hover { background: var(--navy); }

    .btn-warning { background: var(--sky); color: white; }
    .btn-warning:hover { background: var(--blue-mid); }

    .btn-outline-secondary {
        background: transparent;
        color: var(--sky-muted);
        border: 1px solid var(--sky-muted);
    }
    .btn-outline-secondary:hover {
        background: var(--sky-muted);
        color: white;
    }

    /* ---------- STUDENT SELECTOR ---------- */
    .student-selector {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
    }
    .selector-form {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .selector-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    .selector-row-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        flex: 1;
    }
    .selector-row-left label {
        font-weight: 600;
        color: var(--navy);
        white-space: nowrap;
        font-size: 13px;
    }
    .selector-row-left select {
        padding: 8px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        min-width: 200px;
        flex: 1;
        color: var(--navy);
        background: white;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .selector-row-left select:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    .search-form {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .search-form input[type="text"] {
        padding: 8px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        min-width: 200px;
        width: 100%;
        max-width: 300px;
        color: var(--navy);
        transition: all 0.3s ease;
        background: white;
        box-sizing: border-box;
    }
    .search-form input[type="text"]::placeholder {
        color: var(--sky-muted);
    }
    .search-form input[type="text"]:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }
    .search-form input[type="text"].searching {
        border-color: var(--navy);
        background: var(--sky-bg-soft);
    }
    .search-loading {
        display: none;
        font-size: 13px;
        color: var(--navy);
        animation: pulse 1s ease-in-out infinite;
    }
    .search-loading.active { display: inline-block; }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.3; }
    }

    .search-results-info {
        font-size: 13px;
        color: var(--sky-muted);
        padding: 5px 0;
    }
    .search-results-info strong { color: var(--navy); }

    .student-summary {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        padding: 10px 15px;
        background: var(--sky-bg-soft);
        border-radius: 4px;
        margin-top: 10px;
        border-left: 4px solid var(--navy);
    }
    .student-summary span {
        font-weight: 500;
        color: var(--navy);
    }
    .student-number { color: var(--navy); }
    .student-course { color: var(--sky-muted); }
    .student-section { color: var(--blue); }

    /* ---------- PROGRESS BAR ---------- */
    .completion-progress {
        background: white;
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
    }
    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--navy);
    }
    .progress-bar {
        width: 100%;
        height: 20px;
        background: var(--sky-bg);
        border-radius: 10px;
        overflow: hidden;
    }
    .progress-fill {
        height: 100%;
        transition: width 0.3s ease;
    }

    /* ---------- CATEGORY FILTER ---------- */
    .category-filter { margin-bottom: 20px; }
    .filter-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .filter-btn {
        padding: 8px 16px;
        background: var(--sky-bg-soft);
        border: 1px solid var(--sky-border);
        border-radius: 4px;
        text-decoration: none;
        color: var(--navy);
        transition: all 0.2s ease;
        font-weight: 500;
        font-size: 13px;
    }
    .filter-btn:hover {
        background: var(--sky-bg);
        color: var(--navy);
    }
    .filter-btn.active {
        background: var(--navy);
        color: white;
        border-color: var(--navy);
    }

    /* ---------- REQUIREMENTS FORM ---------- */
    .requirements-form {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
    }
    .requirements-grid {
        display: flex;
        flex-direction: column;
        gap: 25px;
    }
    .category-section {
        border: 1px solid var(--sky-bg);
        border-radius: 8px;
        padding: 15px;
    }
    .category-title {
        color: var(--navy);
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
        font-size: 15px;
    }
    .requirement-items {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .requirement-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 12px 15px;
        background: var(--sky-bg-soft);
        border-radius: 4px;
        border-left: 4px solid var(--sky-border);
        transition: all 0.2s ease;
        flex-wrap: wrap;
    }
    .requirement-item.submitted {
        border-left-color: var(--blue);
        background: #e8f0fe;
    }
    .requirement-item.pending {
        border-left-color: var(--sky);
        background: var(--sky-bg-soft);
    }
    .requirement-check {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 200px;
    }
    .requirement-check input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: var(--navy);
    }
    .requirement-check label {
        cursor: pointer;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 5px;
        color: var(--navy);
    }
    .mandatory-badge {
        color: var(--navy-dark);
        font-weight: 700;
    }
    .requirement-notes {
        flex: 1;
        min-width: 150px;
    }
    .note-input {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid var(--sky-border);
        border-radius: 4px;
        font-size: 13px;
        color: var(--navy);
        background: white;
    }
    .note-input:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }
    .requirement-status {
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }
    .status-badge {
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
    }
    .status-badge.submitted {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }
    .status-badge.pending {
        background: #e8f0fe;
        color: var(--blue);
        border: 1px solid var(--sky-border);
    }

    /* ---------- FORM ELEMENTS ---------- */
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--sky-bg);
        flex-wrap: wrap;
    }
    .form-group { margin-bottom: 15px; }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: var(--navy);
        font-size: 13px;
    }
    .form-group input[type="text"],
    .form-group select {
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
    .form-group input[type="text"]:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }
    .form-textarea {
        width: 100%;
        padding: 8px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        font-family: inherit;
        color: var(--navy);
        background: white;
        box-sizing: border-box;
        resize: vertical;
    }
    .form-textarea:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    /* ---------- FOLLOW-UP VIEW ---------- */
    .followup-view { margin-top: 10px; }
    .followup-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 20px;
        gap: 10px;
    }
    .followup-header h2 {
        color: var(--navy);
        margin: 0;
        font-size: 18px;
    }
    .followup-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 15px;
        margin-bottom: 30px;
    }
    .followup-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        border-left: 4px solid var(--sky);
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .followup-card.done {
        border-left-color: var(--blue);
        opacity: 0.75;
    }
    .followup-info {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .student-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .student-header .student-name {
        font-weight: 700;
        font-size: 16px;
        color: var(--navy);
    }
    .student-details {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        font-size: 14px;
        color: var(--sky-muted);
    }
    .missing-requirements {
        color: var(--navy-dark);
        font-weight: 600;
    }
    .followup-meta {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        font-size: 13px;
        color: var(--sky-muted);
    }
    .followup-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 5px;
    }
    .followup-notes { font-style: italic; }

    .incomplete-students {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-top: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
    }
    .incomplete-students h3 {
        color: var(--navy);
        margin-bottom: 15px;
        font-size: 16px;
    }
    .incomplete-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 15px;
    }
    .incomplete-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        background: var(--sky-bg);
        border-radius: 6px;
        border: 1px solid var(--sky-border);
        gap: 10px;
        flex-wrap: wrap;
    }
    .incomplete-card.has-followup {
        background: #d4e8fc;
        border-color: var(--sky-pale);
    }
    .incomplete-card.no-followup {
        background: var(--sky-bg);
        border-color: var(--sky-border);
    }
    .incomplete-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .incomplete-info .student-name {
        font-weight: 700;
        color: var(--navy);
    }
    .incomplete-info .student-number {
        font-size: 13px;
        color: var(--blue);
    }
    .incomplete-info .student-course {
        font-size: 12px;
        color: var(--sky-muted);
    }
    .incomplete-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .missing-count {
        font-size: 13px;
        color: var(--navy-dark);
        font-weight: 600;
    }
    .followup-badge {
        font-size: 12px;
        color: var(--navy);
        background: #d4e8fc;
        padding: 2px 10px;
        border-radius: 12px;
        border: 1px solid var(--sky-pale);
    }

    /* ---------- MANAGE VIEW ---------- */
    .manage-requirements-view {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
    }
    .category-management { margin-bottom: 25px; }
    .category-management h3 {
        color: var(--navy);
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
        font-size: 15px;
    }
    .requirement-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .requirement-manage-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 15px;
        background: var(--sky-bg-soft);
        border-radius: 4px;
        border: 1px solid var(--sky-bg);
        flex-wrap: wrap;
        gap: 10px;
    }
    .req-info {
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }
    .req-name {
        font-weight: 600;
        color: var(--navy);
    }
    .req-mandatory {
        font-size: 12px;
        color: var(--blue);
        font-weight: 700;
    }
    .req-actions {
        display: flex;
        gap: 5px;
    }

    /* ---------- MODAL ---------- */
    .modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(26, 60, 110, 0.55);
        display: none;
        overflow-y: auto;
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
        line-height: 1;
        transition: color 0.2s ease;
    }
    .close:hover { color: var(--navy); }
    .followup-info-box {
        background: var(--sky-bg-soft);
        padding: 10px 15px;
        border-radius: 4px;
        margin-bottom: 15px;
        border-left: 3px solid var(--navy);
        color: var(--navy);
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
        .view-nav { flex-direction: column; align-items: stretch; }
        .btn-group { flex-direction: column; }
        .btn-group .btn { width: 100%; justify-content: center; }
        .selector-row { flex-direction: column; align-items: stretch; }
        .selector-row-left { flex-direction: column; align-items: stretch; }
        .selector-row-left select { width: 100%; }
        .search-form { flex-direction: column; align-items: stretch; }
        .search-form input[type="text"] { max-width: 100%; }
        .student-summary { flex-direction: column; gap: 5px; }
        .requirement-item { flex-direction: column; align-items: stretch; }
        .requirement-check { min-width: unset; }
        .requirement-notes { min-width: unset; }
        .incomplete-grid { grid-template-columns: 1fr; }
        .incomplete-card { flex-direction: column; align-items: stretch; text-align: center; }
        .followup-grid { grid-template-columns: 1fr; }
        .student-header { flex-direction: column; align-items: flex-start; gap: 5px; }
        .requirement-manage-item { flex-direction: column; align-items: stretch; }
        .req-info { justify-content: center; }
        .req-actions { justify-content: center; }
    }
</style>

<script>
// ============================================================
// MODAL HELPERS
// ============================================================
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
    document.body.style.overflow = 'auto';
}

window.openFollowupModal = function (studentId, studentName) {
    document.getElementById('followupStudentId').value = studentId;
    document.getElementById('followupStudentName').textContent = studentName;
    openModal('followupModal');
};

window.editRequirement = function (id, name, category, mandatory) {
    document.getElementById('edit_req_id').value           = id;
    document.getElementById('edit_req_name').value         = name;
    document.getElementById('edit_req_category').value     = category;
    document.getElementById('edit_req_mandatory').checked  = (mandatory == 1);
    openModal('editRequirementModal');
};

// ============================================================
// SEARCH / SELECT
// ============================================================
document.addEventListener('DOMContentLoaded', function () {
    var searchInput    = document.getElementById('searchInput');
    var searchForm     = document.getElementById('searchForm');
    var searchLoading  = document.getElementById('searchLoading');
    var clearSearchBtn = document.getElementById('clearSearchBtn');
    var searchTimeout  = null;
    var isSubmitting   = false;

    if (searchInput && searchForm) {
        searchInput.addEventListener('focus', function () { this.classList.add('searching'); });
        searchInput.addEventListener('blur',  function () { this.classList.remove('searching'); });

        searchInput.addEventListener('input', function () {
            if (searchTimeout) clearTimeout(searchTimeout);

            var searchValue = this.value.trim();
            if (searchLoading) searchLoading.classList.add('active');

            if (searchValue === '') {
                searchTimeout = setTimeout(function () {
                    if (!isSubmitting) {
                        isSubmitting = true;
                        var url = new URL(window.location.href);
                        url.searchParams.delete('search');
                        if (searchLoading) searchLoading.classList.remove('active');
                        window.location.href = url.toString();
                    }
                }, 300);
                return;
            }

            searchTimeout = setTimeout(function () {
                if (!isSubmitting) {
                    isSubmitting = true;
                    var currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('search', searchValue);
                    if (searchLoading) searchLoading.classList.remove('active');
                    window.location.href = currentUrl.toString();
                }
            }, 500);
        });

        searchInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (searchTimeout) clearTimeout(searchTimeout);
                var searchValue = this.value.trim();
                if (searchValue !== '') {
                    var currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('search', searchValue);
                    window.location.href = currentUrl.toString();
                }
            }
        });

        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var url = new URL(window.location.href);
                url.searchParams.delete('search');
                window.location.href = url.toString();
            });
        }
    }

    // FIX: single onchange handler for the student select
    // (previously there was ALSO an inline onchange= attribute → double navigation)
    var studentSelect = document.getElementById('student_id');
    if (studentSelect) {
        studentSelect.addEventListener('change', function () {
            var url = new URL(window.location.href);
            if (this.value) {
                url.searchParams.set('student_id', this.value);
            } else {
                url.searchParams.delete('student_id');
            }
            var searchVal = document.getElementById('searchInput');
            if (searchVal && searchVal.value.trim()) {
                url.searchParams.set('search', searchVal.value.trim());
            }
            window.location.href = url.toString();
        });
    }

    if (searchInput && !searchInput.value) {
        var hasStudent = new URLSearchParams(window.location.search).get('student_id');
        if (!hasStudent) {
            setTimeout(function () { searchInput.focus(); }, 300);
        }
    }
});

// ============================================================
// OUTSIDE-CLICK & ESC
// ============================================================
window.addEventListener('click', function (event) {
    ['addRequirementModal', 'editRequirementModal', 'followupModal'].forEach(function (id) {
        var modal = document.getElementById(id);
        if (event.target === modal) closeModal(id);
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeModal('addRequirementModal');
        closeModal('editRequirementModal');
        closeModal('followupModal');
    }
});
</script>