<?php
// pages/student-edit.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • Personal info + academic info sa ISANG form (isang submit)
//   • year_graduated ay text input (sumusuporta sa "2024-2025")
//   • Age auto-calc via JS
//   • Section dropdown walang current_students/max_students
//   • Null-safe reads sa lahat ng $student[...] fields
//   • Delete action — hindi cascade-delete ang applicant

require_once 'classes/Student.php';
require_once 'classes/StudentController.php';
require_once 'classes/Course.php';
require_once 'classes/Section.php';
require_once 'classes/Application.php';
require_once 'classes/Enrollment.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$controller  = new StudentController();
$course      = new Course();
$section     = new Section();
$application = new Application();
$enrollment  = new Enrollment();

$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($studentId <= 0) {
    $_SESSION['message'] = '❌ Invalid student ID.';
    header('Location: ?page=students');
    exit;
}

$student = $controller->getStudentById($studentId);
if (!$student) {
    $_SESSION['message'] = '❌ Student not found.';
    header('Location: ?page=students');
    exit;
}

$enrollmentDetails = $enrollment->getEnrollmentByStudentId($studentId);
$subjects          = $enrollment->getStudentCurrentSemesterEnrollments($studentId);
if (!is_array($subjects)) $subjects = [];

$courses  = $course->findAll();
if (!is_array($courses)) $courses = [];

$sections = $section->getAllSectionsWithDetails();
if (!is_array($sections)) $sections = [];

$activeSections = $sections;

// ============================================================
// POST handling — isang action lang: 'update_all'
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        case 'update_all':
            try {
                // ------- Update personal info sa enr_applicants -------
                if (!empty($student['applicant_id'])) {
                    $personalFields = [
                        'first_name', 'middle_name', 'surname', 'suffix',
                        'sex', 'date_of_birth', 'place_of_birth', 'civil_status', 'religion',
                        'email', 'contact_number', 'facebook', 'messenger',
                        'address_barangay', 'address_city', 'address_province', 'address_complete',
                        'parent_full_name', 'parent_contact', 'parent_address',
                        'school_last_attended', 'year_graduated', 'how_hear',
                        'working_student', 'admission_type'
                    ];

                    $applicantData = [];
                    foreach ($personalFields as $field) {
                        if (array_key_exists($field, $_POST)) {
                            $value = trim((string) $_POST[$field]);
                            $applicantData[$field] = ($value === '') ? null : $value;
                        }
                    }

                    // Validate
                    if (empty($applicantData['first_name'])) {
                        throw new Exception('First name is required.');
                    }
                    if (empty($applicantData['surname'])) {
                        throw new Exception('Surname is required.');
                    }
                    if (empty($applicantData['email'])) {
                        throw new Exception('Email is required.');
                    }

                    $application->update((int) $student['applicant_id'], $applicantData);
                }

                // ------- Update academic info sa enr_students -------
                $updateData = [
                    'course_id'         => !empty($_POST['course_id'])  ? (int) $_POST['course_id']  : null,
                    'section_id'        => !empty($_POST['section_id']) ? (int) $_POST['section_id'] : null,
                    'year_level'        => (int) ($_POST['year_level'] ?? 1),
                    'enrollment_status' => $_POST['enrollment_status'] ?? 'enrolled'
                ];

                $studentModel = new Student();
                $result = $studentModel->update($studentId, $updateData);

                if ($result) {
                    $_SESSION['message'] = '✅ Student updated successfully!';
                    header('Location: ?page=student-edit&id=' . $studentId);
                    exit;
                }
                $error = '❌ Failed to update student academic info.';

            } catch (Exception $e) {
                $error = '❌ Error: ' . $e->getMessage();
            }
            break;

        case 'delete':
            if ($enrollmentDetails) {
                $error = '❌ Cannot delete student with active enrollment. Please drop the student first.';
                break;
            }

            $studentModel = new Student();
            if ($studentModel->delete($studentId)) {
                $_SESSION['message'] = '✅ Student deleted successfully!';
                header('Location: ?page=students');
                exit;
            }
            $error = '❌ Failed to delete student.';
            break;
    }
}

// ============================================================
// Post-processing
// ============================================================
$fullName = trim(
    ($student['first_name'] ?? '')
    . ' ' . ($student['middle_name'] ?? '')
    . ' ' . ($student['surname'] ?? '')
    . ' ' . ($student['suffix'] ?? '')
);

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$pageTitle = 'Edit Student - ' . $fullName;

