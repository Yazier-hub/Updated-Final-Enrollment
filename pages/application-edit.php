<?php
// pages/application-edit.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • $applicationId now falls back to $_POST['applicant_id']
//     (your form posts that hidden field but the handler was reading $_GET)
//   • Null-safe reads on all $app[...] fields
//   • Escaped $app['status'] in class attributes
//   • calc age is now also set server-side on load

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'classes/Application.php';
require_once 'classes/Course.php';

$application = new Application();
$course      = new Course();

// ============================================================
// Resolve application ID (GET on load, POST as fallback)
// ============================================================
$applicationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($applicationId <= 0 && isset($_POST['applicant_id'])) {
    $applicationId = (int) $_POST['applicant_id'];
}

if ($applicationId <= 0) {
    $_SESSION['message'] = '❌ Invalid application ID.';
    header('Location: ?page=applications');
    exit;
}

$app = $application->getApplicationById($applicationId);

if (!$app) {
    $_SESSION['message'] = '❌ Application not found.';
    header('Location: ?page=applications');
    exit;
}

if (($app['status'] ?? '') === 'converted') {
    $_SESSION['message'] = '❌ This application has already been converted to a student and cannot be edited.';
    header('Location: ?page=application-details&id=' . $applicationId);
    exit;
}

$courses = $course->findAll();

// ============================================================
// POST handling
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        case 'update':
            try {
                $data = [
                    'surname'              => trim($_POST['surname'] ?? ''),
                    'first_name'           => trim($_POST['first_name'] ?? ''),
                    'middle_name'          => trim($_POST['middle_name'] ?? ''),
                    'suffix'               => trim($_POST['suffix'] ?? ''),
                    'admission_type'       => $_POST['admission_type'] ?? 'freshmen',
                    'working_student'      => $_POST['working_student'] ?? 'No',
                    'sex'                  => $_POST['sex'] ?? '',
                    'address_barangay'     => trim($_POST['address_barangay'] ?? ''),
                    'address_city'         => trim($_POST['address_city'] ?? ''),
                    'address_province'     => trim($_POST['address_province'] ?? ''),
                    'address_complete'     => trim($_POST['address_complete'] ?? ''),
                    'school_last_attended' => trim($_POST['school_last_attended'] ?? ''),
                    'year_graduated'       => !empty($_POST['year_graduated']) ? (int) $_POST['year_graduated'] : null,
                    'how_hear'             => $_POST['how_hear'] ?? '',
                    'email'                => trim($_POST['email'] ?? ''),
                    'date_of_birth'        => $_POST['date_of_birth'] ?? null,
                    'place_of_birth'       => trim($_POST['place_of_birth'] ?? ''),
                    'age'                  => !empty($_POST['age']) ? (int) $_POST['age'] : null,
                    'civil_status'         => $_POST['civil_status'] ?? 'Single',
                    'religion'             => trim($_POST['religion'] ?? ''),
                    'contact_number'       => trim($_POST['contact_number'] ?? ''),
                    'facebook'             => trim($_POST['facebook'] ?? ''),
                    'messenger'            => trim($_POST['messenger'] ?? ''),
                    'address'              => trim($_POST['address'] ?? ''),
                    'parent_full_name'     => trim($_POST['parent_full_name'] ?? ''),
                    'parent_contact'       => trim($_POST['parent_contact'] ?? ''),
                    'parent_address'       => trim($_POST['parent_address'] ?? ''),
                    'course_id'            => !empty($_POST['course_id']) ? (int) $_POST['course_id'] : null
                ];

                if ($application->update($applicationId, $data)) {
                    $_SESSION['message'] = '✅ Application updated successfully!';
                    header('Location: ?page=application-details&id=' . $applicationId);
                    exit;
                }
                $error = '❌ Failed to update application.';
            } catch (Exception $e) {
                $error = '❌ Error: ' . $e->getMessage();
            }
            break;

        case 'delete':
            if ($application->delete($applicationId)) {
                $_SESSION['message'] = '✅ Application deleted successfully!';
                header('Location: ?page=applications');
                exit;
            }
            $error = '❌ Failed to delete application.';
            break;
    }
}

