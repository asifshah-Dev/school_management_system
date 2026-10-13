<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');

require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);

$mode = $_GET['mode'] ?? 'collect';  // 'collect' or 'assign'
if (!in_array($mode, ['collect', 'assign'], true)) $mode = 'collect';

// Optional filters
$search    = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$class_filter = isset($_GET['class_filter']) ? (int)$_GET['class_filter'] : 0;

// Load classes for filter
$classList = $conn->query("
    SELECT id, title FROM classes WHERE status = 0 OR status IS NULL
    ORDER BY title COLLATE utf8mb4_general_ci
")->fetch_all(MYSQLI_ASSOC);

// Build students query
$where = ["sr.status = 0"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(sr.name LIKE ? OR sr.father_name LIKE ? OR sr.reg_no LIKE ? OR sr.mobile LIKE ? OR sr.id = ?)";
    $like = '%' . $search . '%';
    $sid  = (int)$search;
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $sid;
    $types .= "ssssi";
}

if ($class_filter > 0) {
    $where[] = "sc.class_id = ?";
    $params[] = $class_filter;
    $types .= "i";
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT sr.id, sr.name, sr.father_name, sr.mobile, sr.reg_no, sr.status,
           c.title AS class_title, s.title AS section_title, ses.title AS session_title,
           (SELECT COUNT(*) FROM student_fee_card sfc
              INNER JOIN student_class scx ON sfc.student_class_id = scx.id
              WHERE scx.student_registration_id = sr.id AND sfc.status IN ('pending','partial')) AS pending_cards,
           (SELECT COUNT(*) FROM student_fee_assignments sfa
              WHERE sfa.student_id = sr.id AND sfa.status = 1) AS assigned_fees
    FROM student_registration sr
    LEFT JOIN (
        SELECT sc1.student_registration_id, sc1.class_id, sc1.class_section_id, sc1.session_id
        FROM student_class sc1
        INNER JOIN (SELECT student_registration_id, MAX(id) AS max_id FROM student_class GROUP BY student_registration_id) sc2
            ON sc1.id = sc2.max_id
    ) sc ON sr.id = sc.student_registration_id
    LEFT JOIN classes c ON sc.class_id = c.id
    LEFT JOIN class_sections cs ON sc.class_section_id = cs.id
    LEFT JOIN sections s ON cs.section_id = s.id
    LEFT JOIN sessions ses ON sc.session_id = ses.id
    WHERE $whereSql
    ORDER BY sr.name COLLATE utf8mb4_general_ci
    LIMIT 500
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = count($students);
$isAssign = ($mode === 'assign');
$pageTitle = $isAssign ? 'Manage Fee Assignments — Pick a Student' : 'Collect Fees — Pick a Student';
$pageSub   = $isAssign
    ? 'Choose a student to assign or edit their fee structure'
    : 'Choose a student to record a fee payment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', sans-serif; color: #1e293b; }
    .wrap { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 30px 40px 26px 40px;
        border-radius: 12px 12px 0 0;
        display: flex; justify-content: space-between;
        align-items: flex-end; gap: 20px; flex-wrap: wrap;
    }
    .head h1 { font-size: 26px; font-weight: 700; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 6px; }

    .mode-toggle {
        display: inline-flex; background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: 10px; overflow: hidden;
    }
    .mode-toggle a {
        padding: 9px 18px; color: #fff;
        text-decoration: none; font-size: 13px; font-weight: 700;
        transition: background 0.15s;
    }
    .mode-toggle a:hover { background: rgba(255,255,255,0.15); text-decoration: none; color: #fff; }
    .mode-toggle a.active { background: #fff; color: #1e40af; }

    .card { background: #fff; padding: 26px 40px 40px 40px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .toolbar {
        display: flex; gap: 12px; align-items: flex-end;
        padding-bottom: 20px; margin-bottom: 20px;
        border-bottom: 1px solid #e2e8f0; flex-wrap: wrap;
    }
    .toolbar .field { flex: 1; min-width: 200px; max-width: 400px; }
    .toolbar label {
        display: block; font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;
    }
    .toolbar input, .toolbar select {
        width: 100%; height: 42px; padding: 0 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        font-size: 14px; font-family: inherit; background: #fff;
    }
    .toolbar input:focus, .toolbar select:focus {
        outline: none; border-color: #1e40af;
        box-shadow: 0 0 0 3px rgba(30,64,175,0.1);
    }
    .toolbar .actions { display: flex; gap: 8px; }
    .btn-primary {
        display: inline-flex; align-items: center; gap: 8px;
        height: 42px; padding: 0 20px; border-radius: 8px;
        background: #1e40af; color: #fff; border: 1px solid #1e40af;
        font-size: 13px; font-weight: 700; text-decoration: none;
        font-family: inherit; cursor: pointer;
    }
    .btn-primary:hover { background: #1e3a8a; border-color: #1e3a8a; text-decoration: none; color: #fff; }
    .btn-ghost {
        display: inline-flex; align-items: center; gap: 8px;
        height: 42px; padding: 0 18px; border-radius: 8px;
        background: #fff; color: #334155; border: 1px solid #cbd5e1;
        font-size: 13px; font-weight: 600; text-decoration: none;
    }
    .btn-ghost:hover { background: #f1f5f9; text-decoration: none; color: #1e293b; }

    .count {
        padding: 10px 16px; background: #eff6ff; border-radius: 8px;
        font-size: 13px; color: #1e3a8a; margin-bottom: 16px;
        display: flex; justify-content: space-between; align-items: center;
    }
    .count strong { font-family: 'SF Mono', monospace; }

    .tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; }
    table.students {
        width: 100%; border-collapse: collapse; background: #fff;
        min-width: 1000px;
    }
    table.students thead th {
        background: #f8fafc; font-size: 11px; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 12px 14px; text-align: left;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    table.students tbody td {
        padding: 12px 14px; font-size: 13px;
        border-bottom: 1px solid #f1f5f9; vertical-align: middle;
    }
    table.students tbody tr:hover td { background: #f8fafc; }

    .id-cell { font-family: 'SF Mono', monospace; font-weight: 700; color: #1e40af; }
    .name-cell { font-weight: 700; color: #0f172a; }
    .meta-cell { font-size: 11px; color: #94a3b8; }

    .pill {
        display: inline-block; padding: 3px 10px; border-radius: 12px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .pill.pending { background: #fee2e2; color: #991b1b; }
    .pill.ok      { background: #d1fae5; color: #065f46; }
    .pill.neutral { background: #f1f5f9; color: #475569; }

    .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .rb {
        display: inline-flex; align-items: center; gap: 5px;
        height: 34px; padding: 0 14px; border-radius: 8px;
        border: 1px solid; font-size: 12px; font-weight: 700;
        text-decoration: none; cursor: pointer; font-family: inherit;
        transition: all 0.15s;
    }
    .rb-collect { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .rb-collect:hover { background: #a7f3d0; text-decoration: none; color: #065f46; }
    .rb-assign  { background: #ede9fe; color: #5b21b6; border-color: #ddd6fe; }
    .rb-assign:hover { background: #ddd6fe; text-decoration: none; color: #5b21b6; }
    .rb-primary { background: #1e40af; color: #fff; border-color: #1e40af; }
    .rb-primary:hover { background: #1e3a8a; text-decoration: none; color: #fff; }
    .rb-dim { opacity: 0.55; }

    .empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
    .empty .big { font-size: 44px; opacity: 0.4; margin-bottom: 12px; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <div>
            <h1><i class="fas fa-user-check" style="opacity:0.8;"></i> <?= htmlspecialchars($pageTitle) ?></h1>
            <div class="sub"><?= htmlspecialchars($pageSub) ?></div>
        </div>
        <div class="mode-toggle">
            <a href="?mode=collect" class="<?= $isAssign ? '' : 'active' ?>">
                <i class="fas fa-hand-holding-usd"></i> Collect Fees
            </a>
            <a href="?mode=assign" class="<?= $isAssign ? 'active' : '' ?>">
                <i class="fas fa-tasks"></i> Fee Assignments
            </a>
        </div>
    </div>

    <div class="card">

        <form method="get" class="toolbar">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
            <div class="field">
                <label>Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Name, father, reg no, mobile, or ID">
            </div>
            <div class="field">
                <label>Class</label>
                <select name="class_filter">
                    <option value="0">All Classes</option>
                    <?php foreach ($classList as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $class_filter === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actions">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if ($search !== '' || $class_filter > 0): ?>
                    <a href="?mode=<?= htmlspecialchars($mode) ?>" class="btn-ghost">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <div class="count">
            <span><i class="fas fa-users"></i> <strong><?= $total ?></strong> student<?= $total === 1 ? '' : 's' ?> found</span>
            <?php if ($mode === 'collect'): ?>
                <span style="font-size:12px;">Click <strong>Collect Fee</strong> on any row</span>
            <?php else: ?>
                <span style="font-size:12px;">Click <strong>Assign Fee</strong> on any row</span>
            <?php endif; ?>
        </div>

        <?php if (empty($students)): ?>
            <div class="empty">
                <div class="big"><i class="fas fa-user-slash"></i></div>
                <div style="font-size:16px;color:#475569;font-weight:600;">No students match your search</div>
                <div style="margin-top:8px;">Try a different name, class, or clear the filters.</div>
            </div>
        <?php else: ?>
            <div class="tbl-wrap">
                <table class="students">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th style="width:70px;">ID</th>
                            <th>Student</th>
                            <th style="width:130px;">Class</th>
                            <th style="width:110px;">Session</th>
                            <th style="width:110px;">Reg No</th>
                            <th style="width:140px;">Fee Status</th>
                            <th style="width:280px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($students as $s):
                            $hasPending = (int)$s['pending_cards'] > 0;
                            $hasAssigned = (int)$s['assigned_fees'] > 0;
                        ?>
                        <tr>
                            <td class="meta-cell"><?= $i++ ?></td>
                            <td class="id-cell"><?= (int)$s['id'] ?></td>
                            <td>
                                <div class="name-cell"><?= htmlspecialchars($s['name']) ?></div>
                                <div class="meta-cell">
                                    s/o <?= htmlspecialchars($s['father_name'] ?: '—') ?>
                                    <?php if (!empty($s['mobile'])): ?>
                                        · <?= htmlspecialchars($s['mobile']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($s['class_title'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($s['session_title'] ?: '—') ?></td>
                            <td class="meta-cell"><?= htmlspecialchars($s['reg_no']) ?></td>
                            <td>
                                <?php if ($hasPending): ?>
                                    <span class="pill pending"><?= (int)$s['pending_cards'] ?> due</span>
                                <?php else: ?>
                                    <span class="pill ok">Clear</span>
                                <?php endif; ?>
                                <div class="meta-cell" style="margin-top:4px;">
                                    <?= (int)$s['assigned_fees'] ?> assigned
                                </div>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a href="fee_collection.php?id=<?= (int)$s['id'] ?>"
                                       class="rb rb-collect <?= $mode === 'assign' ? 'rb-dim' : '' ?>">
                                        <i class="fas fa-hand-holding-usd"></i> Collect Fee
                                    </a>
                                    <a href="student_fee_assignment.php?id=<?= (int)$s['id'] ?>"
                                       class="rb rb-assign <?= $mode === 'collect' ? 'rb-dim' : '' ?>">
                                        <i class="fas fa-tasks"></i> Assign Fee
                                    </a>
                                    <a href="student_registration.php?edit=<?= (int)$s['id'] ?>"
                                       class="rb rb-primary" title="Edit student">
                                        <i class="fas fa-user-edit"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>