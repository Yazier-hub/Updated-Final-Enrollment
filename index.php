<?php
// index.php - Main Entry Point

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base path
define('BASE_PATH', __DIR__);

// Auto-loader
spl_autoload_register(function ($class_name) {
    $file = BASE_PATH . '/classes/' . $class_name . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Get page parameter
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard-overview';

// Allowed pages
$allowed_pages = [
    'dashboard-overview',
    'applications',
    'application-details',
    'application-edit',
    'students',
    'student-details',
    'student-edit',
    'student-subjects',
    'enrollments',
    'courses',
    'sections',
    'section-details',
    'requirements',
    'semesters',            // ✅ ADDED
    'school-years',         // ✅ ADDED
    'archived-students',
    'contact-messages',             // ✅ ADDED
    'login',
    'logout'
];

// Validate page
if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard-overview';
}

// ============================================
// SESSION FIX - Bypass login for testing
// ============================================
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['user_name'] = 'Admin User';
    $_SESSION['user_role'] = 'admin';
}

// If login page, redirect to dashboard
if ($page === 'login') {
    header('Location: ?page=dashboard-overview');
    exit;
}

// If logout page, destroy session
if ($page === 'logout') {
    session_destroy();
    header('Location: ?page=login');
    exit;
}

// ---------------------------------------------------------
// PAGE CONTROLLER
// ---------------------------------------------------------

class PageController
{
    private $currentPage;

    public function __construct($page)
    {
        $this->currentPage = $page;
    }

    public function renderNav()
    {
        $navItems = [
            'dashboard-overview' => [
                'label' => 'Dashboard',
                'icon' => 'fa-solid fa-chart-pie'
            ],
            'applications' => [
                'label' => 'Applications',
                'icon' => 'fa-solid fa-file-pen'
            ],
            'enrollments' => [
                'label' => 'Enrollments',
                'icon' => 'fa-solid fa-user-graduate'
            ],
            'students' => [
                'label' => 'Students',
                'icon' => 'fa-solid fa-users'
            ],
            'courses' => [
                'label' => 'Courses',
                'icon' => 'fa-solid fa-book'
            ],
            'sections' => [
                'label' => 'Sections',
                'icon' => 'fa-solid fa-layer-group'
            ],
            'requirements' => [
                'label' => 'Requirements',
                'icon' => 'fa-solid fa-list-check'
            ],
            'semesters' => [                                // ✅ ADDED
                'label' => 'Semester Management',
                'icon' => 'fa-solid fa-calendar-days'
            ],
            'archived-students' => [                        // ✅ ADDED
                'label' => 'Archived Students',
                'icon' => 'fa-solid fa-box-archive'
            ],
            'contact-messages' => [                         // ✅ ADDED
                'label' => 'Contact Messages',
                'icon' => 'fa-solid fa-envelope'
            ]
        ];

        foreach ($navItems as $pageKey => $item) {
            $isActive = ($this->currentPage === $pageKey);
            $activeClass = $isActive ? 'active-menu-link' : 'menu-link';

            echo '
            <li>
                <a href="?page=' . $pageKey . '" 
                   class="' . $activeClass . '" 
                   data-page="' . $pageKey . '">
                    <i class="' . $item['icon'] . '"></i>
                    <span>' . $item['label'] . '</span>
                    ' . ($isActive ? '<span class="active-indicator"></span>' : '') . '
                </a>
            </li>';
        }
    }

    public function getCurrentPage()
    {
        return $this->currentPage;
    }
}

$pageController = new PageController($page);

// Set page title before loading header
$pageTitles = [
    'dashboard-overview' => 'Dashboard Overview',
    'applications' => 'Applications',
    'application-details' => 'Application Details',
    'application-edit' => 'Edit Application',
    'students' => 'Students',
    'student-details' => 'Student Details',
    'student-edit' => 'Edit Student',
    'student-subjects' => 'Student Subjects',
    'enrollments' => 'Enrollments',
    'courses' => 'Courses',
    'sections' => 'Sections',
    'section-details' => 'Section Details',
    'requirements' => 'Requirements',
    'semesters' => 'Semester Management',           // ✅ ADDED
    'school-years' => 'School Years',               // ✅ ADDED
    'archived-students' => 'Archived Students',     // ✅ ADDED
    'contact-messages' => 'Contact Messages',        // ✅ ADDED
    'login' => 'Login',
    'logout' => 'Logout'
];

$pageTitle = $pageTitles[$page] ?? 'Bestlink College Enrollment System';

// ---------------------------------------------------------
// PAGE CONTENT
// ---------------------------------------------------------

$pageFile = BASE_PATH . '/pages/' . $page . '.php';

// Check if page file exists
if (!file_exists($pageFile)) {
    header('Location: ?page=dashboard-overview');
    exit;
}

// Include the page content
include $pageFile;

?>