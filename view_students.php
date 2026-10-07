<?php
require_once 'conn_inc.php';
require_once 'security.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Define checkConnection function
function checkConnection($conn) {
    if (!$conn || !$conn->ping()) {
        require_once 'conn_inc.php';
        return $conn;
    }
    return $conn;
}

// Check and fix connection
$conn = checkConnection($conn);

// Get filter status from URL parameter
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$status_filter_value = isset($_GET['status']) ? $_GET['status'] : 'all';

// Define current session ID
define('CURRENT_SESSION_ID', 6);

// Get statistics
$stats_sql = "SELECT 
                COUNT(CASE WHEN status = 0 THEN 1 END) as active_count,
                COUNT(CASE WHEN status = 1 THEN 1 END) as withdrawal_count,
                COUNT(CASE WHEN status = 2 THEN 1 END) as struck_count,
                COUNT(*) as total
              FROM student_registration 
              WHERE status IN (0, 1, 2)";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get all classes for filter dropdown
$classes_sql = "SELECT id, title FROM classes  ORDER BY title";
$classes_result = $conn->query($classes_sql);
$classes = [];
while ($class = $classes_result->fetch_assoc()) {
    $classes[] = $class;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }

        /* Modern Navbar */
        .navbar {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 600;
        }

        .logo i {
            font-size: 28px;
            color: #60a5fa;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn {
            background: rgba(255,255,255,0.1);
            padding: 8px 16px;
            border-radius: 8px;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }

        .stat-card.active {
            border-left: 4px solid #3b82f6;
            background: #eff6ff;
        }

        .stat-info h3 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
        }

        .stat-info p {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: #eff6ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #3b82f6;
        }
        /* Active class filter indicator */
.class-filter-wrapper {
    position: relative;
}

.class-filter-wrapper.active::after {
    content: '';
    position: absolute;
    top: -2px;
    right: -2px;
    width: 10px;
    height: 10px;
    background: #3b82f6;
    border-radius: 50%;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% { transform: scale(0.95); opacity: 1; }
    50% { transform: scale(1.2); opacity: 0.7; }
    100% { transform: scale(0.95); opacity: 1; }
}

