<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'security.php';
require_once 'conn_inc.php';
require_once 'permissions.php';

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_date($d): string {
    if (empty($d) || $d === '0000-00-00') return '';
    try { return (new DateTime($d))->format('d M Y'); } catch (Exception $e) { return (string)$d; }
}

/* ═══════════ REVOKE ═══════════ */
if (isset($_GET['revoke']) && !empty($perms['can_delete'])) {
    $rid = intval($_GET['revoke']);
    if ($rid > 0) {
        $sid = 0;
        $stmt = $conn->prepare("SELECT student_id FROM student_certificates WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $rid);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($r && ($row = $r->fetch_assoc())) $sid = (int)$row['student_id'];
            $stmt->close();
        }
        $stmt = $conn->prepare("UPDATE student_certificates SET status = 1 WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $rid);
            $stmt->execute();
            $stmt->close();
        }
        if ($sid > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM student_certificates WHERE student_id = ? AND status = 0");
            $count = 0;
            if ($stmt) {
                $stmt->bind_param("i", $sid);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($row = $r->fetch_assoc())) $count = (int)$row['c'];
                $stmt->close();
            }
            if ($count === 0) {
                $upd = $conn->prepare("UPDATE student_registration SET status = 0 WHERE id = ?");
                if ($upd) {
                    $upd->bind_param("i", $sid);
                    $upd->execute();
                    $upd->close();
                }
            }
        }
        $_SESSION['message'] = 'Certificate revoked. Student restored to Active.';
        $_SESSION['message_type'] = 'success';
    }
    header('Location: student_leaving_certificate_list.php');
    exit;
}

/* ═══════════ FILTERS ═══════════ */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$year   = isset($_GET['year']) ? trim($_GET['year']) : '';
$sort   = isset($_GET['sort']) ? trim($_GET['sort']) : 'recent';

