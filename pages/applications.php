<?php
// pages/applications.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • N/A is now SELECTABLE via per-field checkbox for optional text fields
//   • year_graduated: text input accepting "2024-2025" format
//   • date_of_birth and place_of_birth optional (matches DB nullable)
//   • Age auto-calculation supports both new + edit modals
//   • deleteOldApplications() runs at most once per day
//   • $applications guaranteed to be an array before count()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'classes/Application.php';
require_once 'classes/Course.php';
require_once 'classes/Student.php';
require_once 'classes/ApplicationController.php';

$applicationController = new ApplicationController();

// Cleanup once per day
$today = date('Y-m-d');
if (($_SESSION['last_app_cleanup'] ?? '') !== $today) {
    $applicationController->deleteOldApplications();
    $_SESSION['last_app_cleanup'] = $today;
}

// AJAX
if (isset($_GET['ajax'])) {
    $applicationController->handleAjaxRequest();
    exit;
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $applicationController->handlePostRequest();
}

// Filters
$status = $_GET['status'] ?? 'pending';
$search = $_GET['search'] ?? '';

if (isset($_GET['search']) && $_GET['search'] === '') {
    $params = $_GET;
    unset($params['search']);
    $newUrl = '?' . http_build_query($params);
    if ($_SERVER['REQUEST_URI'] !== $newUrl) {
        header('Location: ' . $newUrl);
        exit;
    }
}

// Load applications
if ($status === 'all') {
    $applications = $applicationController->getAllApplications();
} else {
    $applications = $applicationController->getApplicationsByStatus($status);
}

if (!is_array($applications)) $applications = [];

// Search filter
if ($search !== '') {
    $needle = strtolower($search);
    $applications = array_filter($applications, function ($app) use ($needle) {
        return strpos(strtolower($app['first_name']     ?? ''), $needle) !== false
            || strpos(strtolower($app['surname']        ?? ''), $needle) !== false
            || strpos(strtolower($app['email']          ?? ''), $needle) !== false
            || strpos(strtolower($app['contact_number'] ?? ''), $needle) !== false;
    });
}

$courses = $applicationController->getCourses();
if (!is_array($courses)) $courses = [];

$stats = $applicationController->getStats();

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$pageTitle = 'Applications Management';

// Helper: render a value or "N/A"
if (!function_exists('na')) {
    function na($value, $fallback = 'N/A') {
        if ($value === null) return $fallback;
        if (is_string($value) && trim($value) === '') return $fallback;
        return $value;
    }
}

/**
 * Render a text input with a selectable "N/A" checkbox next to it.
 *
 * @param string $name         Input name attribute
 * @param string $id           Input id (optional, for JS targeting)
 * @param string $label        Field label
 * @param bool   $required     Whether the field is required
 * @param string $placeholder  Placeholder text
 * @param string $value        Current value (from DB when editing)
 * @param string $type         Input type: 'text' | 'textarea'
 * @param string $helpText     Optional help text below input
 * @param bool   $hasPattern   Whether to include the YYYY-YYYY pattern
 */