// ============================================================
// Post-processing
// ============================================================
$fullName = trim(
    ($app['first_name'] ?? '')
    . ' ' . ($app['middle_name'] ?? '')
    . ' ' . ($app['surname'] ?? '')
    . ' ' . ($app['suffix'] ?? '')
);

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$pageTitle = 'Edit Application - ' . $fullName;
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>✏️ Edit Application</h1>
            <p class="dashboard-subtitle">
                <?php echo htmlspecialchars($fullName); ?>
                • Application #<?php echo (int) $app['applicant_id']; ?>
            </p>
        </div>

        <div class="module-content">

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo strpos($message, '✅') !== false ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="edit-form-container">
                <div class="application-header">
                    <div class="application-avatar">
                        <?php echo strtoupper(
                            substr($app['first_name'] ?? '?', 0, 1)
                            . substr($app['surname'] ?? '?', 0, 1)
                        ); ?>
                    </div>
                    <div class="application-header-info">
                        <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
                        <div class="id">📋 Application #: <?php echo (int) $app['applicant_id']; ?></div>
                        <div>
                            <span class="status-badge status-<?php echo htmlspecialchars($app['status'] ?? 'pending'); ?>">
                                <?php echo ucfirst(htmlspecialchars($app['status'] ?? 'pending')); ?>
                            </span>
                            <span style="margin-left:10px;font-size:13px;color:#666;">
                                Submitted:
                                <?php echo !empty($app['submitted_at'])
                                    ? date('M d, Y h:i A', strtotime($app['submitted_at']))
                                    : 'N/A'; ?>
                            </span>
                        </div>
                    </div>
                    <div style="margin-left:auto;">
                        <a href="?page=application-details&id=<?php echo (int) $applicationId; ?>" class="btn btn-info">← Back</a>
                        <a href="?page=applications" class="btn btn-secondary">📋 All Apps</a>
                    </div>
                </div>

                <form method="POST" class="edit-form" id="editForm" action="?page=application-edit&id=<?php echo (int) $applicationId; ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="applicant_id" value="<?php echo (int) $applicationId; ?>">

                    <!-- ========== PERSONAL INFORMATION ========== -->
                    <div class="form-section">
                        <h3><span class="section-icon">👤</span> Personal Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name <span class="required">*</span></label>
                                <input type="text" name="first_name" class="form-control"
                                       value="<?php echo htmlspecialchars($app['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name" class="form-control"
                                       value="<?php echo htmlspecialchars($app['middle_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Surname <span class="required">*</span></label>
                                <input type="text" name="surname" class="form-control"
                                       value="<?php echo htmlspecialchars($app['surname'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Suffix</label>
                                <input type="text" name="suffix" class="form-control"
                                       value="<?php echo htmlspecialchars($app['suffix'] ?? ''); ?>"
                                       placeholder="e.g., Jr., Sr., III">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Admission Type <span class="required">*</span></label>
                                <select name="admission_type" class="form-control" required>
                                    <?php
                                    $admissionOptions = [
                                        'freshmen'    => 'Freshmen',
                                        'transferee'  => 'Transferee',
                                        'returnee'    => 'Returnee',
                                        'senior_high' => 'Senior High'
                                    ];
                                    $currentAdmission = $app['admission_type'] ?? 'freshmen';
                                    foreach ($admissionOptions as $val => $label):
                                    ?>
                                        <option value="<?php echo $val; ?>" <?php echo $currentAdmission === $val ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Working Student</label>
                                <select name="working_student" class="form-control">
                                    <?php $currentWorking = $app['working_student'] ?? 'No'; ?>
                                    <option value="No"  <?php echo $currentWorking === 'No'  ? 'selected' : ''; ?>>No</option>
                                    <option value="Yes" <?php echo $currentWorking === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Sex <span class="required">*</span></label>
                                <select name="sex" class="form-control" required>
                                    <option value="">Select Sex</option>
                                    <?php $currentSex = $app['sex'] ?? ''; ?>
                                    <option value="Male"   <?php echo $currentSex === 'Male'   ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $currentSex === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other"  <?php echo $currentSex === 'Other'  ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Civil Status <span class="required">*</span></label>
                                <select name="civil_status" class="form-control" required>
                                    <option value="">Select Civil Status</option>
                                    <?php $currentCivil = $app['civil_status'] ?? ''; ?>
                                    <option value="Single"   <?php echo $currentCivil === 'Single'   ? 'selected' : ''; ?>>Single</option>
                                    <option value="Married"  <?php echo $currentCivil === 'Married'  ? 'selected' : ''; ?>>Married</option>
                                    <option value="Divorced" <?php echo $currentCivil === 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                    <option value="Widowed"  <?php echo $currentCivil === 'Widowed'  ? 'selected' : ''; ?>>Widowed</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Date of Birth <span class="required">*</span></label>
                                <input type="date" name="date_of_birth" class="form-control"
                                       value="<?php echo htmlspecialchars($app['date_of_birth'] ?? ''); ?>"
                                       required onchange="calculateAge()">
                            </div>
                            <div class="form-group">
                                <label>Age</label>
                                <input type="number" name="age" id="age" class="form-control"
                                       value="<?php echo htmlspecialchars($app['age'] ?? ''); ?>" readonly>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Place of Birth <span class="required">*</span></label>
                                <input type="text" name="place_of_birth" class="form-control"
                                       value="<?php echo htmlspecialchars($app['place_of_birth'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Religion</label>
                                <input type="text" name="religion" class="form-control"
                                       value="<?php echo htmlspecialchars($app['religion'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- ========== CONTACT INFORMATION ========== -->
                    <div class="form-section">
                        <h3><span class="section-icon">📞</span> Contact Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email Address <span class="required">*</span></label>
                                <input type="email" name="email" class="form-control"
                                       value="<?php echo htmlspecialchars($app['email'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Contact Number <span class="required">*</span></label>
                                <input type="text" name="contact_number" class="form-control"
                                       value="<?php echo htmlspecialchars($app['contact_number'] ?? ''); ?>" required>
                                <div class="help-text">Format: 09xxxxxxxxx</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Facebook Account</label>
                                <input type="text" name="facebook" class="form-control"
                                       value="<?php echo htmlspecialchars($app['facebook'] ?? ''); ?>"
                                       placeholder="Facebook username or URL">
                            </div>
                            <div class="form-group">
                                <label>Messenger</label>
                                <input type="text" name="messenger" class="form-control"
                                       value="<?php echo htmlspecialchars($app['messenger'] ?? ''); ?>"
                                       placeholder="Messenger account">
                            </div>
                        </div>
                    </div>

                    <!-- ========== ADDRESS ========== -->
                    <div class="form-section">
                        <h3><span class="section-icon">📍</span> Address Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Barangay <span class="required">*</span></label>
                                <input type="text" name="address_barangay" class="form-control"
                                       value="<?php echo htmlspecialchars($app['address_barangay'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>City/Municipality <span class="required">*</span></label>
                                <input type="text" name="address_city" class="form-control"
                                       value="<?php echo htmlspecialchars($app['address_city'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Province <span class="required">*</span></label>
                                <input type="text" name="address_province" class="form-control"
                                       value="<?php echo htmlspecialchars($app['address_province'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Complete Address</label>
                                <textarea name="address_complete" class="form-control" rows="2"><?php echo htmlspecialchars($app['address_complete'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- ========== EDUCATIONAL BACKGROUND ========== -->
                    <div class="form-section">
                        <h3><span class="section-icon">🎓</span> Educational Background</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>School Last Attended <span class="required">*</span></label>
                                <input type="text" name="school_last_attended" class="form-control"
                                       value="<?php echo htmlspecialchars($app['school_last_attended'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Year Graduated <span class="required">*</span></label>
                                <input type="number" name="year_graduated" class="form-control"
                                       value="<?php echo htmlspecialchars($app['year_graduated'] ?? ''); ?>"
                                       min="1990" max="<?php echo date('Y'); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>How did you hear about us?</label>
                            <select name="how_hear" class="form-control">
                                <option value="">Select option</option>
                                <?php $currentHowHear = $app['how_hear'] ?? ''; ?>
                                <option value="social_media"  <?php echo $currentHowHear === 'social_media'  ? 'selected' : ''; ?>>Social Media</option>
                                <option value="friend"        <?php echo $currentHowHear === 'friend'        ? 'selected' : ''; ?>>Friend/Relative</option>
                                <option value="school"        <?php echo $currentHowHear === 'school'        ? 'selected' : ''; ?>>School</option>
                                <option value="advertisement" <?php echo $currentHowHear === 'advertisement' ? 'selected' : ''; ?>>Advertisement</option>
                                <option value="other"         <?php echo $currentHowHear === 'other'         ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- ========== PARENT/GUARDIAN ========== -->
                    <div class="form-section">
                        <h3><span class="section-icon">👨‍👩‍👧</span> Parent/Guardian Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Parent/Guardian Full Name <span class="required">*</span></label>
                                <input type="text" name="parent_full_name" class="form-control"
                                       value="<?php echo htmlspecialchars($app['parent_full_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Parent Contact Number</label>
                                <input type="text" name="parent_contact" class="form-control"
                                       value="<?php echo htmlspecialchars($app['parent_contact'] ?? ''); ?>">
                                <div class="help-text">Format: 09xxxxxxxxx</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Parent Address</label>
                            <textarea name="parent_address" class="form-control" rows="2"><?php echo htmlspecialchars($app['parent_address'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- ========== COURSE SELECTION ========== -->
                    <div class="form-section">
                        <h3><span class="section-icon">📚</span> Course Selection</h3>
                        <div class="form-group">
                            <label>Preferred Course <span class="required">*</span></label>
                            <select name="course_id" class="form-control" required>
                                <option value="">Select Course</option>
                                <?php $currentCourseId = (int) ($app['course_id'] ?? 0); ?>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>"
                                        <?php echo $currentCourseId === (int) $c['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(($c['code'] ?? '') . ' - ' . ($c['name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text">Select the course the applicant wants to enroll in</div>
                        </div>
                    </div>

                    <!-- ========== FORM ACTIONS ========== -->
                    <div class="form-actions">
                        <a href="?page=application-details&id=<?php echo (int) $applicationId; ?>" class="btn btn-secondary">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            💾 Update Application
                        </button>
                    </div>
                </form>

                <!-- DANGER ZONE -->
                <?php if (($app['status'] ?? '') !== 'converted'): ?>
                <div class="danger-zone">
                    <h4>⚠️ Danger Zone</h4>
                    <p>This action cannot be undone. Please be certain before proceeding.</p>
                    <form method="POST" style="display:inline-block;"
                          onsubmit="return confirm('Are you sure you want to delete this application? This action cannot be undone!');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="applicant_id" value="<?php echo (int) $applicationId; ?>">
                        <button type="submit" class="btn btn-danger">🗑️ Delete Application</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       APPLICATION EDIT — Blue & Sky Blue Theme
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

    .edit-form-container {
        background: white;
        border-radius: 8px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.1);
    }

    .application-header {
        display: flex;
        align-items: center;
        gap: 20px;
        padding-bottom: 20px;
        margin-bottom: 25px;
        border-bottom: 2px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    .application-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--navy), var(--blue));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        font-weight: 700;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(26, 60, 110, 0.25);
    }

    .application-header-info .name {
        font-size: 20px;
        font-weight: 700;
        color: var(--navy);
    }

    .application-header-info .id {
        color: var(--sky-muted);
        font-size: 14px;
    }

    /* Status badges — all blue tones */
    .status-badge {
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        display: inline-block;
        margin-top: 5px;
    }

    .status-pending   { background: #e8f0fe; color: var(--navy);     border: 1px solid var(--sky-border); }
    .status-converted { background: #d4e8fc; color: var(--navy);     border: 1px solid var(--sky-pale); }
    .status-rejected  { background: #dce8f5; color: var(--navy-dark); border: 1px solid var(--sky-pale); }

    .form-section {
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--sky-bg);
    }

    .form-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .form-section h3 {
        color: var(--navy);
        font-size: 16px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-section h3 .section-icon {
        font-size: 20px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        font-size: 14px;
        color: var(--navy);
    }

    .form-group label .required {
        color: var(--navy-dark);
    }

    .form-group .help-text {
        font-size: 12px;
        color: var(--sky-muted);
        margin-top: 4px;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        transition: border-color 0.3s ease;
        background: #fff;
        color: var(--navy);
        box-sizing: border-box;
    }

    .form-control:focus {
        border-color: var(--navy);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.18);
    }

    .form-control[readonly] {
        background: var(--sky-bg-soft);
        cursor: not-allowed;
    }

    .form-control.error {
        border-color: var(--navy-dark);
        background: #dce8f5;
    }

    .form-control.error:focus {
        box-shadow: 0 0 0 3px rgba(26, 60, 110, 0.18);
    }

    textarea.form-control {
        resize: vertical;
        min-height: 60px;
    }

    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 2px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    /* Danger zone — blue-toned warning */
    .danger-zone {
        margin-top: 20px;
        padding: 20px;
        background: #e8f0fe;
        border-radius: 8px;
        border: 1px solid var(--sky-pale);
    }

    .danger-zone h4 {
        color: var(--navy-dark);
        margin-bottom: 10px;
    }

    .danger-zone p {
        color: var(--sky-muted);
        font-size: 14px;
    }

    /* Alerts — all blue variants */
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

    /* Buttons — all blue/sky variants */
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
    }

    .btn-primary {
        background: var(--navy);
        color: white;
    }
    .btn-primary:hover {
        background: var(--blue);
    }

    .btn-success {
        background: var(--blue);
        color: white;
    }
    .btn-success:hover {
        background: var(--navy);
    }

    .btn-secondary {
        background: var(--sky-muted);
        color: white;
    }
    .btn-secondary:hover {
        background: var(--blue);
    }

    .btn-danger {
        background: var(--navy-dark);
        color: white;
    }
    .btn-danger:hover {
        background: var(--navy);
    }

    .btn-info {
        background: var(--sky-light);
        color: white;
    }
    .btn-info:hover {
        background: var(--sky);
    }

    .btn-warning {
        background: var(--sky);
        color: white;
    }
    .btn-warning:hover {
        background: var(--blue-mid);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }

        .application-header {
            flex-direction: column;
            text-align: center;
        }

        .form-actions {
            flex-direction: column;
        }

        .form-actions .btn {
            width: 100%;
            text-align: center;
        }
    }
</style>

<script>
function calculateAge() {
    const birthDateInput = document.querySelector('input[name="date_of_birth"]');
    const ageInput       = document.getElementById('age');
    if (!birthDateInput || !ageInput || !birthDateInput.value) return;

    const today = new Date();
    const birth = new Date(birthDateInput.value);
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    ageInput.value = age;
}

document.getElementById('editForm')?.addEventListener('submit', function (e) {
    const required = this.querySelectorAll('[required]');
    let hasError = false;
    let firstError = null;

    required.forEach(function (field) {
        if (!field.value.trim()) {
            field.classList.add('error');
            hasError = true;
            if (!firstError) firstError = field;
        } else {
            field.classList.remove('error');
        }
    });

    if (hasError) {
        e.preventDefault();
        if (firstError) firstError.focus();
        alert('⚠️ Please fill in all required fields.');
    }
});

document.querySelectorAll('.form-control').forEach(function (input) {
    input.addEventListener('input', function () {
        this.classList.remove('error');
    });
});

document.querySelector('input[name="date_of_birth"]')?.addEventListener('change', calculateAge);
document.querySelector('input[name="date_of_birth"]')?.addEventListener('input',  calculateAge);

// Recalculate age on page load in case it was empty
document.addEventListener('DOMContentLoaded', calculateAge);
</script>