<?php
// Include security file to enforce authentication and authorization
require_once('security.php');

// Language handling
$_SESSION['lang'] = 'en';
$lang = $_SESSION['lang'];

// Define translations for English
$translations = [
    'en' => [
        'title' => 'Account Search',
        'search_label' => 'Search Account (Title or Mobile)',
        'search_button' => 'Search',
        'payment_button' => 'Make Payment',
        'reset' => 'Reset',
        'error' => 'Error: ',
        'no_account_found' => 'No account found or multiple accounts match. Please be more specific.',
        'account_found' => 'Account found: ',
        'account_details' => 'Account Details',
        'account_id' => 'ID',
        'account_title' => 'Title',
        'account_type' => 'Type',
        'account_balance' => 'Balance',
        'account_mobile' => 'Mobile',
        'dashboard_title' => 'Monthly Expense Dashboard (All Accounts)',
        'total_expenses' => 'Total Expenses',
        'total_paid' => 'Total Paid',
        'pending_balance' => 'Pending Balance',
        'recent_transactions' => 'Recent Transactions',
        'expense_distribution' => 'Expense by Payment Type',
        'balance_trend' => 'Balance Trend',
        'no_data' => 'No data available for selected month.',
        'month_picker_label' => 'Select Month'
    ]
];

// Include database connection
require_once('conn_inc.php');

// Check for database connection errors
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error, 3, 'error_log.txt');
    die("Connection failed: " . $conn->connect_error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Include meta tags -->
    <?php require_once('meta_inc.php'); ?>
    <!-- Set viewport for responsive design -->
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <!-- Include Tailwind CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Include Chart.js via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * {
            box-sizing: border-box; /* Prevent padding/margin overflow */
        }
        body {
            font-size: 14px; /* Default font size for English */
        }
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            margin-bottom: 0;
        }
        .container {
            max-width: 100%;
            padding-top: 60px;
            padding-left: 8px;
            padding-right: 8px;
        }
        .search-section {
            position: relative;
            background: #fff;
            padding: 6px;
            border-radius: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-bottom: 10px;
        }
        .search-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .search-group input {
            font-size: 0.9rem;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
        }
        .search-group button {
            padding: 8px 16px;
            font-size: 0.9rem;
            border-radius: 4px;
            min-height: 40px;
        }
        .search-feedback {
            font-size: 0.8rem;
            margin-top: 5px;
        }
        .account-card {
            background: #fff;
            padding: 8px;
            border-radius: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-top: 10px;
            display: none;
        }
        .account-field {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .account-field.balance-field {
            padding: 8px 0;
            background: #fef2f2;
        }
        .account-field:last-child {
            border-bottom: none;
        }
        .account-field-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: #4b5563;
        }
        .account-field-value {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1f2937;
            text-align: right;
        }
        .account-field-value.balance-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: #dc2626;
        }
        .action-buttons {
            padding: 8px;
            border-radius: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            margin-top: 10px;
        }
        .action-buttons .btn {
            padding: 8px;
            font-size: 0.9rem;
            border-radius: 4px;
            min-height: 40px;
            margin-bottom: 6px;
        }
        .dashboard-section {
            padding: 8px;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            min-height: 400px;
        }
        .summary-card {
            padding: 8px;
            border-radius: 5px;
            margin-bottom: 8px;
        }
        #total_expenses_card {
            background: #fee2e2;
        }
        #total_paid_card {
            background: #d1fae5;
        }
        #pending_balance_card {
            background: #fef3c7;
        }
        #transaction_count_card {
            background: #e5e7eb;
        }
        .canvas-container {
            position: relative;
            height: 150px;
            width: 100%;
        }
        .month-picker {
            font-size: 0.9rem;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 150px;
        }
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .hidden {
            display: none;
        }
        @media (max-width: 767px) {
            .container {
                padding-top: 50px;
            }
            .search-section {
                padding: 5px;
            }
            .search-group {
                flex-direction: column;
                align-items: stretch;
                gap: 4px;
            }
            .search-group input,
            .search-group button {
                width: 100%;
                font-size: 0.85rem;
                padding: 6px;
                min-height: 40px;
            }
            .month-picker {
                width: 100%;
                font-size: 0.85rem;
            }
            .account-field-label {
                font-size: 0.85rem;
            }
            .account-field-value {
                font-size: 0.85rem;
            }
            .account-field-value.balance-value {
                font-size: 1rem;
            }
            .action-buttons,
            .dashboard-section {
                padding: 6px;
            }
            .summary-card {
                padding: 6px;
            }
            .canvas-container {
                height: 120px;
            }
            .table th,
            .table td {
                font-size: 0.8rem;
            }
            .summary-card h4 {
                font-size: 0.875rem;
            }
            .summary-card p {
                font-size: 1.125rem;
            }
        }
        .text-lg{
            font-size: 13px !important;
        }
    </style>
