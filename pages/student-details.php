<?php
// pages/student-details.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • subject_type is no longer returned by the API → uses ?? 'Lecture'
//   • All $student[...] reads null-safe
//   • Requirements/progress values guarded with ?? 0
//   • Drop action replaced with Archive (soft-delete + restore-friendly)
//   • Escaped $student['followup_status'] / enrollment_status in class attributes

require_once 'classes/Student.php';
require_once 'classes/StudentController.php';
require_once 'classes/Enrollment.php';
require_once 'classes/Requirement.php';
require_once 'classes/Application.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$controller = new StudentController();

$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($studentId <= 0) {
    header('Location: ?page=students');
    exit;
}

$student = $controller->getStudentById($studentId);
if (!$student) {
    $_SESSION['message'] = '❌ Student not found.';
    header('Location: ?page=students');
    exit;
}

$enrollment        = new Enrollment();
$enrollmentDetails = $enrollment->getEnrollmentByStudentId($studentId);

$subjects = $enrollment->getStudentCurrentSemesterEnrollments($studentId);
if (!is_array($subjects)) $subjects = [];

$requirement         = new Requirement();
$requirements        = $requirement->getStudentRequirementsWithStatus($studentId);
if (!is_array($requirements)) $requirements = [];
$completionStatus    = $requirement->getRequirementCompletionStatus($studentId);
$mandatoryCompleted  = $requirement->hasCompletedMandatoryRequirements($studentId);

$fullName = trim(
    ($student['first_name'] ?? '')
    . ' ' . ($student['middle_name'] ?? '')
    . ' ' . ($student['surname'] ?? '')
    . ' ' . ($student['suffix'] ?? '')
);

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ============================================================
// POST: Archive / Restore actions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $actionStudentId = (int) ($_POST['student_id'] ?? 0);

    if ($actionStudentId > 0) {
        switch ($_POST['action']) {
            case 'archive':
                $reason     = trim($_POST['archive_reason'] ?? 'Dropped');
                $schoolYear = $_POST['school_year'] ?? null;
                $_SESSION['message'] = $enrollment->archiveStudent($actionStudentId, $reason, $schoolYear)
                    ? '✅ Student archived successfully! Pwede pang i-restore.'
                    : '❌ Failed to archive student.';
                header('Location: ?page=student-details&id=' . $studentId);
                exit;

            case 'restore':
                $_SESSION['message'] = $enrollment->restoreStudent($actionStudentId)
                    ? '✅ Student restored successfully!'
                    : '❌ Failed to restore student.';
                header('Location: ?page=student-details&id=' . $studentId);
                exit;

            case 'drop': // legacy — kept for backward compat
                $schoolYear = $_POST['school_year'] ?? null;
                $_SESSION['message'] = $enrollment->dropStudent($actionStudentId, $schoolYear)
                    ? '✅ Student dropped successfully!'
                    : '❌ Failed to drop student.';
                header('Location: ?page=student-details&id=' . $studentId);
                exit;
        }
    }
}

// ============================================================
// Badges / labels
// ============================================================
$admissionType = $student['admission_type'] ?? 'freshmen';
$badgeMap = [
    'freshmen'    => 'badge-freshmen',
    'transferee'  => 'badge-transferee',
    'returnee'    => 'badge-returnee',
    'senior_high' => 'badge-senior_high'
];
$admissionBadgeClass = $badgeMap[$admissionType] ?? 'badge-default';
$admissionLabel      = ucfirst(str_replace('_', ' ', $admissionType));

$currentStatus = $student['enrollment_status'] ?? 'enrolled';
$statusMap = [
    'enrolled'  => 'badge-enrolled',
    'on_leave'  => 'badge-on_leave',
    'graduated' => 'badge-graduated',
    'dropped'   => 'badge-dropped'
];
$statusBadgeClass = $statusMap[$currentStatus] ?? 'badge-enrolled';
$statusLabel      = ucfirst(str_replace('_', ' ', $currentStatus));

