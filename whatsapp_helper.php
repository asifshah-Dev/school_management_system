<?php
/**
 * whatsapp_helper.php
 * WASenderApi integration for Fee Reminder System
 */

// ============================================================
// 🔑 CONFIGURATION
// ============================================================
// Paste your WASenderApi token below (from https://wasenderapi.com dashboard)
define('WASENDER_API_KEY', '0947d18c8eb599d21a4fbc47656f54f61554426ae3699c083fe44e74e1681e16');

// Your school name (appears in the message)
define('SCHOOL_NAME', 'Dar-e-Arqam School System');

// Default country code (Pakistan = 92). Change for other countries.
define('DEFAULT_COUNTRY_CODE', '92');

// Delay between messages in bulk sends (microseconds). 500000 = 0.5 seconds
define('WHATSAPP_SEND_DELAY_US', 500000);

// ============================================================
// 📤 SEND MESSAGE FUNCTION
// ============================================================

/**
 * Send a WhatsApp message via WASenderApi.
 *
 * @param string $to      Raw phone number (e.g., "03001234567" or "923001234567")
 * @param string $message Message text
 * @return array          ['success'=>bool, 'http_code'=>int, 'response'=>string, 'error'=>string]
 */
function sendWhatsAppMessage($to, $message) {
    $url = 'https://wasenderapi.com/api/send-message';

    // Normalize phone number
    $to = preg_replace('/\D/', '', $to);

    // Handle local numbers starting with 0 (e.g., 03001234567 → 923001234567)
    if (substr($to, 0, 1) === '0') {
        $to = DEFAULT_COUNTRY_CODE . substr($to, 1);
    }
    // If 10 digits and not already starting with country code, prepend it
    elseif (strlen($to) === 10 && substr($to, 0, strlen(DEFAULT_COUNTRY_CODE)) !== DEFAULT_COUNTRY_CODE) {
        $to = DEFAULT_COUNTRY_CODE . $to;
    }

    // Basic validation
    if (strlen($to) < 10 || strlen($to) > 15) {
        return [
            'success'   => false,
            'http_code' => 0,
            'response'  => '',
            'error'     => "Invalid phone number: {$to}",
        ];
    }

    $payload = [
        'to'   => $to,
        'text' => $message,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . WASENDER_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'success'   => false,
            'http_code' => 0,
            'response'  => '',
            'error'     => $curlError,
        ];
    }

    $success = ($httpCode >= 200 && $httpCode < 300);

    return [
        'success'   => $success,
        'http_code' => $httpCode,
        'response'  => $response,
        'error'     => $success ? '' : "HTTP {$httpCode}: " . substr($response, 0, 200),
    ];
}

// ============================================================
// ✍️ BUILD MESSAGE FUNCTION
// ============================================================

/**
 * Build the WhatsApp fee reminder message.
 *
 * @param array  $student       Student data (name, father_name, id, mobile, etc.)
 * @param array  $pendingFees   Array of ['fee_type'=>, 'month'=>, 'amount'=>]
 * @param float  $totalPending  Total pending fees amount
 * @param float  $oldDues       Previous session dues
 * @param float  $totalDue      Grand total
 * @param string $paidUntil     Display text for paid-until month (or null)
 * @param string $classTitle    Class name
 * @param string $sessionTitle  Session name
 * @return string               Formatted WhatsApp message
 */
