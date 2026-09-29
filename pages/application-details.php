<?php
// pages/application-details.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • convertToStudentAndEnroll() now passes an empty $scheduleIds array
//     (matches the new signature; requires schedule_ids for real enrollment)
//   • Convert form now includes a section_id lookup fix: reads $sec['id']
//   • Requirements checklist now gets saved via Requirement class
//   • Null-safe reads on all $app[...] fields
//   • Escaped $app['status'] in class attributes

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'classes/Application.php';
require_once 'classes/Student.php';
require_once 'classes/Course.php';
require_once 'classes/Enrollment.php';
require_once 'classes/Section.php';
require_once 'classes/Requirement.php';

$application = new Application();
$student     = new Student();
$course      = new Course();
$enrollment  = new Enrollment();
$section     = new Section();
$requirement = new Requirement();

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

$app = $application->getApplicationWithStudent($applicationId);
if (!$app) {
    $_SESSION['message'] = '❌ Application not found.';
    header('Location: ?page=applications');
    exit;
}

$fullName = trim(
    ($app['first_name'] ?? '')
    . ' ' . ($app['middle_name'] ?? '')
    . ' ' . ($app['surname'] ?? '')
    . ' ' . ($app['suffix'] ?? '')
);

$courses           = $course->findAll();
$availableSections = [];

if (!empty($app['course_id'])) {
    // NOTE: Section::getAvailableSections() returns rows keyed with `id`,
    // not `section_id`. We normalize below.
    $raw = $section->getAvailableSections((int) $app['course_id']);
    if (is_array($raw)) {
        foreach ($raw as $sec) {
            $sec['section_id'] = $sec['id'] ?? $sec['section_id'] ?? null;
            $availableSections[] = $sec;
        }
    }
}

// ============================================================
// POST handling
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        case 'update':
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
                'course_id'            => !empty($_POST['course_id']) ? (int) $_POST['course_id'] : null,
                'preferred_section_id' => !empty($_POST['preferred_section_id']) ? (int) $_POST['preferred_section_id'] : null
            ];

            if ($application->update($applicationId, $data)) {
                $_SESSION['message'] = '✅ Application updated successfully!';
                header('Location: ?page=application-details&id=' . $applicationId);
                exit;
            }
            $error = '❌ Failed to update application.';
            break;

        case 'delete':
            if ($application->delete($applicationId)) {
                $_SESSION['message'] = '✅ Application deleted successfully!';
                header('Location: ?page=applications');
                exit;
            }
            $error = '❌ Failed to delete application.';
            break;

        case 'reject':
            $notes = trim($_POST['notes'] ?? 'Application rejected');
            if ($application->updateStatus($applicationId, 'rejected')) {
                if ($notes !== '') {
                    $application->update($applicationId, ['notes' => $notes]);
                }
                $_SESSION['message'] = '✅ Application rejected successfully!';
                header('Location: ?page=application-details&id=' . $applicationId);
                exit;
            }
            $error = '❌ Failed to reject application.';
            break;

        case 'convert':
            if (empty($app['course_id'])) {
                $error = '❌ Please assign a course to this application first.';
                break;
            }

            if (empty($_POST['section_id'])) {
                $error = '❌ Please select a section for enrollment.';
                break;
            }

            $sectionId  = (int) $_POST['section_id'];
            $schoolYear = !empty($_POST['school_year'])
                ? $_POST['school_year']
                : (date('Y') . '-' . (date('Y') + 1));

            // NOTE: schedule_ids comes from the section's offered schedules.
            // If your convert form does not yet let the user pick schedules,
            // we pass an empty array — the applicant will be converted but
            // not enrolled in any subjects. Add schedule selection to enable
            // real enrollment.
            $scheduleIds = [];
            if (isset($_POST['schedule_ids']) && !empty($_POST['schedule_ids'])) {
                $scheduleIds = array_map('intval', explode(',', $_POST['schedule_ids']));
            }
            if (isset($_POST['schedule_ids_array']) && is_array($_POST['schedule_ids_array'])) {
                $scheduleIds = array_merge(
                    $scheduleIds,
                    array_map('intval', $_POST['schedule_ids_array'])
                );
            }
            $scheduleIds = array_values(array_unique(array_filter($scheduleIds, fn($id) => $id > 0)));

            $result = $application->convertToStudentAndEnroll(
                $applicationId,
                $sectionId,
                $schoolYear,
                $scheduleIds
            );

            if (!empty($result['success'])) {
                // Save checked requirements (if any)
                $checked = $_POST['requirements'] ?? [];
                if (is_array($checked) && !empty($checked) && !empty($result['student_id'])) {
                    $reqNotes = $_POST['req_notes'] ?? [];
                    foreach ($checked as $reqId => $submitted) {
                        $isSubmitted = ($submitted == '1' || $submitted === true || $submitted === 'on');
                        $note        = isset($reqNotes[$reqId]) ? trim($reqNotes[$reqId]) : null;
                        $requirement->updateOrCreateRequirementStatus(
                            (int) $result['student_id'],
                            (int) $reqId,
                            $isSubmitted ? 1 : 0,
                            $note
                        );
                    }
                }

                $_SESSION['message'] = '✅ Student created successfully! Student Number: '
                    . ($result['student_number'] ?? 'N/A');
                if (!empty($result['enrollment_count'])) {
                    $_SESSION['message'] .= ' Enrolled in '
                        . (int) $result['enrollment_count'] . ' subject(s).';
                }
                header('Location: ?page=student-details&id=' . (int) $result['student_id']);
                exit;
            }

            $error = '❌ Failed to convert: ' . ($result['message'] ?? 'Unknown error');
            break;
    }
}