$subjectCount = count($subjects);
$pageTitle    = 'Student Details - ' . $fullName;
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>👨‍🎓 Student Details</h1>
            <p class="dashboard-subtitle">
                <?php echo htmlspecialchars($fullName); ?>
                • Student #<?php echo htmlspecialchars($student['student_number'] ?? ''); ?>
            </p>
        </div>

        <div class="module-content">

            <!-- MESSAGES -->
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo strpos($message, '✅') !== false ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- STUDENT PROFILE -->
            <div class="student-profile">
                <div class="profile-header">
                    <div class="profile-info">
                        <div class="student-name"><?php echo htmlspecialchars($fullName); ?></div>
                        <div class="student-number">
                            🎓 Student #: <?php echo htmlspecialchars($student['student_number'] ?? ''); ?>
                        </div>
                        <div class="profile-badges">
                            <span class="badge-lg <?php echo htmlspecialchars($statusBadgeClass); ?>">
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </span>
                            <span class="badge-lg <?php echo htmlspecialchars($admissionBadgeClass); ?>">
                                <?php echo htmlspecialchars($admissionLabel); ?>
                            </span>
                            <?php if ($enrollmentDetails): ?>
                                <span class="badge-lg badge-active">✅ Currently Enrolled</span>
                            <?php else: ?>
                                <span class="badge-lg badge-inactive">❌ Not Enrolled</span>
                            <?php endif; ?>
                            <?php if (($student['followup_status'] ?? '') === 'pending' && !empty($student['followup_date'])): ?>
                                <span class="badge-lg" style="background:#e8f0fe;color:#2a5c9e;border:1px solid #b8d4e8;">
                                    📅 Follow-up: <?php echo htmlspecialchars(date('M d, Y', strtotime($student['followup_date']))); ?>
                                </span>
                            <?php endif; ?>
                            <span class="badge-lg" style="background:#4a90d9;color:white;">
                                📚 <?php echo $subjectCount; ?> Subjects
                            </span>
                        </div>
                    </div>
                    <div class="profile-actions">
                        <a href="?page=students" class="btn btn-secondary">← Back</a>
                        <a href="?page=student-edit&id=<?php echo (int) $student['student_id']; ?>" class="btn btn-warning">✏️ Edit</a>
                        <a href="?page=student-subjects&id=<?php echo (int) $student['student_id']; ?>" class="btn btn-info">📚 Subjects</a>
                        <a href="?page=requirements&student_id=<?php echo (int) $student['student_id']; ?>&view=students" class="btn btn-primary">📋 Requirements</a>
                        <?php if ($enrollmentDetails): ?>
                            <button class="btn btn-danger" onclick="openArchiveModal()">📦 Archive</button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DETAILS GRID -->
                <div class="details-grid">
                    <!-- Personal Information -->
                    <div class="detail-section">
                        <h3>📋 Personal Information</h3>
                        <div class="detail-row"><span class="detail-label">First Name</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['first_name'] ?? ''); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Middle Name</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['middle_name'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Surname</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['surname'] ?? ''); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Suffix</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['suffix'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Admission Type</span>
                            <span class="detail-value"><?php echo htmlspecialchars($admissionLabel); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Sex</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['sex'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Date of Birth</span>
                            <span class="detail-value"><?php echo !empty($student['date_of_birth']) ? htmlspecialchars(date('M d, Y', strtotime($student['date_of_birth']))) : 'N/A'; ?></span></div>
                        <div class="detail-row"><span class="detail-label">Age</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['age'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Place of Birth</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['place_of_birth'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Civil Status</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['civil_status'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Religion</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['religion'] ?? 'N/A'); ?></span></div>
                    </div>

                    <!-- Contact & Address -->
                    <div class="detail-section">
                        <h3>📞 Contact Information</h3>
                        <div class="detail-row"><span class="detail-label">Email</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Contact Number</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Facebook</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['facebook'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Messenger</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['messenger'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row" style="margin-top:10px;padding-top:10px;border-top:2px solid #e8f4fd;">
                            <span class="detail-label">Barangay</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['address_barangay'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">City/Municipality</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['address_city'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Province</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['address_province'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Complete Address</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['address_complete'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row" style="margin-top:10px;padding-top:10px;border-top:2px solid #e8f4fd;">
                            <span class="detail-label"><strong>Parent/Guardian</strong></span>
                            <span class="detail-value"></span></div>
                        <div class="detail-row"><span class="detail-label">Parent Name</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['parent_full_name'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Parent Contact</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['parent_contact'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Parent Address</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['parent_address'] ?? 'N/A'); ?></span></div>
                    </div>

                    <!-- Academic Information -->
                    <div class="detail-section">
                        <h3>🎓 Academic Information</h3>
                        <div class="detail-row"><span class="detail-label">Course</span>
                            <span class="detail-value"><?php echo htmlspecialchars(
                                ($student['course_code'] ?? '') . ' - ' . ($student['course_name'] ?? '')
                            ); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Section</span>
                            <span class="detail-value">
                                <?php echo htmlspecialchars($student['section_code'] ?? 'Not Assigned'); ?>
                                <?php if (!empty($student['grade_level'])): ?>
                                    (<?php echo htmlspecialchars($student['grade_level']); ?>)
                                <?php endif; ?>
                            </span></div>
                        <div class="detail-row"><span class="detail-label">Year Level</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">School Last Attended</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['school_last_attended'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Year Graduated</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['year_graduated'] ?? 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label">How Heard</span>
                            <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($student['how_hear'] ?? 'N/A'))); ?></span></div>
                        <div class="detail-row"><span class="detail-label">Enrolled At</span>
                            <span class="detail-value"><?php echo !empty($student['enrolled_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($student['enrolled_at']))) : 'N/A'; ?></span></div>
                    </div>

                    <!-- Status Information -->
                    <div class="detail-section">
                        <h3>📊 Status Information</h3>
                        <div class="detail-row"><span class="detail-label">Enrollment Status</span>
                            <span class="detail-value">
                                <span class="badge-lg <?php echo htmlspecialchars($statusBadgeClass); ?>" style="font-size:12px;padding:4px 12px;">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                            </span></div>
                        <div class="detail-row"><span class="detail-label">Working Student</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['working_student'] ?? 'No'); ?></span></div>
                        <?php if (!empty($student['followup_date'])): ?>
                            <div class="detail-row"><span class="detail-label">Follow-up Date</span>
                                <span class="detail-value"><?php echo htmlspecialchars(date('M d, Y', strtotime($student['followup_date']))); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($student['followup_notes'])): ?>
                            <div class="detail-row"><span class="detail-label">Follow-up Notes</span>
                                <span class="detail-value" style="text-align:left;max-width:200px;"><?php echo htmlspecialchars($student['followup_notes']); ?></span></div>
                        <?php endif; ?>
                        <div class="detail-row"><span class="detail-label">Follow-up Status</span>
                            <span class="detail-value">
                                <span class="badge-lg"
                                      style="font-size:12px;padding:4px 12px;background:<?php echo ($student['followup_status'] ?? '') === 'done' ? '#d4e8fc' : '#e8f0fe'; ?>;color:<?php echo ($student['followup_status'] ?? '') === 'done' ? '#1a3c6e' : '#2a5c9e'; ?>;">
                                    <?php echo ucfirst(htmlspecialchars($student['followup_status'] ?? 'N/A')); ?>
                                </span>
                            </span></div>
                        <div class="detail-row" style="margin-top:10px;padding-top:10px;border-top:2px solid #e8f4fd;">
                            <span class="detail-label">Requirements Progress</span>
                            <span class="detail-value">
                                <?php $pct = (int) ($completionStatus['percentage'] ?? 0); ?>
                                <span style="font-weight:600;color:<?php echo $pct >= 80 ? '#2a5c9e' : ($pct >= 50 ? '#4a90d9' : '#0f2a4e'); ?>;">
                                    <?php echo $pct; ?>%
                                </span>
                                <br>
                                <small style="color:#5a7fa8;font-weight:normal;">
                                    <?php echo (int) ($completionStatus['submitted'] ?? 0); ?>/<?php echo (int) ($completionStatus['total'] ?? 0); ?> submitted
                                </small>
                                <?php if ($mandatoryCompleted): ?>
                                    <br><small style="color:#2a5c9e;font-weight:bold;">✅ All mandatory requirements completed</small>
                                <?php else: ?>
                                    <br><small style="color:#0f2a4e;">⚠️ Some mandatory requirements pending</small>
                                <?php endif; ?>
                            </span></div>
                    </div>
                </div>
            </div>

            <!-- FOLLOW-UP STATUS -->
            <?php if (($student['followup_status'] ?? '') === 'pending' && !empty($student['followup_date'])): ?>
                <div class="followup-card">
                    <div class="followup-label">📅 Follow-up Scheduled</div>
                    <p style="margin:5px 0;"><strong>Date:</strong> <?php echo htmlspecialchars(date('l, F d, Y', strtotime($student['followup_date']))); ?></p>
                    <?php if (!empty($student['followup_notes'])): ?>
                        <p style="margin:5px 0;"><strong>Notes:</strong> <?php echo htmlspecialchars($student['followup_notes']); ?></p>
                    <?php endif; ?>
                    <a href="?page=requirements&student_id=<?php echo (int) $student['student_id']; ?>&view=followup"
                       class="btn btn-sm btn-primary" style="margin-top:5px;">View in Follow-ups</a>
                </div>
            <?php endif; ?>

            <!-- ENROLLMENT INFORMATION -->
            <?php if ($enrollmentDetails): ?>
                <div class="enrollment-card">
                    <h2>📌 Current Enrollment</h2>
                    <div class="enrollment-grid">
                        <div class="enrollment-item">
                            <div class="label">School Year</div>
                            <div class="value"><?php echo htmlspecialchars($enrollmentDetails['school_year'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="label">Section</div>
                            <div class="value"><?php echo htmlspecialchars($enrollmentDetails['section_code'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="label">Grade Level</div>
                            <div class="value"><?php echo htmlspecialchars($enrollmentDetails['grade_level'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="label">Subjects</div>
                            <div class="value"><?php echo $subjectCount; ?></div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    ℹ️ This student is not currently enrolled in any section.
                    <a href="?page=enrollments&student_id=<?php echo (int) $student['student_id']; ?>"
                       class="btn btn-sm btn-success" style="margin-left:10px;">Enroll Now</a>
                </div>
            <?php endif; ?>

            <!-- SUBJECTS SECTION -->
            <div class="subjects-section">
                <div class="subjects-header">
                    <h2>📚 Enrolled Subjects (<?php echo $subjectCount; ?>)</h2>
                    <div style="display:flex;gap:8px;">
                        <a href="?page=student-subjects&id=<?php echo (int) $student['student_id']; ?>"
                           class="btn btn-info btn-sm">View All Subjects →</a>
                    </div>
                </div>

                <?php if ($subjectCount > 0): ?>
                    <div class="table-responsive">
                        <table class="table subjects-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Subject Code</th>
                                    <th>Subject Name</th>
                                    <th>Units</th>
                                    <th>Type</th>
                                    <th>Schedule</th>
                                    <th>Faculty</th>
                                    <th>Room</th>
                                    <th>Status</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $counter    = 1;
                                $totalUnits = 0;
                                foreach ($subjects as $subject):
                                    $totalUnits += (int) ($subject['units'] ?? 0);

                                    // Schedule display
                                    $scheduleDisplay = 'No schedule';
                                    if (!empty($subject['day_of_week']) && !empty($subject['start_time'])) {
                                        $scheduleDisplay = $subject['day_of_week'];
                                        if (!empty($subject['start_time'])) {
                                            $scheduleDisplay .= ' ' . date('h:i A', strtotime($subject['start_time']));
                                            if (!empty($subject['end_time'])) {
                                                $scheduleDisplay .= ' - ' . date('h:i A', strtotime($subject['end_time']));
                                            }
                                        }
                                    }

                                    // Faculty name
                                    $facultyDisplay = trim(
                                        ($subject['faculty_first'] ?? '') . ' ' . ($subject['faculty_last'] ?? '')
                                    );
                                    if ($facultyDisplay === '') $facultyDisplay = 'N/A';

                                    // Room
                                    $roomDisplay = $subject['room_name'] ?? '';
                                    if ($roomDisplay !== '' && !empty($subject['room_code'])) {
                                        $roomDisplay .= ' (' . $subject['room_code'] . ')';
                                    }
                                    if ($roomDisplay === '') $roomDisplay = 'N/A';

                                    $enrollmentStatus = $subject['enrollment_status'] ?? 'enrolled';
                                    $gradeDisplay     = $subject['grade'] ?? null;

                                    // subject_type no longer comes from the API. Fallback to 'Lecture'.
                                    $subjectType = $subject['subject_type'] ?? 'Lecture';
                                    $typeClass   = 'subject-' . strtolower($subjectType);
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($subject['subject_code'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subject['subject_name'] ?? 'N/A'); ?></td>
                                    <td class="text-center"><?php echo (int) ($subject['units'] ?? 0); ?></td>
                                    <td>
                                        <span class="subject-type-badge <?php echo htmlspecialchars($typeClass); ?>">
                                            <?php echo htmlspecialchars($subjectType); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($subject['schedule_id'])): ?>
                                            <span class="schedule-badge has-schedule">📅 <?php echo htmlspecialchars($scheduleDisplay); ?></span>
                                        <?php else: ?>
                                            <span class="schedule-badge no-schedule">⏳ <?php echo htmlspecialchars($scheduleDisplay); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($facultyDisplay !== 'N/A'): ?>
                                            <span class="faculty-name">👨‍🏫 <?php echo htmlspecialchars($facultyDisplay); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($roomDisplay !== 'N/A'): ?>
                                            <span class="room-name">🏫 <?php echo htmlspecialchars($roomDisplay); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($enrollmentStatus); ?>">
                                            <?php echo ucfirst(htmlspecialchars($enrollmentStatus)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($gradeDisplay !== null): ?>
                                            <span class="grade-badge grade-<?php echo $gradeDisplay >= 75 ? 'passed' : 'failed'; ?>">
                                                <?php echo number_format((float) $gradeDisplay, 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="grade-badge grade-pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3"><strong>Total Units</strong></td>
                                    <td class="text-center"><strong><?php echo $totalUnits; ?></strong></td>
                                    <td colspan="6"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Subject Summary Cards -->
                    <div class="subject-summary-cards">
                        <div class="summary-card">
                            <div class="summary-number"><?php echo $subjectCount; ?></div>
                            <div class="summary-label">Total Subjects</div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-number"><?php echo $totalUnits; ?></div>
                            <div class="summary-label">Total Units</div>
                        </div>
                        <div class="summary-card">
                            <?php
                                $completed = 0;
                                foreach ($subjects as $s) {
                                    if (($s['enrollment_status'] ?? '') === 'completed') $completed++;
                                }
                            ?>
                            <div class="summary-number"><?php echo $completed; ?></div>
                            <div class="summary-label">✅ Completed</div>
                        </div>
                        <div class="summary-card">
                            <?php
                                $withSchedule = 0;
                                foreach ($subjects as $s) {
                                    if (!empty($s['schedule_id'])) $withSchedule++;
                                }
                            ?>
                            <div class="summary-number"><?php echo $withSchedule; ?></div>
                            <div class="summary-label">📅 With Schedule</div>
                        </div>
                        <div class="summary-card">
                            <?php
                                $inProgress = 0;
                                foreach ($subjects as $s) {
                                    if (($s['enrollment_status'] ?? '') === 'enrolled') $inProgress++;
                                }
                            ?>
                            <div class="summary-number"><?php echo $inProgress; ?></div>
                            <div class="summary-label">⏳ In Progress</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info" style="margin-top:10px;">
                        📚 No subjects enrolled for this student.
                        <a href="?page=enrollments" class="btn btn-sm btn-success" style="margin-left:10px;">Enroll Now</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- REQUIREMENTS SECTION -->
            <div class="requirements-section">
                <div class="requirements-header">
                    <h2>📋 Requirements (<?php echo htmlspecialchars($admissionLabel); ?>)</h2>
                    <span class="progress-text">
                        <?php echo (int) ($completionStatus['submitted'] ?? 0); ?>/<?php echo (int) ($completionStatus['total'] ?? 0); ?>
                        submitted (<?php echo (int) ($completionStatus['percentage'] ?? 0); ?>%)
                    </span>
                </div>

                <div class="progress-bar">
                    <?php $pct = (int) ($completionStatus['percentage'] ?? 0); ?>
                    <div class="progress-fill"
                         style="width:<?php echo $pct; ?>%;background:<?php echo $pct >= 80 ? '#2a5c9e' : ($pct >= 50 ? '#4a90d9' : '#0f2a4e'); ?>">
                    </div>
                </div>

                <div class="requirements-grid">
                    <?php if (count($requirements) > 0): ?>
                        <?php foreach ($requirements as $req):
                            $isSubmitted = !empty($req['is_submitted']);
                        ?>
                            <div class="requirement-item <?php echo $isSubmitted ? 'submitted' : 'pending'; ?>">
                                <span class="req-name">
                                    <?php echo htmlspecialchars($req['requirement_name'] ?? ''); ?>
                                    <?php if (!empty($req['is_mandatory'])): ?>
                                        <span style="color:#0f2a4e;font-weight:bold;">*</span>
                                    <?php endif; ?>
                                </span>
                                <span class="req-status <?php echo $isSubmitted ? 'submitted' : 'pending'; ?>">
                                    <?php if ($isSubmitted): ?>
                                        ✅ Submitted
                                        <?php if (!empty($req['submitted_date'])): ?>
                                            <br><small style="color:#5a7fa8;font-weight:normal;">
                                                <?php echo htmlspecialchars(date('M d, Y', strtotime($req['submitted_date']))); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        ⏳ Pending
                                        <?php if (!empty($req['notes'])): ?>
                                            <br><small style="color:#5a7fa8;font-weight:normal;">
                                                📝 <?php echo htmlspecialchars($req['notes']); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-info" style="grid-column:1 / -1;">
                            No requirements found for this student.
                            <a href="?page=requirements&view=manage" class="btn btn-sm btn-primary" style="margin-left:10px;">Add Requirements</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="margin-top:20px;text-align:right;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
                    <a href="?page=requirements&student_id=<?php echo (int) $student['student_id']; ?>&view=students" class="btn btn-primary">
                        📋 Manage Requirements
                    </a>
                    <?php if (($student['followup_status'] ?? '') !== 'done'): ?>
                        <a href="?page=requirements&student_id=<?php echo (int) $student['student_id']; ?>&view=followup" class="btn btn-warning">
                            📞 Schedule Follow-up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ARCHIVE MODAL -->
<div id="archiveModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
        <span class="close" onclick="closeArchiveModal()">&times;</span>
        <h2>📦 Archive Student</h2>
        <div style="background:#f0f7ff;padding:15px;border-radius:4px;margin-bottom:20px;border-left:4px solid #1a3c6e;">
            <p><strong>Student:</strong> <?php echo htmlspecialchars($fullName); ?></p>
            <p style="color:#2a5c9e;font-weight:bold;">⚠️ Ang student ay hindi made-delete — ma-archive lang. Pwede pang i-restore.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="student_id" value="<?php echo (int) $student['student_id']; ?>">
            <?php if ($enrollmentDetails): ?>
                <input type="hidden" name="school_year" value="<?php echo htmlspecialchars($enrollmentDetails['school_year'] ?? ''); ?>">
            <?php endif; ?>
            <div class="form-group" style="margin-bottom:15px;">
                <label style="display:block;margin-bottom:5px;font-weight:500;color:#1a3c6e;">Reason for Archiving *</label>
                <select name="archive_reason" required style="width:100%;padding:8px 12px;border:2px solid #b8d4e8;border-radius:4px;color:#1a3c6e;background:white;">
                    <option value="Dropped">Dropped</option>
                    <option value="Transferred">Transferred</option>
                    <option value="LOA">Leave of Absence (LOA)</option>
                    <option value="Graduated">Graduated</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:15px;border-top:1px solid #e8f4fd;">
                <button type="button" class="btn btn-secondary" onclick="closeArchiveModal()">Cancel</button>
                <button type="submit" class="btn btn-warning">📦 Archive Student</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       STUDENT DETAILS — Blue & Sky Blue Theme
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

    /* ---------- PROFILE CARD ---------- */
    .student-profile {
        background: white;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.08);
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

    .profile-info .student-name {
        font-size: 28px;
        font-weight: 700;
        color: var(--navy);
    }

    .profile-info .student-number {
        font-size: 16px;
        color: var(--sky-muted);
    }

    .profile-badges {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    /* ---------- BADGE-LG ---------- */
    .badge-lg {
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        display: inline-block;
        border: 1px solid transparent;
    }

    .badge-enrolled  { background: #d4e8fc; color: var(--navy);      border-color: var(--sky-pale); }
    .badge-on_leave  { background: #e8f0fe; color: var(--blue);      border-color: var(--sky-border); }
    .badge-graduated { background: #e8f4fd; color: var(--navy);      border-color: var(--sky-border); }
    .badge-dropped   { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }

    .badge-freshmen    { background: #d4e8fc; color: var(--navy); }
    .badge-transferee  { background: #cce5ff; color: var(--navy); }
    .badge-returnee    { background: #e8f0fe; color: var(--blue); }
    .badge-senior_high { background: #dce8f5; color: var(--navy-dark); }
    .badge-default     { background: var(--sky-bg); color: var(--sky-muted); }

    .badge-active   { background: #d4e8fc; color: var(--navy);      border-color: var(--sky-pale); }
    .badge-inactive { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }

    .profile-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* ---------- DETAILS GRID ---------- */
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
        font-size: 15px;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px solid var(--sky-bg);
    }

    .detail-row:last-child { border-bottom: none; }

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

    /* ---------- FOLLOW-UP CARD ---------- */
    .followup-card {
        background: #e8f0fe;
        border-left: 4px solid var(--sky);
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
    }

    .followup-label {
        font-weight: 700;
        color: var(--navy);
        font-size: 14px;
        margin-bottom: 5px;
    }

    /* ---------- ENROLLMENT CARD ---------- */
    .enrollment-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.08);
        border-left: 4px solid var(--navy);
    }

    .enrollment-card h2 {
        color: var(--navy);
        font-size: 18px;
        margin-bottom: 15px;
    }

    .enrollment-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }

    .enrollment-item {
        background: var(--sky-bg-soft);
        padding: 12px 15px;
        border-radius: 6px;
        text-align: center;
    }

    .enrollment-item .label {
        font-size: 12px;
        color: var(--sky-muted);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 5px;
    }

    .enrollment-item .value {
        font-size: 16px;
        font-weight: 700;
        color: var(--navy);
    }

    /* ---------- SUBJECTS SECTION ---------- */
    .subjects-section {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.08);
    }

    .subjects-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .subjects-header h2 {
        color: var(--navy);
        font-size: 18px;
        margin: 0;
    }

    .table-responsive { overflow-x: auto; }

    .subjects-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .subjects-table th {
        background: var(--sky-bg-soft);
        padding: 10px;
        text-align: left;
        font-weight: 600;
        font-size: 11px;
        text-transform: uppercase;
        color: var(--sky-muted);
        border-bottom: 2px solid var(--sky-border);
        white-space: nowrap;
        letter-spacing: 0.3px;
    }

    .subjects-table td {
        padding: 10px;
        border-bottom: 1px solid var(--sky-bg);
        vertical-align: middle;
        color: var(--navy);
    }

    .subjects-table tbody tr { transition: background 0.2s ease; }
    .subjects-table tbody tr:hover { background: var(--sky-bg-soft); }

    .subjects-table .text-center { text-align: center; }
    .subjects-table .text-muted { color: var(--sky-muted); }

    .subjects-table tfoot td {
        background: var(--sky-bg);
        border-top: 2px solid var(--sky-border);
        padding: 12px 10px;
    }

    /* ---------- SUBJECT TYPE BADGES ---------- */
    .subject-type-badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    .subject-lecture { background: #d4e8fc; color: var(--navy); }
    .subject-lab     { background: #e8f0fe; color: var(--blue); }
    .subject-seminar { background: var(--sky-bg); color: var(--navy); }

    /* ---------- SCHEDULE BADGES ---------- */
    .schedule-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }

    .schedule-badge.has-schedule {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .schedule-badge.no-schedule {
        background: var(--sky-bg-soft);
        color: var(--sky-muted);
        border: 1px solid var(--sky-border);
    }

    /* ---------- FACULTY / ROOM ---------- */
    .faculty-name,
    .room-name {
        font-size: 12px;
        color: var(--navy);
        white-space: nowrap;
    }

    /* ---------- STATUS BADGES ---------- */
    .status-badge {
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border: 1px solid transparent;
    }

    .status-enrolled  { background: #d4e8fc; color: var(--navy);      border-color: var(--sky-pale); }
    .status-completed { background: #e8f4fd; color: var(--navy);      border-color: var(--sky-border); }
    .status-dropped   { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }

    /* ---------- GRADE BADGES ---------- */
    .grade-badge {
        display: inline-block;
        padding: 3px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    .grade-passed  { background: #d4e8fc; color: var(--navy);      border: 1px solid var(--sky-pale); }
    .grade-failed  { background: #dce8f5; color: var(--navy-dark); border: 1px solid var(--sky-pale); }
    .grade-pending { background: var(--sky-bg); color: var(--sky-muted); border: 1px solid var(--sky-border); }

    /* ---------- SUBJECT SUMMARY CARDS ---------- */
    .subject-summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 12px;
        margin-top: 20px;
    }

    .summary-card {
        background: var(--sky-bg-soft);
        border-radius: 6px;
        padding: 12px;
        text-align: center;
        border-top: 3px solid var(--navy);
    }

    .summary-card .summary-number {
        font-size: 22px;
        font-weight: 700;
        color: var(--navy);
    }

    .summary-card .summary-label {
        font-size: 11px;
        color: var(--sky-muted);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 4px;
    }

    /* ---------- REQUIREMENTS SECTION ---------- */
    .requirements-section {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.08);
    }

    .requirements-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 15px;
    }

    .requirements-header h2 {
        color: var(--navy);
        font-size: 18px;
        margin: 0;
    }

    .progress-text {
        font-size: 13px;
        color: var(--sky-muted);
        font-weight: 600;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: var(--sky-bg);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 20px;
    }

    .progress-fill {
        height: 100%;
        transition: width 0.3s ease;
    }

    .requirements-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 12px;
    }

    .requirement-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background: var(--sky-bg-soft);
        border-radius: 6px;
        border-left: 4px solid var(--sky-border);
        gap: 10px;
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

    .requirement-item .req-name {
        font-weight: 500;
        color: var(--navy);
        font-size: 13px;
        flex: 1;
    }

    .req-status {
        font-size: 12px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 12px;
        white-space: nowrap;
    }

    .req-status.submitted {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .req-status.pending {
        background: #e8f0fe;
        color: var(--blue);
        border: 1px solid var(--sky-border);
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

    .alert a {
        color: var(--blue);
        font-weight: 700;
        text-decoration: underline;
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

    .btn:hover { transform: translateY(-1px); }

    .btn-sm {
        padding: 4px 10px;
        font-size: 12px;
    }

    .btn-primary { background: var(--navy); color: white; }
    .btn-primary:hover { background: var(--blue); }

    .btn-success { background: var(--blue); color: white; }
    .btn-success:hover { background: var(--navy); }

    .btn-secondary { background: var(--sky-muted); color: white; }
    .btn-secondary:hover { background: var(--blue); }

    .btn-danger { background: var(--navy-dark); color: white; }
    .btn-danger:hover { background: var(--navy); }

    .btn-info { background: var(--sky-light); color: white; }
    .btn-info:hover { background: var(--sky); }

    .btn-warning { background: var(--sky); color: white; }
    .btn-warning:hover { background: var(--blue-mid); }

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

    .modal-content h2 {
        color: var(--navy);
        font-size: 18px;
        margin-bottom: 20px;
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

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 992px) {
        .details-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 768px) {
        .profile-header { flex-direction: column; }
        .profile-info .student-name { font-size: 22px; }
        .profile-actions { flex-direction: column; width: 100%; }
        .profile-actions .btn { width: 100%; text-align: center; }
        .details-grid { grid-template-columns: 1fr; }
        .subject-summary-cards { grid-template-columns: repeat(2, 1fr); }
        .requirements-grid { grid-template-columns: 1fr; }
        .subjects-table { font-size: 11px; }
        .subjects-table th,
        .subjects-table td { padding: 6px; }
    }

    @media (max-width: 480px) {
        .subject-summary-cards { grid-template-columns: 1fr; }
    }
</style>

<script>
function openArchiveModal() {
    document.getElementById('archiveModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeArchiveModal() {
    document.getElementById('archiveModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

window.addEventListener('click', function (e) {
    var m = document.getElementById('archiveModal');
    if (e.target === m) closeArchiveModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeArchiveModal();
});
</script>