$where = ['sc.status = 0'];
$params = [];
$types = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(sr.name LIKE ? OR sr.reg_no LIKE ? OR sr.father_name LIKE ? OR sc.cert_no LIKE ?)";
    $types .= 'ssss';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($year !== '' && is_numeric($year)) {
    $where[] = "YEAR(sc.cert_date) = ?";
    $types .= 'i';
    $params[] = intval($year);
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$order_sql = "ORDER BY sc.id DESC";
if ($sort === 'oldest')  $order_sql = "ORDER BY sc.id ASC";
if ($sort === 'name')    $order_sql = "ORDER BY sr.name ASC";
if ($sort === 'cert_no') $order_sql = "ORDER BY sc.cert_no ASC";

$sql = "
    SELECT sc.id, sc.cert_no, sc.cert_date, sc.leaving_date, sc.conduct, sc.issued_by, sc.student_id,
           sr.name AS student_name, sr.reg_no, sr.father_name,
           c.title AS class_title, s.title AS section_title,
           b.title AS branch_title
    FROM student_certificates sc
    LEFT JOIN student_registration sr ON sr.id = sc.student_id
    LEFT JOIN (
        SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id
        FROM student_class sc1
        INNER JOIN (
            SELECT student_registration_id, MAX(id) AS max_id
            FROM student_class GROUP BY student_registration_id
        ) sc2 ON sc1.id = sc2.max_id
    ) cls ON sr.id = cls.student_registration_id
    LEFT JOIN classes c ON cls.class_id = c.id
    LEFT JOIN class_sections cs ON cls.class_section_id = cs.id
    LEFT JOIN sections s ON cs.section_id = s.id
    LEFT JOIN branches b ON sr.branch_id = b.id
    $where_sql
    $order_sql
";
$stmt = $conn->prepare($sql);
$certs = [];
if ($stmt) {
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $certs[] = $row;
    $stmt->close();
}

/* ═══════════ YEARS + STATS ═══════════ */
$years = [];
$res = $conn->query("SELECT DISTINCT YEAR(cert_date) AS y FROM student_certificates WHERE status = 0 AND cert_date IS NOT NULL ORDER BY y DESC");
if ($res) while ($row = $res->fetch_assoc()) { if ($row['y']) $years[] = (int)$row['y']; }

$total = count($certs);
$this_month = 0;
$this_year = 0;
$cm = date('Y-m');
$cy = date('Y');
foreach ($certs as $c) {
    if (substr((string)$c['cert_date'], 0, 7) === $cm) $this_month++;
    if (substr((string)$c['cert_date'], 0, 4) === $cy) $this_year++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaving Certificates</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f6fa;
            color: #0f172a;
            margin: 0;
            padding: 28px 24px;
            font-size: 14px;
            line-height: 1.5;
        }
        .wrap { max-width: 1320px; margin: 0 auto; }

        /* ═════ HEADER ═════ */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-icon {
            width: 52px; height: 52px;
            background: #2563eb;
            color: #fff;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            box-shadow: 0 8px 20px rgba(37,99,235,0.25);
        }
        .header-text h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.4px;
            color: #0f172a;
        }
        .header-text .subtitle {
            font-size: 13px;
            color: #64748b;
            margin-top: 3px;
        }
        .btn-new {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: #2563eb;
            color: #fff;
            padding: 12px 22px;
            border-radius: 11px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            box-shadow: 0 6px 16px rgba(37,99,235,0.28);
            transition: all 0.18s ease;
        }
        .btn-new:hover {
            background: #1d4ed8;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(37,99,235,0.35);
            text-decoration: none;
        }
        .btn-new i { font-size: 12px; }

        /* ═════ ALERT ═════ */
        .flash {
            background: #ecfdf5;
            border-left: 4px solid #10b981;
            color: #065f46;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .flash i { font-size: 16px; }

        /* ═════ STATS ═════ */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat {
            background: #fff;
            border-radius: 14px;
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(15,23,42,0.04), 0 1px 2px rgba(15,23,42,0.06);
            transition: all 0.18s ease;
            border: 1px solid #f1f5f9;
        }
        .stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(15,23,42,0.08);
        }
        .stat-icon {
            width: 52px; height: 52px;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .stat.blue .stat-icon { background: #dbeafe; color: #2563eb; }
        .stat.green .stat-icon { background: #d1fae5; color: #059669; }
        .stat.amber .stat-icon { background: #fef3c7; color: #d97706; }
        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.6px;
            line-height: 1.1;
        }
        .stat-label {
            font-size: 11.5px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            margin-top: 4px;
        }

        /* ═════ FILTER BAR ═════ */
        .filters {
            background: #fff;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: minmax(280px, 1fr) 160px 180px auto;
            gap: 12px;
            align-items: center;
            box-shadow: 0 1px 3px rgba(15,23,42,0.04);
            border: 1px solid #f1f5f9;
        }
        @media (max-width: 1000px) { .filters { grid-template-columns: 1fr; } }

        .search-box { position: relative; }
        .search-box i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
            pointer-events: none;
        }
        .search-box input {
            width: 100%;
            height: 46px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            padding: 0 16px 0 46px;
            font-size: 13.5px;
            font-family: inherit;
            color: #0f172a;
            background: #f9fafb;
            transition: all 0.15s;
        }
        .search-box input::placeholder { color: #94a3b8; }
        .search-box input:focus {
            outline: none;
            background: #fff;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.1);
        }

        .filters select {
            height: 46px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            padding: 0 16px;
            font-size: 13.5px;
            font-family: inherit;
            color: #0f172a;
            background: #f9fafb;
            transition: all 0.15s;
            cursor: pointer;
            font-weight: 500;
        }
        .filters select:focus {
            outline: none;
            background: #fff;
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.1);
        }

        .btn-group { display: flex; gap: 8px; }
        .btn-apply {
            height: 46px;
            padding: 0 24px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-apply:hover { background: #1d4ed8; }
        .btn-reset {
            height: 46px;
            padding: 0 18px;
            background: #f1f5f9;
            color: #475569;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-reset:hover {
            background: #e5e7eb;
            color: #1e293b;
            text-decoration: none;
        }

        /* ═════ TABLE ═════ */
        .table-card {
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(15,23,42,0.04), 0 4px 16px rgba(15,23,42,0.04);
            border: 1px solid #f1f5f9;
        }
        .table-info-bar {
            padding: 16px 24px;
            background: #fafbfc;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-info-bar b { color: #0f172a; font-weight: 700; }

        .table-scroll { overflow-x: auto; }
        table.lc {
            width: 100%;
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 1000px;
        }
        table.lc thead th {
            background: #fafbfc;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 14px 24px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap;
        }
        table.lc tbody td {
            padding: 18px 24px;
            font-size: 13.5px;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle;
            color: #334155;
        }
        table.lc tbody tr:last-child td { border-bottom: none; }
        table.lc tbody tr { transition: background 0.12s; }
        table.lc tbody tr:hover td { background: #fafbfc; }

        .cert-no {
            display: inline-block;
            font-family: ui-monospace, 'SF Mono', Menlo, monospace;
            font-weight: 700;
            font-size: 12.5px;
            color: #2563eb;
            background: #eff6ff;
            padding: 6px 12px;
            border-radius: 8px;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .student-name {
            font-weight: 700;
            color: #0f172a;
            font-size: 14px;
            margin-bottom: 4px;
        }
        .student-sub {
            font-size: 12px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .student-sub .dot {
            width: 3px; height: 3px;
            background: #cbd5e1;
            border-radius: 50%;
            display: inline-block;
        }

        .class-chip {
            display: inline-block;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            padding: 5px 12px;
            border-radius: 8px;
            white-space: nowrap;
        }

        .date { color: #334155; font-weight: 600; font-size: 13px; white-space: nowrap; }
        .date.muted { color: #cbd5e1; font-weight: 500; }

        .conduct {
            display: inline-block;
            padding: 5px 13px;
            border-radius: 20px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .conduct.excellent { background: #d1fae5; color: #065f46; }
        .conduct.very-good { background: #dbeafe; color: #1e40af; }
        .conduct.good      { background: #e0e7ff; color: #3730a3; }
        .conduct.satisfactory { background: #fef3c7; color: #92400e; }
        .conduct.fair      { background: #fee2e2; color: #991b1b; }

        .issued-by { color: #475569; font-weight: 500; font-size: 13px; }

        .actions-cell { text-align: right; white-space: nowrap; }
        .icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px; height: 38px;
            border-radius: 9px;
            border: none;
            background: transparent;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            margin-left: 4px;
        }
        .icon-btn.view { color: #2563eb; background: #eff6ff; }
        .icon-btn.view:hover { background: #2563eb; color: #fff; text-decoration: none; }

        .icon-btn.print { color: #059669; background: #ecfdf5; }
        .icon-btn.print:hover { background: #059669; color: #fff; }

        .icon-btn.danger { color: #dc2626; background: #fef2f2; }
        .icon-btn.danger:hover { background: #dc2626; color: #fff; text-decoration: none; }

        /* ═════ EMPTY ═════ */
        .empty {
            padding: 90px 24px;
            text-align: center;
        }
        .empty-icon {
            width: 88px; height: 88px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 34px;
            color: #cbd5e1;
        }
        .empty h3 {
            font-size: 17px;
            font-weight: 700;
            color: #334155;
            margin: 0 0 8px;
        }
        .empty p {
            font-size: 13.5px;
            color: #94a3b8;
            margin: 0 0 22px;
        }

        #printFrame {
            position: absolute;
            width: 0; height: 0;
            border: 0;
            visibility: hidden;
        }

        @media (max-width: 768px) {
            body { padding: 16px; }
            .header-text h1 { font-size: 18px; }
            .header-icon { width: 44px; height: 44px; font-size: 18px; }
            table.lc thead th,
            table.lc tbody td { padding: 14px 16px; }
        }
    </style>
</head>
<body>
<?php require_once 'navbar.php'; ?>

<div class="wrap">

    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <div class="header-icon"><i class="fas fa-scroll"></i></div>
            <div class="header-text">
                <h1>Leaving Certificates</h1>
                <div class="subtitle">All issued school leaving certificates</div>
            </div>
        </div>
        <a href="student_leaving_certificate.php" class="btn-new">
            <i class="fas fa-plus"></i> Issue New
        </a>
    </div>

    <!-- FLASH -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="flash">
            <i class="fas fa-check-circle"></i>
            <span><?= h($_SESSION['message']) ?></span>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats">
        <div class="stat blue">
            <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="stat-value"><?= number_format($total) ?></div>
                <div class="stat-label">Total Certificates</div>
            </div>
        </div>
        <div class="stat green">
            <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
            <div>
                <div class="stat-value"><?= number_format($this_month) ?></div>
                <div class="stat-label">This Month</div>
            </div>
        </div>
        <div class="stat amber">
            <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
            <div>
                <div class="stat-value"><?= number_format($this_year) ?></div>
                <div class="stat-label">This Year</div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="get" action="" class="filters">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Search by student, reg no, father, or certificate no..."
                   value="<?= h($search) ?>">
        </div>
        <select name="year">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y ?>" <?= ($year !== '' && (int)$year === $y) ? 'selected' : '' ?>><?= (int)$y ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort">
            <option value="recent"  <?= $sort === 'recent'  ? 'selected' : '' ?>>Most Recent</option>
            <option value="oldest"  <?= $sort === 'oldest'  ? 'selected' : '' ?>>Oldest First</option>
            <option value="name"    <?= $sort === 'name'    ? 'selected' : '' ?>>Name (A-Z)</option>
            <option value="cert_no" <?= $sort === 'cert_no' ? 'selected' : '' ?>>Certificate No.</option>
        </select>
        <div class="btn-group">
            <button type="submit" class="btn-apply">
                <i class="fas fa-filter"></i> Apply
            </button>
            <?php if ($search !== '' || $year !== '' || $sort !== 'recent'): ?>
                <a href="student_leaving_certificate_list.php" class="btn-reset">
                    <i class="fas fa-times"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- TABLE -->
    <div class="table-card">

        <?php if ($certs): ?>

        <div class="table-info-bar">
            <div>
                Showing <b><?= count($certs) ?></b> certificate<?= count($certs) === 1 ? '' : 's' ?>
                <?php if ($search !== ''): ?> matching "<b><?= h($search) ?></b>"<?php endif; ?>
                <?php if ($year !== ''): ?> from <b><?= (int)$year ?></b><?php endif; ?>
            </div>
        </div>

        <div class="table-scroll">
            <table class="lc">
                <thead>
                    <tr>
                        <th style="width: 180px;">Certificate No.</th>
                        <th>Student</th>
                        <th style="width: 150px;">Class</th>
                        <th style="width: 130px;">Issued</th>
                        <th style="width: 130px;">Left On</th>
                        <th style="width: 130px;">Conduct</th>
                        <th style="width: 140px;">Issued By</th>
                        <th style="width: 150px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($certs as $c):
                        $class_label = trim(($c['class_title'] ?? '') . (!empty($c['section_title']) ? ' - ' . $c['section_title'] : ''));
                        $conduct_class = strtolower(str_replace(' ', '-', $c['conduct'] ?? 'good'));
                        if (!in_array($conduct_class, ['excellent','very-good','good','satisfactory','fair'])) $conduct_class = 'good';
                    ?>
                    <tr>
                        <td>
                            <span class="cert-no"><?= h($c['cert_no']) ?></span>
                        </td>
                        <td>
                            <div class="student-name"><?= h($c['student_name'] ?: '—') ?></div>
                            <div class="student-sub">
                                <?php if (!empty($c['reg_no'])): ?>
                                    <span>Reg: <?= h($c['reg_no']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($c['father_name']) && !empty($c['reg_no'])): ?>
                                    <span class="dot"></span>
                                <?php endif; ?>
                                <?php if (!empty($c['father_name'])): ?>
                                    <span>S/O <?= h($c['father_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($class_label): ?>
                                <span class="class-chip"><?= h($class_label) ?></span>
                            <?php else: ?>
                                <span class="date muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="date <?= empty($c['cert_date']) ? 'muted' : '' ?>">
                                <?= h(fmt_date($c['cert_date']) ?: '—') ?>
                            </span>
                        </td>
                        <td>
                            <span class="date <?= empty($c['leaving_date']) ? 'muted' : '' ?>">
                                <?= h(fmt_date($c['leaving_date']) ?: '—') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($c['conduct']): ?>
                                <span class="conduct <?= h($conduct_class) ?>"><?= h($c['conduct']) ?></span>
                            <?php else: ?>
                                <span class="date muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="issued-by"><?= h($c['issued_by'] ?: '—') ?></span>
                        </td>
                        <td class="actions-cell">
                            <a href="student_leaving_certificate.php?student_id=<?= (int)$c['student_id'] ?>&go_next=1"
                               class="icon-btn view" title="View / Edit">
                                <i class="fas fa-eye"></i>
                            </a>
                            <button type="button"
                                    class="icon-btn print"
                                    title="Print Certificate"
                                    data-student-id="<?= (int)$c['student_id'] ?>"
                                    onclick="printCertificate(this);">
                                <i class="fas fa-print"></i>
                            </button>
                            <?php if (!empty($perms['can_delete'])): ?>
                            <a href="student_leaving_certificate_list.php?revoke=<?= (int)$c['id'] ?>"
                               class="icon-btn danger" title="Revoke"
                               onclick="return confirm('Revoke this certificate? The student will be restored to Active.');">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>

        <div class="empty">
            <div class="empty-icon"><i class="fas fa-scroll"></i></div>
            <h3>No certificates found</h3>
            <p>
                <?php if ($search !== '' || $year !== ''): ?>
                    Try adjusting your filters or clearing the search.
                <?php else: ?>
                    No leaving certificates have been issued yet.
                <?php endif; ?>
            </p>
            <a href="student_leaving_certificate.php" class="btn-new" style="display:inline-flex;">
                <i class="fas fa-plus"></i> Issue First Certificate
            </a>
        </div>

        <?php endif; ?>

    </div>

</div>

<!-- Hidden iframe used for printing without leaving the page -->
<iframe id="printFrame" name="printFrame" src="about:blank"></iframe>

<script>
$(document).ready(function () {
    // Auto-submit on dropdown change
    $('.filters select').on('change', function () {
        $(this).closest('form').submit();
    });
});

/**
 * Print a certificate inside a hidden iframe — no new tab, no redirect, no double print.
 *
 * Flow:
 *  1. Set iframe src to the certificate URL (WITHOUT autoprint=1, so the
 *     certificate page never self-triggers print — prevents the double dialog).
 *  2. On iframe load, wait a moment for fonts/layout to render.
 *  3. Call iframe.contentWindow.print() — the ONLY print trigger.
 */
var _printBusy = false;

function printCertificate(btn) {
    if (_printBusy) return;
    var sid = btn.getAttribute('data-student-id');
    if (!sid) return;

    var url = 'student_leaving_certificate.php?student_id=' + encodeURIComponent(sid) + '&go_next=1';

    var frame = document.getElementById('printFrame');
    if (!frame) return;

    _printBusy = true;
    frame.onload = null;

    frame.onload = function () {
        try {
            setTimeout(function () {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch (e) {
                    console.error('Print failed:', e);
                }
                // Release the lock after the dialog closes
                _printBusy = false;
            }, 350);
        } catch (e) {
            _printBusy = false;
            console.error('Iframe onload error:', e);
        }
    };

    frame.src = url;
}
</script>
</body>
</html>
<?php $conn->close(); ?>