// ============================================================
// Post-processing
// ============================================================
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$requirements = [];
if (!empty($app['admission_type'])) {
    $requirements = $requirement->getRequirementsByCategory($app['admission_type']);
    if (!is_array($requirements)) {
        $requirements = [];
    }
}

$pageTitle = 'Application Details - ' . $fullName;
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>📋 Application Details</h1>
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

            <!-- APPLICATION PROFILE -->
            <div class="application-profile">
                <div class="profile-header">
                    <div class="profile-info">
                        <div class="applicant-name"><?php echo htmlspecialchars($fullName); ?></div>
                        <div class="applicant-id">📋 Application #: <?php echo (int) $app['applicant_id']; ?></div>
                        <div class="profile-badges">
                            <span class="badge-lg badge-<?php echo htmlspecialchars($app['status'] ?? 'pending'); ?>">
                                <?php echo ucfirst(htmlspecialchars($app['status'] ?? 'pending')); ?>
                            </span>
                            <?php if (!empty($app['admission_type'])): ?>
                                <span class="badge-lg" style="background: #e9ecef; color: #333;">
                                    <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($app['admission_type']))); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($app['student_id'])): ?>
                                <span class="badge-lg badge-converted">✅ Converted to Student</span>
                            <?php endif; ?>
                            <span class="badge-lg" style="background: #e9ecef; color: #333;">
                                <?php echo htmlspecialchars($app['course_code'] ?? 'No Course Assigned'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="profile-actions">
                        <a href="?page=applications" class="btn btn-secondary">← Back</a>
                        <?php if (($app['status'] ?? '') === 'pending'): ?>
                            <button onclick="toggleEdit()" class="btn btn-warning" id="editToggleBtn">✏️ Edit</button>
                            <button onclick="showRejectForm()" class="btn btn-danger">❌ Reject</button>
                            <button onclick="toggleConvert()" class="btn btn-success">🎓 Convert</button>
                        <?php endif; ?>
                        <?php if (!empty($app['student_id'])): ?>
                            <a href="?page=student-details&id=<?php echo (int) $app['student_id']; ?>"
                               class="btn btn-primary">👤 View Student</a>
                        <?php endif; ?>
                        <?php if (($app['status'] ?? '') !== 'converted'): ?>
                            <form method="POST" style="display: inline-block;"
                                  onsubmit="return confirm('Are you sure you want to delete this application?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="applicant_id" value="<?php echo (int) $app['applicant_id']; ?>">
                                <button type="submit" class="btn btn-danger">🗑️ Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DETAILS GRID -->
                <div class="details-grid" id="detailsView">
                    <!-- Personal Information -->
                    <div class="detail-section">
                        <h3>📋 Personal Information</h3>
                        <div class="detail-row"><span class="detail-label">First Name</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['first_name'] ?? ''); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Middle Name</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['middle_name'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Surname</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['surname'] ?? ''); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Suffix</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['suffix'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Admission Type</span>
                            <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($app['admission_type'] ?? 'N/A'))); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Working Student</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['working_student'] ?? 'No'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Sex</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['sex'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Date of Birth</span>
                            <span class="detail-value"><?php echo !empty($app['date_of_birth']) ? date('M d, Y', strtotime($app['date_of_birth'])) : 'N/A'; ?></span></div>
                        <div class="detail-row"><span class="detail-label">Age</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['age'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Place of Birth</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['place_of_birth'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Civil Status</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['civil_status'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Religion</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['religion'] ?? 'N/A'); ?></span></div>
                    </div>

                    <!-- Contact & Address -->
                    <div class="detail-section">
                        <h3>📞 Contact Information</h3>
                        <div class="detail-row"><span class="detail-label">Email</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['email'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Contact Number</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['contact_number'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Facebook</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['facebook'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Messenger</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['messenger'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row" style="margin-top:10px;padding-top:10px;border-top:2px solid #dee2e6;">
                            <span class="detail-label">Barangay</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['address_barangay'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">City/Municipality</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['address_city'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Province</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['address_province'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Complete Address</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['address_complete'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row" style="margin-top:10px;padding-top:10px;border-top:2px solid #dee2e6;">
                            <span class="detail-label"><strong>Parent/Guardian</strong></span>
                            <span class="detail-value"></span></div>
                        <div class="detail-row"><span class="detail-label">Parent Name</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['parent_full_name'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Parent Contact</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['parent_contact'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Parent Address</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['parent_address'] ?? 'N/A'); ?></span></div>
                    </div>

                    <!-- Academic Information -->
                    <div class="detail-section">
                        <h3>🎓 Academic Information</h3>
                        <div class="detail-row"><span class="detail-label">Course</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['course_code'] ?? 'No Course Assigned'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">School Last Attended</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['school_last_attended'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Year Graduated</span>
                            <span class="detail-value"><?php echo htmlspecialchars($app['year_graduated'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">How Heard</span>
                            <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($app['how_hear'] ?? 'N/A'))); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Submitted At</span>
                            <span class="detail-value"><?php echo !empty($app['submitted_at']) ? date('M d, Y h:i A', strtotime($app['submitted_at'])) : 'N/A'; ?></span></div>
                        <?php if (!empty($app['notes'])): ?>
                            <div class="detail-row"><span class="detail-label">Notes</span>
                                <span class="detail-value" style="text-align:left;max-width:200px;"><?php echo htmlspecialchars($app['notes']); ?></span></div>
                        <?php endif; ?>
                    </div>

                    <!-- Status Information -->
                    <div class="detail-section">
                        <h3>📊 Status Information</h3>
                        <div class="detail-row"><span class="detail-label">Application Status</span>
                            <span class="detail-value">
                                <span class="badge-lg badge-<?php echo htmlspecialchars($app['status'] ?? 'pending'); ?>"
                                      style="font-size:12px;padding:4px 12px;">
                                    <?php echo ucfirst(htmlspecialchars($app['status'] ?? 'pending')); ?>
                                </span>
                            </span>
                        </div>
                        <?php if (!empty($app['student_id'])): ?>
                            <div class="detail-row"><span class="detail-label">Student ID</span>
                                <span class="detail-value"><?php echo htmlspecialchars($app['student_id']); ?></span></div>
                            <div class="detail-row"><span class="detail-label">Student Number</span>
                                <span class="detail-value"><?php echo htmlspecialchars($app['student_number'] ?? 'N/A'); ?></span></div>
                            <div class="detail-row"><span class="detail-label">Student Status</span>
                                <span class="detail-value"><?php echo htmlspecialchars($app['student_status'] ?? 'N/A'); ?></span></div>
                        <?php else: ?>
                            <div class="detail-row">
                                <span class="detail-label" style="color:#856404;">Not yet converted</span>
                                <span class="detail-value" style="color:#856404;">⏳ Pending</span>
                            </div>
                            <?php if (!empty($app['course_id'])): ?>
                                <div class="detail-row" style="margin-top:10px;padding-top:10px;border-top:2px solid #dee2e6;">
                                    <span class="detail-label" style="color:#28a745;">Ready for enrollment</span>
                                    <span class="detail-value" style="color:#28a745;">✅ Click "Convert to Student"</span>
                                </div>
                                <?php if (!empty($availableSections)): ?>
                                    <div class="detail-row">
                                        <span class="detail-label" style="color:#17a2b8;">Available sections</span>
                                        <span class="detail-value" style="color:#17a2b8;"><?php echo count($availableSections); ?> found</span>
                                    </div>
                                <?php else: ?>
                                    <div class="detail-row">
                                        <span class="detail-label" style="color:#dc3545;">No available sections</span>
                                        <span class="detail-value" style="color:#dc3545;">⚠️ Please add a section</span>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="detail-row" style="margin-top:10px;padding-top:10px;border-top:2px solid #dee2e6;">
                                    <span class="detail-label" style="color:#dc3545;">Missing Course</span>
                                    <span class="detail-value" style="color:#dc3545;">⚠️ Edit to assign a course</span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- CONVERT FORM -->
            <?php if (($app['status'] ?? '') === 'pending' && empty($app['student_id']) && !empty($app['course_id'])): ?>
            <div class="convert-section" id="convertForm" style="display: none;">
                <h3>🎓 Convert to Student & Enroll</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="convert">
                    <input type="hidden" name="applicant_id" value="<?php echo (int) $app['applicant_id']; ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Section <span class="required">*</span></label>
                            <select name="section_id" class="form-control" required>
                                <option value="">-- Select Section --</option>
                                <?php foreach ($availableSections as $sec): ?>
                                    <option value="<?php echo (int) $sec['section_id']; ?>">
                                        <?php echo htmlspecialchars($sec['section_code'] ?? ''); ?>
                                        (<?php echo (int) ($sec['current_students'] ?? 0); ?>/<?php echo (int) ($sec['max_students'] ?? 40); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($availableSections)): ?>
                                <small style="color:#dc3545;">⚠️ No available sections for this course.</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>School Year <span class="required">*</span></label>
                            <input type="text" name="school_year" class="form-control"
                                   value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>"
                                   pattern="\d{4}-\d{4}" placeholder="e.g., 2026-2027" required>
                        </div>
                    </div>

                    <?php if (!empty($requirements)): ?>
                    <div class="form-group" style="margin-top:15px;">
                        <label>Requirements Checklist</label>
                        <div style="background:#f8f9fa;padding:15px;border-radius:4px;border:1px solid #e9ecef;">
                            <?php foreach ($requirements as $req): ?>
                                <div style="margin:5px 0;">
                                    <label style="font-weight:normal;cursor:pointer;">
                                        <input type="checkbox"
                                               name="requirements[<?php echo (int) $req['requirement_id']; ?>]"
                                               value="1">
                                        <?php echo htmlspecialchars($req['requirement_name']); ?>
                                        <?php if (!empty($req['is_mandatory'])): ?>
                                            <span style="color:#dc3545;font-size:12px;">*Required</span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <small style="color:#6c757d;">Check off any requirements already submitted.</small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-actions" style="margin-top:20px;">
                        <button type="button" class="btn btn-secondary" onclick="toggleConvert()">Cancel</button>
                        <button type="submit" class="btn btn-success" <?php echo empty($availableSections) ? 'disabled' : ''; ?>>
                            🎓 Convert & Enroll
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- REJECT FORM -->
            <div id="rejectForm" style="display:none;background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:20px;margin-top:20px;">
                <h3>❌ Reject Application</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="applicant_id" value="<?php echo (int) $app['applicant_id']; ?>">
                    <div class="form-group">
                        <label>Reason for Rejection</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Enter reason for rejection..."></textarea>
                    </div>
                    <div class="form-actions" style="margin-top:15px;">
                        <button type="button" class="btn btn-secondary" onclick="hideRejectForm()">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Reject</button>
                    </div>
                </form>
            </div>

            <!-- EDIT FORM -->
<div class="edit-form-container" id="editForm" style="display: none;">
    <h2>✏️ Edit Application</h2>
    <form method="POST">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="applicant_id" value="<?php echo (int) $app['applicant_id']; ?>">

        <!-- PERSONAL INFORMATION -->
        <h3 style="color: var(--navy); margin: 15px 0 10px; font-size: 15px;">📋 Personal Information</h3>
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
                       value="<?php echo htmlspecialchars($app['suffix'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Admission Type</label>
                <select name="admission_type" class="form-control">
                    <?php
                    $admissionTypes = ['freshmen', 'transferee', 'returnee', 'shiftee', 'second_courser'];
                    foreach ($admissionTypes as $type):
                    ?>
                        <option value="<?php echo $type; ?>"
                            <?php echo (($app['admission_type'] ?? '') === $type) ? 'selected' : ''; ?>>
                            <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Working Student</label>
                <select name="working_student" class="form-control">
                    <option value="No"  <?php echo (($app['working_student'] ?? 'No') === 'No')  ? 'selected' : ''; ?>>No</option>
                    <option value="Yes" <?php echo (($app['working_student'] ?? '')   === 'Yes') ? 'selected' : ''; ?>>Yes</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Sex</label>
                <select name="sex" class="form-control">
                    <option value="">-- Select --</option>
                    <option value="Male"   <?php echo (($app['sex'] ?? '') === 'Male')   ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo (($app['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>
            <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="date_of_birth" class="form-control"
                       value="<?php echo htmlspecialchars($app['date_of_birth'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Age</label>
                <input type="number" name="age" class="form-control" min="0" max="120"
                       value="<?php echo htmlspecialchars($app['age'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Place of Birth</label>
                <input type="text" name="place_of_birth" class="form-control"
                       value="<?php echo htmlspecialchars($app['place_of_birth'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Civil Status</label>
                <select name="civil_status" class="form-control">
                    <?php
                    $civilStatuses = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
                    foreach ($civilStatuses as $cs):
                    ?>
                        <option value="<?php echo $cs; ?>"
                            <?php echo (($app['civil_status'] ?? 'Single') === $cs) ? 'selected' : ''; ?>>
                            <?php echo $cs; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Religion</label>
                <input type="text" name="religion" class="form-control"
                       value="<?php echo htmlspecialchars($app['religion'] ?? ''); ?>">
            </div>
        </div>

        <!-- CONTACT INFORMATION -->
        <h3 style="color: var(--navy); margin: 20px 0 10px; font-size: 15px;">📞 Contact Information</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?php echo htmlspecialchars($app['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Contact Number</label>
                <input type="text" name="contact_number" class="form-control"
                       value="<?php echo htmlspecialchars($app['contact_number'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Facebook</label>
                <input type="text" name="facebook" class="form-control"
                       value="<?php echo htmlspecialchars($app['facebook'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Messenger</label>
                <input type="text" name="messenger" class="form-control"
                       value="<?php echo htmlspecialchars($app['messenger'] ?? ''); ?>">
            </div>
        </div>

        <!-- ADDRESS -->
        <h3 style="color: var(--navy); margin: 20px 0 10px; font-size: 15px;">🏠 Address</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Barangay</label>
                <input type="text" name="address_barangay" class="form-control"
                       value="<?php echo htmlspecialchars($app['address_barangay'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>City/Municipality</label>
                <input type="text" name="address_city" class="form-control"
                       value="<?php echo htmlspecialchars($app['address_city'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Province</label>
                <input type="text" name="address_province" class="form-control"
                       value="<?php echo htmlspecialchars($app['address_province'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Complete Address</label>
                <input type="text" name="address_complete" class="form-control"
                       value="<?php echo htmlspecialchars($app['address_complete'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Address (legacy / free text)</label>
            <input type="text" name="address" class="form-control"
                   value="<?php echo htmlspecialchars($app['address'] ?? ''); ?>">
        </div>

        <!-- PARENT / GUARDIAN -->
        <h3 style="color: var(--navy); margin: 20px 0 10px; font-size: 15px;">👨‍👩‍👧 Parent / Guardian</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Parent Full Name</label>
                <input type="text" name="parent_full_name" class="form-control"
                       value="<?php echo htmlspecialchars($app['parent_full_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Parent Contact</label>
                <input type="text" name="parent_contact" class="form-control"
                       value="<?php echo htmlspecialchars($app['parent_contact'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Parent Address</label>
            <input type="text" name="parent_address" class="form-control"
                   value="<?php echo htmlspecialchars($app['parent_address'] ?? ''); ?>">
        </div>

        <!-- ACADEMIC INFORMATION -->
        <h3 style="color: var(--navy); margin: 20px 0 10px; font-size: 15px;">🎓 Academic Information</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Course</label>
                <select name="course_id" class="form-control">
                    <option value="">-- Select Course --</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>"
                            <?php echo ((int) ($app['course_id'] ?? 0) === (int) $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['course_code'] ?? ''); ?>
                            — <?php echo htmlspecialchars($c['course_name'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Preferred Section</label>
                <select name="preferred_section_id" class="form-control">
                    <option value="">-- None --</option>
                    <?php foreach ($availableSections as $sec): ?>
                        <option value="<?php echo (int) $sec['section_id']; ?>"
                            <?php echo ((int) ($app['preferred_section_id'] ?? 0) === (int) $sec['section_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sec['section_code'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>School Last Attended</label>
                <input type="text" name="school_last_attended" class="form-control"
                       value="<?php echo htmlspecialchars($app['school_last_attended'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Year Graduated</label>
                <input type="number" name="year_graduated" class="form-control"
                       min="1900" max="<?php echo date('Y') + 1; ?>"
                       value="<?php echo htmlspecialchars($app['year_graduated'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-group">
            <label>How did you hear about us?</label>
            <select name="how_hear" class="form-control">
                <option value="">-- Select --</option>
                <?php
                $howHearOptions = ['facebook', 'friend', 'family', 'school', 'walk_in', 'other'];
                foreach ($howHearOptions as $opt):
                ?>
                    <option value="<?php echo $opt; ?>"
                        <?php echo (($app['how_hear'] ?? '') === $opt) ? 'selected' : ''; ?>>
                        <?php echo ucfirst(str_replace('_', ' ', $opt)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-secondary" onclick="toggleEdit()">Cancel</button>
            <button type="submit" class="btn btn-primary">Update Application</button>
        </div>
    </form>
</div>


<?php include __DIR__ . '/../includes/footer.php'; ?>
<style>
    /* ============================================================
       APPLICATION DETAILS — Blue & Sky Blue Theme
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

    .application-profile {
        background: white;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.1);
    }

    .profile-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 20px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--sky-bg);
    }

    .profile-info {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .profile-info .applicant-name {
        font-size: 28px;
        font-weight: 700;
        color: var(--navy);
    }

    .profile-info .applicant-id {
        font-size: 16px;
        color: var(--sky-muted);
    }

    .profile-badges {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .badge-lg {
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
        display: inline-block;
    }

    /* Status badges — all in blue/sky tones */
    .badge-pending   { background: #e8f0fe; color: var(--navy);     border: 1px solid var(--sky-border); }
    .badge-converted { background: #d4e8fc; color: var(--navy);     border: 1px solid var(--sky-pale); }
    .badge-rejected  { background: #dce8f5; color: var(--navy-dark); border: 1px solid var(--sky-pale); }

    .profile-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .details-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
    }

    .detail-section {
        background: var(--sky-bg-soft);
        border-radius: 8px;
        padding: 15px 20px;
        border: 1px solid var(--sky-bg);
    }

    .detail-section h3 {
        color: var(--navy);
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--sky-border);
        font-size: 16px;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px solid var(--sky-bg);
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-weight: 500;
        color: var(--sky-muted);
        font-size: 14px;
    }

    .detail-value {
        font-weight: 500;
        color: var(--navy);
        text-align: right;
        font-size: 14px;
    }

    .edit-form-container {
        background: white;
        border-radius: 8px;
        padding: 25px;
        margin-top: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.1);
    }

    .edit-form-container h2 {
        color: var(--navy);
        margin-bottom: 20px;
        font-size: 18px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
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
        font-weight: 500;
        font-size: 14px;
        color: var(--navy);
    }

    .form-group label .required {
        color: var(--navy-dark);
    }

    .form-control {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        transition: border-color 0.3s ease;
        box-sizing: border-box;
        background: white;
        color: var(--navy);
    }

    .form-control:focus {
        border-color: var(--navy);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    .form-control[readonly] {
        background: var(--sky-bg-soft);
        cursor: not-allowed;
    }

    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    .convert-section {
        background: var(--sky-bg-soft);
        border: 1px solid var(--sky-border);
        border-radius: 8px;
        padding: 20px;
        margin-top: 20px;
    }

    .convert-section h3 {
        color: var(--navy);
        margin-bottom: 15px;
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

    .btn-sm {
        padding: 4px 10px;
        font-size: 12px;
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

    .btn-warning {
        background: var(--sky);
        color: white;
    }
    .btn-warning:hover {
        background: var(--blue-mid);
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

    /* Responsive */
    @media (max-width: 768px) {
        .details-grid {
            grid-template-columns: 1fr;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .profile-header {
            flex-direction: column;
        }

        .profile-info .applicant-name {
            font-size: 22px;
        }

        .form-actions {
            flex-direction: column;
        }

        .form-actions .btn {
            width: 100%;
            text-align: center;
        }

        .profile-actions {
            flex-direction: column;
            width: 100%;
        }

        .profile-actions .btn {
            width: 100%;
            text-align: center;
        }
    }
</style>

<script>
function toggleEdit() {
    var detailsView = document.getElementById('detailsView');
    var editForm = document.getElementById('editForm');
    var editBtn = document.getElementById('editToggleBtn');
    
    if (editForm.style.display === 'none') {
        detailsView.style.display = 'none';
        editForm.style.display = 'block';
        editBtn.textContent = 'Cancel Edit';
        editBtn.className = 'btn btn-secondary';
    } else {
        detailsView.style.display = 'grid';
        editForm.style.display = 'none';
        editBtn.textContent = '✏️ Edit';
        editBtn.className = 'btn btn-warning';
    }
}

function toggleConvert() {
    var convertForm = document.getElementById('convertForm');
    if (convertForm) {
        if (convertForm.style.display === 'none') {
            convertForm.style.display = 'block';
            convertForm.scrollIntoView({ behavior: 'smooth' });
        } else {
            convertForm.style.display = 'none';
        }
    }
}

function showRejectForm() {
    var rejectForm = document.getElementById('rejectForm');
    if (rejectForm.style.display === 'none') {
        rejectForm.style.display = 'block';
        rejectForm.scrollIntoView({ behavior: 'smooth' });
    } else {
        rejectForm.style.display = 'none';
    }
}

function hideRejectForm() {
    document.getElementById('rejectForm').style.display = 'none';
}

// Close modals on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        if (document.getElementById('editForm').style.display !== 'none') {
            toggleEdit();
        }
        if (document.getElementById('rejectForm').style.display !== 'none') {
            hideRejectForm();
        }
        if (document.getElementById('convertForm') && document.getElementById('convertForm').style.display !== 'none') {
            toggleConvert();
        }
    }
});
</script>