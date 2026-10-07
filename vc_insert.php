<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set memory and timeout limits
ini_set('memory_limit', '256M');
set_time_limit(60);

// Set default charset for multibyte functions
mb_internal_encoding('UTF-8');

// Log all errors to debug.log
ini_set('log_errors', 1);
ini_set('error_log', 'debug.log');

try {
    // Include connection file
    require_once('conn_inc.php');

    // Verify connection object
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection not established in conn_inc.php");
    }

    // Set UTF-8 encoding for connection (use utf8mb4 for full Unicode support)
    if (!mysqli_set_charset($conn, 'utf8mb4')) {
        throw new Exception("Failed to set UTF-8 charset: " . mysqli_error($conn));
    }

    // Full list of 152 Urdu titles
    $titles = [
        'مٹہ', 'سمبٹ', 'بیدارہ', 'خرہ رائی', 'دیٹپنائی', 'چپریال', 'ٹوٹکے',
        'بماخیلہ', 'بوڈیگرام، مٹہ', 'پرکلے', 'خوازہ خیلہ', 'مناپتی', 'تحصیل مدین',
        'تحصیل کبل', 'تحصیل خوازہ خیلہ', 'شنگلہ', 'تحصیل کالام', 'باغ دیرائی', 'بحرین',
        'چار باغ', 'سخرا', 'درمائی', 'لالکو', 'راہت کوٹ', 'کالاکوٹ', 'اشارے', 'دروشخیلہ',
        'رورینگر', 'دیران پٹے', 'میاں کلے', 'شکرڈارہ', 'مندل', 'برطانہ', 'بیہا', 'شوار',
        'گرا', 'سیج بانڈ', 'ننگولائی', 'شیرپلام', 'گوالیرئی', 'سر بانڈہ', 'وئینائی',
        'بیاکانڈ', 'باندی', 'کانجو', 'ارکوٹ', 'اغل', 'سنپورہ', 'بریام', 'لباط', 'رونیال',
        'نمال', 'نیلاگرام', 'نمال، شوار', 'نزارآباد', 'جرا', 'پاراو', 'شخدڑہ', 'ٹنگر',
        'مندول', 'سردان', 'مین کلے', 'میاں کلے، بیاکانڈ', 'پشٹونی', 'غرائی، چپریال',
        'گجر بانڑ، غرائی', 'عویشہ، چپریال', 'بکور، برطانہ', 'کنالہ، اغل', 'کاراکل، اغل',
        'جانا، برطانہ', 'بہادر بانڈہ، برطانہ', 'برا بانڈہ', 'کوزہ بانڈہ', 'کوزہ دروشخیلہ',
        'بجورہ، بیدارہ', 'دروشخیلہ', 'درمائی', 'کالاکوٹ', 'منگاؤرہ', 'سواتائی، بیہا',
        'فضل بانڈہ، بیہا', 'دوغالگو، بیہا', 'چارٹیکال، بیہا', 'بلکرائی، چپریال', 'شنگواتائی',
        'گرا', 'بماخیلہ', 'نوکھارہ', 'شینر', 'بحرین', 'تحصیل خوازہ خیلہ', 'میاں ڈیم',
        'فاتح پور', 'الپورائی', 'بنیر', 'منگلور', 'سیدو شریف', 'دیرائی، کانجو',
        'پاراو، برطانہ', 'کوارائی', 'جلالہ، اشارے', 'چنالہ، دروشخیلہ', 'گالشہ، چنالہ، دروشخیلہ',
        'قندیل مدین', 'عالم گنج خوازہ خیلہ', 'اسالہ، کوٹنائی', 'ٹنگ بانڑ، گرا', 'مندور، خرہ رائی',
        'خرہ رائی، مٹہ', 'گیگا، گرا', 'نزارآباد', 'بالاسور، چپریال', 'دندارائی، چپریال',
        'میاندام، خوازہ خیلہ', 'کوز شیرپلام', 'کمالے، چپریال', 'شیرپلام', 'برا دروشخیلہ',
        'لیل بانڑ، اغل', 'علیگرام، شوار', 'شوار', 'کوز شوار', 'سمبٹ چم، مٹہ', 'خرہ رائی',
        'خوارائی چم، شخدڑہ', 'کالج چوک، مٹہ', 'معصوم شہید، مٹہ', 'شاہدان، مٹہ', 'مدینہ کالونی، مٹہ',
        'گمسیر، مندل', 'سلطانڈ، مندل', 'گبین جبا، لالکو', 'گبرال، کالام', 'میاں میرا، رونیال',
        'جربندہ، اغل', 'نالکوٹ', 'سگرام، شوار', 'شیدالہ، شکرڈارہ', 'مدین', '   ', '',
        'پیوچار، شوار', 'نیو کالونی، مٹہ', '.............', '', 'بوڈیگرام', 'پاراو', 'نمال', ''
    ];

    // Clean and validate titles before insertion
    $cleaned_titles = [];
    foreach ($titles as $index => $title) {
        // Trim whitespace
        $title = trim($title);
        
        // Skip empty strings
        if (empty($title)) {
            continue;
        }
        
        // Convert to UTF-8 if not already
        if (!mb_check_encoding($title, 'UTF-8')) {
            $title = mb_convert_encoding($title, 'UTF-8', 'auto');
        }
        
        $cleaned_titles[] = $title;
    }

    // Start transaction for batch insert
    mysqli_begin_transaction($conn);

    // Prepare the INSERT statement
    $query = "INSERT INTO village_councils (title) VALUES (?)";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . mysqli_error($conn));
    }

    // Bind and execute for each title
    foreach ($cleaned_titles as $index => $title) {
        mysqli_stmt_bind_param($stmt, "s", $title);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Execute failed for title at index $index: " . mysqli_stmt_error($stmt));
        }
    }

    mysqli_stmt_close($stmt);
    mysqli_commit($conn);
    
    echo count($cleaned_titles) . " Urdu titles inserted successfully.";
    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - " . count($cleaned_titles) . " Urdu titles inserted successfully\n", FILE_APPEND);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        mysqli_rollback($conn);
    }
    $error = "Error: " . $e->getMessage();
    echo $error;
    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - " . $error . "\n", FILE_APPEND);
} finally {
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>