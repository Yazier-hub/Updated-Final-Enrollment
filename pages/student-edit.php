<?php
// pages/student-edit.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • Section dropdown no longer reads $sec['current_students']/['max_students']
//     (those columns don't exist in kms)
//   • Delete no longer cascade-deletes the applicant (keeps audit history)
//   • Removed duplicate updateStudentSection() call
//   • Null-safe reads on all $student[...] fields
//   • Escaped $student['enrollment_status'] in class attribute

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

$courses = $course->findAll();
if (!is_array($courses)) $courses = [];

$sections = $section->getAllSectionsWithDetails();
if (!is_array($sections)) $sections = [];

$activeSections = $sections;

// ============================================================
// POST handling
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        case 'update':
            try {
                $updateData = [
                    'course_id'         => !empty($_POST['course_id'])   ? (int) $_POST['course_id']   : null,
                    'section_id'        => !empty($_POST['section_id'])  ? (int) $_POST['section_id']  : null,
                    'year_level'        => (int) ($_POST['year_level'] ?? 1),
                    'enrollment_status' => $_POST['enrollment_status'] ?? 'enrolled'
                ];

                // Update applicant's admission_type / working_student (if changed)
                $applicantData = [];
                if (isset($_POST['admission_type'])) {
                    $applicantData['admission_type'] = $_POST['admission_type'];
                }
                if (isset($_POST['working_student'])) {
                    $applicantData['working_student'] = $_POST['working_student'];
                }
                if (!empty($applicantData) && !empty($student['applicant_id'])) {
                    $application->update((int) $student['applicant_id'], $applicantData);
                }

                // Update student — Model::update() now persists section_id even when null
                $studentModel = new Student();
                $result = $studentModel->update($studentId, $updateData);

                if ($result) {
                    $_SESSION['message'] = '✅ Student updated successfully!';
                    header('Location: ?page=student-details&id=' . $studentId);
                    exit;
                }
                $error = '❌ Failed to update student.';
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
                // NOTE: We intentionally DO NOT delete the applicant record.
                // It serves as audit history and is linked from the student.
                $_SESSION['message'] = '✅ Student deleted successfully!';
                header('Location: ?page=students');
                exit;
            }
            $error = '❌ Failed to delete student.';
            break;

        case 'update_schedule':
            if (!empty($_POST['subject_enrollment_id'])) {
                $subjectEnrollmentId = (int) $_POST['subject_enrollment_id'];
                $scheduleId          = !empty($_POST['schedule_id']) ? (int) $_POST['schedule_id'] : null;

                $_SESSION['message'] = $enrollment->updateSubjectSchedule($subjectEnrollmentId, $scheduleId)
                    ? '✅ Schedule updated successfully!'
                    : '❌ Failed to update schedule.';

                header('Location: ?page=student-edit&id=' . $studentId);
                exit;
            }
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

$applicantData = null;
if (!empty($student['applicant_id'])) {
    $applicantData = $application->getApplicationById((int) $student['applicant_id']);
}

$pageTitle = 'Edit Student - ' . $fullName;

