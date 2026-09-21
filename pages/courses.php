<?php
// pages/courses.php - FULLY FIXED for `kms` schema
//
// FIXES:
//   • Null-safe reads on $stats[...], $c[...], $pc[...]
//   • is_array() guards on $courses, $popularCourses
//   • Corrected delete-confirmation text (course with sections can't be deleted)
//   • Replaced window.onclick with addEventListener
//   • Consistent (int) casts on IDs

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'classes/Course.php';
require_once 'classes/CourseController.php';
require_once 'classes/Section.php';

$courseController = new CourseController();
$section          = new Section();

// ============================================================
// AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    $courseController->handleAjaxRequest();
    exit;
}

// ============================================================
// POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $courseController->handlePostRequest();
    // handlePostRequest() redirects and exits
}

// ============================================================
// Data
// ============================================================
$courses = $courseController->getAllCoursesWithStats();
if (!is_array($courses)) $courses = [];

$stats = $courseController->getCourseStats();
if (!is_array($stats)) $stats = [];

$popularCourses = $courseController->getPopularCourses(5);
if (!is_array($popularCourses)) $popularCourses = [];

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$pageTitle = 'Courses Management';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">
        <div class="module-header">
            <h1>📚 Courses Management</h1>
            <p class="dashboard-subtitle">Manage academic courses</p>
        </div>

        <div class="module-content">

            <!-- MESSAGES -->
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo strpos($message, '✅') !== false ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- STATISTICS -->
            <div class="stats-container">
                <div class="stat-card stat-total">
                    <span class="stat-number"><?php echo (int) ($stats['total_courses']  ?? count($courses)); ?></span>
                    <span class="stat-label">📚 Total Courses</span>
                </div>
                <div class="stat-card stat-sections">
                    <span class="stat-number"><?php echo (int) ($stats['total_sections'] ?? 0); ?></span>
                    <span class="stat-label">📋 Total Sections</span>
                </div>
                <div class="stat-card stat-students">
                    <span class="stat-number"><?php echo (int) ($stats['total_students'] ?? 0); ?></span>
                    <span class="stat-label">👨‍🎓 Total Students</span>
                </div>
            </div>

            <!-- POPULAR COURSES -->
            <?php if (!empty($popularCourses)): ?>
            <div class="popular-courses">
                <h3>🔥 Popular Courses</h3>
                <div class="popular-list">
                    <?php foreach ($popularCourses as $pc): ?>
                    <div class="popular-item">
                        <div class="course-name"><?php echo htmlspecialchars($pc['code'] ?? ''); ?></div>
                        <div class="course-stats">
                            <?php echo htmlspecialchars($pc['name'] ?? ''); ?><br>
                            <span><?php echo (int) ($pc['total_students'] ?? 0); ?></span> students enrolled
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- COURSES TABLE -->
            <div class="courses-table-container">
                <div class="table-header">
                    <span class="title">All Courses</span>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <span class="badge-count"><?php echo count($courses); ?></span>
                        <button class="btn btn-primary btn-sm" onclick="showAddCourse()">+ Add Course</button>
                    </div>
                </div>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Course Name</th>
                            <th>Years</th>
                            <th>Sections</th>
                            <th>Students</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($courses) > 0): ?>
                            <?php foreach ($courses as $c):
                                $courseId     = (int) ($c['id'] ?? 0);
                                $totalSections = (int) ($c['total_sections'] ?? 0);
                                $totalStudents = (int) ($c['total_students'] ?? 0);
                                $hasSections   = $totalSections > 0;
                                $canDelete     = $totalStudents === 0 && $totalSections === 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['code'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($c['years'] ?? ''); ?></td>
                                <td>
                                    <span class="badge badge-info"><?php echo $totalSections; ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-success"><?php echo $totalStudents; ?></span>
                                </td>
                                <td>
                                    <?php if ($hasSections): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">No Sections</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="editCourse(<?php echo $courseId; ?>)"
                                                class="btn btn-sm btn-warning">Edit</button>
                                        <a href="?page=sections&course_id=<?php echo $courseId; ?>"
                                           class="btn btn-sm btn-info">Sections</a>
                                        <form method="POST" style="display:inline-block;"
                                              onsubmit="return confirm('Delete this course? This action cannot be undone.');">
                                            <input type="hidden" name="id" value="<?php echo $courseId; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="redirect" value="?page=courses">
                                            <button type="submit"
                                                    class="btn btn-sm btn-danger"
                                                    <?php echo $canDelete ? '' : 'disabled'; ?>
                                                    title="<?php echo $canDelete
                                                        ? 'Delete this course'
                                                        : 'Cannot delete: course has sections or enrolled students'; ?>">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">
                                    No courses found.
                                    <a href="#" onclick="showAddCourse(); return false;">Create one now</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ADD/EDIT COURSE MODAL -->
<div id="courseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="courseModalTitle">Add New Course</h2>
            <span class="close" onclick="hideCourseModal()">&times;</span>
        </div>
        <form method="POST" class="course-form" id="courseForm">
            <input type="hidden" name="id" id="courseId">
            <input type="hidden" name="action" id="courseAction" value="add">
            <input type="hidden" name="redirect" value="?page=courses">

            <div class="form-group">
                <label>Course Code <span class="required">*</span></label>
                <input type="text" name="code" id="courseCode" required
                       placeholder="e.g., BSIT" style="text-transform:uppercase;">
                <small>Use uppercase letters only (e.g., BSIT, BSCS)</small>
            </div>
            <div class="form-group">
                <label>Course Name <span class="required">*</span></label>
                <input type="text" name="name" id="courseName" required
                       placeholder="e.g., Bachelor of Science in Information Technology">
            </div>
            <div class="form-group">
                <label>Duration (Years) <span class="required">*</span></label>
                <input type="number" name="years" id="courseDuration"
                       value="4" min="2" max="6" required>
                <small>Number of years to complete the course (2-6 years)</small>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="hideCourseModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="courseSubmitBtn">Save Course</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<style>
    /* ============================================================
       COURSES MANAGEMENT — Blue & Sky Blue Theme
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
    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .stat-card {
        background: white;
        padding: 20px;
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

    .stat-card .stat-number {
        font-size: 28px;
        font-weight: 700;
        display: block;
        color: var(--navy);
    }

    .stat-card .stat-label {
        font-size: 13px;
        color: var(--sky-muted);
        margin-top: 5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Stat accent variations — all blue tones */
    .stat-total    { border-top-color: var(--navy); }
    .stat-total .stat-number    { color: var(--navy); }

    .stat-sections { border-top-color: var(--blue); }
    .stat-sections .stat-number { color: var(--blue); }

    .stat-students { border-top-color: var(--sky); }
    .stat-students .stat-number { color: var(--sky); }

    /* ---------- POPULAR COURSES ---------- */
    .popular-courses {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(26, 60, 110, 0.08);
        margin-bottom: 20px;
    }

    .popular-courses h3 {
        color: var(--navy);
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
        font-size: 16px;
    }

    .popular-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }

    .popular-item {
        background: var(--sky-bg-soft);
        padding: 15px;
        border-radius: 6px;
        border-left: 4px solid var(--navy);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .popular-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(26, 60, 110, 0.12);
    }

    .popular-item .course-name {
        font-weight: 700;
        color: var(--navy);
        font-size: 15px;
    }

    .popular-item .course-stats {
        font-size: 13px;
        color: var(--sky-muted);
        margin-top: 5px;
    }

    .popular-item .course-stats span {
        font-weight: 700;
        color: var(--blue);
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

    /* ---------- TABLE CONTAINER ---------- */
    .courses-table-container {
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
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
        flex-wrap: wrap;
        gap: 10px;
    }

    .table-header .title {
        font-weight: 700;
        font-size: 16px;
        color: var(--navy);
    }

    .badge-count {
        background: var(--navy);
        color: white;
        padding: 3px 14px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 700;
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
        white-space: nowrap;
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

    .table .text-center {
        text-align: center;
        padding: 30px 20px;
        color: var(--sky-muted);
    }

    .table .text-center a {
        color: var(--blue);
        font-weight: 600;
        text-decoration: underline;
        cursor: pointer;
    }

    .table .text-center a:hover {
        color: var(--navy);
    }

    /* ---------- BADGES ---------- */
    .table .badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    .badge-success {
        background: #d4e8fc;
        color: var(--navy);
        border: 1px solid var(--sky-pale);
    }

    .badge-warning {
        background: #e8f0fe;
        color: var(--blue);
        border: 1px solid var(--sky-border);
    }

    .badge-info {
        background: var(--sky-bg);
        color: var(--navy);
        border: 1px solid var(--sky-border);
    }

    /* ---------- ACTION BUTTONS ---------- */
    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
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
        border-radius: 4px;
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

    .btn-danger {
        background: var(--navy-dark);
        color: white;
    }
    .btn-danger:hover { background: var(--navy); }
    .btn-danger:disabled {
        background: var(--sky-muted);
        opacity: 0.55;
        cursor: not-allowed;
        transform: none;
    }

    .btn-warning {
        background: var(--sky);
        color: white;
    }
    .btn-warning:hover { background: var(--blue-mid); }

    .btn-secondary {
        background: var(--sky-muted);
        color: white;
    }
    .btn-secondary:hover { background: var(--blue); }

    .btn-info {
        background: var(--sky-light);
        color: white;
    }
    .btn-info:hover { background: var(--sky); }

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
        max-width: 550px;
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
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--sky-bg);
    }

    .modal-header h2 {
        color: var(--navy);
        margin: 0;
        font-size: 20px;
    }

    .close {
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        color: var(--sky-muted);
        transition: color 0.2s ease;
        line-height: 1;
    }

    .close:hover {
        color: var(--navy);
    }

    /* ---------- COURSE FORM ---------- */
    .course-form .form-group {
        margin-bottom: 15px;
    }

    .course-form label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: var(--navy);
        font-size: 13px;
    }

    .course-form label .required {
        color: var(--navy-dark);
        font-weight: 700;
    }

    .course-form input,
    .course-form textarea {
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

    .course-form input::placeholder {
        color: var(--sky-muted);
        opacity: 0.7;
    }

    .course-form input:focus,
    .course-form textarea:focus {
        border-color: var(--navy);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
    }

    .course-form small {
        color: var(--sky-muted);
        font-size: 12px;
        display: block;
        margin-top: 4px;
    }

    /* ---------- FORM ACTIONS ---------- */
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid var(--sky-bg);
        flex-wrap: wrap;
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 768px) {
        .modal-content {
            margin: 10% auto;
            padding: 20px;
            width: 95%;
        }

        .action-buttons {
            flex-direction: column;
        }

        .action-buttons .btn {
            width: 100%;
            text-align: center;
        }

        .popular-list {
            grid-template-columns: 1fr;
        }

        .stats-container {
            grid-template-columns: repeat(2, 1fr);
        }

        .table-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .form-actions {
            flex-direction: column;
        }

        .form-actions .btn {
            width: 100%;
            text-align: center;
        }
    }

    @media (max-width: 480px) {
        .stats-container {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
// ===== MODAL CONTROLS =====
function showAddCourse() {
    document.getElementById('courseModalTitle').textContent = 'Add New Course';
    document.getElementById('courseAction').value   = 'add';
    document.getElementById('courseId').value       = '';
    document.getElementById('courseCode').value     = '';
    document.getElementById('courseName').value     = '';
    document.getElementById('courseDuration').value = '4';
    document.getElementById('courseSubmitBtn').textContent = 'Add Course';
    document.getElementById('courseModal').style.display   = 'block';
    document.body.style.overflow = 'hidden';
    setTimeout(function () { document.getElementById('courseCode').focus(); }, 100);
}

function hideCourseModal() {
    document.getElementById('courseModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// ===== EDIT COURSE =====
function editCourse(courseId) {
    document.getElementById('courseModalTitle').textContent = 'Loading...';
    document.getElementById('courseModal').style.display    = 'block';
    document.body.style.overflow = 'hidden';

    fetch('?page=courses&ajax=get_course&id=' + encodeURIComponent(courseId))
        .then(function (response) {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(function (data) {
            if (data.success && data.data) {
                var course = data.data;
                document.getElementById('courseModalTitle').textContent = 'Edit Course';
                document.getElementById('courseAction').value   = 'update';
                document.getElementById('courseId').value       = course.id ?? '';
                document.getElementById('courseCode').value     = course.code || '';
                document.getElementById('courseName').value     = course.name || '';
                document.getElementById('courseDuration').value = course.years || '4';
                document.getElementById('courseSubmitBtn').textContent = 'Update Course';
            } else {
                alert(data.message || 'Failed to load course data.');
                hideCourseModal();
            }
        })
        .catch(function (error) {
            console.error('Error:', error);
            alert('Error loading course data. Please try again.');
            hideCourseModal();
        });
}

// ===== CLOSE MODAL =====
window.addEventListener('click', function (event) {
    var modal = document.getElementById('courseModal');
    if (event.target === modal) hideCourseModal();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') hideCourseModal();
});

// ===== AUTO-UPPERCASE =====
document.addEventListener('DOMContentLoaded', function () {
    var courseCodeInput = document.getElementById('courseCode');
    if (courseCodeInput) {
        courseCodeInput.addEventListener('input', function () {
            this.value = this.value.toUpperCase();
        });
    }
});

// ===== FORM VALIDATION =====
var form = document.getElementById('courseForm');
if (form) {
    form.addEventListener('submit', function (e) {
        var code     = document.getElementById('courseCode').value.trim();
        var name     = document.getElementById('courseName').value.trim();
        var duration = document.getElementById('courseDuration').value;

        if (!code) {
            e.preventDefault();
            alert('⚠️ Please enter a course code.');
            document.getElementById('courseCode').focus();
            return false;
        }

        if (!name) {
            e.preventDefault();
            alert('⚠️ Please enter a course name.');
            document.getElementById('courseName').focus();
            return false;
        }

        if (!duration || duration < 2 || duration > 6) {
            e.preventDefault();
            alert('⚠️ Please enter a valid duration (2-6 years).');
            document.getElementById('courseDuration').focus();
            return false;
        }

        if (document.getElementById('courseAction').value === 'add') {
            var existingCodes = <?php echo json_encode(array_column($courses, 'code'), JSON_UNESCAPED_UNICODE); ?>;
            if (Array.isArray(existingCodes) && existingCodes.includes(code)) {
                e.preventDefault();
                alert('⚠️ Course code "' + code + '" already exists. Please use a different code.');
                document.getElementById('courseCode').focus();
                return false;
            }
        }
    });
}
</script>