<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get user info from session or use defaults
$userName = $_SESSION['user_name'] ?? 'Admin User';
$userPosition = $_SESSION['user_position'] ?? 'Administrator';
$userAvatar = substr($userName, 0, 1);
?>

<aside class="sidebar">
    <div class="school-logo">
        <img src="assets/bcp-logo.png" alt="School Logo">
        <div class="sidebar-icons">

            <!-- Bell Icon + Notification Dropdown -->
            <div class="icon-wrapper" id="bellWrapper">
                <i class="fa-regular fa-bell" id="bellBtn"></i>
                <div class="icon-dropdown" id="bellDropdown">
                    <div class="dropdown-header">
                        <span>Notifications</span>
                        <button class="mark-all-read">Mark all as read</button>
                    </div>
                    <ul class="notif-list">
                        <li class="notif-item">
                        </li>
                    </ul>
                    <div class="dropdown-footer">
                        <a href="#">View all notifications</a>
                    </div>
                </div>
            </div>

            <!-- User Icon + Profile Dropdown -->
            <div class="icon-wrapper" id="userWrapper">
                <i class="fa-regular fa-circle-user" id="userBtn"></i>
                <div class="icon-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-user-info">
                            <div class="dropdown-avatar">
                                <?= htmlspecialchars($userAvatar) ?>
                            </div>
                            <div>
                                <strong><?= htmlspecialchars($userName) ?></strong>
                                <span><?= htmlspecialchars($userPosition) ?></span>
                            </div>
                        </div>
                    </div>
                    <ul class="user-menu">
                        <li>
                            <a href="#"><i class="fa-regular fa-user"></i> Profile Settings</a>
                        </li>
                        <li>
                            <a href="#"><i class="fa-solid fa-lock"></i> Change Password</a>
                        </li>
                        <li class="divider"></li>
                        <li>
                            <a href="/sms/auth/logout.php" class="signout-link">
                                <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
    <div class="sidebar-header">
        <div class="user_avatar"><?= htmlspecialchars($userAvatar) ?></div>
        <h1 class="employee_name"><?= htmlspecialchars($userName) ?></h1>
        <p class="employee_position"><?= htmlspecialchars($userPosition) ?></p>
    </div>
    <h2>Enrollment Dashboard</h2>
    <ul>
        <?php 
        // Check if $pageController exists and has renderNav method
        if (isset($pageController) && method_exists($pageController, 'renderNav')) {
            $pageController->renderNav();
        } else {
            // Fallback navigation
            $currentPage = $_GET['page'] ?? 'dashboard-overview';
            $navItems = [
                'dashboard-overview' => ['label' => 'Dashboard', 'icon' => 'fa-solid fa-chart-pie'],
                'applications' => ['label' => 'Applications', 'icon' => 'fa-solid fa-file-pen'],
                'enrollments' => ['label' => 'Enrollments', 'icon' => 'fa-solid fa-user-graduate'],
                'students' => ['label' => 'Students', 'icon' => 'fa-solid fa-users'],
                'courses' => ['label' => 'Courses', 'icon' => 'fa-solid fa-book'],
                'sections' => ['label' => 'Sections', 'icon' => 'fa-solid fa-layer-group'],
                'requirements' => ['label' => 'Requirements', 'icon' => 'fa-solid fa-list-check'],
                'semesters' => ['label' => 'Semester Management', 'icon' => 'fa-solid fa-calendar-days'],        // ✅ ADDED
                'archived-students' => ['label' => 'Archived Students', 'icon' => 'fa-solid fa-box-archive'],  // ✅ ADDED
            ];
            
            foreach ($navItems as $page => $item) {
                $active = ($currentPage === $page) ? 'active-menu-link' : 'menu-link';
                echo '<li><a href="?page=' . $page . '" class="' . $active . '" data-page="' . $page . '">';
                echo '<i class="' . $item['icon'] . '"></i> ' . $item['label'];
                echo '</a></li>';
            }
        }
        ?>
    </ul>
</aside>