// Helper: get display value from student or applicant
$getAdmissionType   = $applicantData['admission_type']   ?? 'freshmen';
$getWorkingStudent  = $applicantData['working_student']  ?? 'No';
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

            <!-- EDIT FORM -->
            <div class="edit-form-container">
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

                <form method="POST" class="edit-form">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">

                    <!-- Read-only Information -->
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
                            <label>Email</label>
                            <input type="text" class="form-control"
                                   value="<?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" class="form-control"
                                   value="<?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?>" readonly>
                        </div>
                    </div>

                    <!-- Editable -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admission_type">Admission Type</label>
                            <select name="admission_type" id="admission_type" class="form-control">
                                <?php foreach ([
                                    'freshmen'    => 'Freshmen',
                                    'transferee'  => 'Transferee',
                                    'returnee'    => 'Returnee',
                                    'senior_high' => 'Senior High'
                                ] as $val => $lbl): ?>
                                    <option value="<?php echo $val; ?>"
                                        <?php echo $getAdmissionType === $val ? 'selected' : ''; ?>>
                                        <?php echo $lbl; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text">Student's admission type (affects requirements)</div>
                        </div>

                        <div class="form-group">
                            <label for="working_student">Working Student</label>
                            <select name="working_student" id="working_student" class="form-control">
                                <option value="No"  <?php echo $getWorkingStudent === 'No'  ? 'selected' : ''; ?>>No</option>
                                <option value="Yes" <?php echo $getWorkingStudent === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                            </select>
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
                                        <?php echo htmlspecialchars(
                                            ($sec['section_code'] ?? '') . ' - ' . ($sec['course_code'] ?? '')
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text">Select a section to assign the student to a specific class</div>
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

                    <!-- Additional Info -->
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
                        <?php if (!empty($student['followup_date'])): ?>
                            <div class="form-row" style="margin-top:15px;">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>Follow-up Date</label>
                                    <input type="text" class="form-control"
                                           value="<?php echo htmlspecialchars(date('M d, Y', strtotime($student['followup_date']))); ?>"
                                           readonly>
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>Follow-up Notes</label>
                                    <input type="text" class="form-control"
                                           value="<?php echo htmlspecialchars($student['followup_notes'] ?? 'N/A'); ?>"
                                           readonly>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

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
    /* ============================================================
       EDIT STUDENT — Blue & Sky Blue Theme
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

    /* ---------- HEADER ---------- */
    .dashboard-subtitle {
        color: var(--sky-muted);
        font-size: 14px;
        margin-bottom: 0;
    }

    /* ---------- FORM CONTAINER ---------- */
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

    .student-header-info .name {
        font-size: 20px;
        font-weight: 700;
        color: var(--navy);
    }

    .student-header-info .number {
        color: var(--sky-muted);
        font-size: 14px;
    }

    /* ---------- BADGE-LG (Enrollment Status) ---------- */
    .badge-lg {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        display: inline-block;
        border: 1px solid transparent;
    }

    /* Differentiated by blue shade intensity */
    .badge-enrolled {
        background: #d4e8fc;
        color: var(--navy);
        border-color: var(--sky-pale);
    }
    .badge-on_leave {
        background: #e8f0fe;
        color: var(--blue);
        border-color: var(--sky-border);
    }
    .badge-graduated {
        background: #e8f4fd;
        color: var(--navy);
        border-color: var(--sky-border);
    }
    .badge-dropped {
        background: #dce8f5;
        color: var(--navy-dark);
        border-color: var(--sky-pale);
    }

    /* ---------- FORM ELEMENTS ---------- */
    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: var(--navy);
        font-size: 14px;
    }

    .form-group label .required {
        color: var(--navy-dark);
        font-weight: 700;
    }

    .form-group .help-text {
        font-size: 12px;
        color: var(--sky-muted);
        margin-top: 4px;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid var(--sky-border);
        border-radius: 4px;
        font-size: 14px;
        color: var(--navy);
        background: #fff;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
        box-sizing: border-box;
    }

    .form-control:focus {
        border-color: var(--navy);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    .form-control[readonly] {
        background: var(--sky-bg-soft);
        cursor: not-allowed;
        color: var(--sky-muted);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
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

    /* ---------- INFO BOX ---------- */
    .info-box {
        background: var(--sky-bg-soft);
        padding: 15px;
        border-radius: 6px;
        margin-top: 15px;
        border-left: 4px solid var(--navy);
    }

    .info-box h4 {
        color: var(--navy);
        margin-bottom: 10px;
        font-size: 14px;
    }

    /* ---------- SCHEDULE SECTION ---------- */
    .schedule-section {
        margin-top: 25px;
        padding-top: 25px;
        border-top: 2px solid var(--sky-bg);
    }

    .schedule-section h3 {
        color: var(--navy);
        margin-bottom: 15px;
        font-size: 18px;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .schedule-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .schedule-table th {
        background: var(--sky-bg-soft);
        padding: 10px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        color: var(--sky-muted);
        border-bottom: 2px solid var(--sky-border);
        white-space: nowrap;
        letter-spacing: 0.3px;
    }

    .schedule-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--sky-bg);
        vertical-align: middle;
        color: var(--navy);
    }

    .schedule-table tbody tr {
        transition: background 0.2s ease;
    }

    .schedule-table tbody tr:hover {
        background: var(--sky-bg-soft);
    }

    .schedule-table td small {
        color: var(--sky-muted);
        font-size: 12px;
    }

    /* ---------- SCHEDULE BADGES ---------- */
    .schedule-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
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

    /* ---------- STATUS BADGES (Subject Enrollment) ---------- */
    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        display: inline-block;
        border: 1px solid transparent;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .status-enrolled {
        background: #d4e8fc;
        color: var(--navy);
        border-color: var(--sky-pale);
    }
    .status-dropped {
        background: #dce8f5;
        color: var(--navy-dark);
        border-color: var(--sky-pale);
    }
    .status-completed {
        background: #e8f4fd;
        color: var(--navy);
        border-color: var(--sky-border);
    }

    /* ---------- DANGER ZONE ---------- */
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

    .btn:hover {
        transform: translateY(-1px);
    }

    .btn-sm {
        padding: 4px 10px;
        font-size: 12px;
    }

    .btn-primary {
        background: var(--navy);
        color: white;
    }
    .btn-primary:hover { background: var(--blue); }

    .btn-success {
        background: var(--blue);
        color: white;
    }
    .btn-success:hover { background: var(--navy); }

    .btn-secondary {
        background: var(--sky-muted);
        color: white;
    }
    .btn-secondary:hover { background: var(--blue); }

    .btn-danger {
        background: var(--navy-dark);
        color: white;
    }
    .btn-danger:hover { background: var(--navy); }

    .btn-info {
        background: var(--sky-light);
        color: white;
    }
    .btn-info:hover { background: var(--sky); }

    .btn-warning {
        background: var(--sky);
        color: white;
    }
    .btn-warning:hover { background: var(--blue-mid); }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
        .form-row { grid-template-columns: 1fr; }
        .student-info-header { flex-direction: column; text-align: center; }
        .form-actions { flex-direction: column; }
        .form-actions .btn { width: 100%; text-align: center; }
        .schedule-table { font-size: 12px; }
        .schedule-table th,
        .schedule-table td { padding: 6px 8px; }
    }
</style>