$getAdmissionType  = $student['admission_type']  ?? 'freshmen';
$getWorkingStudent = $student['working_student'] ?? 'No';
$currentSex        = $student['sex']             ?? '';
$currentCivil      = $student['civil_status']    ?? '';
$currentHowHear    = $student['how_hear']        ?? '';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>✏️ Edit Student</h1>
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

            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="edit-form-container">

                <!-- HEADER -->
                <div class="student-info-header">
                    <div class="student-avatar">
                        <?php echo strtoupper(
                            substr($student['first_name'] ?? '?', 0, 1)
                            . substr($student['surname'] ?? '?', 0, 1)
                        ); ?>
                    </div>
                    <div class="student-header-info">
                        <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
                        <div class="number">🎓 Student #: <?php echo htmlspecialchars($student['student_number'] ?? ''); ?></div>
                        <div style="margin-top:5px;">
                            <?php $eStatus = $student['enrollment_status'] ?? 'unknown'; ?>
                            <span class="badge-lg badge-<?php echo htmlspecialchars($eStatus); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($eStatus))); ?>
                            </span>
                            <?php if ($enrollmentDetails): ?>
                                <span class="badge-lg" style="background:#d4edda;color:#155724;">✅ Enrolled</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-left:auto;">
                        <a href="?page=student-details&id=<?php echo $studentId; ?>" class="btn btn-info">← Back</a>
                        <a href="?page=students" class="btn btn-secondary">👥 All Students</a>
                    </div>
                </div>

                <!-- ============================================================
                     ONE FORM — personal + academic
                     ============================================================ -->
                <form method="POST" class="edit-form" id="editForm">
                    <input type="hidden" name="action" value="update_all">
                    <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">

                    <!-- PERSONAL INFORMATION -->
                    <div class="form-section">
                        <h3>👤 Personal Information</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name <span class="required">*</span></label>
                                <input type="text" name="first_name" id="first_name" class="form-control" required
                                       value="<?php echo htmlspecialchars($student['first_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" name="middle_name" id="middle_name" class="form-control"
                                       value="<?php echo htmlspecialchars($student['middle_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="surname">Surname <span class="required">*</span></label>
                                <input type="text" name="surname" id="surname" class="form-control" required
                                       value="<?php echo htmlspecialchars($student['surname'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="suffix">Suffix</label>
                                <input type="text" name="suffix" id="suffix" class="form-control"
                                       value="<?php echo htmlspecialchars($student['suffix'] ?? ''); ?>"
                                       placeholder="e.g., Jr., Sr., III">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="sex">Sex</label>
                                <select name="sex" id="sex" class="form-control">
                                    <option value="">Select Sex</option>
                                    <option value="Male"   <?php echo $currentSex === 'Male'   ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $currentSex === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other"  <?php echo $currentSex === 'Other'  ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="civil_status">Civil Status</label>
                                <select name="civil_status" id="civil_status" class="form-control">
                                    <option value="">Select Civil Status</option>
                                    <option value="Single"   <?php echo $currentCivil === 'Single'   ? 'selected' : ''; ?>>Single</option>
                                    <option value="Married"  <?php echo $currentCivil === 'Married'  ? 'selected' : ''; ?>>Married</option>
                                    <option value="Divorced" <?php echo $currentCivil === 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                    <option value="Widowed"  <?php echo $currentCivil === 'Widowed'  ? 'selected' : ''; ?>>Widowed</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="date_of_birth">Date of Birth</label>
                                <input type="date" name="date_of_birth" id="date_of_birth" class="form-control"
                                       value="<?php echo htmlspecialchars($student['date_of_birth'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="age">Age</label>
                                <input type="number" name="age" id="age" class="form-control"
                                       value="<?php echo htmlspecialchars($student['age'] ?? ''); ?>" readonly>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="place_of_birth">Place of Birth</label>
                                <input type="text" name="place_of_birth" id="place_of_birth" class="form-control"
                                       value="<?php echo htmlspecialchars($student['place_of_birth'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="religion">Religion</label>
                                <input type="text" name="religion" id="religion" class="form-control"
                                       value="<?php echo htmlspecialchars($student['religion'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="admission_type">Admission Type</label>
                                <select name="admission_type" id="admission_type" class="form-control">
                                    <option value="freshmen"    <?php echo $getAdmissionType === 'freshmen'    ? 'selected' : ''; ?>>Freshmen</option>
                                    <option value="transferee"  <?php echo $getAdmissionType === 'transferee'  ? 'selected' : ''; ?>>Transferee</option>
                                    <option value="returnee"    <?php echo $getAdmissionType === 'returnee'    ? 'selected' : ''; ?>>Returnee</option>
                                    <option value="senior_high" <?php echo $getAdmissionType === 'senior_high' ? 'selected' : ''; ?>>Senior High</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="working_student">Working Student</label>
                                <select name="working_student" id="working_student" class="form-control">
                                    <option value="No"  <?php echo $getWorkingStudent === 'No'  ? 'selected' : ''; ?>>No</option>
                                    <option value="Yes" <?php echo $getWorkingStudent === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- CONTACT & ADDRESS -->
                    <div class="form-section">
                        <h3>📞 Contact & Address</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email <span class="required">*</span></label>
                                <input type="email" name="email" id="email" class="form-control" required
                                       value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="contact_number">Contact Number</label>
                                <input type="text" name="contact_number" id="contact_number" class="form-control"
                                       placeholder="09xxxxxxxxx"
                                       value="<?php echo htmlspecialchars($student['contact_number'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="facebook">Facebook</label>
                                <input type="text" name="facebook" id="facebook" class="form-control"
                                       value="<?php echo htmlspecialchars($student['facebook'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="messenger">Messenger</label>
                                <input type="text" name="messenger" id="messenger" class="form-control"
                                       value="<?php echo htmlspecialchars($student['messenger'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="address_barangay">Barangay</label>
                                <input type="text" name="address_barangay" id="address_barangay" class="form-control"
                                       value="<?php echo htmlspecialchars($student['address_barangay'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="address_city">City/Municipality</label>
                                <input type="text" name="address_city" id="address_city" class="form-control"
                                       value="<?php echo htmlspecialchars($student['address_city'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="address_province">Province</label>
                                <input type="text" name="address_province" id="address_province" class="form-control"
                                       value="<?php echo htmlspecialchars($student['address_province'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="address_complete">Complete Address</label>
                                <textarea name="address_complete" id="address_complete" class="form-control" rows="2"><?php echo htmlspecialchars($student['address_complete'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- PARENT / GUARDIAN -->
                    <div class="form-section">
                        <h3>👨‍👩‍👧 Parent / Guardian</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="parent_full_name">Parent Full Name</label>
                                <input type="text" name="parent_full_name" id="parent_full_name" class="form-control"
                                       value="<?php echo htmlspecialchars($student['parent_full_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="parent_contact">Parent Contact</label>
                                <input type="text" name="parent_contact" id="parent_contact" class="form-control"
                                       value="<?php echo htmlspecialchars($student['parent_contact'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="parent_address">Parent Address</label>
                            <textarea name="parent_address" id="parent_address" class="form-control" rows="2"><?php echo htmlspecialchars($student['parent_address'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- EDUCATIONAL BACKGROUND -->
                    <div class="form-section">
                        <h3>🎓 Educational Background</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="school_last_attended">School Last Attended</label>
                                <input type="text" name="school_last_attended" id="school_last_attended" class="form-control"
                                       value="<?php echo htmlspecialchars($student['school_last_attended'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="year_graduated">Year Graduated</label>
                                <input type="text" name="year_graduated" id="year_graduated" class="form-control"
                                       placeholder="e.g., 2024-2025"
                                       value="<?php echo htmlspecialchars($student['year_graduated'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="how_hear">How did you hear about us?</label>
                            <select name="how_hear" id="how_hear" class="form-control">
                                <option value="">Select option</option>
                                <option value="social_media"  <?php echo $currentHowHear === 'social_media'  ? 'selected' : ''; ?>>Social Media</option>
                                <option value="friend"        <?php echo $currentHowHear === 'friend'        ? 'selected' : ''; ?>>Friend/Relative</option>
                                <option value="school"        <?php echo $currentHowHear === 'school'        ? 'selected' : ''; ?>>School</option>
                                <option value="advertisement" <?php echo $currentHowHear === 'advertisement' ? 'selected' : ''; ?>>Advertisement</option>
                                <option value="other"         <?php echo $currentHowHear === 'other'         ? 'selected' : ''; ?>>Other</option>
                                <option value="N/A"           <?php echo $currentHowHear === 'N/A'           ? 'selected' : ''; ?>>N/A</option>
                            </select>
                        </div>
                    </div>

                    <!-- ACADEMIC INFORMATION -->
                    <div class="form-section">
                        <h3>🎓 Academic Information</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Student Number</label>
                                <input type="text" class="form-control"
                                       value="<?php echo htmlspecialchars($student['student_number'] ?? ''); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" class="form-control"
                                       value="<?php echo htmlspecialchars($fullName); ?>" readonly>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="course_id">Course <span class="required">*</span></label>
                                <select name="course_id" id="course_id" class="form-control" required>
                                    <option value="">Select Course</option>
                                    <?php $currentCourseId = (int) ($student['course_id'] ?? 0); ?>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>"
                                            <?php echo $currentCourseId === (int) $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(($c['code'] ?? '') . ' - ' . ($c['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="section_id">Section</label>
                                <select name="section_id" id="section_id" class="form-control">
                                    <option value="">No Section</option>
                                    <?php $currentSectionId = (int) ($student['section_id'] ?? 0); ?>
                                    <?php foreach ($activeSections as $sec): ?>
                                        <option value="<?php echo (int) $sec['id']; ?>"
                                            <?php echo $currentSectionId === (int) $sec['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(($sec['section_code'] ?? '') . ' - ' . ($sec['course_code'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="year_level">Year Level <span class="required">*</span></label>
                                <select name="year_level" id="year_level" class="form-control" required>
                                    <?php $currentYear = (int) ($student['year_level'] ?? 1); ?>
                                    <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'] as $y => $lbl): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $currentYear === $y ? 'selected' : ''; ?>>
                                            <?php echo $lbl; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="enrollment_status">Enrollment Status <span class="required">*</span></label>
                                <select name="enrollment_status" id="enrollment_status" class="form-control" required>
                                    <?php $currentEnrStatus = $student['enrollment_status'] ?? 'enrolled'; ?>
                                    <?php foreach ([
                                        'enrolled'  => 'Enrolled',
                                        'on_leave'  => 'On Leave',
                                        'graduated' => 'Graduated',
                                        'dropped'   => 'Dropped'
                                    ] as $val => $lbl): ?>
                                        <option value="<?php echo $val; ?>"
                                            <?php echo $currentEnrStatus === $val ? 'selected' : ''; ?>>
                                            <?php echo $lbl; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="info-box">
                            <h4>📋 Additional Information</h4>
                            <div class="form-row">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>Enrolled At</label>
                                    <input type="text" class="form-control"
                                           value="<?php echo !empty($student['enrolled_at'])
                                                ? htmlspecialchars(date('M d, Y h:i A', strtotime($student['enrolled_at'])))
                                                : 'N/A'; ?>"
                                           readonly>
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>Follow-up Status</label>
                                    <input type="text" class="form-control"
                                           value="<?php echo htmlspecialchars(ucfirst($student['followup_status'] ?? 'N/A')); ?>"
                                           readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FORM ACTIONS -->
                    <div class="form-actions">
                        <a href="?page=student-details&id=<?php echo $studentId; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-success">💾 Update Student</button>
                    </div>
                </form>

                <!-- SCHEDULE SECTION -->
                <div class="schedule-section">
                    <h3>📅 Student Schedule (<?php echo count($subjects); ?> subjects)</h3>

                    <?php if (count($subjects) > 0): ?>
                        <div class="table-responsive">
                            <table class="table schedule-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Subject</th>
                                        <th>Schedule</th>
                                        <th>Faculty</th>
                                        <th>Room</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; ?>
                                    <?php foreach ($subjects as $subject):
                                        $scheduleDisplay = 'No schedule';
                                        if (!empty($subject['day_of_week']) && !empty($subject['start_time'])) {
                                            $scheduleDisplay = $subject['day_of_week']
                                                . ' ' . date('h:i A', strtotime($subject['start_time']));
                                            if (!empty($subject['end_time'])) {
                                                $scheduleDisplay .= ' - ' . date('h:i A', strtotime($subject['end_time']));
                                            }
                                        }

                                        $facultyDisplay = trim(
                                            ($subject['faculty_first'] ?? '') . ' ' . ($subject['faculty_last'] ?? '')
                                        );
                                        if ($facultyDisplay === '') $facultyDisplay = 'N/A';

                                        $roomDisplay = $subject['room_name'] ?? '';
                                        if ($roomDisplay !== '' && !empty($subject['room_code'])) {
                                            $roomDisplay .= ' (' . $subject['room_code'] . ')';
                                        }
                                        if ($roomDisplay === '') $roomDisplay = 'N/A';

                                        $enrollmentStatus = $subject['enrollment_status'] ?? 'enrolled';
                                    ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($subject['subject_code'] ?? 'N/A'); ?></strong>
                                            <br><small><?php echo htmlspecialchars($subject['subject_name'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($subject['schedule_id'])): ?>
                                                <span class="schedule-badge has-schedule">
                                                    📅 <?php echo htmlspecialchars($scheduleDisplay); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="schedule-badge no-schedule">
                                                    ⏳ <?php echo htmlspecialchars($scheduleDisplay); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($facultyDisplay); ?></td>
                                        <td><?php echo htmlspecialchars($roomDisplay); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo htmlspecialchars($enrollmentStatus); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($enrollmentStatus))); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            No subjects found for this student's current semester.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Danger Zone -->
                <div class="danger-zone">
                    <h4>⚠️ Danger Zone</h4>
                    <p>This action cannot be undone. Please be certain before proceeding.</p>
                    <?php if ($enrollmentDetails): ?>
                        <div class="alert alert-warning">
                            <strong>⚠️ This student has an active enrollment.</strong>
                            Please <a href="?page=student-details&id=<?php echo $studentId; ?>">drop the student</a>
                            first before deleting.
                        </div>
                    <?php else: ?>
                        <form method="POST" style="display:inline-block;"
                              onsubmit="return confirm('Are you sure you want to delete this student? This action cannot be undone!');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
                            <button type="submit" class="btn btn-danger">🗑️ Delete Student</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
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

    .dashboard-subtitle { color: var(--sky-muted); font-size: 14px; margin-bottom: 0; }

    .edit-form-container {
        background: white;
        border-radius: 8px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(26, 60, 110, 0.08);
    }

    .student-info-header {
        display: flex;
        align-items: center;
        gap: 20px;
        padding-bottom: 20px;
        margin-bottom: 25px;
        border-bottom: 2px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    .student-avatar {
        width: 80px; height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--navy), var(--blue));
        color: white;
        display: flex; align-items: center; justify-content: center;
        font-size: 32px; font-weight: 700;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(26, 60, 110, 0.25);
    }

    .student-header-info .name { font-size: 20px; font-weight: 700; color: var(--navy); }
    .student-header-info .number { color: var(--sky-muted); font-size: 14px; }

    .badge-lg {
        padding: 4px 12px; border-radius: 20px;
        font-size: 12px; font-weight: 700;
        display: inline-block; border: 1px solid transparent;
    }
    .badge-enrolled  { background: #d4e8fc; color: var(--navy);      border-color: var(--sky-pale); }
    .badge-on_leave  { background: #e8f0fe; color: var(--blue);      border-color: var(--sky-border); }
    .badge-graduated { background: #e8f4fd; color: var(--navy);      border-color: var(--sky-border); }
    .badge-dropped   { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }

    .form-section {
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--sky-bg);
    }
    .form-section:last-of-type { border-bottom: none; }
    .form-section h3 {
        color: var(--navy);
        font-size: 16px;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--sky-bg);
    }

    .form-group { margin-bottom: 20px; }
    .form-group label {
        display: block; margin-bottom: 5px;
        font-weight: 600; color: var(--navy); font-size: 14px;
    }
    .form-group label .required { color: var(--navy-dark); font-weight: 700; }
    .form-group .help-text { font-size: 12px; color: var(--sky-muted); margin-top: 4px; }

    .form-control {
        width: 100%; padding: 10px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px; color: var(--navy);
        background: #fff;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
        box-sizing: border-box; font-family: inherit;
    }
    .form-control:focus {
        border-color: var(--navy); outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }
    .form-control[readonly] {
        background: var(--sky-bg-soft);
        cursor: not-allowed; color: var(--sky-muted);
    }
    textarea.form-control { resize: vertical; min-height: 60px; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

    .form-actions {
        display: flex; justify-content: flex-end; gap: 10px;
        margin-top: 25px; padding-top: 20px;
        border-top: 2px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    .info-box {
        background: var(--sky-bg-soft);
        padding: 15px; border-radius: 6px; margin-top: 15px;
        border-left: 4px solid var(--navy);
    }
    .info-box h4 { color: var(--navy); margin-bottom: 10px; font-size: 14px; }

    .schedule-section {
        margin-top: 25px; padding-top: 25px;
        border-top: 2px solid var(--sky-bg);
    }
    .schedule-section h3 { color: var(--navy); margin-bottom: 15px; font-size: 18px; }

    .table-responsive { overflow-x: auto; }
    .schedule-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .schedule-table th {
        background: var(--sky-bg-soft); padding: 10px 12px;
        text-align: left; font-weight: 600; font-size: 12px;
        text-transform: uppercase; color: var(--sky-muted);
        border-bottom: 2px solid var(--sky-border);
        white-space: nowrap; letter-spacing: 0.3px;
    }
    .schedule-table td {
        padding: 10px 12px; border-bottom: 1px solid var(--sky-bg);
        vertical-align: middle; color: var(--navy);
    }
    .schedule-table tbody tr:hover { background: var(--sky-bg-soft); }
    .schedule-table td small { color: var(--sky-muted); font-size: 12px; }

    .schedule-badge {
        display: inline-block; padding: 3px 10px;
        border-radius: 4px; font-size: 12px; font-weight: 600;
    }
    .schedule-badge.has-schedule {
        background: #d4e8fc; color: var(--navy); border: 1px solid var(--sky-pale);
    }
    .schedule-badge.no-schedule {
        background: var(--sky-bg-soft); color: var(--sky-muted); border: 1px solid var(--sky-border);
    }

    .status-badge {
        padding: 4px 12px; border-radius: 12px;
        font-size: 12px; font-weight: 700;
        display: inline-block; border: 1px solid transparent;
        text-transform: uppercase; letter-spacing: 0.3px;
    }
    .status-enrolled  { background: #d4e8fc; color: var(--navy);      border-color: var(--sky-pale); }
    .status-dropped   { background: #dce8f5; color: var(--navy-dark); border-color: var(--sky-pale); }
    .status-completed { background: #e8f4fd; color: var(--navy);      border-color: var(--sky-border); }

    .danger-zone {
        margin-top: 20px; padding: 20px;
        background: #e8f0fe; border-radius: 8px;
        border: 1px solid var(--sky-pale);
    }
    .danger-zone h4 { color: var(--navy-dark); margin-bottom: 10px; }
    .danger-zone p { color: var(--sky-muted); font-size: 14px; }

    .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; }
    .alert-success { background: #d4e8fc; color: var(--navy);      border: 1px solid var(--sky-pale); }
    .alert-danger  { background: #dce8f5; color: var(--navy-dark); border: 1px solid var(--sky-pale); }
    .alert-info    { background: var(--sky-bg); color: var(--navy); border: 1px solid var(--sky-border); }
    .alert-warning { background: #e8f0fe; color: var(--navy);      border: 1px solid var(--sky-light); }
    .alert a { color: var(--blue); font-weight: 700; text-decoration: underline; }

    .btn {
        padding: 8px 20px; border: none; border-radius: 4px;
        cursor: pointer; font-size: 14px;
        transition: all 0.2s ease; text-decoration: none;
        display: inline-block; font-weight: 500; font-family: inherit;
        line-height: 1.4;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn-primary   { background: var(--navy);      color: white; }
    .btn-primary:hover { background: var(--blue); }
    .btn-success   { background: var(--blue);      color: white; }
    .btn-success:hover { background: var(--navy); }
    .btn-secondary { background: var(--sky-muted); color: white; }
    .btn-secondary:hover { background: var(--blue); }
    .btn-danger    { background: var(--navy-dark); color: white; }
    .btn-danger:hover { background: var(--navy); }
    .btn-info      { background: var(--sky-light); color: white; }
    .btn-info:hover { background: var(--sky); }
    .btn-warning   { background: var(--sky);       color: white; }
    .btn-warning:hover { background: var(--blue-mid); }

    @media (max-width: 768px) {
        .form-row { grid-template-columns: 1fr; }
        .student-info-header { flex-direction: column; text-align: center; }
        .form-actions { flex-direction: column; }
        .form-actions .btn { width: 100%; text-align: center; }
        .schedule-table { font-size: 12px; }
        .schedule-table th, .schedule-table td { padding: 6px 8px; }
    }
</style>

<script>
// Age auto-calc
function calculateAge() {
    var birthInput = document.getElementById('date_of_birth');
    var ageInput   = document.getElementById('age');
    if (!birthInput || !ageInput || !birthInput.value) return;

    var today = new Date();
    var birth = new Date(birthInput.value);
    if (isNaN(birth.getTime())) { ageInput.value = ''; return; }

    var age = today.getFullYear() - birth.getFullYear();
    var m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    ageInput.value = age >= 0 ? age : '';
}

document.getElementById('date_of_birth')?.addEventListener('change', calculateAge);
document.getElementById('date_of_birth')?.addEventListener('input',  calculateAge);
document.addEventListener('DOMContentLoaded', calculateAge);
</script>