.filter-badge {
    display: inline-block;
    background: #3b82f6;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    margin-left: 8px;
}

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 16px;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .status-chips {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .filter-chip {
            padding: 8px 20px;
            border-radius: 40px;
            background: #f1f5f9;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
            border: none;
        }

        .filter-chip:hover {
            background: #e2e8f0;
        }

        .filter-chip.active {
            background: #3b82f6;
            color: white;
        }

        .search-box {
            display: flex;
            gap: 8px;
            margin-top: 16px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
        }

        .search-box button {
            padding: 10px 24px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
        }

        .reset-btn {
            background: #64748b !important;
        }

        /* Students Grid */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 24px;
        }

        /* Student Card */
        .student-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .student-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0,0,0,0.15);
        }

        .card-top {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            position: relative;
            color: white;
        }

        .student-badge {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .student-id-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .status-chip {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-active { background: #10b981; color: white; }
        .status-withdrawal { background: #f59e0b; color: white; }
        .status-struck { background: #ef4444; color: white; }

        .student-name {
            font-size: 18px;
            font-weight: 600;
            margin: 12px 0 4px;
        }

        .student-regno {
            font-size: 12px;
            opacity: 0.85;
        }

        .card-body {
            padding: 16px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .info-item i {
            width: 16px;
            color: #3b82f6;
        }

        .info-item .label {
            color: #64748b;
        }

        .info-item .value {
            color: #1e293b;
            font-weight: 500;
        }

        .class-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px;
            margin: 12px 0;
        }

        .class-header {
            font-size: 12px;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .current-class {
            display: inline-block;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .promotion-list {
            margin-top: 8px;
        }

        .promotion-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .promotion-class {
            background: #e0e7ff;
            padding: 2px 8px;
            border-radius: 12px;
            color: #3b82f6;
        }

        .card-footer {
            padding: 12px 16px;
            background: #f8fafc;
            display: flex;
            gap: 12px;
            border-top: 1px solid #e5e7eb;
        }

        .btn-action {
            flex: 1;
            padding: 8px;
            border-radius: 8px;
            text-align: center;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-view {
            background: #3b82f6;
            color: white;
        }

        .btn-edit {
            background: #f59e0b;
            color: white;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }

        /* Skeleton Loader */
        .skeleton-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .skeleton-top {
            background: linear-gradient(135deg, #cbd5e1, #94a3b8);
            height: 120px;
        }

        .skeleton-body {
            padding: 16px;
        }

        .skeleton-line {
            height: 12px;
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: shimmer 1.2s ease-in-out infinite;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .skeleton-line.large { height: 18px; width: 60%; }
        .skeleton-line.medium { width: 40%; }
        .skeleton-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 16px 0;
        }
        .skeleton-grid .skeleton-line { margin-bottom: 0; }

        .skeleton-footer {
            padding: 12px 16px;
            background: #f8fafc;
            display: flex;
            gap: 12px;
        }
        .skeleton-button {
            flex: 1;
            height: 36px;
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: shimmer 1.2s ease-in-out infinite;
            border-radius: 8px;
        }

        .loading-trigger {
            height: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .no-data {
            grid-column: 1/-1;
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 16px;
        }

        .filter-summary {
            margin-top: 12px;
            padding: 8px 12px;
            background: #f1f5f9;
            border-radius: 8px;
            font-size: 13px;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .students-grid { grid-template-columns: 1fr; }
            .filter-row { flex-direction: column; }
            .filter-group { width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Modern Navbar -->
    

    <div class="container">
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card" data-status="all">
                <div class="stat-info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total Students</p>
                </div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card" data-status="0">
                <div class="stat-info">
                    <h3><?php echo $stats['active_count']; ?></h3>
                    <p>Active</p>
                </div>
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            </div>
            <div class="stat-card" data-status="1">
                <div class="stat-info">
                    <h3><?php echo $stats['withdrawal_count']; ?></h3>
                    <p>Withdrawn</p>
                </div>
                <div class="stat-icon"><i class="fas fa-user-slash"></i></div>
            </div>
            <div class="stat-card" data-status="2">
                <div class="stat-info">
                    <h3><?php echo $stats['struck_count']; ?></h3>
                    <p>Struck Off</p>
                </div>
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-filter"></i> Filter by Class</label>
                    <select id="classFilter">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-flag-checkered"></i> Filter by Status</label>
                    <div class="status-chips">
                        <button class="filter-chip" data-status="all">All</button>
                        <button class="filter-chip" data-status="0">Active</button>
                        <button class="filter-chip" data-status="1">Withdrawn</button>
                        <button class="filter-chip" data-status="2">Struck Off</button>
                    </div>
                </div>
            </div>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search by name, registration number, father name, or mobile...">
                <button id="searchBtn"><i class="fas fa-search"></i> Search</button>
                <button id="resetBtn" class="reset-btn"><i class="fas fa-undo"></i> Reset</button>
            </div>
            <div class="filter-summary" id="filterSummary"></div>
        </div>

        <!-- Students Grid -->
        <div class="students-grid" id="studentsGrid"></div>
        <div class="loading-trigger" id="loadingTrigger"></div>
    </div>

    <script>
        let currentPage = 1;
        let isLoading = false;
        let hasMore = true;
        let currentStatus = '<?php echo $filter_status; ?>';
        let currentClass = <?php echo $filter_class; ?>;
        let searchQuery = '';

        // Get class name from ID
        function getClassName(classId) {
            const classMap = {
                <?php foreach ($classes as $class): ?>
                    <?php echo $class['id']; ?>: '<?php echo addslashes($class['title']); ?>',
                <?php endforeach; ?>
            };
            return classMap[classId] || 'All Classes';
        }

        // Update filter summary
function updateFilterSummary() {
    const summaryDiv = document.getElementById('filterSummary');
    let filters = [];
    
    if (currentClass != 0) {
        const className = getClassName(currentClass);
        filters.push(`<strong>Class:</strong> ${className} (Current)`);
        
        // Add visual indicator for class filter
        const classSelect = document.getElementById('classFilter');
        if (currentClass != 0) {
            classSelect.style.borderColor = '#3b82f6';
            classSelect.style.backgroundColor = '#eff6ff';
        } else {
            classSelect.style.borderColor = '#e2e8f0';
            classSelect.style.backgroundColor = 'white';
        }
    }
    if (currentStatus !== 'all') {
        const statusText = currentStatus == '0' ? 'Active' : (currentStatus == '1' ? 'Withdrawn' : 'Struck Off');
        filters.push(`<strong>Status:</strong> ${statusText}`);
    }
    if (searchQuery) {
        filters.push(`<strong>Search:</strong> "${searchQuery}"`);
    }
    
    if (filters.length > 0) {
        summaryDiv.innerHTML = `<i class="fas fa-info-circle"></i> Active filters: ${filters.join(' | ')} <span style="color: #3b82f6;">✓</span>`;
        summaryDiv.style.background = '#eff6ff';
        summaryDiv.style.border = '1px solid #bfdbfe';
    } else {
        summaryDiv.innerHTML = '<i class="fas fa-info-circle"></i> Showing all students';
        summaryDiv.style.background = '#f1f5f9';
        summaryDiv.style.border = 'none';
    }
}

        // Create skeleton loader
        function createSkeletonCards(count = 6) {
            let html = '';
            for (let i = 0; i < count; i++) {
                html += `
                    <div class="skeleton-card">
                        <div class="skeleton-top"></div>
                        <div class="skeleton-body">
                            <div class="skeleton-line large"></div>
                            <div class="skeleton-line medium"></div>
                            <div class="skeleton-grid">
                                <div class="skeleton-line"></div>
                                <div class="skeleton-line"></div>
                                <div class="skeleton-line"></div>
                                <div class="skeleton-line"></div>
                            </div>
                            <div class="skeleton-line" style="width: 30%;"></div>
                        </div>
                        <div class="skeleton-footer">
                            <div class="skeleton-button"></div>
                            <div class="skeleton-button"></div>
                        </div>
                    </div>
                `;
            }
            return html;
        }

        // Escape HTML
        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Format date
        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-GB');
        }

        // Create student card
        function createStudentCard(student) {
            const statusMap = {
                0: { text: 'Active', class: 'status-active' },
                1: { text: 'Withdrawn', class: 'status-withdrawal' },
                2: { text: 'Struck Off', class: 'status-struck' }
            };
            const status = statusMap[student.school_status] || statusMap[0];
            const currentClass = student.current_class || 'Not Assigned';
            const gender = student.gender === 'M' ? 'Male' : (student.gender === 'F' ? 'Female' : 'Other');

            // Build promotion history
            let promotionHtml = '';
            if (student.previous_classes && student.previous_classes.length > 0) {
                promotionHtml = '<div class="promotion-list">';
                student.previous_classes.forEach((prev, idx) => {
                    promotionHtml += `
                        <div class="promotion-item">
                            <span class="promotion-class">${escapeHtml(prev.class)}</span>
                            <span style="color: #64748b;">${escapeHtml(prev.session)}</span>
                            ${idx < student.previous_classes.length - 1 ? '<i class="fas fa-arrow-right" style="font-size: 10px; color: #cbd5e1;"></i>' : ''}
                        </div>
                    `;
                });
                promotionHtml += '</div>';
            }

            return `
                <div class="student-card">
                    <div class="card-top">
                        <div class="student-badge">
                            <span class="student-id-badge"><i class="fas fa-hashtag"></i> ID: ${student.id}</span>
                            <span class="status-chip ${status.class}"><i class="fas ${student.school_status == 0 ? 'fa-check-circle' : (student.school_status == 1 ? 'fa-sign-out-alt' : 'fa-ban')}"></i> ${status.text}</span>
                        </div>
                        <div class="student-name">${escapeHtml(student.name)}</div>
                        <div class="student-regno"><i class="fas fa-id-card"></i> ${escapeHtml(student.reg_no)}</div>
                    </div>
                    
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <i class="fas fa-user-friends"></i>
                                <span class="label">Father:</span>
                                <span class="value">${escapeHtml(student.father_name)}</span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-phone"></i>
                                <span class="label">Mobile:</span>
                                <span class="value">${escapeHtml(student.mobile || 'N/A')}</span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-id-card"></i>
                                <span class="label">CNIC:</span>
                                <span class="value">${escapeHtml(student.cnic || 'N/A')}</span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-venus-mars"></i>
                                <span class="label">Gender:</span>
                                <span class="value">${gender}</span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-calendar"></i>
                                <span class="label">DOB:</span>
                                <span class="value">${formatDate(student.dob)}</span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-calendar-plus"></i>
                                <span class="label">Reg Date:</span>
                                <span class="value">${formatDate(student.registration_date)}</span>
                            </div>
                        </div>

                        <div class="class-info">
                            <div class="class-header">
                                <i class="fas fa-graduation-cap"></i> Current Class
                            </div>
                            <span class="current-class">${escapeHtml(currentClass)}</span>
                            ${student.current_session_title ? `<span style="margin-left: 8px; font-size: 11px; color: #64748b;">${escapeHtml(student.current_session_title)}</span>` : ''}
                        </div>

                        ${promotionHtml ? `
                        <div class="class-info">
                            <div class="class-header">
                                <i class="fas fa-history"></i> Promotion History
                            </div>
                            ${promotionHtml}
                        </div>
                        ` : ''}

                        <div class="info-item" style="margin-top: 8px;">
                            <i class="fas fa-check-circle"></i>
                            <span class="label">Admission:</span>
                            <span class="value" style="color: ${student.is_admission ? '#10b981' : '#ef4444'}">${student.is_admission ? 'Confirmed' : 'Pending'}</span>
                            ${student.is_transport ? '<span style="margin-left: 12px;"><i class="fas fa-bus"></i> Transport</span>' : ''}
                        </div>
                    </div>

                    <div class="card-footer">
                        <a href="view_student_detail.php?id=${student.id}" class="btn-action btn-view">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        <a href="student_registration.php?user=${student.id}" class="btn-action btn-edit">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                </div>
            `;
        }

        // Load students via AJAX
        async function loadStudents(reset = false) {
            if (isLoading) return;
            if (!hasMore && !reset) return;

            if (reset) {
                currentPage = 1;
                hasMore = true;
                document.getElementById('studentsGrid').innerHTML = '';
            }

            isLoading = true;
            
            // Show skeletons
            const grid = document.getElementById('studentsGrid');
            if (currentPage === 1) {
                grid.innerHTML = createSkeletonCards(8);
            } else {
                const skeletons = createSkeletonCards(4);
                grid.insertAdjacentHTML('beforeend', skeletons);
            }

            try {
                const url = `view_student_data.php?page=${currentPage}&status=${currentStatus}&class_id=${currentClass}&search=${encodeURIComponent(searchQuery)}`;
                const response = await fetch(url);
                const data = await response.json();

                // Remove skeletons
                const skeletonCards = document.querySelectorAll('.skeleton-card');
                skeletonCards.forEach(card => card.remove());

                if (data.success && data.students.length > 0) {
                    data.students.forEach(student => {
                        grid.insertAdjacentHTML('beforeend', createStudentCard(student));
                    });
                    
                    hasMore = data.has_more;
                    currentPage++;
                } else if (currentPage === 1) {
                    grid.innerHTML = `
                        <div class="no-data">
                            <i class="fas fa-folder-open" style="font-size: 48px; color: #cbd5e1;"></i>
                            <h3 style="margin-top: 16px;">No Students Found</h3>
                            <p style="color: #64748b; margin-top: 8px;">No students match your criteria</p>
                        </div>
                    `;
                    hasMore = false;
                } else {
                    hasMore = false;
                }
                
                updateFilterSummary();
            } catch (error) {
                console.error('Error:', error);
                const skeletonCards = document.querySelectorAll('.skeleton-card');
                skeletonCards.forEach(card => card.remove());
            } finally {
                isLoading = false;
            }
        }

        // Intersection Observer for infinite scroll
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !isLoading && hasMore) {
                    loadStudents();
                }
            });
        }, { threshold: 0.1 });

        // Start observing
        function startObserver() {
            const trigger = document.getElementById('loadingTrigger');
            if (trigger) observer.observe(trigger);
        }

        // Reset all filters
        function resetFilters() {
            currentStatus = 'all';
            currentClass = 0;
            searchQuery = '';
            
            document.getElementById('classFilter').value = '0';
            document.getElementById('searchInput').value = '';
            
            // Update active status chips
            document.querySelectorAll('.filter-chip').forEach(el => {
                el.classList.remove('active');
            });
            document.querySelector('.filter-chip[data-status="all"]').classList.add('active');
            
            // Update stat cards
            document.querySelectorAll('.stat-card').forEach(el => {
                el.classList.remove('active');
            });
            document.querySelector('.stat-card[data-status="all"]').classList.add('active');
            
            loadStudents(true);
        }

        // Handle filter change
        function applyFilters() {
            currentStatus = document.querySelector('.filter-chip.active')?.getAttribute('data-status') || 'all';
            currentClass = parseInt(document.getElementById('classFilter').value);
            searchQuery = document.getElementById('searchInput').value;
            loadStudents(true);
        }

        // Event listeners
        document.querySelectorAll('.stat-card').forEach(el => {
            el.addEventListener('click', () => {
                const status = el.getAttribute('data-status');
                if (status) {
                    document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
                    el.classList.add('active');
                    
                    document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
                    document.querySelector(`.filter-chip[data-status="${status}"]`).classList.add('active');
                    
                    applyFilters();
                }
            });
        });

        document.querySelectorAll('.filter-chip').forEach(el => {
            el.addEventListener('click', () => {
                const status = el.getAttribute('data-status');
                if (status) {
                    document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
                    el.classList.add('active');
                    
                    document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
                    document.querySelector(`.stat-card[data-status="${status}"]`).classList.add('active');
                    
                    applyFilters();
                }
            });
        });

        document.getElementById('classFilter').addEventListener('change', () => {
            applyFilters();
        });

        document.getElementById('searchBtn').addEventListener('click', () => {
            applyFilters();
        });

        document.getElementById('resetBtn').addEventListener('click', () => {
            resetFilters();
        });

        document.getElementById('searchInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') applyFilters();
        });

        // Set active states on load
        document.querySelectorAll(`.stat-card[data-status="${currentStatus}"]`).forEach(el => {
            el.classList.add('active');
        });
        document.querySelectorAll(`.filter-chip[data-status="${currentStatus}"]`).forEach(el => {
            el.classList.add('active');
        });

        // Initial load
        loadStudents(true);
        startObserver();
    </script>
</body>
</html>