</head>
<body>

<!-- Include navigation bar -->
<?php require_once('navbar.php'); ?>

<!-- Main content -->
<div class="container mx-auto">
    <div class="flex flex-wrap -mx-2">
        <!-- Left Column: Search and Account Details -->
        <div class="w-full md:w-1/3 px-2 mb-4">
            <!-- Panel for account search -->
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo $translations[$lang]['title']; ?></h3>
                </div>
                <div class="panel-body" style="padding: 8px;">
                    <!-- Display session messages -->
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['message_type']; ?>">
                            <?php 
                            echo $_SESSION['message']; 
                            unset($_SESSION['message'], $_SESSION['message_type']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <!-- Search input section -->
                    <div class="search-section">
                        <div class="form-group search-group">
                            <input type="text" class="form-control" id="account_search" 
                                   placeholder="<?php echo $translations[$lang]['search_label']; ?>" required>
                            <button type="button" class="btn btn-primary" id="search_button">
                                <?php echo $translations[$lang]['search_button']; ?>
                            </button>
                            <div class="search-feedback" id="search_feedback"></div>
                        </div>
                    </div>

                    <!-- Account details card -->
                    <div class="account-card" id="account_card">
                        <div class="account-details">
                            <div class="account-field">
                                <span class="account-field-label"><?php echo $translations[$lang]['account_id']; ?>:</span>
                                <span class="account-field-value" id="account_id_display"></span>
                            </div>
                            <div class="account-field">
                                <span class="account-field-label"><?php echo $translations[$lang]['account_title']; ?>:</span>
                                <span class="account-field-value" id="account_title"></span>
                            </div>
                            <div class="account-field">
                                <span class="account-field-label"><?php echo $translations[$lang]['account_type']; ?>:</span>
                                <span class="account-field-value" id="account_type"></span>
                            </div>
                            <div class="account-field balance-field">
                                <span class="account-field-label"><?php echo $translations[$lang]['account_balance']; ?>:</span>
                                <span class="account-field-value balance-value" id="account_balance"></span>
                            </div>
                            <div class="account-field">
                                <span class="account-field-label"><?php echo $translations[$lang]['account_mobile']; ?>:</span>
                                <span class="account-field-value" id="account_mobile"></span>
                            </div>
                        </div>
                        <!-- Action buttons -->
                        <div class="action-buttons" id="action_buttons" style="display:none;">
                            <input type="hidden" id="account_id" value="">
                            <button class="btn btn-success btn-lg w-full" id="payment_button" disabled 
                                    onclick="window.location.href='payment.php?account_id=' + document.getElementById('account_id').value">
                                <span class="glyphicon glyphicon-minus"></span> 
                                <?php echo $translations[$lang]['payment_button']; ?>
                            </button>
                            <button type="button" class="btn btn-default btn-lg w-full" id="reset_button">
                                <span class="glyphicon glyphicon-refresh"></span> 
                                <?php echo $translations[$lang]['reset']; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Monthly Expense Dashboard -->
        <div class="w-full md:w-2/3 px-2 mb-4">
            <div class="dashboard-section">
                <div class="flex items-center mb-3">
                    <label for="month_picker" class="text-sm font-medium mr-2"><?php echo $translations[$lang]['month_picker_label']; ?>:</label>
                    <input type="month" id="month_picker" class="month-picker" value="2025-07">
                </div>
                <h3 class="text-lg font-semibold mb-3"><?php echo $translations[$lang]['dashboard_title']; ?></h3>
                <div id="dashboard_content">
                    <!-- Error message for dashboard -->
                    <div id="dashboard_error" class="alert alert-danger hidden" role="alert">
                        <?php echo $translations[$lang]['error']; ?> Unable to load dashboard data.
                    </div>
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div class="summary-card" id="total_expenses_card">
                            <h4 class="text-lg font-medium"><?php echo $translations[$lang]['total_expenses']; ?></h4>
                            <p class="text-lg font-bold" id="total_expenses">0.00</p>
                        </div>
                        <div class="summary-card" id="total_paid_card">
                            <h4 class="text-lg font-medium"><?php echo $translations[$lang]['total_paid']; ?></h4>
                            <p class="text-lg font-bold" id="total_paid">0.00</p>
                        </div>
                        <div class="summary-card" id="pending_balance_card">
                            <h4 class="text-lg font-medium"><?php echo $translations[$lang]['pending_balance']; ?></h4>
                            <p class="text-lg font-bold" id="pending_balance">0.00</p>
                        </div>
                        <div class="summary-card" id="transaction_count_card">
                            <h4 class="text-lg font-medium"><?php echo $translations[$lang]['recent_transactions']; ?></h4>
                            <p class="text-lg font-bold" id="transaction_count">0</p>
                        </div>
                    </div>
                    <!-- Charts -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mb-3">
                        <div class="canvas-container">
                            <h4 class="text-lg font-medium"><?php echo $translations[$lang]['expense_distribution']; ?></h4>
                            <canvas id="expensePieChart"></canvas>
                        </div>
                        <div class="canvas-container">
                            <h4 class="text-lg font-medium"><?php echo $translations[$lang]['balance_trend']; ?></h4>
                            <canvas id="balanceLineChart"></canvas>
                        </div>
                    </div>
                    <!-- Recent Transactions -->
                    <div>
                        <h4 class="text-sm font-medium"><?php echo $translations[$lang]['recent_transactions']; ?></h4>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Account ID</th>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Balance</th>
                                    </tr>
                                </thead>
                                <tbody id="recent_transactions">
                                    <tr><td colspan="5"><?php echo $translations[$lang]['no_data']; ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/mobile_menu.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    let expenseChart, balanceChart; // Store chart instances

    // Load monthly dashboard for default month (July 2025) on page load
    updateDashboard();

    // Update dashboard when month is changed
    $('#month_picker').on('change', function() {
        updateDashboard();
    });

    // Perform account search for account details only
    function performSearch() {
        const searchTerm = $('#account_search').val().trim();
        if (searchTerm.length < 2) {
            $('#search_feedback').html('<span class="text-danger"><?php echo $translations[$lang]['no_account_found']; ?></span>').show();
            resetAccountUI();
            return;
        }

        $.ajax({
            url: 'fetch_account.php',
            method: 'POST',
            data: { search_term: searchTerm },
            dataType: 'json',
            success: function(data) {
                console.log('Search response:', data); // Debug log
                if (data.status === 'success' && data.account) {
                    $('#search_feedback').html('<span class="text-success"><?php echo $translations[$lang]['account_found']; ?>' + data.account.title + ' (' + data.account.type + ')</span>').show();
                    $('#account_id').val(data.account.id);
                    $('#account_id_display').text(data.account.id || 'N/A');
                    $('#account_title').text(data.account.title || 'N/A');
                    $('#account_type').text(data.account.type || 'N/A');
                    $('#account_balance').text(parseFloat(data.account.balance || 0).toFixed(2));
                    $('#account_mobile').text(data.account.mobile_no || 'N/A');
                    $('#account_card').show().addClass('fade-in');
                    $('#action_buttons').show().addClass('fade-in');
                    $('#payment_button').prop('disabled', false);
                } else {
                    $('#search_feedback').html('<span class="text-danger"><?php echo $translations[$lang]['no_account_found']; ?></span>').show();
                    resetAccountUI();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Search AJAX error:', textStatus, errorThrown, jqXHR.responseText); // Detailed debug log
                $('#search_feedback').html('<span class="text-danger"><?php echo $translations[$lang]['error']; ?> ' + textStatus + '</span>').show();
                resetAccountUI();
            }
        });
    }

    // Fetch and update monthly dashboard data
    function updateDashboard() {
        $('#dashboard_error').addClass('hidden');
        const monthPicker = document.getElementById('month_picker').value;
        const [year, month] = monthPicker ? monthPicker.split('-') : ['2025', '07'];
        
        $.ajax({
            url: 'fetch_dashboard_data.php',
            method: 'POST',
            data: { year: year, month: month },
            dataType: 'json',
            success: function(data) {
                console.log('Dashboard response:', data); // Debug log
                if (data.status === 'success') {
                    // Update summary cards
                    $('#total_expenses').text(parseFloat(data.summary.total_expenses || 0).toFixed(2));
                    $('#total_paid').text(parseFloat(data.summary.total_paid || 0).toFixed(2));
                    $('#pending_balance').text(parseFloat(data.summary.pending_balance || 0).toFixed(2));
                    $('#transaction_count').text(data.summary.transaction_count || 0);

                    // Update pie chart
                    if (expenseChart) expenseChart.destroy();
                    const expenseData = data.expense_distribution || { labels: [], values: [] };
                    if (expenseData.labels.length === 0) {
                        $('#dashboard_error').removeClass('hidden').text('<?php echo $translations[$lang]['no_data']; ?> (Expense Distribution)');
                    }
                    expenseChart = new Chart(document.getElementById('expensePieChart'), {
                        type: 'pie',
                        data: {
                            labels: expenseData.labels.length ? data.expense_distribution.labels : ['<?php echo $translations[$lang]['no_data']; ?>'],
                            datasets: [{
                                data: expenseData.values.length ? data.expense_distribution.values : [1],
                                backgroundColor: expenseData.values.length ? ['#ff6384', '#36a2eb', '#ffce56', '#4bc0c0', '#9966ff'] : ['#e5e7eb'],
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }
                        }
                    });

                    // Update line chart
                    if (balanceChart) balanceChart.destroy();
                    const balanceData = data.balance_trend || { dates: [], balances: [] };
                    if (balanceData.dates.length === 0) {
                        $('#dashboard_error').removeClass('hidden').text('<?php echo $translations[$lang]['no_data']; ?> (Balance Trend)');
                    }
                    balanceChart = new Chart(document.getElementById('balanceLineChart'), {
                        type: 'line',
                        data: {
                            labels: balanceData.dates.length ? balanceData.dates : ['<?php echo $translations[$lang]['no_data']; ?>'],
                            datasets: [{
                                label: '<?php echo $translations[$lang]['balance_trend']; ?>',
                                data: balanceData.balances.length ? balanceData.balances : [0],
                                borderColor: '#36a2eb',
                                fill: false,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { ticks: { font: { size: 10 } } },
                                y: { ticks: { font: { size: 10 } } }
                            },
                            plugins: { legend: { labels: { font: { size: 10 } } } }
                        }
                    });

                    // Update recent transactions
                    $('#recent_transactions').empty();
                    if (!data.recent_transactions || data.recent_transactions.length === 0) {
                        $('#recent_transactions').append('<tr><td colspan="5"><?php echo $translations[$lang]['no_data']; ?></td></tr>');
                        $('#dashboard_error').removeClass('hidden').text('<?php echo $translations[$lang]['no_data']; ?> (Recent Transactions)');
                    } else {
                        data.recent_transactions.forEach(function(tx) {
                            $('#recent_transactions').append(
                                '<tr>' +
                                '<td>' + (tx.account_id || 'N/A') + '</td>' +
                                '<td>' + (tx.dated || 'N/A') + '</td>' +
                                '<td>' + (tx.description || 'N/A') + '</td>' +
                                '<td>' + parseFloat(tx.amount || 0).toFixed(2) + '</td>' +
                                '<td>' + parseFloat(tx.balance || 0).toFixed(2) + '</td>' +
                                '</tr>'
                            );
                        });
                    }

                    $('#dashboard_content').show();
                } else {
                    $('#dashboard_error').removeClass('hidden').text('<?php echo $translations[$lang]['error']; ?> ' + (data.message || 'No data returned.'));
                    resetDashboard();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Dashboard AJAX error:', textStatus, errorThrown, jqXHR.responseText); // Detailed debug log
                $('#dashboard_error').removeClass('hidden').text('<?php echo $translations[$lang]['error']; ?> AJAX request failed: ' + textStatus);
                resetDashboard();
            }
        });
    }

    // Reset dashboard elements
    function resetDashboard() {
        $('#total_expenses').text('0.00');
        $('#total_paid').text('0.00');
        $('#pending_balance').text('0.00');
        $('#transaction_count').text('0');
        $('#recent_transactions').empty().append('<tr><td colspan="5"><?php echo $translations[$lang]['no_data']; ?></td></tr>');
        if (expenseChart) expenseChart.destroy();
        expenseChart = new Chart(document.getElementById('expensePieChart'), {
            type: 'pie',
            data: {
                labels: ['<?php echo $translations[$lang]['no_data']; ?>'],
                datasets: [{ data: [1], backgroundColor: ['#e5e7eb'] }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } } }
        });
        if (balanceChart) balanceChart.destroy();
        balanceChart = new Chart(document.getElementById('balanceLineChart'), {
            type: 'line',
            data: {
                labels: ['<?php echo $translations[$lang]['no_data']; ?>'],
                datasets: [{ label: '<?php echo $translations[$lang]['balance_trend']; ?>', data: [0], borderColor: '#36a2eb', fill: false }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { x: { ticks: { font: { size: 10 } } }, y: { ticks: { font: { size: 10 } } } },
                plugins: { legend: { labels: { font: { size: 10 } } } }
            }
        });
        $('#dashboard_content').show();
        $('#dashboard_error').removeClass('hidden').text('<?php echo $translations[$lang]['no_data']; ?>');
    }

    // Reset account details UI
    function resetAccountUI() {
        $('#account_id').val('');
        $('#account_id_display').text('');
        $('#account_title').text('');
        $('#account_type').text('');
        $('#account_balance').text('');
        $('#account_mobile').text('');
        $('#account_card').hide();
        $('#action_buttons').hide();
        $('#payment_button').prop('disabled', true);
        $('#search_feedback').text('');
    }

    // Trigger search on input blur
    $('#account_search').on('blur', function() {
        performSearch();
    });

    // Trigger search on button click
    $('#search_button').on('click', function() {
        performSearch();
    });

    // Handle reset button click
    $('#reset_button').on('click', function() {
        resetAccountUI();
        updateDashboard(); // Reload dashboard for selected month
    });

    // Handle window resize for chart responsiveness
    $(window).resize(function() {
        if (expenseChart) expenseChart.resize();
        if (balanceChart) balanceChart.resize();
    });
});
</script>

</body>
<?php
// Close database connection if open
if (isset($conn)) {
    $conn->close();
}
?>
</html>