function buildFeeReminderMessage($student, $pendingFees, $totalPending, $oldDues, $totalDue, $paidUntil, $classTitle, $sessionTitle) {
    $name     = $student['name'];
    $father   = $student['father_name'];
    $studentId = $student['id'];

    $msg  = "Assalam-o-Alaikum,\n\n";
    $msg .= "Dear Parent / Guardian of *{$name}* (S/O {$father}),\n\n";
    $msg .= "This is a friendly reminder regarding outstanding school fees.\n\n";

    $msg .= "📋 *Fee Summary*\n";
    $msg .= "• Student ID: {$studentId}\n";
    $msg .= "• Class: {$classTitle}\n";
    $msg .= "• Session: {$sessionTitle}\n";
    if (!empty($paidUntil)) {
        $msg .= "• Fees Paid Until: {$paidUntil}\n";
    }
    $msg .= "\n";

    $msg .= "💰 *Dues Breakdown*\n";
    if ($oldDues > 0) {
        $msg .= "• Previous Session Dues: " . number_format($oldDues) . " PKR\n";
    }
    $msg .= "• Current Pending Fees: " . number_format($totalPending) . " PKR\n";
    $msg .= "*TOTAL DUE: " . number_format($totalDue) . " PKR*\n\n";

    if (!empty($pendingFees)) {
        $msg .= "📌 *Pending Fee Details:*\n";
        foreach ($pendingFees as $fee) {
            $msg .= "  • {$fee['fee_type']} ({$fee['month']}): " . number_format($fee['amount']) . " PKR\n";
        }
        $msg .= "\n";
    }

    $msg .= "Please clear the dues at your earliest convenience to avoid late fee charges.\n\n";
    $msg .= "JazakAllah Khair,\n";
    $msg .= "*" . SCHOOL_NAME . "*\n";
    $msg .= "📞 0346-9401982";

    return $msg;
}

// ============================================================
// 🧮 CALCULATE STUDENT DUES (extracted from main file)
// ============================================================

/**
 * Calculate a student's pending fees and dues.
 * Returns an array with all computed data.
 */
function calculateStudentDues($conn, $studentId, $sessionId) {
    // Fetch fee cards for this student in this session
    $feeCardsStmt = $conn->prepare(
        "SELECT sfc.id, sfc.total_amount, sfc.due_date,
                DATE_FORMAT(sfc.due_date, '%M %Y') AS month_display,
                DATE_FORMAT(sfc.due_date, '%Y-%m') AS month_key
         FROM student_fee_card sfc
         JOIN student_class sc ON sfc.student_class_id = sc.id
         WHERE sc.student_registration_id = ? AND sc.session_id = ?
         ORDER BY sfc.due_date"
    );
    $feeCardsStmt->bind_param("ii", $studentId, $sessionId);
    $feeCardsStmt->execute();
    $fcResult = $feeCardsStmt->get_result();

    $all_months = [];
    while ($fc = $fcResult->fetch_assoc()) {
        $payStmt = $conn->prepare(
            "SELECT COALESCE(SUM(paid_amount), 0) AS tp,
                    COALESCE(SUM(discount_amount), 0) AS td
             FROM student_fee_payments
             WHERE fee_card_id = ? AND status = 'completed'"
        );
        $payStmt->bind_param("i", $fc['id']);
        $payStmt->execute();
        $p = $payStmt->get_result()->fetch_assoc();
        $cleared = ($p['tp'] ?? 0) + ($p['td'] ?? 0);
        $due     = $fc['total_amount'] - $cleared;

        $mk = $fc['month_key'];
        if (!isset($all_months[$mk])) {
            $all_months[$mk] = [
                'display'       => $fc['month_display'],
                'fees'          => [],
                'is_fully_paid' => true,
            ];
        }
        $all_months[$mk]['fees'][] = [
            'fee_type' => 'Fee',
            'amount'   => max(0, $due),
        ];
        if ($due > 0) {
            $all_months[$mk]['is_fully_paid'] = false;
        }
    }
    ksort($all_months);

    // Find paid-until month (consecutive fully paid months from start)
    $paid_until_key     = null;
    $paid_until_display = null;
    foreach ($all_months as $mk => $m) {
        if ($m['is_fully_paid']) {
            $paid_until_key     = $mk;
            $paid_until_display = $m['display'];
        } else {
            break;
        }
    }

    // Collect pending fees
    $pending_fees   = [];
    $total_pending  = 0;
    $start_collect  = ($paid_until_key === null);

    foreach ($all_months as $mk => $m) {
        if (!$start_collect && $mk > $paid_until_key) {
            $start_collect = true;
        }
        if ($start_collect) {
            foreach ($m['fees'] as $f) {
                if ($f['amount'] > 0) {
                    $pending_fees[] = [
                        'fee_type' => $f['fee_type'],
                        'month'    => $m['display'],
                        'amount'   => $f['amount'],
                    ];
                    $total_pending += $f['amount'];
                }
            }
        }
    }

    return [
        'pending_fees'       => $pending_fees,
        'total_pending'      => $total_pending,
        'paid_until_display' => $paid_until_display,
    ];
}