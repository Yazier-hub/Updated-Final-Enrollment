<?php
// classes/ApplicationView.php - COMPLETE FIXED VERSION
// ✅ FIXED: Updated enrollment flow to use schedule_id
// ✅ FIXED: ONE schedule = ONE enrollment record
// ✅ FIXED: Better UI for schedule-based enrollment

class ApplicationView {
    private $applications;
    private $courses;
    private $stats;
    private $search;
    private $message;
    private $error;
    private $currentStatus;
    
    public function __construct($applications, $courses, $stats = null, $search = '', $message = '', $error = '', $currentStatus = 'pending') {
        $this->applications = $applications;
        $this->courses = $courses;
        $this->stats = $stats ?: ['total' => 0, 'pending' => 0, 'converted' => 0, 'rejected' => 0];
        $this->search = $search;
        $this->message = $message;
        $this->error = $error;
        $this->currentStatus = $currentStatus;
    }
    
    public function render() {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Applications Management - Bestlink College</title>
            <style>
                /* General Styles */
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: #f0f2f5;
                    color: #333;
                }
                .applications-page {
                    max-width: 1400px;
                    margin: 0 auto;
                    padding: 20px;
                }
                
                /* Header */
                .page-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    background: white;
                    padding: 20px 30px;
                    border-radius: 10px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    margin-bottom: 20px;
                    flex-wrap: wrap;
                    gap: 15px;
                }
                .page-header h1 {
                    font-size: 28px;
                    color: #1a3c6e;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .header-actions {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                
                /* Buttons */
                .btn {
                    padding: 10px 20px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                }
                .btn-primary {
                    background: #1a3c6e;
                    color: white;
                }
                .btn-primary:hover {
                    background: #2a4c8e;
                    transform: translateY(-2px);
                    box-shadow: 0 4px 8px rgba(26, 60, 110, 0.3);
                }
                .btn-success {
                    background: #28a745;
                    color: white;
                }
                .btn-success:hover {
                    background: #34ce57;
                    transform: translateY(-2px);
                    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
                }
                .btn-warning {
                    background: #ffc107;
                    color: #333;
                }
                .btn-warning:hover {
                    background: #ffd44d;
                }
                .btn-danger {
                    background: #dc3545;
                    color: white;
                }
                .btn-danger:hover {
                    background: #e74c5e;
                }
                .btn-info {
                    background: #17a2b8;
                    color: white;
                }
                .btn-info:hover {
                    background: #20c4d6;
                }
                .btn-secondary {
                    background: #6c757d;
                    color: white;
                }
                .btn-secondary:hover {
                    background: #7e8891;
                }
                .btn-sm {
                    padding: 5px 10px;
                    font-size: 12px;
                }
                
                /* Alerts */
                .alert {
                    padding: 15px 20px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                    font-weight: 500;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .alert-success {
                    background: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                }
                .alert-error {
                    background: #f8d7da;
                    color: #721c24;
                    border: 1px solid #f5c6cb;
                }
                .alert-info {
                    background: #d1ecf1;
                    color: #0c5460;
                    border: 1px solid #bee5eb;
                }
                
                /* Info Banner */
                .info-banner {
                    background: #e7f3ff;
                    border-left: 4px solid #1a3c6e;
                    padding: 15px 20px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                    color: #004085;
                }
                .info-banner a {
                    color: #1a3c6e;
                    font-weight: bold;
                    text-decoration: underline;
                }
                
                /* Stats */
                .stats-container {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 15px;
                    margin-bottom: 20px;
                }
                .stat-card {
                    background: white;
                    padding: 20px;
                    border-radius: 10px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .stat-card .stat-number {
                    font-size: 32px;
                    font-weight: bold;
                    display: block;
                }
                .stat-card .stat-label {
                    font-size: 14px;
                    color: #6c757d;
                    margin-top: 5px;
                }
                .stat-pending .stat-number { color: #ffc107; }
                .stat-converted .stat-number { color: #28a745; }
                .stat-rejected .stat-number { color: #dc3545; }
                .stat-total .stat-number { color: #17a2b8; }
                
                /* Search Section */
                .search-section {
                    background: white;
                    padding: 20px;
                    border-radius: 10px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    margin-bottom: 20px;
                }
                .search-form {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .search-form input[type="text"] {
                    flex: 1;
                    min-width: 200px;
                    padding: 10px 15px;
                    border: 2px solid #ddd;
                    border-radius: 5px;
                    font-size: 14px;
                    transition: border-color 0.3s;
                }
                .search-form input[type="text"]:focus {
                    outline: none;
                    border-color: #1a3c6e;
                }
                .search-form select {
                    padding: 10px 15px;
                    border: 2px solid #ddd;
                    border-radius: 5px;
                    font-size: 14px;
                    background: white;
                }
                
                /* Status Filter */
                .status-filters {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                    margin-bottom: 15px;
                }
                .status-filter {
                    padding: 8px 16px;
                    border-radius: 20px;
                    text-decoration: none;
                    color: #6c757d;
                    background: #e9ecef;
                    font-size: 14px;
                    transition: all 0.3s;
                }
                .status-filter:hover {
                    background: #dee2e6;
                }
                .status-filter.active {
                    background: #1a3c6e;
                    color: white;
                }
                .status-filter .count {
                    background: rgba(255,255,255,0.3);
                    padding: 0 8px;
                    border-radius: 10px;
                    font-size: 12px;
                }
                .status-filter.active .count {
                    background: rgba(255,255,255,0.2);
                }
                
                /* Table */
                .applications-table-container {
                    background: white;
                    border-radius: 10px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    overflow: hidden;
                    margin-bottom: 20px;
                }
                .table-header {
                    padding: 15px 20px;
                    background: #f8f9fa;
                    border-bottom: 1px solid #dee2e6;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 10px;
                }
                .table-header .badge-count {
                    background: #1a3c6e;
                    color: white;
                    padding: 3px 12px;
                    border-radius: 20px;
                    font-size: 14px;
                }
                .table-responsive {
                    overflow-x: auto;
                }
                .table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 14px;
                }
                .table th {
                    background: #f8f9fa;
                    padding: 12px 15px;
                    text-align: left;
                    font-weight: 600;
                    color: #495057;
                    border-bottom: 2px solid #dee2e6;
                    white-space: nowrap;
                }
                .table td {
                    padding: 12px 15px;
                    border-bottom: 1px solid #dee2e6;
                    vertical-align: middle;
                }
                .table tr:hover {
                    background: #f8f9fa;
                }
                .table .text-center {
                    text-align: center;
                }
                
                /* Status Badge */
                .status-badge {
                    padding: 5px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    display: inline-block;
                }
                .status-pending {
                    background: #fff3cd;
                    color: #856404;
                }
                .status-converted {
                    background: #d4edda;
                    color: #155724;
                }
                .status-rejected {
                    background: #f8d7da;
                    color: #721c24;
                }
                
                /* Action Buttons */
                .action-buttons {
                    display: flex;
                    gap: 5px;
                    flex-wrap: wrap;
                }
                .action-buttons form {
                    display: inline-block;
                }
                
                /* Modal */
                .modal {
                    display: none;
                    position: fixed;
                    z-index: 1000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    overflow: auto;
                    background: rgba(0,0,0,0.5);
                    animation: fadeIn 0.3s;
                }
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                .modal-content {
                    background: white;
                    margin: 2% auto;
                    padding: 30px;
                    width: 95%;
                    max-width: 900px;
                    border-radius: 10px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
                    max-height: 90vh;
                    overflow-y: auto;
                    animation: slideDown 0.3s;
                }
                @keyframes slideDown {
                    from { transform: translateY(-50px); opacity: 0; }
                    to { transform: translateY(0); opacity: 1; }
                }
                .modal-content .close {
                    float: right;
                    font-size: 28px;
                    font-weight: bold;
                    cursor: pointer;
                    color: #999;
                    transition: color 0.3s;
                }
                .modal-content .close:hover {
                    color: #333;
                }
                .modal-content h2 {
                    margin-bottom: 20px;
                    color: #1a3c6e;
                    border-bottom: 2px solid #eee;
                    padding-bottom: 10px;
                }
                
                /* Form */
                .application-form {
                    display: flex;
                    flex-direction: column;
                    gap: 20px;
                }
                .form-section {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    border: 1px solid #e9ecef;
                }
                .form-section h3 {
                    margin-bottom: 15px;
                    color: #495057;
                    font-size: 16px;
                    border-bottom: 1px solid #dee2e6;
                    padding-bottom: 8px;
                }
                .form-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 15px;
                }
                .form-group {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                }
                .form-group.full-width {
                    grid-column: 1 / -1;
                }
                .form-group label {
                    font-weight: 600;
                    font-size: 14px;
                    color: #495057;
                }
                .form-group input,
                .form-group select,
                .form-group textarea {
                    padding: 10px;
                    border: 2px solid #ddd;
                    border-radius: 5px;
                    font-size: 14px;
                    transition: border-color 0.3s;
                    width: 100%;
                }
                .form-group input:focus,
                .form-group select:focus,
                .form-group textarea:focus {
                    outline: none;
                    border-color: #1a3c6e;
                }
                .form-group textarea {
                    resize: vertical;
                    min-height: 60px;
                }
                .form-actions {
                    display: flex;
                    gap: 10px;
                    justify-content: flex-end;
                    padding-top: 20px;
                    border-top: 1px solid #dee2e6;
                }
                
                /* Bulk Actions */
                .bulk-actions {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    flex-wrap: wrap;
                }
                .bulk-actions select {
                    padding: 8px 15px;
                    border: 2px solid #ddd;
                    border-radius: 5px;
                    font-size: 14px;
                }
                
                /* Responsive */
                @media (max-width: 768px) {
                    .page-header {
                        flex-direction: column;
                        align-items: stretch;
                        text-align: center;
                    }
                    .header-actions {
                        justify-content: center;
                    }
                    .stats-container {
                        grid-template-columns: repeat(2, 1fr);
                    }
                    .form-grid {
                        grid-template-columns: 1fr;
                    }
                    .modal-content {
                        margin: 5% auto;
                        width: 98%;
                        padding: 20px;
                    }
                    .search-form {
                        flex-direction: column;
                    }
                    .bulk-actions {
                        flex-direction: column;
                        width: 100%;
                    }
                    .table th, .table td {
                        padding: 8px 10px;
                        font-size: 12px;
                    }
                    .action-buttons .btn-sm {
                        padding: 3px 8px;
                        font-size: 11px;
                    }
                }
                @media (max-width: 480px) {
                    .stats-container {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
        </head>
        <body>
        <div class="applications-page">
            <!-- Page Header -->
            <div class="page-header">
                <h1>📋 Applications Management</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="showNewApplication()">+ New Application</button>
                    <a href="?page=enrollments" class="btn btn-success">🎯 Go to Enrollments</a>
                    <?php if ($this->currentStatus !== 'pending'): ?>
                        <a href="?page=applications" class="btn btn-info">📋 View Pending</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($this->message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($this->message); ?></div>
            <?php endif; ?>
            <?php if ($this->error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($this->error); ?></div>
            <?php endif; ?>

            <!-- Info Banner -->
            <div class="info-banner">
                <strong>💡 How it works:</strong> Submit an application, then go to <a href="?page=enrollments">Enrollments</a> to enroll the student in subjects.
                <br>
                <small>✅ <strong>NEW:</strong> Each subject enrollment creates a separate enrollment record linked to a specific schedule. Students can be enrolled in multiple subjects, each as a separate enrollment.</small>
            </div>

            <!-- Statistics -->
            <div class="stats-container">
                <div class="stat-card stat-total">
                    <span class="stat-number"><?php echo $this->stats['total']; ?></span>
                    <span class="stat-label">📊 Total Applications</span>
                </div>
                <div class="stat-card stat-pending">
                    <span class="stat-number"><?php echo $this->stats['pending']; ?></span>
                    <span class="stat-label">🔄 Pending</span>
                </div>
                <div class="stat-card stat-converted">
                    <span class="stat-number"><?php echo $this->stats['converted']; ?></span>
                    <span class="stat-label">✅ Converted</span>
                </div>
                <div class="stat-card stat-rejected">
                    <span class="stat-number"><?php echo $this->stats['rejected']; ?></span>
                    <span class="stat-label">❌ Rejected</span>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="search-section">
                <div class="status-filters">
                    <a href="?page=applications" class="status-filter <?php echo $this->currentStatus === 'pending' ? 'active' : ''; ?>">
                        Pending <span class="count"><?php echo $this->stats['pending']; ?></span>
                    </a>
                    <a href="?page=applications&status=converted" class="status-filter <?php echo $this->currentStatus === 'converted' ? 'active' : ''; ?>">
                        Converted <span class="count"><?php echo $this->stats['converted']; ?></span>
                    </a>
                    <a href="?page=applications&status=rejected" class="status-filter <?php echo $this->currentStatus === 'rejected' ? 'active' : ''; ?>">
                        Rejected <span class="count"><?php echo $this->stats['rejected']; ?></span>
                    </a>
                    <a href="?page=applications&status=all" class="status-filter <?php echo $this->currentStatus === 'all' ? 'active' : ''; ?>">
                        All <span class="count"><?php echo $this->stats['total']; ?></span>
                    </a>
                </div>
                <form method="GET" class="search-form">
                    <input type="hidden" name="page" value="applications">
                    <?php if ($this->currentStatus !== 'pending'): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($this->currentStatus); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" placeholder="Search applications by name, email, or contact..." 
                           value="<?php echo htmlspecialchars($this->search); ?>">
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                    <?php if ($this->search): ?>
                        <a href="?page=applications<?php echo $this->currentStatus !== 'pending' ? '&status=' . $this->currentStatus : ''; ?>" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Applications Table -->
            <div class="applications-table-container">
                <div class="table-header">
                    <span>
                        Applications 
                        <?php if ($this->currentStatus !== 'pending'): ?>
                            (<?php echo ucfirst($this->currentStatus); ?>)
                        <?php endif; ?>
                    </span>
                    <span class="badge-count"><?php echo count($this->applications); ?></span>
                </div>
                <div class="table-responsive">
                    <form method="POST" id="bulkActionForm" onsubmit="return confirmBulkAction();">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes(this)"></th>
                                    <th>ID</th>
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
                                <?php if (count($this->applications) > 0): ?>
                                    <?php foreach ($this->applications as $app): ?>
                                    <tr>
                                        <td>
                                            <?php if ($app['status'] !== 'converted'): ?>
                                                <input type="checkbox" name="ids[]" value="<?php echo $app['applicant_id']; ?>" class="app-checkbox">
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $app['applicant_id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['surname']); ?></strong>
                                            <?php if ($app['suffix']): ?>
                                                <small>(<?php echo htmlspecialchars($app['suffix']); ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['course_code'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($app['contact_number']); ?><br>
                                            <small><?php echo htmlspecialchars($app['email']); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                                $typeLabels = [
                                                    'freshmen' => 'Freshmen',
                                                    'transferee' => 'Transferee',
                                                    'returnee' => 'Returnee',
                                                    'senior_high' => 'Senior High'
                                                ];
                                                echo $typeLabels[$app['admission_type']] ?? 'N/A';
                                            ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($app['submitted_at'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $app['status']; ?>">
                                                <?php echo ucfirst($app['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?page=application-details&id=<?php echo $app['applicant_id']; ?>" class="btn btn-sm btn-info">View</a>
                                                <?php if ($app['status'] === 'pending'): ?>
                                                    <button onclick="editApplication(<?php echo $app['applicant_id']; ?>)" class="btn btn-sm btn-warning">Edit</button>
                                                    <a href="?page=enrollments&applicant_id=<?php echo $app['applicant_id']; ?>" class="btn btn-sm btn-success">🎯 Enroll</a>
                                                    <button onclick="rejectApplication(<?php echo $app['applicant_id']; ?>)" class="btn btn-sm btn-danger">Reject</button>
                                                    <form method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this application?');">
                                                        <input type="hidden" name="applicant_id" value="<?php echo $app['applicant_id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center">
                                            <?php if ($this->search): ?>
                                                No applications found for "<strong><?php echo htmlspecialchars($this->search); ?></strong>".
                                                <a href="?page=applications<?php echo $this->currentStatus !== 'pending' ? '&status=' . $this->currentStatus : ''; ?>">Clear search</a>
                                            <?php else: ?>
                                                No <?php echo $this->currentStatus !== 'pending' ? ucfirst($this->currentStatus) : 'pending'; ?> applications found.
                                                <?php if ($this->currentStatus === 'pending'): ?>
                                                    <a href="#" onclick="showNewApplication()">Create one now</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                        <?php if (count($this->applications) > 0 && $this->currentStatus === 'pending'): ?>
                            <div class="bulk-actions" style="padding: 15px 20px; border-top: 1px solid #dee2e6;">
                                <span>Bulk Actions:</span>
                                <select name="bulk_action" id="bulkAction">
                                    <option value="">-- Select Action --</option>
                                    <option value="delete">Delete Selected</option>
                                    <option value="reject">Reject Selected</option>
                                </select>
                                <button type="submit" name="action" value="bulk_action" class="btn btn-primary btn-sm">Apply</button>
                                <span id="selectedCount" style="color: #6c757d; font-size: 14px;">0 selected</span>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- New Application Modal -->
        <?php $this->renderNewApplicationModal(); ?>
        
        <!-- Edit Application Modal -->
        <?php $this->renderEditApplicationModal(); ?>
        
        <!-- Reject Application Modal -->
        <?php $this->renderRejectApplicationModal(); ?>

        <script>
            // Toggle all checkboxes
            function toggleAllCheckboxes(master) {
                const checkboxes = document.querySelectorAll('.app-checkbox');
                checkboxes.forEach(cb => cb.checked = master.checked);
                updateSelectedCount();
            }
            
            // Update selected count
            function updateSelectedCount() {
                const checkboxes = document.querySelectorAll('.app-checkbox:checked');
                document.getElementById('selectedCount').textContent = checkboxes.length + ' selected';
            }
            
            // Add event listeners to checkboxes
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.app-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateSelectedCount);
                });
            });
            
            // Confirm bulk action
            function confirmBulkAction() {
                const action = document.getElementById('bulkAction').value;
                if (!action) {
                    alert('Please select an action.');
                    return false;
                }
                const selected = document.querySelectorAll('.app-checkbox:checked');
                if (selected.length === 0) {
                    alert('Please select at least one application.');
                    return false;
                }
                return confirm('Are you sure you want to ' + action + ' ' + selected.length + ' application(s)?');
            }
            
            function showNewApplication() {
                document.getElementById('newApplicationModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            }

            function hideNewApplication() {
                document.getElementById('newApplicationModal').style.display = 'none';
                document.body.style.overflow = 'auto';
            }

            function editApplication(id) {
                fetch('?page=applications&ajax=get_application&id=' + id)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const app = data.data;
                            document.getElementById('edit_applicant_id').value = app.applicant_id;
                            document.getElementById('edit_first_name').value = app.first_name || '';
                            document.getElementById('edit_middle_name').value = app.middle_name || '';
                            document.getElementById('edit_surname').value = app.surname || '';
                            document.getElementById('edit_suffix').value = app.suffix || '';
                            document.getElementById('edit_admission_type').value = app.admission_type || 'freshmen';
                            document.getElementById('edit_working_student').value = app.working_student || 'No';
                            document.getElementById('edit_sex').value = app.sex || '';
                            document.getElementById('edit_civil_status').value = app.civil_status || '';
                            document.getElementById('edit_date_of_birth').value = app.date_of_birth || '';
                            document.getElementById('edit_age').value = app.age || '';
                            document.getElementById('edit_place_of_birth').value = app.place_of_birth || '';
                            document.getElementById('edit_religion').value = app.religion || '';
                            document.getElementById('edit_address_barangay').value = app.address_barangay || '';
                            document.getElementById('edit_address_city').value = app.address_city || '';
                            document.getElementById('edit_address_province').value = app.address_province || '';
                            document.getElementById('edit_address_complete').value = app.address_complete || '';
                            document.getElementById('edit_email').value = app.email || '';
                            document.getElementById('edit_contact_number').value = app.contact_number || '';
                            document.getElementById('edit_facebook').value = app.facebook || '';
                            document.getElementById('edit_messenger').value = app.messenger || '';
                            document.getElementById('edit_school_last_attended').value = app.school_last_attended || '';
                            document.getElementById('edit_year_graduated').value = app.year_graduated || '';
                            document.getElementById('edit_how_hear').value = app.how_hear || '';
                            document.getElementById('edit_parent_full_name').value = app.parent_full_name || '';
                            document.getElementById('edit_parent_contact').value = app.parent_contact || '';
                            document.getElementById('edit_parent_address').value = app.parent_address || '';
                            document.getElementById('edit_course_id').value = app.course_id || '';
                            
                            document.getElementById('editApplicationModal').style.display = 'block';
                            document.body.style.overflow = 'hidden';
                        } else {
                            alert(data.message || 'Failed to load application data.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error loading application data. Please try again.');
                    });
            }

            function hideEditApplication() {
                document.getElementById('editApplicationModal').style.display = 'none';
                document.body.style.overflow = 'auto';
            }

            function rejectApplication(id) {
                document.getElementById('reject_applicant_id').value = id;
                document.getElementById('rejectApplicationModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            }

            function hideRejectApplication() {
                document.getElementById('rejectApplicationModal').style.display = 'none';
                document.body.style.overflow = 'auto';
            }

            function calculateAge() {
                const birthDate = document.querySelector('input[name="date_of_birth"]').value;
                if (birthDate) {
                    const today = new Date();
                    const birth = new Date(birthDate);
                    let age = today.getFullYear() - birth.getFullYear();
                    const monthDiff = today.getMonth() - birth.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                        age--;
                    }
                    document.querySelector('input[name="age"]').value = age;
                }
            }

            function calculateEditAge() {
                const birthDate = document.getElementById('edit_date_of_birth').value;
                if (birthDate) {
                    const today = new Date();
                    const birth = new Date(birthDate);
                    let age = today.getFullYear() - birth.getFullYear();
                    const monthDiff = today.getMonth() - birth.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                        age--;
                    }
                    document.getElementById('edit_age').value = age;
                }
            }

            // Close modals on outside click
            window.onclick = function(event) {
                var newModal = document.getElementById('newApplicationModal');
                var editModal = document.getElementById('editApplicationModal');
                var rejectModal = document.getElementById('rejectApplicationModal');
                if (event.target == newModal) {
                    hideNewApplication();
                }
                if (event.target == editModal) {
                    hideEditApplication();
                }
                if (event.target == rejectModal) {
                    hideRejectApplication();
                }
            }

            // Close on Escape key
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    hideNewApplication();
                    hideEditApplication();
                    hideRejectApplication();
                }
            });
        </script>
        </body>
        </html>
        <?php
    }
    
    private function renderNewApplicationModal() {
        ?>
        <div id="newApplicationModal" class="modal" style="display: none;">
            <div class="modal-content">
                <span class="close" onclick="hideNewApplication()">&times;</span>
                <h2>📝 New Application</h2>
                <form method="POST" class="application-form" id="applicationForm">
                    <div class="form-section">
                        <h3>Personal Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>First Name *</label>
                                <input type="text" name="first_name" required>
                            </div>
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name">
                            </div>
                            <div class="form-group">
                                <label>Surname *</label>
                                <input type="text" name="surname" required>
                            </div>
                            <div class="form-group">
                                <label>Suffix</label>
                                <input type="text" name="suffix" placeholder="e.g., Jr., Sr., III">
                            </div>
                            <div class="form-group">
                                <label>Admission Type</label>
                                <select name="admission_type">
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
                                <label>Sex *</label>
                                <select name="sex" required>
                                    <option value="">Select Sex</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Civil Status *</label>
                                <select name="civil_status" required>
                                    <option value="">Select Civil Status</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Divorced">Divorced</option>
                                    <option value="Widowed">Widowed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date of Birth *</label>
                                <input type="date" name="date_of_birth" required onchange="calculateAge()">
                            </div>
                            <div class="form-group">
                                <label>Age</label>
                                <input type="number" name="age" id="age" readonly>
                            </div>
                            <div class="form-group">
                                <label>Place of Birth *</label>
                                <input type="text" name="place_of_birth" required>
                            </div>
                            <div class="form-group">
                                <label>Religion</label>
                                <input type="text" name="religion">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Address Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Barangay *</label>
                                <input type="text" name="address_barangay" required>
                            </div>
                            <div class="form-group">
                                <label>City/Municipality *</label>
                                <input type="text" name="address_city" required>
                            </div>
                            <div class="form-group">
                                <label>Province *</label>
                                <input type="text" name="address_province" required>
                            </div>
                            <div class="form-group full-width">
                                <label>Complete Address</label>
                                <textarea name="address_complete" rows="2"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Contact Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label>Contact Number *</label>
                                <input type="text" name="contact_number" required placeholder="09xxxxxxxxx">
                            </div>
                            <div class="form-group">
                                <label>Facebook Account</label>
                                <input type="text" name="facebook" placeholder="Facebook username or URL">
                            </div>
                            <div class="form-group">
                                <label>Messenger</label>
                                <input type="text" name="messenger" placeholder="Messenger account">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Educational Background</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>School Last Attended *</label>
                                <input type="text" name="school_last_attended" required>
                            </div>
                            <div class="form-group">
                                <label>Year Graduated *</label>
                                <input type="number" name="year_graduated" min="1990" max="<?php echo date('Y'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>How did you hear about us?</label>
                                <select name="how_hear">
                                    <option value="">Select option</option>
                                    <option value="social_media">Social Media</option>
                                    <option value="friend">Friend/Relative</option>
                                    <option value="school">School</option>
                                    <option value="advertisement">Advertisement</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Parent/Guardian Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Parent/Guardian Full Name *</label>
                                <input type="text" name="parent_full_name" required>
                            </div>
                            <div class="form-group">
                                <label>Parent Contact Number</label>
                                <input type="text" name="parent_contact" placeholder="09xxxxxxxxx">
                            </div>
                            <div class="form-group full-width">
                                <label>Parent Address</label>
                                <textarea name="parent_address" rows="2"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Course Selection</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label>Preferred Course *</label>
                                <select name="course_id" required>
                                    <option value="">Select Course</option>
                                    <?php foreach ($this->courses as $c): ?>
                                        <option value="<?php echo $c['id']; ?>">
                                            <?php echo htmlspecialchars($c['code'] . ' - ' . $c['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">Reset</button>
                        <button type="submit" name="action" value="submit" class="btn btn-primary">Submit Application</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function renderEditApplicationModal() {
        ?>
        <div id="editApplicationModal" class="modal" style="display: none;">
            <div class="modal-content">
                <span class="close" onclick="hideEditApplication()">&times;</span>
                <h2>✏️ Edit Application</h2>
                <form method="POST" class="application-form" id="editForm">
                    <input type="hidden" name="applicant_id" id="edit_applicant_id">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="redirect" value="?page=applications">
                    
                    <div class="form-section">
                        <h3>Personal Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>First Name *</label>
                                <input type="text" name="first_name" id="edit_first_name" required>
                            </div>
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name" id="edit_middle_name">
                            </div>
                            <div class="form-group">
                                <label>Surname *</label>
                                <input type="text" name="surname" id="edit_surname" required>
                            </div>
                            <div class="form-group">
                                <label>Suffix</label>
                                <input type="text" name="suffix" id="edit_suffix" placeholder="e.g., Jr., Sr., III">
                            </div>
                            <div class="form-group">
                                <label>Admission Type</label>
                                <select name="admission_type" id="edit_admission_type">
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
                                <label>Sex *</label>
                                <select name="sex" id="edit_sex" required>
                                    <option value="">Select Sex</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Civil Status *</label>
                                <select name="civil_status" id="edit_civil_status" required>
                                    <option value="">Select Civil Status</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Divorced">Divorced</option>
                                    <option value="Widowed">Widowed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date of Birth *</label>
                                <input type="date" name="date_of_birth" id="edit_date_of_birth" required onchange="calculateEditAge()">
                            </div>
                            <div class="form-group">
                                <label>Age</label>
                                <input type="number" name="age" id="edit_age" readonly>
                            </div>
                            <div class="form-group">
                                <label>Place of Birth *</label>
                                <input type="text" name="place_of_birth" id="edit_place_of_birth" required>
                            </div>
                            <div class="form-group">
                                <label>Religion</label>
                                <input type="text" name="religion" id="edit_religion">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Address Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Barangay *</label>
                                <input type="text" name="address_barangay" id="edit_address_barangay" required>
                            </div>
                            <div class="form-group">
                                <label>City/Municipality *</label>
                                <input type="text" name="address_city" id="edit_address_city" required>
                            </div>
                            <div class="form-group">
                                <label>Province *</label>
                                <input type="text" name="address_province" id="edit_address_province" required>
                            </div>
                            <div class="form-group full-width">
                                <label>Complete Address</label>
                                <textarea name="address_complete" id="edit_address_complete" rows="2"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Contact Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" id="edit_email" required>
                            </div>
                            <div class="form-group">
                                <label>Contact Number *</label>
                                <input type="text" name="contact_number" id="edit_contact_number" required placeholder="09xxxxxxxxx">
                            </div>
                            <div class="form-group">
                                <label>Facebook Account</label>
                                <input type="text" name="facebook" id="edit_facebook" placeholder="Facebook username or URL">
                            </div>
                            <div class="form-group">
                                <label>Messenger</label>
                                <input type="text" name="messenger" id="edit_messenger" placeholder="Messenger account">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Educational Background</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>School Last Attended *</label>
                                <input type="text" name="school_last_attended" id="edit_school_last_attended" required>
                            </div>
                            <div class="form-group">
                                <label>Year Graduated *</label>
                                <input type="number" name="year_graduated" id="edit_year_graduated" min="1990" max="<?php echo date('Y'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>How did you hear about us?</label>
                                <select name="how_hear" id="edit_how_hear">
                                    <option value="">Select option</option>
                                    <option value="social_media">Social Media</option>
                                    <option value="friend">Friend/Relative</option>
                                    <option value="school">School</option>
                                    <option value="advertisement">Advertisement</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Parent/Guardian Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Parent/Guardian Full Name *</label>
                                <input type="text" name="parent_full_name" id="edit_parent_full_name" required>
                            </div>
                            <div class="form-group">
                                <label>Parent Contact Number</label>
                                <input type="text" name="parent_contact" id="edit_parent_contact" placeholder="09xxxxxxxxx">
                            </div>
                            <div class="form-group full-width">
                                <label>Parent Address</label>
                                <textarea name="parent_address" id="edit_parent_address" rows="2"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Course Selection</h3>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label>Preferred Course *</label>
                                <select name="course_id" id="edit_course_id" required>
                                    <option value="">Select Course</option>
                                    <?php foreach ($this->courses as $c): ?>
                                        <option value="<?php echo $c['id']; ?>">
                                            <?php echo htmlspecialchars($c['code'] . ' - ' . $c['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="hideEditApplication()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Application</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function renderRejectApplicationModal() {
        ?>
        <div id="rejectApplicationModal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <span class="close" onclick="hideRejectApplication()">&times;</span>
                <h2>❌ Reject Application</h2>
                <form method="POST" class="application-form">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="applicant_id" id="reject_applicant_id">
                    <input type="hidden" name="redirect" value="?page=applications">
                    
                    <div class="form-section">
                        <p>Are you sure you want to reject this application?</p>
                        <div class="form-group">
                            <label>Reason (optional)</label>
                            <textarea name="notes" rows="3" placeholder="Enter reason for rejection..."></textarea>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="hideRejectApplication()">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Application</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}
?>