function naInput($name, $id = '', $label = '', $required = false,
                 $placeholder = '', $value = '', $type = 'text',
                 $helpText = '', $hasPattern = false) {
    $idAttr         = $id !== '' ? "id=\"{$id}\"" : '';
    $requiredAttr   = $required ? ' required' : '';
    $requiredMark   = $required ? ' <span class="required">*</span>' : '';
    $isNA           = is_string($value) && strtoupper(trim($value)) === 'N/A';
    $displayValue   = $isNA ? 'N/A' : $value;
    $readonlyAttr   = $isNA ? ' readonly' : '';
    $checkedAttr    = $isNA ? ' checked' : '';
    $patternAttr    = $hasPattern
        ? ' pattern="\d{4}(\s*-\s*\d{4})?" title="Format: YYYY-YYYY (e.g., 2024-2025)"'
        : '';

    $inputId = $id !== '' ? $id : $name;

    echo '<div class="form-group na-group">';
    echo "<label for=\"{$inputId}\">{$label}{$requiredMark}</label>";

    echo '<div class="na-input-wrap">';

    if ($type === 'textarea') {
        echo "<textarea name=\"{$name}\" {$idAttr} rows=\"2\"{$requiredAttr}{$readonlyAttr} "
           . "placeholder=\"" . htmlspecialchars($placeholder) . "\" "
           . "class=\"na-input\" data-na-toggle=\"na_chk_{$inputId}\">"
           . htmlspecialchars($displayValue)
           . "</textarea>";
    } else {
        echo "<input type=\"text\" name=\"{$name}\" {$idAttr}{$requiredAttr}{$readonlyAttr}"
           . "{$patternAttr} "
           . "placeholder=\"" . htmlspecialchars($placeholder) . "\" "
           . "value=\"" . htmlspecialchars($displayValue) . "\" "
           . "class=\"na-input\" data-na-toggle=\"na_chk_{$inputId}\">";
    }

    echo '<label class="na-checkbox" for="na_chk_' . htmlspecialchars($inputId) . '" '
       . 'title="Check to set this field to N/A">'
       . "<input type=\"checkbox\" id=\"na_chk_{$inputId}\" "
       . "onchange=\"toggleNA('{$inputId}', this)\"{$checkedAttr}>"
       . '<span>N/A</span>'
       . '</label>';

    echo '</div>';

    if ($helpText !== '') {
        echo '<div class="help-text">' . htmlspecialchars($helpText) . '</div>';
    }

    echo '</div>';
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>📋 Applications Management</h1>
            <p class="dashboard-subtitle">Manage student applications</p>
        </div>

        <div class="module-content">

            <!-- STATISTICS -->
            <div class="stats-grid">
                <div class="stat-card stat-total">
                    <span class="number"><?php echo (int) ($stats['total'] ?? 0); ?></span>
                    <span class="label">📊 Total Applications</span>
                </div>
                <div class="stat-card stat-pending">
                    <span class="number"><?php echo (int) ($stats['pending'] ?? 0); ?></span>
                    <span class="label">⏳ Pending</span>
                </div>
                <div class="stat-card stat-converted">
                    <span class="number"><?php echo (int) ($stats['converted'] ?? 0); ?></span>
                    <span class="label">✅ Converted</span>
                </div>
                <div class="stat-card stat-rejected">
                    <span class="number"><?php echo (int) ($stats['rejected'] ?? 0); ?></span>
                    <span class="label">❌ Rejected</span>
                </div>
            </div>

            <!-- INFO BANNER -->
            <div class="info-banner">
                <strong>💡 How it works:</strong> Submit an application, then go to
                <a href="?page=enrollments">Enrollments</a> to enroll the student.
                <br>
                <small>✅ <strong>NEW:</strong> Optional fields have an <strong>N/A</strong>
                checkbox — tick it to explicitly set the field to "N/A".</small>
            </div>

            <!-- MESSAGES -->
            <?php if ($message !== ''): ?>
                <?php $isSuccess = (strpos($message, '✅') !== false); ?>
                <div class="alert alert-<?php echo $isSuccess ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- SEARCH & FILTERS -->
            <div class="search-section">
                <div class="status-filters">
                    <a href="?page=applications&status=pending"
                       class="status-filter <?php echo $status === 'pending' ? 'active' : ''; ?>">
                        ⏳ Pending <span class="count"><?php echo (int) ($stats['pending'] ?? 0); ?></span>
                    </a>
                    <a href="?page=applications&status=converted"
                       class="status-filter <?php echo $status === 'converted' ? 'active' : ''; ?>">
                        ✅ Converted <span class="count"><?php echo (int) ($stats['converted'] ?? 0); ?></span>
                    </a>
                    <a href="?page=applications&status=rejected"
                       class="status-filter <?php echo $status === 'rejected' ? 'active' : ''; ?>">
                        ❌ Rejected <span class="count"><?php echo (int) ($stats['rejected'] ?? 0); ?></span>
                    </a>
                    <a href="?page=applications&status=all"
                       class="status-filter <?php echo $status === 'all' ? 'active' : ''; ?>">
                        📊 All <span class="count"><?php echo (int) ($stats['total'] ?? 0); ?></span>
                    </a>
                </div>

                <form method="GET" class="search-form">
                    <input type="hidden" name="page" value="applications">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                    <input type="text" name="search"
                           placeholder="Search applications by name, email, or contact..."
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="?page=applications&status=<?php echo htmlspecialchars($status); ?>"
                           class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- APPLICATIONS TABLE -->
            <div class="applications-table-container">
                <div class="table-header">
                    <span class="title">
                        <?php echo ucfirst($status); ?> Applications
                        <?php if ($search !== ''): ?>
                            <span style="font-weight: normal; color: #666; font-size: 14px;">
                                (search: "<?php echo htmlspecialchars($search); ?>")
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="badge-count"><?php echo count($applications); ?></span>
                </div>

                <?php if (count($applications) > 0): ?>
                <form method="POST" id="bulkActionForm" onsubmit="return confirmBulkAction();">
                    <table class="table">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAll"
                                           onchange="toggleAllCheckboxes(this)">
                                </th>
                                <th>Name</th>
                                <th>Course</th>
                                <th>Contact</th>
                                <th>Type</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $app): ?>
                            <tr>
                                <td class="checkbox-column">
                                    <?php if (($app['status'] ?? '') !== 'converted'): ?>
                                        <input type="checkbox" name="ids[]"
                                               value="<?php echo (int) $app['applicant_id']; ?>"
                                               class="app-checkbox">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>
                                        <?php
                                            $firstName = na($app['first_name'] ?? '', '');
                                            $surname   = na($app['surname']    ?? '', '');
                                            echo htmlspecialchars(trim($firstName . ' ' . $surname));
                                        ?>
                                    </strong>
                                    <?php
                                        $suffix = trim((string) ($app['suffix'] ?? ''));
                                        if ($suffix !== '' && strtoupper($suffix) !== 'N/A'):
                                    ?>
                                        <small>(<?php echo htmlspecialchars($suffix); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(na($app['course_code'] ?? '')); ?></td>
                                <td>
                                    <?php echo htmlspecialchars(na($app['contact_number'] ?? '')); ?><br>
                                    <small><?php echo htmlspecialchars(na($app['email'] ?? '')); ?></small>
                                </td>
                                <td>
                                    <?php
                                        $typeLabels = [
                                            'freshmen'    => 'Freshmen',
                                            'transferee'  => 'Transferee',
                                            'returnee'    => 'Returnee',
                                            'senior_high' => 'Senior High'
                                        ];
                                        $admissionType = $app['admission_type'] ?? '';
                                        $typeLabel  = $typeLabels[$admissionType] ?? 'N/A';
                                        $badgeClass = $admissionType !== ''
                                            ? 'badge-' . $admissionType
                                            : 'badge-default';
                                    ?>
                                    <span class="badge <?php echo htmlspecialchars($badgeClass); ?>">
                                        <?php echo htmlspecialchars($typeLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                        $submitted = $app['submitted_at'] ?? null;
                                        echo $submitted ? date('M d, Y', strtotime($submitted)) : 'N/A';
                                    ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($app['status'] ?? 'pending'); ?>">
                                        <?php echo ucfirst(htmlspecialchars($app['status'] ?? 'pending')); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?page=application-details&id=<?php echo (int) $app['applicant_id']; ?>"
                                           class="btn btn-sm btn-info">View</a>
                                        <?php if (($app['status'] ?? '') === 'pending'): ?>
                                            <a href="javascript:void(0)"
                                               onclick="editApplication(<?php echo (int) $app['applicant_id']; ?>)"
                                               class="btn btn-sm btn-warning">Edit</a>
                                            <a href="?page=enrollments&applicant_id=<?php echo (int) $app['applicant_id']; ?>"
                                               class="btn btn-sm btn-success">🎯 Enroll</a>
                                            <form method="POST" style="display: inline-block;"
                                                  onsubmit="return confirm('Are you sure you want to delete this application?');">
                                                <input type="hidden" name="applicant_id"
                                                       value="<?php echo (int) $app['applicant_id']; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($status === 'pending'): ?>
                    <div class="bulk-actions">
                        <span>Bulk Actions:</span>
                        <select name="bulk_action" id="bulkAction">
                            <option value="">-- Select Action --</option>
                            <option value="delete">Delete Selected</option>
                            <option value="reject">Reject Selected</option>
                        </select>
                        <button type="submit" name="action" value="bulk_action"
                                class="btn btn-primary">Apply</button>
                        <span class="selected-count" id="selectedCount">0 selected</span>
                    </div>
                    <?php endif; ?>
                </form>
                <?php else: ?>
                    <div class="text-center">
                        <?php if ($search !== ''): ?>
                            No applications found for
                            "<strong><?php echo htmlspecialchars($search); ?></strong>".
                            <a href="?page=applications&status=<?php echo htmlspecialchars($status); ?>">
                                Clear search
                            </a>
                        <?php else: ?>
                            No <?php echo $status === 'all' ? '' : htmlspecialchars(ucfirst($status)); ?>
                            applications found.
                            <?php if ($status === 'pending'): ?>
                                <a href="#" onclick="showNewApplication(); return false;">Create one now</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- NEW APPLICATION MODAL -->
<div id="newApplicationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📝 New Application</h2>
            <span class="close" onclick="hideNewApplication()">&times;</span>
        </div>
        <form method="POST" class="application-form" id="applicationForm">
            <input type="hidden" name="action" value="submit">

            <div class="form-section">
                <h3>👤 Personal Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" required>
                    </div>

                    <?php naInput(
                        'middle_name', 'new_middle_name',
                        'Middle Name', false,
                        'Type or click N/A', '', 'text'
                    ); ?>

                    <div class="form-group">
                        <label>Surname <span class="required">*</span></label>
                        <input type="text" name="surname" required>
                    </div>

                    <?php naInput(
                        'suffix', 'new_suffix',
                        'Suffix', false,
                        'e.g., Jr., Sr., III', '', 'text'
                    ); ?>

                    <div class="form-group">
                        <label>Admission Type <span class="required">*</span></label>
                        <select name="admission_type" required>
                            <option value="freshmen">Freshmen</option>
                            <option value="transferee">Transferee</option>
                            <option value="returnee">Returnee</option>
                            <option value="senior_high">Senior High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Working Student</label>
                        <select name="working_student">
                            <option value="No">No</option>
                            <option value="Yes">Yes</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sex <span class="required">*</span></label>
                        <select name="sex" required>
                            <option value="">Select Sex</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Civil Status <span class="required">*</span></label>
                        <select name="civil_status" required>
                            <option value="">Select Civil Status</option>
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Divorced">Divorced</option>
                            <option value="Widowed">Widowed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" onchange="calculateAge('new')">
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" id="new_age" readonly>
                    </div>

                    <?php naInput(
                        'place_of_birth', 'new_place_of_birth',
                        'Place of Birth', false,
                        'Type or click N/A', '', 'text'
                    ); ?>

                    <?php naInput(
                        'religion', 'new_religion',
                        'Religion', false,
                        'Type or click N/A', '', 'text'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>📍 Address Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Barangay <span class="required">*</span></label>
                        <input type="text" name="address_barangay" required>
                    </div>
                    <div class="form-group">
                        <label>City/Municipality <span class="required">*</span></label>
                        <input type="text" name="address_city" required>
                    </div>
                    <div class="form-group">
                        <label>Province <span class="required">*</span></label>
                        <input type="text" name="address_province" required>
                    </div>

                    <?php naInput(
                        'address_complete', 'new_address_complete',
                        'Complete Address', false,
                        'Type or click N/A', '', 'textarea'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>📞 Contact Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number <span class="required">*</span></label>
                        <input type="text" name="contact_number" required placeholder="09xxxxxxxxx">
                        <div class="help-text">Format: 09xxxxxxxxx</div>
                    </div>

                    <?php naInput(
                        'facebook', 'new_facebook',
                        'Facebook Account', false,
                        'Type or click N/A', '', 'text'
                    ); ?>

                    <?php naInput(
                        'messenger', 'new_messenger',
                        'Messenger', false,
                        'Type or click N/A', '', 'text'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>🎓 Educational Background</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>School Last Attended <span class="required">*</span></label>
                        <input type="text" name="school_last_attended" required>
                    </div>

                    <?php naInput(
                        'year_graduated', 'new_year_graduated',
                        'Year Graduated', false,
                        'e.g., 2024-2025', '', 'text',
                        'Format: YYYY-YYYY or click N/A', true
                    ); ?>

                    <div class="form-group full-width">
                        <label>How did you hear about us?</label>
                        <select name="how_hear">
                            <option value="">Select option</option>
                            <option value="social_media">Social Media</option>
                            <option value="friend">Friend/Relative</option>
                            <option value="school">School</option>
                            <option value="advertisement">Advertisement</option>
                            <option value="other">Other</option>
                            <option value="N/A">N/A</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>👨‍👩‍👧 Parent/Guardian Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Parent/Guardian Full Name <span class="required">*</span></label>
                        <input type="text" name="parent_full_name" required>
                    </div>

                    <?php naInput(
                        'parent_contact', 'new_parent_contact',
                        'Parent Contact Number', false,
                        '09xxxxxxxxx', '', 'text'
                    ); ?>

                    <?php naInput(
                        'parent_address', 'new_parent_address',
                        'Parent Address', false,
                        'Type or click N/A', '', 'textarea'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>📚 Course Selection</h3>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Preferred Course <span class="required">*</span></label>
                        <select name="course_id" required>
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>">
                                    <?php echo htmlspecialchars(($c['code'] ?? '') . ' - ' . ($c['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="reset" class="btn btn-secondary">Reset</button>
                <button type="submit" class="btn btn-primary">Submit Application</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT APPLICATION MODAL -->
<div id="editApplicationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>✏️ Edit Application</h2>
            <span class="close" onclick="hideEditApplication()">&times;</span>
        </div>
        <form method="POST" class="application-form" id="editForm">
            <input type="hidden" name="applicant_id" id="edit_applicant_id">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="redirect" value="?page=applications">

            <div class="form-section">
                <h3>👤 Personal Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" id="edit_first_name" required>
                    </div>

                    <?php naInput(
                        'middle_name', 'edit_middle_name',
                        'Middle Name', false,
                        'Type or click N/A'
                    ); ?>

                    <div class="form-group">
                        <label>Surname <span class="required">*</span></label>
                        <input type="text" name="surname" id="edit_surname" required>
                    </div>

                    <?php naInput(
                        'suffix', 'edit_suffix',
                        'Suffix', false,
                        'e.g., Jr., Sr., III'
                    ); ?>

                    <div class="form-group">
                        <label>Admission Type <span class="required">*</span></label>
                        <select name="admission_type" id="edit_admission_type" required>
                            <option value="freshmen">Freshmen</option>
                            <option value="transferee">Transferee</option>
                            <option value="returnee">Returnee</option>
                            <option value="senior_high">Senior High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Working Student</label>
                        <select name="working_student" id="edit_working_student">
                            <option value="No">No</option>
                            <option value="Yes">Yes</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sex <span class="required">*</span></label>
                        <select name="sex" id="edit_sex" required>
                            <option value="">Select Sex</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Civil Status <span class="required">*</span></label>
                        <select name="civil_status" id="edit_civil_status" required>
                            <option value="">Select Civil Status</option>
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Divorced">Divorced</option>
                            <option value="Widowed">Widowed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" id="edit_date_of_birth"
                               onchange="calculateAge('edit')">
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" id="edit_age" readonly>
                    </div>

                    <?php naInput(
                        'place_of_birth', 'edit_place_of_birth',
                        'Place of Birth', false,
                        'Type or click N/A'
                    ); ?>

                    <?php naInput(
                        'religion', 'edit_religion',
                        'Religion', false,
                        'Type or click N/A'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>📍 Address Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Barangay <span class="required">*</span></label>
                        <input type="text" name="address_barangay" id="edit_address_barangay" required>
                    </div>
                    <div class="form-group">
                        <label>City/Municipality <span class="required">*</span></label>
                        <input type="text" name="address_city" id="edit_address_city" required>
                    </div>
                    <div class="form-group">
                        <label>Province <span class="required">*</span></label>
                        <input type="text" name="address_province" id="edit_address_province" required>
                    </div>

                    <?php naInput(
                        'address_complete', 'edit_address_complete',
                        'Complete Address', false,
                        'Type or click N/A', '', 'textarea'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>📞 Contact Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" id="edit_email" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number <span class="required">*</span></label>
                        <input type="text" name="contact_number" id="edit_contact_number"
                               required placeholder="09xxxxxxxxx">
                    </div>

                    <?php naInput(
                        'facebook', 'edit_facebook',
                        'Facebook Account', false,
                        'Type or click N/A'
                    ); ?>

                    <?php naInput(
                        'messenger', 'edit_messenger',
                        'Messenger', false,
                        'Type or click N/A'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>🎓 Educational Background</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>School Last Attended <span class="required">*</span></label>
                        <input type="text" name="school_last_attended"
                               id="edit_school_last_attended" required>
                    </div>

                    <?php naInput(
                        'year_graduated', 'edit_year_graduated',
                        'Year Graduated', false,
                        'e.g., 2024-2025', '', 'text',
                        'Format: YYYY-YYYY or click N/A', true
                    ); ?>

                    <div class="form-group full-width">
                        <label>How did you hear about us?</label>
                        <select name="how_hear" id="edit_how_hear">
                            <option value="">Select option</option>
                            <option value="social_media">Social Media</option>
                            <option value="friend">Friend/Relative</option>
                            <option value="school">School</option>
                            <option value="advertisement">Advertisement</option>
                            <option value="other">Other</option>
                            <option value="N/A">N/A</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>👨‍👩‍👧 Parent/Guardian Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Parent/Guardian Full Name <span class="required">*</span></label>
                        <input type="text" name="parent_full_name"
                               id="edit_parent_full_name" required>
                    </div>

                    <?php naInput(
                        'parent_contact', 'edit_parent_contact',
                        'Parent Contact Number', false,
                        '09xxxxxxxxx'
                    ); ?>

                    <?php naInput(
                        'parent_address', 'edit_parent_address',
                        'Parent Address', false,
                        'Type or click N/A', '', 'textarea'
                    ); ?>
                </div>
            </div>

            <div class="form-section">
                <h3>📚 Course Selection</h3>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Preferred Course <span class="required">*</span></label>
                        <select name="course_id" id="edit_course_id" required>
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>">
                                    <?php echo htmlspecialchars(($c['code'] ?? '') . ' - ' . ($c['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary"
                        onclick="hideEditApplication()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Application</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       APPLICATIONS PAGE — Blue & Sky Blue Theme
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
        box-shadow: 0 4px 10px rgba(26, 60, 110, 0.15);
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
        letter-spacing: 0.4px;
    }

    .stat-card.stat-total     { border-top-color: var(--navy); }
    .stat-card.stat-total .number     { color: var(--navy); }
    .stat-card.stat-pending   { border-top-color: var(--sky); }
    .stat-card.stat-pending .number   { color: var(--sky); }
    .stat-card.stat-converted { border-top-color: var(--blue); }
    .stat-card.stat-converted .number { color: var(--blue); }
    .stat-card.stat-rejected  { border-top-color: var(--navy-dark); }
    .stat-card.stat-rejected .number  { color: var(--navy-dark); }

    /* ---------- INFO BANNER ---------- */
    .info-banner {
        background: var(--sky-bg);
        border-left: 4px solid var(--sky);
        border-radius: 6px;
        padding: 14px 20px;
        margin-bottom: 20px;
        color: var(--navy);
        font-size: 14px;
        line-height: 1.6;
    }

    .info-banner a { color: var(--blue); font-weight: 600; text-decoration: none; }
    .info-banner a:hover { color: var(--navy); text-decoration: underline; }
    .info-banner small { color: var(--sky-muted); }

    /* ---------- ALERTS ---------- */
    .alert {
        padding: 12px 20px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .alert-success { background: #d4e8fc; color: var(--navy);      border: 1px solid var(--sky-pale); }
    .alert-danger  { background: #dce8f5; color: var(--navy-dark); border: 1px solid var(--sky-pale); }

    /* ---------- SEARCH SECTION ---------- */
    .search-section {
        background: white;
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .status-filters {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid var(--sky-bg);
        padding-bottom: 12px;
    }

    .status-filter {
        padding: 8px 16px;
        border-radius: 20px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        color: var(--sky-muted);
        background: var(--sky-bg-soft);
        border: 1px solid transparent;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .status-filter:hover {
        background: var(--sky-bg);
        color: var(--navy);
        border-color: var(--sky-border);
    }

    .status-filter.active {
        background: var(--navy);
        color: white;
        border-color: var(--navy);
    }

    .status-filter.active .count {
        background: rgba(255, 255, 255, 0.25);
        color: white;
    }

    .status-filter .count {
        background: var(--sky-bg);
        color: var(--navy);
        padding: 1px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 700;
    }

    .search-form {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .search-form input[type="text"] {
        flex: 1;
        min-width: 240px;
        padding: 10px 14px;
        border: 2px solid var(--sky-border);
        border-radius: 6px;
        font-size: 14px;
        color: var(--navy);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
        background: white;
        box-sizing: border-box;
    }

    .search-form input[type="text"]::placeholder { color: var(--sky-muted); }
    .search-form input[type="text"]:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    /* ---------- TABLE ---------- */
    .applications-table-container {
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
        padding-bottom: 12px;
        border-bottom: 2px solid var(--sky-bg);
        flex-wrap: wrap;
        gap: 10px;
    }

    .table-header .title {
        font-size: 16px;
        font-weight: 700;
        color: var(--navy);
    }

    .badge-count {
        background: var(--navy);
        color: white;
        padding: 4px 14px;
        border-radius: 14px;
        font-size: 13px;
        font-weight: 700;
    }

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

    .table tbody tr { transition: background 0.2s ease; }
    .table tbody tr:hover { background: var(--sky-bg-soft); }
    .table tbody tr small { color: var(--sky-muted); font-size: 12px; }

    .checkbox-column { width: 40px; text-align: center; }

    .app-checkbox,
    #selectAll {
        width: 16px;
        height: 16px;
        cursor: pointer;
        accent-color: var(--navy);
    }

    /* ---------- STATUS / TYPE BADGES ---------- */
    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .status-pending   { background: #e8f0fe; color: var(--navy);      border: 1px solid var(--sky-border); }
    .status-converted { background: #d4e8fc; color: var(--navy);      border: 1px solid var(--sky-pale); }
    .status-rejected  { background: #dce8f5; color: var(--navy-dark); border: 1px solid var(--sky-pale); }

    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge-freshmen    { background: #d4e8fc; color: var(--navy); }
    .badge-transferee  { background: #cce5ff; color: var(--navy); }
    .badge-returnee    { background: #e8f0fe; color: var(--blue); }
    .badge-senior_high { background: #dce8f5; color: var(--navy-dark); }
    .badge-default     { background: var(--sky-bg); color: var(--sky-muted); }

    /* ---------- BUTTONS ---------- */
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        align-items: center;
    }

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
    .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 4px; }

    .btn-primary   { background: var(--navy);      color: white; }
    .btn-primary:hover { background: var(--blue); }
    .btn-success   { background: var(--blue);      color: white; }
    .btn-success:hover { background: var(--navy); }
    .btn-info      { background: var(--sky);       color: white; }
    .btn-info:hover { background: var(--blue-mid); }
    .btn-secondary { background: var(--sky-muted); color: white; }
    .btn-secondary:hover { background: var(--blue); }
    .btn-warning   { background: var(--sky-light); color: white; }
    .btn-warning:hover { background: var(--sky); }
    .btn-danger    { background: var(--navy-dark); color: white; }
    .btn-danger:hover { background: var(--navy); }

    /* ---------- BULK ACTIONS ---------- */
    .bulk-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 15px;
        padding: 12px 16px;
        background: var(--sky-bg-soft);
        border-radius: 6px;
        border: 1px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    .bulk-actions > span:first-child {
        font-size: 13px;
        font-weight: 600;
        color: var(--navy);
    }

    .bulk-actions select {
        padding: 8px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 13px;
        background: white;
        color: var(--navy);
        cursor: pointer;
        transition: border-color 0.2s ease;
        min-width: 180px;
    }

    .bulk-actions select:focus {
        outline: none;
        border-color: var(--navy);
    }

    .selected-count {
        font-size: 12px;
        font-weight: 700;
        color: var(--navy);
        background: var(--sky-bg);
        padding: 4px 12px;
        border-radius: 12px;
        margin-left: auto;
    }

    /* ---------- EMPTY STATE ---------- */
    .text-center {
        text-align: center;
        padding: 40px 20px;
        color: var(--sky-muted);
        font-size: 14px;
    }

    .text-center a {
        color: var(--blue);
        font-weight: 600;
        text-decoration: none;
    }

    .text-center a:hover {
        color: var(--navy);
        text-decoration: underline;
    }

    /* ---------- MODALS ---------- */
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
        margin: 3% auto;
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

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 15px;
        margin-bottom: 20px;
        border-bottom: 2px solid var(--sky-bg);
    }

    .modal-header h2 {
        color: var(--navy);
        font-size: 20px;
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

    /* ---------- FORM SECTIONS ---------- */
    .application-form .form-section {
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--sky-bg);
    }

    .application-form .form-section:last-of-type {
        border-bottom: none;
        margin-bottom: 0;
    }

    .application-form .form-section h3 {
        color: var(--navy);
        font-size: 15px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px 20px;
    }

    .form-group {
        margin-bottom: 0;
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .form-group.full-width { grid-column: 1 / -1; }

    .form-group label {
        font-weight: 600;
        font-size: 13px;
        color: var(--navy);
    }

    .form-group label .required {
        color: var(--navy-dark);
        font-weight: 700;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 9px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 5px;
        font-size: 14px;
        font-family: inherit;
        color: var(--navy);
        background: white;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        box-sizing: border-box;
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--sky-muted);
        opacity: 0.7;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    .form-group input[readonly],
    .form-group textarea[readonly] {
        background: var(--sky-bg-soft);
        cursor: not-allowed;
        color: var(--sky-muted);
    }

    .form-group textarea {
        resize: vertical;
        min-height: 60px;
    }

    .help-text {
        font-size: 11px;
        color: var(--sky-muted);
        margin-top: 2px;
    }

    /* ============================================================
       N/A SELECTABLE INPUT
       ============================================================ */
    .na-group .na-input-wrap {
        display: flex;
        gap: 6px;
        align-items: stretch;
    }

    .na-group .na-input {
        flex: 1;
        min-width: 0;
    }

    .na-checkbox {
        display: inline-flex !important;
        align-items: center;
        gap: 4px;
        padding: 0 10px;
        border: 2px solid var(--sky-border);
        border-radius: 5px;
        background: white;
        font-size: 12px;
        font-weight: 600;
        color: var(--sky-muted);
        cursor: pointer;
        white-space: nowrap;
        transition: all 0.15s ease;
        user-select: none;
    }

    .na-checkbox:hover {
        border-color: var(--sky);
        color: var(--navy);
        background: var(--sky-bg-soft);
    }

    .na-checkbox input[type="checkbox"] {
        width: 14px;
        height: 14px;
        cursor: pointer;
        accent-color: var(--navy);
        margin: 0;
    }

    .na-checkbox:has(input:checked) {
        background: var(--navy);
        border-color: var(--navy);
        color: white;
    }

    .na-checkbox:has(input:checked) input[type="checkbox"] {
        accent-color: white;
    }

    /* ---------- FORM ACTIONS ---------- */
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 2px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
        .modal-content { margin: 5% auto; padding: 20px; width: 95%; }
        .form-grid { grid-template-columns: 1fr; }
        .status-filters { justify-content: center; }
        .search-form { flex-direction: column; align-items: stretch; }
        .search-form input[type="text"] { min-width: unset; width: 100%; }
        .table { font-size: 12px; }
        .table th, .table td { padding: 8px; }
        .bulk-actions { flex-direction: column; align-items: stretch; }
        .bulk-actions select { min-width: unset; width: 100%; }
        .selected-count { margin-left: 0; text-align: center; }
        .form-actions { flex-direction: column; }
        .form-actions .btn { width: 100%; text-align: center; }
        .stats-grid { grid-template-columns: 1fr 1fr; }
    }

    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
        .action-buttons { flex-direction: column; align-items: stretch; }
        .action-buttons .btn { width: 100%; text-align: center; }
    }
</style>
<script>
// ===== N/A TOGGLE =====
// Called when an N/A checkbox is clicked.
// Sets/clears the paired input and toggles readonly state.
function toggleNA(inputId, checkbox) {
    const input = document.getElementById(inputId);
    if (!input) return;

    if (checkbox.checked) {
        input.dataset.previousValue = input.value;  // remember what was typed
        input.value = 'N/A';
        input.readOnly = true;
        input.classList.add('na-active');
    } else {
        input.value = input.dataset.previousValue || '';
        input.readOnly = false;
        input.classList.remove('na-active');
        input.focus();
    }
}

// ===== MODAL CONTROLS =====
function showNewApplication() {
    document.getElementById('newApplicationModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function hideNewApplication() {
    document.getElementById('newApplicationModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function hideEditApplication() {
    document.getElementById('editApplicationModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// ===== EDIT APPLICATION =====
function editApplication(id) {
    fetch('?page=applications&ajax=get_application&id=' + encodeURIComponent(id))
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Failed to load application data.');
                return;
            }

            const app = data.data;
            const map = {
                edit_applicant_id: 'applicant_id',
                edit_first_name: 'first_name',
                edit_middle_name: 'middle_name',
                edit_surname: 'surname',
                edit_suffix: 'suffix',
                edit_admission_type: 'admission_type',
                edit_working_student: 'working_student',
                edit_sex: 'sex',
                edit_civil_status: 'civil_status',
                edit_date_of_birth: 'date_of_birth',
                edit_age: 'age',
                edit_place_of_birth: 'place_of_birth',
                edit_religion: 'religion',
                edit_address_barangay: 'address_barangay',
                edit_address_city: 'address_city',
                edit_address_province: 'address_province',
                edit_address_complete: 'address_complete',
                edit_email: 'email',
                edit_contact_number: 'contact_number',
                edit_facebook: 'facebook',
                edit_messenger: 'messenger',
                edit_school_last_attended: 'school_last_attended',
                edit_year_graduated: 'year_graduated',
                edit_how_hear: 'how_hear',
                edit_parent_full_name: 'parent_full_name',
                edit_parent_contact: 'parent_contact',
                edit_parent_address: 'parent_address',
                edit_course_id: 'course_id'
            };

            for (const [domId, field] of Object.entries(map)) {
                const el = document.getElementById(domId);
                if (!el) continue;

                const raw = app[field] ?? '';
                const isNA = typeof raw === 'string'
                          && raw.trim().toUpperCase() === 'N/A';

                // Look for the paired N/A checkbox (id = na_chk_<domId>)
                const naChk = document.getElementById('na_chk_' + domId);

                if (naChk) {
                    // na-capable field
                    if (isNA) {
                        el.value = 'N/A';
                        el.readOnly = true;
                        el.classList.add('na-active');
                        naChk.checked = true;
                    } else {
                        el.value = raw;
                        el.readOnly = false;
                        el.classList.remove('na-active');
                        naChk.checked = false;
                    }
                } else {
                    // plain field
                    el.value = isNA ? '' : raw;
                }
            }

            document.getElementById('editApplicationModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading application data. Please try again.');
        });
}

// ===== AGE CALCULATION =====
function calculateAge(scope) {
    const birthInputId = scope === 'edit' ? 'edit_date_of_birth' : null;
    const ageInputId   = scope === 'edit' ? 'edit_age'         : 'new_age';

    const birthInput = birthInputId
        ? document.getElementById(birthInputId)
        : document.querySelector('#applicationForm input[name="date_of_birth"]');

    const ageInput = document.getElementById(ageInputId);

    if (!birthInput || !ageInput || !birthInput.value) {
        if (ageInput) ageInput.value = '';
        return;
    }

    const birth = new Date(birthInput.value);
    const today = new Date();

    if (isNaN(birth.getTime())) {
        ageInput.value = '';
        return;
    }

    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;

    ageInput.value = age >= 0 ? age : '';
}

// ===== BULK ACTIONS =====
function toggleAllCheckboxes(master) {
    document.querySelectorAll('.app-checkbox').forEach(cb => cb.checked = master.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.app-checkbox:checked').length;
    const span  = document.getElementById('selectedCount');
    if (span) span.textContent = count + ' selected';
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.app-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    const editDob = document.getElementById('edit_date_of_birth');
    if (editDob) {
        editDob.addEventListener('change', () => calculateAge('edit'));
    }
});

function confirmBulkAction() {
    const actionEl = document.getElementById('bulkAction');
    const action   = actionEl ? actionEl.value : '';

    if (!action) { alert('Please select an action.'); return false; }

    const selected = document.querySelectorAll('.app-checkbox:checked').length;
    if (selected === 0) { alert('Please select at least one application.'); return false; }

    if (action === 'delete') {
        return confirm('Are you sure you want to delete ' + selected + ' application(s)? This cannot be undone!');
    }
    if (action === 'reject') {
        return confirm('Are you sure you want to reject ' + selected + ' application(s)?');
    }
    return true;
}

// ===== CLOSE MODALS =====
window.addEventListener('click', function (event) {
    const newModal  = document.getElementById('newApplicationModal');
    const editModal = document.getElementById('editApplicationModal');
    if (event.target === newModal)  hideNewApplication();
    if (event.target === editModal) hideEditApplication();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        hideNewApplication();
        hideEditApplication();
    }
});

// ===== FORM VALIDATION =====
document.getElementById('applicationForm')?.addEventListener('submit', function (e) {
    const required = this.querySelectorAll('[required]');
    let hasError = false;
    let firstError = null;

    required.forEach(function (field) {
        // Skip validation on inputs that are locked to N/A
        if (field.readOnly && field.value === 'N/A') return;

        if (!field.value.trim()) {
            field.style.borderColor = '#dc3545';
            hasError = true;
            if (!firstError) firstError = field;
        } else {
            field.style.borderColor = '';
        }
    });

    // Year graduated format check (skip when set to N/A)
    const yearGrad = this.querySelector('input[name="year_graduated"]');
    if (yearGrad
        && yearGrad.value.trim() !== ''
        && yearGrad.value.trim().toUpperCase() !== 'N/A') {
        const ok = /^\d{4}(\s*-\s*\d{4})?$/.test(yearGrad.value.trim());
        if (!ok) {
            yearGrad.style.borderColor = '#dc3545';
            alert('⚠️ Year Graduated must be in YYYY-YYYY format (e.g., 2024-2025).');
            e.preventDefault();
            return;
        }
    }

    if (hasError) {
        e.preventDefault();
        if (firstError) firstError.focus();
        alert('⚠️ Please fill in all required fields.');
    }
});
</script>