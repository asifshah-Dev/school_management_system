<?php
ob_start();
require_once('security.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once('conn_inc.php');

$can_add    = !empty($perms['can_add']);
$can_edit   = !empty($perms['can_edit']);
$can_delete = !empty($perms['can_delete']);

function h($v) { return htmlspecialchars((string)(isset($v) ? $v : ''), ENT_QUOTES, 'UTF-8'); }

$has_classes_status  = false;
$has_sections_status = false;
$chk = $conn->query("SHOW COLUMNS FROM classes LIKE 'status'");
if ($chk && $chk->num_rows > 0) $has_classes_status = true;
$chk = $conn->query("SHOW COLUMNS FROM sections LIKE 'status'");
if ($chk && $chk->num_rows > 0) $has_sections_status = true;

$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['classes','sections','assignments']) ? $_GET['tab'] : 'classes';

/* ============================================================
   HELPERS
   ============================================================ */
function render_classes_rows($conn, $search) {
    $has_status = false;
    $chk = $conn->query("SHOW COLUMNS FROM classes LIKE 'status'");
    if ($chk && $chk->num_rows > 0) $has_status = true;

    $where = []; $params = []; $types = '';
    if ($has_status) $where[] = "(c.status = 0 OR c.status IS NULL)";
    if ($search !== '') { $where[] = "c.title LIKE ?"; $params[] = '%' . $search . '%'; $types .= 's'; }
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT c.id, c.title, c.dated, (SELECT COUNT(*) FROM class_sections cs WHERE cs.class_id = c.id AND cs.status = 0) AS section_count FROM classes c $where_sql ORDER BY CAST(REGEXP_REPLACE(c.title, '[^0-9]', '') AS UNSIGNED) ASC, c.title ASC";
    $stmt = $conn->prepare($sql);
    $rows = [];
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        $stmt->close();
    }
    return $rows;
}

function render_sections_rows($conn, $search) {
    $has_status = false;
    $chk = $conn->query("SHOW COLUMNS FROM sections LIKE 'status'");
    if ($chk && $chk->num_rows > 0) $has_status = true;

    $where = []; $params = []; $types = '';
    if ($has_status) $where[] = "(s.status = 0 OR s.status IS NULL)";
    if ($search !== '') { $where[] = "s.title LIKE ?"; $params[] = '%' . $search . '%'; $types .= 's'; }
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT s.id, s.title, (SELECT COUNT(*) FROM class_sections cs WHERE cs.section_id = s.id AND cs.status = 0) AS class_count FROM sections s $where_sql ORDER BY s.title";
    $stmt = $conn->prepare($sql);
    $rows = [];
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        $stmt->close();
    }
    return $rows;
}

function render_assignments_rows($conn, $search, $session_filter, $page, $per_page = 20) {
    $where  = ["cs.status = 0"];
    $params = [];
    $types  = '';

    if ($search !== '') {
        $where[] = "(c.title LIKE ? OR s.title LIKE ? OR ses.title LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'sss';
    }
    if ($session_filter > 0) {
        $where[] = "cs.session_id = ?";
        $params[] = $session_filter;
        $types .= 'i';
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $total_rows = 0;
    $sql = "SELECT COUNT(*) AS c FROM class_sections cs LEFT JOIN classes c ON c.id = cs.class_id LEFT JOIN sections s ON s.id = cs.section_id LEFT JOIN sessions ses ON ses.id = cs.session_id $where_sql";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $total_rows = (int)($r['c'] ?? 0);
        $stmt->close();
    }
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $rows = [];
    $sql = "SELECT cs.id, cs.class_id, cs.section_id, cs.session_id, cs.coordinator_id, cs.max_students, c.title AS class_title, s.title AS section_title, ses.title AS session_title, u.username AS coordinator_name FROM class_sections cs LEFT JOIN classes c ON c.id = cs.class_id LEFT JOIN sections s ON s.id = cs.section_id LEFT JOIN sessions ses ON ses.id = cs.session_id LEFT JOIN users u ON u.id = cs.coordinator_id $where_sql ORDER BY cs.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $bind_types = $types . 'ii';
        $bind_params = $params;
        $bind_params[] = $per_page;
        $bind_params[] = $offset;
        $stmt->bind_param($bind_types, ...$bind_params);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $cnt = 0;
            $st2 = $conn->prepare("SELECT COUNT(DISTINCT student_registration_id) AS c FROM student_class WHERE class_id = ?");
            if ($st2) {
                $cid = (int)$row['class_id'];
                $st2->bind_param("i", $cid);
                $st2->execute();
                $r2 = $st2->get_result()->fetch_assoc();
                $cnt = (int)($r2['c'] ?? 0);
                $st2->close();
            }
            $row['enrolled_count'] = $cnt;

            $tc = 0;
            $st3 = $conn->prepare("SELECT COUNT(*) AS c FROM teacher_classes WHERE class_id = ? AND ((class_section_id = ?) OR (class_section_id IS NULL AND ? = 0))");
            if ($st3) {
                $row_cid = (int)$row['class_id'];
                $row_csid = (int)$row['id'];
                $st3->bind_param("iii", $row_cid, $row_csid, $row_csid);
                $st3->execute();
                $r3 = $st3->get_result()->fetch_assoc();
                $tc = (int)($r3['c'] ?? 0);
                $st3->close();
            }
            $row['teacher_count'] = $tc;
            $rows[] = $row;
        }
        $stmt->close();
    }

    return [
        'rows' => $rows, 'total_rows' => $total_rows, 'total_pages' => $total_pages,
        'page' => $page, 'offset' => $offset, 'per_page' => $per_page,
    ];
}

/* ============================================================
   AJAX ENDPOINTS
   ============================================================ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    ob_clean();

    if ($_GET['ajax'] === 'list_classes') {
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $rows = render_classes_rows($conn, $search);

        $html = '';
        if (!empty($rows)) {
            $i = 1;
            foreach ($rows as $r) {
                $row_dated = $r['dated'];
                $dated_display = '&mdash;';
                if (!empty($row_dated) && $row_dated !== '0000-00-00') {
                    $ts = strtotime($row_dated);
                    if ($ts) $dated_display = date('d M Y', $ts);
                }
                $has_sections = (int)$r['section_count'] > 0;

                $html .= '<tr>';
                $html .= '<td data-label="#"><span class="id-badge">' . $i++ . '</span></td>';
                $html .= '<td data-label="Title"><div class="class-title">' . h($r['title']) . '</div></td>';
                $html .= '<td data-label="Sections"><span class="section-pill ' . ($has_sections ? 'has' : '') . '"><i class="fas fa-layer-group"></i> ' . (int)$r['section_count'] . '</span></td>';
                $html .= '<td data-label="Added">' . $dated_display . '</td>';
                $html .= '<td data-label="Actions" style="text-align:right; white-space:nowrap;">';
                if (!empty($perms['can_edit'])) {
                    $html .= '<a href="create_timetable.php?class_id=' . (int)$r['id'] . '" class="icon-btn timetable" title="Timetable"><i class="fas fa-table"></i></a>';
                    $html .= '<a href="?tab=classes&edit_class=' . (int)$r['id'] . '" class="icon-btn edit" title="Edit"><i class="fas fa-pen"></i></a>';
                }
                if (!empty($perms['can_delete'])) {
                    $html .= '<form method="post" action="" style="display:inline;" onsubmit="return confirm(\'Delete this class?\');">';
                    $html .= '<input type="hidden" name="form" value="class">';
                    $html .= '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
                    $html .= '<input type="hidden" name="action" value="delete">';
                    $html .= '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
                    $html .= '<button type="submit" class="icon-btn delete" title="Delete"><i class="fas fa-trash-alt"></i></button>';
                    $html .= '</form>';
                }
                $html .= '</td></tr>';
            }
        } else {
            $html = '<tr><td colspan="5"><div class="empty-state"><div class="big"><i class="fas fa-search"></i></div><h5>No matches</h5><p>Try a different search.</p></div></td></tr>';
        }
        echo json_encode(['ok' => true, 'count' => count($rows), 'html' => $html]);
        exit;
    }

    if ($_GET['ajax'] === 'list_sections') {
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $rows = render_sections_rows($conn, $search);

        $html = '';
        if (!empty($rows)) {
            $i = 1;
            foreach ($rows as $r) {
                $used = (int)$r['class_count'] > 0;
                $html .= '<tr>';
                $html .= '<td data-label="#"><span class="id-badge">' . $i++ . '</span></td>';
                $html .= '<td data-label="Title"><div class="class-title">' . h($r['title']) . '</div></td>';
                $html .= '<td data-label="Used in Classes">';
                $html .= $used ? '<span class="section-pill has"><i class="fas fa-check"></i> ' . (int)$r['class_count'] . ' class(es)</span>' : '<span class="section-pill">unused</span>';
                $html .= '</td>';
                $html .= '<td data-label="Actions" style="text-align:right; white-space:nowrap;">';
                if (!empty($perms['can_edit'])) {
                    $html .= '<a href="?tab=sections&edit_section=' . (int)$r['id'] . '" class="icon-btn edit" title="Edit"><i class="fas fa-pen"></i></a>';
                }
                if (!empty($perms['can_delete'])) {
                    $html .= '<form method="post" action="" style="display:inline;" onsubmit="return confirm(\'Delete this section?\');">';
                    $html .= '<input type="hidden" name="form" value="section">';
                    $html .= '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
                    $html .= '<input type="hidden" name="action" value="delete">';
                    $html .= '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
                    $html .= '<button type="submit" class="icon-btn delete" title="Delete"><i class="fas fa-trash-alt"></i></button>';
                    $html .= '</form>';
                }
                $html .= '</td></tr>';
            }
        } else {
            $html = '<tr><td colspan="4"><div class="empty-state"><div class="big"><i class="fas fa-search"></i></div><h5>No matches</h5><p>Try a different search.</p></div></td></tr>';
        }
        echo json_encode(['ok' => true, 'count' => count($rows), 'html' => $html]);
        exit;
    }

    if ($_GET['ajax'] === 'list_assignments') {
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $session_filter = isset($_GET['session_filter']) ? (int)$_GET['session_filter'] : 0;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

        $data = render_assignments_rows($conn, $search, $session_filter, $page);

        $html = '';
        if (!empty($data['rows'])) {
            $counter = $data['offset'] + 1;
            foreach ($data['rows'] as $r) {
                $max = (int)$r['max_students'];
                $enrolled = (int)$r['enrolled_count'];
                $tcount = (int)$r['teacher_count'];

                $html .= '<tr>';
                $html .= '<td data-label="#">' . $counter++ . '</td>';
                $html .= '<td data-label="Session"><span class="session-chip">' . h($r['session_title'] ?: '—') . '</span></td>';
                $html .= '<td data-label="Class"><span class="class-chip">' . h($r['class_title'] ?: '—') . '</span></td>';
                $html .= '<td data-label="Section"><span class="section-pill has">' . h($r['section_title'] ?: '—') . '</span></td>';
                $html .= '<td data-label="Coordinator">';
                if (!empty($r['coordinator_name'])) $html .= '<span class="coordinator">' . h($r['coordinator_name']) . '</span>';
                else $html .= '<span class="coordinator unassigned">Unassigned</span>';
                $html .= '</td>';
                $html .= '<td data-label="Enrolled / Max"><div class="capacity"><span class="num">' . $enrolled . '</span><span class="sep">/</span><span class="max">' . ($max > 0 ? $max : '&infin;') . '</span></div></td>';
                $html .= '<td data-label="Teachers"><span class="teacher-count-pill ' . ($tcount === 0 ? 'zero' : '') . '"><i class="fas fa-user-tie"></i> ' . $tcount . '</span></td>';
                $html .= '<td data-label="Actions" style="text-align:right; white-space:nowrap;">';
                if (!empty($perms['can_edit'])) {
                    $tt_url = 'create_timetable.php?session_id=' . (int)$r['session_id'] . '&class_id=' . (int)$r['class_id'] . '&class_section_id=' . (int)$r['id'];
                    $html .= '<a href="' . $tt_url . '" class="icon-btn timetable" title="Timetable"><i class="fas fa-table"></i></a>';
                    $html .= '<button type="button" class="icon-btn assign btn-assign-teachers"';
                    $html .= ' data-class-id="' . (int)$r['class_id'] . '"';
                    $html .= ' data-class-section-id="' . (int)$r['id'] . '"';
                    $html .= ' data-label="' . h($r['class_title'] . ' - ' . $r['section_title'] . ' (' . $r['session_title'] . ')') . '"';
                    $html .= ' title="Assign Teachers"><i class="fas fa-user-plus"></i></button>';
                    $html .= '<a href="?tab=assignments&edit_assignment=' . (int)$r['id'] . '" class="icon-btn edit" title="Edit"><i class="fas fa-pen"></i></a>';
                }
                if (!empty($perms['can_delete'])) {
                    $html .= '<a href="?tab=assignments&delete_assignment=' . (int)$r['id'] . '" class="icon-btn delete" title="Delete" onclick="return confirm(\'Delete this assignment?\');"><i class="fas fa-trash-alt"></i></a>';
                }
                $html .= '</td></tr>';
            }
        } else {
            $html = '<tr><td colspan="8"><div class="empty-state"><div class="big"><i class="fas fa-search"></i></div><h5>No matches</h5><p>Try a different search or filter.</p></div></td></tr>';
        }

        $pagination_html = '';
        if ($data['total_pages'] > 1) {
            $page = $data['page'];
            $total_pages = $data['total_pages'];
            $offset = $data['offset'];
            $per_page = $data['per_page'];
            $total_rows = $data['total_rows'];

            $pagination_html .= '<div class="pagination-bar">';
            $pagination_html .= '<div class="pagination-info">Showing <b>' . number_format($offset + 1) . '</b>&ndash;<b>' . number_format(min($offset + $per_page, $total_rows)) . '</b> of <b>' . number_format($total_rows) . '</b></div>';
            $pagination_html .= '<div class="pagination">';
            if ($page > 1) $pagination_html .= '<a href="javascript:void(0);" class="page-link" data-page="' . ($page - 1) . '"><i class="fas fa-chevron-left"></i></a>';
            $range = 2;
            $start = max(1, $page - $range);
            $end   = min($total_pages, $page + $range);
            if ($start > 1) {
                $pagination_html .= '<a href="javascript:void(0);" class="page-link" data-page="1">1</a>';
                if ($start > 2) $pagination_html .= '<span class="dots">...</span>';
            }
            for ($i = $start; $i <= $end; $i++) {
                if ($i === $page) $pagination_html .= '<span class="current">' . $i . '</span>';
                else $pagination_html .= '<a href="javascript:void(0);" class="page-link" data-page="' . $i . '">' . $i . '</a>';
            }
            if ($end < $total_pages) {
                if ($end < $total_pages - 1) $pagination_html .= '<span class="dots">...</span>';
                $pagination_html .= '<a href="javascript:void(0);" class="page-link" data-page="' . $total_pages . '">' . $total_pages . '</a>';
            }
            if ($page < $total_pages) $pagination_html .= '<a href="javascript:void(0);" class="page-link" data-page="' . ($page + 1) . '"><i class="fas fa-chevron-right"></i></a>';
            $pagination_html .= '</div></div>';
        }

        echo json_encode([
            'ok' => true, 'count' => count($data['rows']), 'total_rows' => $data['total_rows'],
            'html' => $html, 'pagination_html' => $pagination_html
        ]);
        exit;
    }

    if ($_GET['ajax'] === 'load_teachers') {
        $class_id         = (int)($_GET['class_id'] ?? 0);
        $class_section_id = (int)($_GET['class_section_id'] ?? 0);
        if ($class_id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid class']); exit; }

        $assigned = [];
        if ($class_section_id > 0) {
            $stmt = $conn->prepare("SELECT teacher_id FROM teacher_classes WHERE class_section_id = ?");
            $stmt->bind_param("i", $class_section_id);
        } else {
            $stmt = $conn->prepare("SELECT teacher_id FROM teacher_classes WHERE class_id = ? AND class_section_id IS NULL");
            $stmt->bind_param("i", $class_id);
        }
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $assigned[] = (int)$row['teacher_id'];
        $stmt->close();

        $teachers = [];
        $q = $conn->query("SELECT id, username, email FROM users WHERE role_id = 3 AND status = 0 ORDER BY username COLLATE utf8mb4_general_ci");
        if ($q) { while ($row = $q->fetch_assoc()) $teachers[] = $row; $q->close(); }

        $load = [];
        $q2 = $conn->query("SELECT teacher_id, COUNT(DISTINCT COALESCE(class_section_id, class_id * -1)) AS cnt FROM teacher_classes GROUP BY teacher_id");
        if ($q2) { while ($row = $q2->fetch_assoc()) $load[(int)$row['teacher_id']] = (int)$row['cnt']; $q2->close(); }

        foreach ($teachers as &$t) {
            $tid = (int)$t['id'];
            $t['id'] = $tid;
            $t['load'] = $load[$tid] ?? 0;
            $t['checked'] = in_array($tid, $assigned, true);
        }
        unset($t);
        echo json_encode(['ok' => true, 'teachers' => $teachers]);
        exit;
    }

    if ($_GET['ajax'] === 'assign_teachers') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        $class_id         = (int)($_POST['class_id'] ?? 0);
        $class_section_id = (int)($_POST['class_section_id'] ?? 0);
        $teacher_ids      = isset($_POST['teacher_ids']) && is_array($_POST['teacher_ids']) ? array_map('intval', $_POST['teacher_ids']) : [];

        if ($class_id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid class']); exit; }

        $conn->begin_transaction();
        try {
            if ($class_section_id > 0) {
                $del = $conn->prepare("DELETE FROM teacher_classes WHERE class_section_id = ?");
                $del->bind_param("i", $class_section_id);
            } else {
                $del = $conn->prepare("DELETE FROM teacher_classes WHERE class_id = ? AND class_section_id IS NULL");
                $del->bind_param("i", $class_id);
            }
            $del->execute(); $del->close();

            if (!empty($teacher_ids)) {
                $ins = $conn->prepare("INSERT INTO teacher_classes (teacher_id, class_id, class_section_id) VALUES (?, ?, ?)");
                $cs_param = $class_section_id > 0 ? $class_section_id : null;
                foreach ($teacher_ids as $tid) {
                    if ($tid <= 0) continue;
                    $ins->bind_param("iii", $tid, $class_id, $cs_param);
                    $ins->execute();
                }
                $ins->close();
            }
            $conn->commit();
            echo json_encode(['ok' => true, 'count' => count($teacher_ids)]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown endpoint']);
    exit;
}

/* ============================================================
   POST — CLASSES
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'class') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.'; $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=classes'); exit;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $title  = trim($_POST['title'] ?? '');
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 0;

    if ($action === 'delete') {
        if (!$can_delete) { $_SESSION['message'] = 'No permission.'; $_SESSION['message_type'] = 'danger'; }
        elseif ($id <= 0) { $_SESSION['message'] = 'Invalid class.'; $_SESSION['message_type'] = 'danger'; }
        else {
            $ref = 0;
            $chk = $conn->prepare("SELECT COUNT(*) AS c FROM class_sections WHERE class_id = ? AND status = 0");
            if ($chk) {
                $chk->bind_param("i", $id); $chk->execute();
                $r = $chk->get_result()->fetch_assoc();
                $ref = (int)($r['c'] ?? 0); $chk->close();
            }
            if ($ref > 0) {
                $_SESSION['message'] = 'Cannot delete: linked to ' . $ref . ' active class-section(s).'; $_SESSION['message_type'] = 'danger';
            } elseif ($has_classes_status) {
                $stmt = $conn->prepare("UPDATE classes SET status = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) { $_SESSION['message'] = 'Class deleted.'; $_SESSION['message_type'] = 'success'; }
                else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) { $_SESSION['message'] = 'Class deleted.'; $_SESSION['message_type'] = 'success'; }
                else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
                $stmt->close();
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=classes'); exit;
    }

    if ($title === '') {
        $_SESSION['message'] = 'Class title required.'; $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=classes'); exit;
    }

    if ($id > 0) {
        if (!$can_edit) { $_SESSION['message'] = 'No permission.'; $_SESSION['message_type'] = 'danger'; }
        elseif ($has_classes_status) {
            $stmt = $conn->prepare("UPDATE classes SET title = ?, status = ? WHERE id = ?");
            $stmt->bind_param("sii", $title, $status, $id);
            if ($stmt->execute()) { $_SESSION['message'] = 'Class updated.'; $_SESSION['message_type'] = 'success'; }
            else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE classes SET title = ? WHERE id = ?");
            $stmt->bind_param("si", $title, $id);
            if ($stmt->execute()) { $_SESSION['message'] = 'Class updated.'; $_SESSION['message_type'] = 'success'; }
            else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
            $stmt->close();
        }
    } else {
        if (!$can_add) { $_SESSION['message'] = 'No permission.'; $_SESSION['message_type'] = 'danger'; }
        else {
            $today = date('Y-m-d');
            if ($has_classes_status) {
                $stmt = $conn->prepare("INSERT INTO classes (title, dated, status) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $title, $today, $status);
            } else {
                $stmt = $conn->prepare("INSERT INTO classes (title, dated) VALUES (?, ?)");
                $stmt->bind_param("ss", $title, $today);
            }
            if ($stmt->execute()) { $_SESSION['message'] = 'Class added.'; $_SESSION['message_type'] = 'success'; }
            else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
            $stmt->close();
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=classes'); exit;
}

/* ============================================================
   POST — SECTIONS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'section') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.'; $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=sections'); exit;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $title  = trim($_POST['title'] ?? '');

    if ($action === 'delete') {
        if (!$can_delete) { $_SESSION['message'] = 'No permission.'; $_SESSION['message_type'] = 'danger'; }
        elseif ($id <= 0) { $_SESSION['message'] = 'Invalid section.'; $_SESSION['message_type'] = 'danger'; }
        else {
            $ref = 0;
            $chk = $conn->prepare("SELECT COUNT(*) AS c FROM class_sections WHERE section_id = ? AND status = 0");
            if ($chk) {
                $chk->bind_param("i", $id); $chk->execute();
                $r = $chk->get_result()->fetch_assoc();
                $ref = (int)($r['c'] ?? 0); $chk->close();
            }
            if ($ref > 0) {
                $_SESSION['message'] = 'Cannot delete: used in ' . $ref . ' active class-section(s).'; $_SESSION['message_type'] = 'danger';
            } elseif ($has_sections_status) {
                $stmt = $conn->prepare("UPDATE sections SET status = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) { $_SESSION['message'] = 'Section deleted.'; $_SESSION['message_type'] = 'success'; }
                else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("DELETE FROM sections WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) { $_SESSION['message'] = 'Section deleted.'; $_SESSION['message_type'] = 'success'; }
                else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
                $stmt->close();
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=sections'); exit;
    }

    if ($title === '') {
        $_SESSION['message'] = 'Section title required.'; $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=sections'); exit;
    }

    $check = $conn->prepare("SELECT id FROM sections WHERE LOWER(title) = LOWER(?) AND id <> ? LIMIT 1");
    $check->bind_param("si", $title, $id); $check->execute();
    $dupe = $check->get_result()->fetch_assoc(); $check->close();

    if ($dupe) {
        $_SESSION['message'] = 'A section with that title already exists.'; $_SESSION['message_type'] = 'warning';
    } elseif ($id > 0) {
        if (!$can_edit) { $_SESSION['message'] = 'No permission.'; $_SESSION['message_type'] = 'danger'; }
        else {
            $stmt = $conn->prepare("UPDATE sections SET title = ? WHERE id = ?");
            $stmt->bind_param("si", $title, $id);
            if ($stmt->execute()) { $_SESSION['message'] = 'Section updated.'; $_SESSION['message_type'] = 'success'; }
            else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
            $stmt->close();
        }
    } else {
        if (!$can_add) { $_SESSION['message'] = 'No permission.'; $_SESSION['message_type'] = 'danger'; }
        else {
            if ($has_sections_status) $stmt = $conn->prepare("INSERT INTO sections (title, status) VALUES (?, 0)");
            else $stmt = $conn->prepare("INSERT INTO sections (title) VALUES (?)");
            $stmt->bind_param("s", $title);
            if ($stmt->execute()) { $_SESSION['message'] = 'Section added.'; $_SESSION['message_type'] = 'success'; }
            else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
            $stmt->close();
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=sections'); exit;
}

/* ============================================================
   POST — ASSIGNMENTS (with duplicate + soft-delete safety)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'assignment') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['message'] = 'Invalid request.'; $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=assignments'); exit;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $id             = (int)($_POST['id'] ?? 0);
    $class_id       = (int)($_POST['class_id'] ?? 0);
    $section_id     = (int)($_POST['section_id'] ?? 0);
    $session_id     = (int)($_POST['session_id'] ?? 0);
    $coordinator_id = (int)($_POST['coordinator_id'] ?? 0);
    $max_students   = (int)($_POST['max_students'] ?? 0);

    if ($class_id <= 0 || $section_id <= 0 || $session_id <= 0) {
        $_SESSION['message'] = 'Class, Section and Session are required.'; $_SESSION['message_type'] = 'danger';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=assignments'); exit;
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE class_sections SET class_id=?, section_id=?, session_id=?, coordinator_id=?, max_students=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("iiiiii", $class_id, $section_id, $session_id, $coordinator_id, $max_students, $id);
            try {
                if ($stmt->execute()) { $_SESSION['message'] = 'Assignment updated.'; $_SESSION['message_type'] = 'success'; }
                else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1062) $_SESSION['message'] = 'That Class + Section + Session combination already exists.';
                else $_SESSION['message'] = 'Failed: ' . $e->getMessage();
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        }
    } else {
        $existing_id = 0; $existing_sts = -1;
        $chk = $conn->prepare("SELECT id, status FROM class_sections WHERE class_id = ? AND section_id = ? AND session_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("iii", $class_id, $section_id, $session_id);
            $chk->execute();
            $r = $chk->get_result()->fetch_assoc();
            if ($r) { $existing_id = (int)$r['id']; $existing_sts = (int)$r['status']; }
            $chk->close();
        }

        if ($existing_id > 0 && $existing_sts === 0) {
            $_SESSION['message'] = 'An active assignment for this Class + Section + Session already exists.'; $_SESSION['message_type'] = 'warning';
        } elseif ($existing_id > 0 && $existing_sts === 1) {
            $stmt = $conn->prepare("UPDATE class_sections SET status = 0, coordinator_id = ?, max_students = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("iii", $coordinator_id, $max_students, $existing_id);
                try {
                    if ($stmt->execute()) { $_SESSION['message'] = 'Assignment restored (reactivated).'; $_SESSION['message_type'] = 'success'; }
                    else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
                } catch (mysqli_sql_exception $e) {
                    $_SESSION['message'] = 'Failed: ' . $e->getMessage(); $_SESSION['message_type'] = 'danger';
                }
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO class_sections (class_id, section_id, session_id, coordinator_id, max_students, status) VALUES (?,?,?,?,?,0)");
            if ($stmt) {
                $stmt->bind_param("iiiii", $class_id, $section_id, $session_id, $coordinator_id, $max_students);
                try {
                    if ($stmt->execute()) { $_SESSION['message'] = 'Assignment added.'; $_SESSION['message_type'] = 'success'; }
                    else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) $_SESSION['message'] = 'That Class + Section + Session combination already exists.';
                    else $_SESSION['message'] = 'Failed: ' . $e->getMessage();
                    $_SESSION['message_type'] = 'danger';
                }
                $stmt->close();
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=assignments'); exit;
}

/* ============================================================
   DELETE ASSIGNMENT
   ============================================================ */
if (isset($_GET['delete_assignment']) && $can_delete) {
    $did = (int)$_GET['delete_assignment'];
    $stmt = $conn->prepare("UPDATE class_sections SET status = 1 WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $did);
        if ($stmt->execute()) { $_SESSION['message'] = 'Assignment deleted.'; $_SESSION['message_type'] = 'success'; }
        else { $_SESSION['message'] = 'Failed: ' . $stmt->error; $_SESSION['message_type'] = 'danger'; }
        $stmt->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=assignments'); exit;
}

/* ============================================================
   EDIT LOADERS
   ============================================================ */
$edit_class = null;
if (isset($_GET['edit_class'])) {
    $eid = (int)$_GET['edit_class'];
    $stmt = $conn->prepare("SELECT id, title, status FROM classes WHERE id = ?");
    $stmt->bind_param("i", $eid); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($r) $edit_class = $r;
}

$edit_section = null;
if (isset($_GET['edit_section'])) {
    $eid = (int)$_GET['edit_section'];
    $stmt = $conn->prepare("SELECT id, title FROM sections WHERE id = ?");
    $stmt->bind_param("i", $eid); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($r) $edit_section = $r;
}

$edit_assignment = null;
if (isset($_GET['edit_assignment'])) {
    $eid = (int)$_GET['edit_assignment'];
    $stmt = $conn->prepare("SELECT * FROM class_sections WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $eid); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($r) $edit_assignment = $r;
}

/* ============================================================
   INITIAL PAGE DATA
   ============================================================ */
$classes   = render_classes_rows($conn, '');
$sections  = render_sections_rows($conn, '');
$sessions_list = [];
$q = $conn->query("SELECT id, title FROM sessions ORDER BY from_dated DESC");
if ($q) { while ($r = $q->fetch_assoc()) $sessions_list[] = $r; $q->close(); }
$coordinators_list = [];
$q = $conn->query("SELECT id, username FROM users WHERE status = 0 ORDER BY username ASC");
if ($q) { while ($r = $q->fetch_assoc()) $coordinators_list[] = $r; $q->close(); }

$grand_total = 0;
$q = $conn->query("SELECT COUNT(*) AS c FROM class_sections WHERE status = 0");
if ($q && ($r = $q->fetch_assoc())) { $grand_total = (int)$r['c']; $q->close(); }

$stat_total_classes  = count($classes);
$stat_total_sections = count($sections);
$stat_total_assign   = $grand_total;

$message = ''; $message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Classes &middot; Sections &middot; Assignments</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding-bottom: 40px; }

    .page-header-card { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border-radius: 14px; padding: 20px 24px; color: #fff; margin-bottom: 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 8px 28px rgba(37, 99, 235, 0.28); }
    .page-header-card .header-icon { width: 48px; height: 48px; background: rgba(255,255,255,0.18); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
    .page-header-card h1 { font-size: 19px; font-weight: 700; margin: 0; }
    .page-header-card .subtitle { font-size: 12px; opacity: 0.88; margin-top: 3px; }

    .tab-strip { display: inline-flex; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 4px; gap: 4px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .tab-strip a { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 9px; font-size: 13px; font-weight: 700; color: #64748b; text-decoration: none; transition: all 0.15s; }
    .tab-strip a:hover { color: #1e293b; text-decoration: none; }
    .tab-strip a.active { background: #2563eb; color: #fff; box-shadow: 0 2px 6px rgba(37,99,235,0.3); }
    .tab-strip a .tab-badge { background: rgba(0,0,0,0.08); padding: 2px 8px; border-radius: 10px; font-size: 10.5px; font-weight: 700; }
    .tab-strip a.active .tab-badge { background: rgba(255,255,255,0.25); }

    .stats-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
    .stat-card { background: #fff; border-radius: 10px; padding: 12px 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 12px; border-left: 3px solid #2563eb; }
    .stat-card .stat-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
    .stat-card .stat-body .stat-value { font-size: 18px; font-weight: 800; color: #1e293b; line-height: 1; }
    .stat-card .stat-body .stat-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }
    .stat-card.blue { border-left-color: #2563eb; }
    .stat-card.blue .stat-icon { background: #eff6ff; color: #2563eb; }
    .stat-card.green { border-left-color: #059669; }
    .stat-card.green .stat-icon { background: #d1fae5; color: #059669; }
    .stat-card.purple { border-left-color: #7c3aed; }
    .stat-card.purple .stat-icon { background: #ede9fe; color: #7c3aed; }

    .form-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); padding: 20px 22px; margin-bottom: 18px; border-top: 4px solid #2563eb; }
    .form-card.editing { border-top-color: #f59e0b; background: #fffbeb; }
    .form-card .form-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
    .form-card .form-head h4 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px; }
    .form-card.editing .form-head h4 { color: #b45309; }
    .form-card .form-head h4 i { color: #2563eb; }
    .form-card.editing .form-head h4 i { color: #f59e0b; }

    .form-card label.field-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; display: block; }
    .form-card .form-control { border-radius: 7px; border: 1px solid #cbd5e1; font-size: 13px; padding: 6px 10px; height: 36px; line-height: 1.4; color: #1e293b; }
    .form-card select.form-control { height: 36px; padding: 5px 10px; }
    .form-card .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .form-card .form-group { margin-bottom: 14px; }
    .form-card .row > [class*="col-"] { padding-left: 8px; padding-right: 8px; }
    .form-card .row { margin-left: -8px; margin-right: -8px; }

    .btn-brand { background: #2563eb; border: 1px solid #2563eb; color: #fff; font-weight: 600; font-size: 12.5px; padding: 8px 16px; border-radius: 7px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; height: 36px; }
    .btn-brand:hover, .btn-brand:focus { background: #1d4ed8; border-color: #1d4ed8; color: #fff; text-decoration: none; }
    .btn-ghost { background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; font-weight: 600; font-size: 12.5px; padding: 8px 14px; border-radius: 7px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; height: 36px; }
    .btn-ghost:hover, .btn-ghost:focus { background: #e2e8f0; color: #1e293b; text-decoration: none; }
    .btn-success-brand { background: #059669; border: 1px solid #059669; color: #fff; font-weight: 600; font-size: 12.5px; padding: 8px 16px; border-radius: 7px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; height: 36px; }
    .btn-success-brand:hover { background: #047857; border-color: #047857; color: #fff; text-decoration: none; }

    .list-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); overflow: hidden; }
    .list-card-head { padding: 14px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .list-card-head h3 { font-size: 14px; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px; }
    .list-card-head h3 i { color: #2563eb; }
    .list-card-head .count-chip { background: #eff6ff; color: #2563eb; padding: 4px 11px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }

    .toolbar { padding: 12px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #fafbfc; }
    .toolbar .search-box { position: relative; flex: 1; min-width: 220px; max-width: 340px; }
    .toolbar .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; }
    .toolbar .search-box input { width: 100%; height: 34px; padding: 0 12px 0 32px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; }
    .toolbar .search-box input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .toolbar select { height: 34px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; font-family: inherit; font-weight: 600; color: #334155; background: #fff; }
    .toolbar .count-inline { margin-left: auto; font-size: 11.5px; color: #64748b; font-weight: 600; }

    table.data-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
    table.data-table thead th { background: #f8fafc; color: #475569; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; padding: 10px 14px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.data-table tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 12.5px; color: #1e293b; }
    table.data-table tbody tr:hover { background: #f8fafc; }
    table.data-table tbody tr:last-child td { border-bottom: none; }

    .id-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 24px; padding: 0 8px; background: #eff6ff; color: #2563eb; font-weight: 700; font-size: 11.5px; border-radius: 6px; }
    .class-title { font-weight: 700; color: #1e293b; font-size: 13px; }
    .section-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 14px; font-size: 11px; font-weight: 700; background: #f1f5f9; color: #475569; }
    .section-pill.has { background: #e0e7ff; color: #3730a3; }
    .session-chip { display: inline-block; font-weight: 600; color: #475569; background: #f1f5f9; padding: 3px 10px; border-radius: 8px; font-size: 11.5px; }
    .class-chip { display: inline-block; font-weight: 700; color: #0f172a; background: #eef2ff; padding: 3px 10px; border-radius: 8px; font-size: 12px; }
    .coordinator { font-weight: 600; color: #334155; font-size: 12px; }
    .coordinator.unassigned { color: #cbd5e1; font-weight: 500; font-style: italic; }
    .capacity { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 700; }
    .capacity .num { background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 8px; font-size: 11px; }
    .capacity .sep { color: #cbd5e1; }
    .capacity .max { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 8px; font-size: 11px; }

    .teacher-count-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; background: #fef3c7; color: #92400e; }
    .teacher-count-pill.zero { background: #f1f5f9; color: #94a3b8; }

    .icon-btn { width: 30px; height: 30px; border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s; padding: 0; font-size: 12px; text-decoration: none; }
    .icon-btn:hover { transform: translateY(-1px); text-decoration: none; }
    .icon-btn.edit:hover { background: #eff6ff; border-color: #93c5fd; color: #2563eb; }
    .icon-btn.delete:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .icon-btn.assign { background: #059669; border-color: #059669; color: #fff; }
    .icon-btn.assign:hover { background: #047857; border-color: #047857; color: #fff; }
    .icon-btn.timetable { background: #7c3aed; border-color: #7c3aed; color: #fff; }
    .icon-btn.timetable:hover { background: #6d28d9; border-color: #6d28d9; color: #fff; }
    .icon-btn + .icon-btn { margin-left: 5px; }

    .empty-state { padding: 45px 20px; text-align: center; color: #94a3b8; }
    .empty-state .big { font-size: 38px; opacity: 0.4; margin-bottom: 8px; }
    .empty-state h5 { font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 5px 0; }

    .pagination-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 20px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }
    .pagination-info { font-size: 12px; color: #64748b; font-weight: 500; }
    .pagination { display: inline-flex; gap: 4px; margin: 0; }
    .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 0 9px; border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; color: #475569; text-decoration: none; font-size: 12px; font-weight: 700; cursor: pointer; }
    .pagination a:hover { background: #f8fafc; border-color: #cbd5e1; text-decoration: none; color: #1e293b; }
    .pagination .current { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pagination .dots { background: transparent; border-color: transparent; cursor: default; color: #94a3b8; }

    #teacherModal .modal-content { border: none; border-radius: 12px; overflow: hidden; }
    #teacherModal .modal-header { background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #fff; border: none; padding: 16px 20px; }
    #teacherModal .modal-header h5 { font-weight: 700; font-size: 15px; margin: 0; display: flex; align-items: center; gap: 8px; }
    #teacherModal .modal-header .subj-label { font-size: 11.5px; opacity: 0.9; margin-top: 3px; }
    #teacherModal .modal-header .close { color: #fff; opacity: 0.8; text-shadow: none; font-size: 22px; padding: 0; margin: 0; }
    #teacherModal .modal-body { padding: 16px 20px; max-height: 60vh; overflow-y: auto; }
    #teacherModal .modal-footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 20px; }

    .teacher-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .teacher-card { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 9px; cursor: pointer; transition: all 0.15s; user-select: none; }
    .teacher-card:hover { border-color: #86efac; background: #f8fafc; }
    .teacher-card input[type="checkbox"] { width: 16px; height: 16px; accent-color: #059669; cursor: pointer; flex-shrink: 0; margin: 0; }
    .teacher-card .info { flex: 1; min-width: 0; }
    .teacher-card .name { font-size: 12.5px; font-weight: 700; color: #1e293b; }
    .teacher-card .meta { font-size: 10.5px; color: #64748b; margin-top: 2px; }
    .teacher-card .load-pill { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 9.5px; font-weight: 700; background: #dbeafe; color: #1e40af; }
    .teacher-card:has(input:checked) { border-color: #059669; background: #f0fdf4; }
    .teacher-card:has(input:checked) .name { color: #065f46; }

    .teacher-toolbar { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
    .teacher-toolbar button { background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; padding: 5px 10px; border-radius: 7px; font-size: 11.5px; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.15s; }
    .teacher-toolbar button:hover { background: #e2e8f0; color: #1e293b; }

    @media (max-width: 768px) {
        .stats-strip { grid-template-columns: 1fr; }
        .form-card { padding: 16px; }
        .teacher-grid { grid-template-columns: 1fr; }
        table.data-table thead { display: none; }
        table.data-table, table.data-table tbody, table.data-table tr, table.data-table td { display: block; width: 100%; }
        table.data-table tr { margin-bottom: 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 12px; }
        table.data-table td { border: none; padding: 6px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        table.data-table td::before { content: attr(data-label); font-weight: 700; font-size: 10.5px; text-transform: uppercase; color: #64748b; letter-spacing: 0.4px; flex-shrink: 0; }
    }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="container" style="padding: 20px; max-width: 1280px; margin: 0 auto;">

    <div class="page-header-card">
        <div class="header-icon"><i class="fas fa-school"></i></div>
        <div>
            <h1>Classes &middot; Sections &middot; Assignments</h1>
            <div class="subtitle">Manage classes, sections, and per-session assignments &mdash; jump to Timetable from any row</div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($message_type); ?> alert-dismissible fade show" role="alert">
            <?= h($message); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="tab-strip">
        <a href="?tab=classes" class="<?= $active_tab === 'classes' ? 'active' : ''; ?>">
            <i class="fas fa-chalkboard"></i> Classes
            <span class="tab-badge"><?= $stat_total_classes ?></span>
        </a>
        <a href="?tab=sections" class="<?= $active_tab === 'sections' ? 'active' : ''; ?>">
            <i class="fas fa-layer-group"></i> Sections
            <span class="tab-badge"><?= $stat_total_sections ?></span>
        </a>
        <a href="?tab=assignments" class="<?= $active_tab === 'assignments' ? 'active' : ''; ?>">
            <i class="fas fa-link"></i> Assignments
            <span class="tab-badge"><?= $stat_total_assign ?></span>
        </a>
    </div>

    <div class="stats-strip">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $stat_total_classes ?></div>
                <div class="stat-label">Classes</div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $stat_total_sections ?></div>
                <div class="stat-label">Sections</div>
            </div>
        </div>
        <div class="stat-card purple">
            <div class="stat-icon"><i class="fas fa-link"></i></div>
            <div class="stat-body">
                <div class="stat-value"><?= $stat_total_assign ?></div>
                <div class="stat-label">Assignments</div>
            </div>
        </div>
    </div>

    <?php if ($active_tab === 'classes'): ?>
    <!-- CLASSES TAB -->
    <div class="form-card <?= $edit_class ? 'editing' : ''; ?>">
        <div class="form-head">
            <h4><i class="fas fa-<?= $edit_class ? 'pen' : 'plus-circle'; ?>"></i> <?= $edit_class ? 'Edit Class' : 'Add New Class' ?></h4>
            <?php if ($edit_class): ?>
                <a href="?tab=classes" class="btn-ghost"><i class="fas fa-times"></i> Cancel Edit</a>
            <?php endif; ?>
        </div>
        <form method="post" action="">
            <input type="hidden" name="form" value="class">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($edit_class): ?><input type="hidden" name="id" value="<?= (int)$edit_class['id']; ?>"><?php endif; ?>
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <label class="field-label">Class Title <span style="color:#dc2626;">*</span></label>
                        <input type="text" class="form-control" name="title" required value="<?= $edit_class ? h($edit_class['title']) : ''; ?>" placeholder="e.g. Class 1">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="field-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="0" <?= (!$edit_class || (int)$edit_class['status'] === 0) ? 'selected' : ''; ?>>Active</option>
                            <option value="1" <?= ($edit_class && (int)$edit_class['status'] === 1) ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4" style="display:flex; align-items:flex-end;">
                    <div class="form-group" style="width:100%;">
                        <?php if ($edit_class ? $can_edit : $can_add): ?>
                            <button type="submit" class="btn-brand" style="width:100%; justify-content:center;">
                                <i class="fas fa-<?= $edit_class ? 'save' : 'check'; ?>"></i>
                                <?= $edit_class ? 'Save Changes' : 'Add Class' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="list-card">
        <div class="list-card-head">
            <h3><i class="fas fa-list-ul"></i> All Classes</h3>
            <span class="count-chip" id="classes_count_chip"><?= count($classes) ?> class(es)</span>
        </div>
        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="classes_search" placeholder="Search classes..." autocomplete="off">
            </div>
            <div class="count-inline" id="classes_count_inline"><?= count($classes) ?> shown</div>
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:70px;">#</th>
                        <th>Class Title</th>
                        <th style="width:120px;">Sections</th>
                        <th style="width:130px;">Added</th>
                        <th style="width:170px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="classes_tbody">
                    <?php if (!empty($classes)): $i = 1; foreach ($classes as $r):
                        $row_dated = $r['dated']; $dated_display = '&mdash;';
                        if (!empty($row_dated) && $row_dated !== '0000-00-00') { $ts = strtotime($row_dated); if ($ts) $dated_display = date('d M Y', $ts); }
                    ?>
                    <tr>
                        <td data-label="#"><span class="id-badge"><?= $i++ ?></span></td>
                        <td data-label="Title"><div class="class-title"><?= h($r['title']) ?></div></td>
                        <td data-label="Sections">
                            <span class="section-pill <?= (int)$r['section_count'] > 0 ? 'has' : '' ?>">
                                <i class="fas fa-layer-group"></i> <?= (int)$r['section_count'] ?>
                            </span>
                        </td>
                        <td data-label="Added"><?= $dated_display ?></td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            <?php if ($can_edit): ?>
                                <a href="create_timetable.php?class_id=<?= (int)$r['id'] ?>" class="icon-btn timetable" title="Timetable"><i class="fas fa-table"></i></a>
                                <a href="?tab=classes&edit_class=<?= (int)$r['id'] ?>" class="icon-btn edit" title="Edit"><i class="fas fa-pen"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                                <form method="post" action="" style="display:inline;" onsubmit="return confirm('Delete this class?');">
                                    <input type="hidden" name="form" value="class">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="icon-btn delete" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5"><div class="empty-state"><div class="big"><i class="fas fa-chalkboard"></i></div><h5>No classes yet</h5><p>Add your first class using the form above.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($active_tab === 'sections'): ?>
    <!-- SECTIONS TAB -->
    <div class="form-card <?= $edit_section ? 'editing' : ''; ?>">
        <div class="form-head">
            <h4><i class="fas fa-<?= $edit_section ? 'pen' : 'plus-circle'; ?>"></i> <?= $edit_section ? 'Edit Section' : 'Add New Section' ?></h4>
            <?php if ($edit_section): ?>
                <a href="?tab=sections" class="btn-ghost"><i class="fas fa-times"></i> Cancel Edit</a>
            <?php endif; ?>
        </div>
        <form method="post" action="">
            <input type="hidden" name="form" value="section">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($edit_section): ?><input type="hidden" name="id" value="<?= (int)$edit_section['id']; ?>"><?php endif; ?>
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <label class="field-label">Section Title <span style="color:#dc2626;">*</span></label>
                        <input type="text" class="form-control" name="title" required maxlength="10" value="<?= $edit_section ? h($edit_section['title']) : ''; ?>" placeholder="e.g. A, B, C">
                    </div>
                </div>
                <div class="col-md-3" style="display:flex; align-items:flex-end;">
                    <div class="form-group" style="width:100%;">
                        <?php if ($edit_section ? $can_edit : $can_add): ?>
                            <button type="submit" class="btn-brand" style="width:100%; justify-content:center;">
                                <i class="fas fa-<?= $edit_section ? 'save' : 'check'; ?>"></i>
                                <?= $edit_section ? 'Save Changes' : 'Add Section' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="list-card">
        <div class="list-card-head">
            <h3><i class="fas fa-list-ul"></i> All Sections</h3>
            <span class="count-chip" id="sections_count_chip"><?= count($sections) ?> section(s)</span>
        </div>
        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="sections_search" placeholder="Search sections..." autocomplete="off">
            </div>
            <div class="count-inline" id="sections_count_inline"><?= count($sections) ?> shown</div>
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:70px;">#</th>
                        <th>Section Title</th>
                        <th style="width:200px;">Used in Classes</th>
                        <th style="width:130px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="sections_tbody">
                    <?php if (!empty($sections)): $i = 1; foreach ($sections as $r): ?>
                    <tr>
                        <td data-label="#"><span class="id-badge"><?= $i++ ?></span></td>
                        <td data-label="Title"><div class="class-title"><?= h($r['title']) ?></div></td>
                        <td data-label="Used in Classes">
                            <?php if ((int)$r['class_count'] > 0): ?>
                                <span class="section-pill has"><i class="fas fa-check"></i> <?= (int)$r['class_count'] ?> class(es)</span>
                            <?php else: ?>
                                <span class="section-pill">unused</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            <?php if ($can_edit): ?>
                                <a href="?tab=sections&edit_section=<?= (int)$r['id'] ?>" class="icon-btn edit" title="Edit"><i class="fas fa-pen"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                                <form method="post" action="" style="display:inline;" onsubmit="return confirm('Delete this section?');">
                                    <input type="hidden" name="form" value="section">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="icon-btn delete" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4"><div class="empty-state"><div class="big"><i class="fas fa-layer-group"></i></div><h5>No sections yet</h5><p>Add sections A, B, C above.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- ASSIGNMENTS TAB -->
    <div class="form-card <?= $edit_assignment ? 'editing' : ''; ?>">
        <div class="form-head">
            <h4><i class="fas fa-<?= $edit_assignment ? 'pen' : 'plus-circle'; ?>"></i> <?= $edit_assignment ? 'Edit Assignment' : 'Add Class + Section Assignment' ?></h4>
            <?php if ($edit_assignment): ?>
                <a href="?tab=assignments" class="btn-ghost"><i class="fas fa-times"></i> Cancel Edit</a>
            <?php endif; ?>
        </div>
        <form method="post" action="">
            <input type="hidden" name="form" value="assignment">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <?php if ($edit_assignment): ?><input type="hidden" name="id" value="<?= (int)$edit_assignment['id']; ?>"><?php endif; ?>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="field-label">Session <span style="color:#dc2626;">*</span></label>
                        <select name="session_id" class="form-control" required>
                            <option value="">&mdash; Select &mdash;</option>
                            <?php foreach ($sessions_list as $sess):
                                $sel = $edit_assignment && (int)$edit_assignment['session_id'] === (int)$sess['id']; ?>
                                <option value="<?= (int)$sess['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= h($sess['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="field-label">Class <span style="color:#dc2626;">*</span></label>
                        <select name="class_id" class="form-control" required>
                            <option value="">&mdash; Select &mdash;</option>
                            <?php foreach ($classes as $cls):
                                $sel = $edit_assignment && (int)$edit_assignment['class_id'] === (int)$cls['id']; ?>
                                <option value="<?= (int)$cls['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= h($cls['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="field-label">Section <span style="color:#dc2626;">*</span></label>
                        <select name="section_id" class="form-control" required>
                            <option value="">&mdash; Select &mdash;</option>
                            <?php foreach ($sections as $sec):
                                $sel = $edit_assignment && (int)$edit_assignment['section_id'] === (int)$sec['id']; ?>
                                <option value="<?= (int)$sec['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= h($sec['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="field-label">Max Students</label>
                        <input type="number" min="0" class="form-control" name="max_students" value="<?= $edit_assignment ? h($edit_assignment['max_students']) : '' ?>" placeholder="e.g. 40">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="field-label">Coordinator</label>
                        <select name="coordinator_id" class="form-control">
                            <option value="0">— Unassigned —</option>
                            <?php foreach ($coordinators_list as $co):
                                $sel = $edit_assignment && (int)$edit_assignment['coordinator_id'] === (int)$co['id']; ?>
                                <option value="<?= (int)$co['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= h($co['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6" style="display:flex; align-items:flex-end;">
                    <div class="form-group" style="width:100%; text-align:right;">
                        <?php if ($edit_assignment ? $can_edit : $can_add): ?>
                            <button type="submit" class="btn-success-brand">
                                <i class="fas fa-<?= $edit_assignment ? 'save' : 'check'; ?>"></i>
                                <?= $edit_assignment ? 'Save Changes' : 'Add Assignment' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="list-card">
        <div class="list-card-head">
            <h3><i class="fas fa-list-ul"></i> All Assignments</h3>
            <span class="count-chip" id="assignments_count_chip"><?= $grand_total ?> total</span>
        </div>

        <div class="toolbar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="assignments_search" placeholder="Search class, section, session..." autocomplete="off">
            </div>
            <select id="assignments_session_filter">
                <option value="0">All Sessions</option>
                <?php foreach ($sessions_list as $sess): ?>
                    <option value="<?= (int)$sess['id'] ?>"><?= h($sess['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="count-inline" id="assignments_count_inline"><?= number_format($grand_total) ?> result(s)</div>
        </div>

        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:170px;">Session</th>
                        <th style="width:130px;">Class</th>
                        <th style="width:110px;">Section</th>
                        <th>Coordinator</th>
                        <th style="width:150px;">Enrolled / Max</th>
                        <th style="width:120px;">Teachers</th>
                        <th style="width:220px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="assignments_tbody">
                    <tr><td colspan="8" style="text-align:center; padding: 30px; color: #94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <div id="assignments_pagination"></div>
    </div>
    <?php endif; ?>

</div>

<!-- Assign Teachers Modal -->
<div class="modal fade" id="teacherModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5><i class="fas fa-user-plus"></i> Assign Teachers</h5>
                    <div class="subj-label" id="teacher_modal_label"></div>
                </div>
                <button type="button" class="close" id="teacher_close_x">&times;</button>
            </div>
            <div class="modal-body">
                <div class="teacher-toolbar">
                    <button type="button" id="t_select_all"><i class="fas fa-check-double"></i> Select All</button>
                    <button type="button" id="t_clear_all"><i class="fas fa-times"></i> Clear All</button>
                </div>
                <div class="teacher-grid" id="teacher_grid">
                    <div style="text-align:center; padding:24px; grid-column:1/-1; color:#94a3b8;">
                        <i class="fas fa-spinner fa-spin"></i> Loading teachers...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" id="teacher_cancel">Cancel</button>
                <button type="button" class="btn-success-brand" id="teacher_save">
                    <i class="fas fa-save"></i> Save Assignments
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {

    var CSRF = '<?= $_SESSION['csrf_token'] ?>';

    setTimeout(function() { $('.alert').fadeOut('slow'); }, 5000);

    /* ---------- Classes search (AJAX) ---------- */
    var classesTimer = null, classesReq = null;
    $('#classes_search').on('input', function() {
        var q = $(this).val();
        clearTimeout(classesTimer);
        classesTimer = setTimeout(function() {
            if (classesReq) classesReq.abort();
            classesReq = $.ajax({ url: '?ajax=list_classes', method: 'GET', dataType: 'json', data: { q: q } })
                .done(function(res) {
                    if (res && res.ok) {
                        $('#classes_tbody').html(res.html);
                        $('#classes_count_inline').text(res.count + ' shown');
                        $('#classes_count_chip').text(res.count + ' class(es)');
                    }
                });
        }, 300);
    });

    /* ---------- Sections search (AJAX) ---------- */
    var sectionsTimer = null, sectionsReq = null;
    $('#sections_search').on('input', function() {
        var q = $(this).val();
        clearTimeout(sectionsTimer);
        sectionsTimer = setTimeout(function() {
            if (sectionsReq) sectionsReq.abort();
            sectionsReq = $.ajax({ url: '?ajax=list_sections', method: 'GET', dataType: 'json', data: { q: q } })
                .done(function(res) {
                    if (res && res.ok) {
                        $('#sections_tbody').html(res.html);
                        $('#sections_count_inline').text(res.count + ' shown');
                        $('#sections_count_chip').text(res.count + ' section(s)');
                    }
                });
        }, 300);
    });

    /* ---------- Assignments search + filter + pagination (AJAX) ---------- */
    var assignmentsPage = 1;
    var assignmentsSearch = '';
    var assignmentsSessionFilter = 0;
    var assignmentsTimer = null;
    var assignmentsReq = null;

    function loadAssignments() {
        if (assignmentsReq) assignmentsReq.abort();
        $('#assignments_tbody').html('<tr><td colspan="8" style="text-align:center; padding: 30px; color: #94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>');
        assignmentsReq = $.ajax({
            url: '?ajax=list_assignments', method: 'GET', dataType: 'json',
            data: { q: assignmentsSearch, session_filter: assignmentsSessionFilter, page: assignmentsPage }
        }).done(function(res) {
            if (res && res.ok) {
                $('#assignments_tbody').html(res.html);
                $('#assignments_pagination').html(res.pagination_html || '');
                $('#assignments_count_inline').text(res.total_rows + ' result(s)');
                $('#assignments_count_chip').text(res.total_rows + ' total');
            }
        }).fail(function(xhr) {
            if (xhr.statusText !== 'abort') {
                $('#assignments_tbody').html('<tr><td colspan="8" style="text-align:center; padding: 30px; color: #dc2626;">Network error</td></tr>');
            }
        });
    }

    if ($('#assignments_tbody').length) {
        loadAssignments();

        $('#assignments_search').on('input', function() {
            assignmentsSearch = $(this).val();
            assignmentsPage = 1;
            clearTimeout(assignmentsTimer);
            assignmentsTimer = setTimeout(loadAssignments, 300);
        });

        $('#assignments_session_filter').on('change', function() {
            assignmentsSessionFilter = parseInt($(this).val(), 10) || 0;
            assignmentsPage = 1;
            loadAssignments();
        });

        $(document).on('click', '#assignments_pagination .page-link', function(e) {
            e.preventDefault();
            var p = parseInt($(this).data('page'), 10);
            if (p > 0) { assignmentsPage = p; loadAssignments(); }
        });
    }

    /* ---------- Assign teachers modal ---------- */
    var currentClassId = 0, currentClassSectionId = 0;

    $(document).on('click', '.btn-assign-teachers', function() {
        currentClassId = $(this).data('class-id');
        currentClassSectionId = $(this).data('class-section-id');
        var label = $(this).data('label');
        $('#teacher_modal_label').text(label);
        $('#teacher_grid').html('<div style="text-align:center; padding:24px; grid-column:1/-1; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading teachers...</div>');
        $('#teacherModal').modal('show');

        $.ajax({
            url: '?ajax=load_teachers&class_id=' + currentClassId + '&class_section_id=' + currentClassSectionId,
            method: 'GET', dataType: 'json'
        }).done(function(res) {
            if (res && res.ok) {
                if (!res.teachers || res.teachers.length === 0) {
                    $('#teacher_grid').html('<div style="text-align:center; padding:24px; grid-column:1/-1; color:#94a3b8;">No teachers found. Add users with role = Teacher first.</div>');
                    return;
                }
                var html = '';
                $.each(res.teachers, function(idx, t) {
                    var checked = t.checked ? 'checked' : '';
                    html += '<label class="teacher-card">' +
                        '<input type="checkbox" class="teacher-cb" value="' + t.id + '" ' + checked + '>' +
                        '<div class="info">' +
                            '<div class="name">' + t.username + '</div>' +
                            '<div class="meta">' + (t.email || 'no email') + ' &middot; <span class="load-pill">' + t.load + ' class' + (t.load === 1 ? '' : 'es') + '</span></div>' +
                        '</div>' +
                    '</label>';
                });
                $('#teacher_grid').html(html);
            } else {
                $('#teacher_grid').html('<div style="text-align:center; padding:24px; grid-column:1/-1; color:#dc2626;">Error: ' + (res.error || 'Unknown') + '</div>');
            }
        }).fail(function() {
            $('#teacher_grid').html('<div style="text-align:center; padding:24px; grid-column:1/-1; color:#dc2626;">Network error</div>');
        });
    });

    $('#teacher_close_x, #teacher_cancel').on('click', function() { $('#teacherModal').modal('hide'); });
    $('#t_select_all').on('click', function() { $('#teacher_grid .teacher-cb').prop('checked', true); });
    $('#t_clear_all').on('click', function() { $('#teacher_grid .teacher-cb').prop('checked', false); });

    $('#teacher_save').on('click', function() {
        var $btn = $(this);
        var teacher_ids = [];
        $('#teacher_grid .teacher-cb:checked').each(function() { teacher_ids.push(parseInt($(this).val(), 10)); });

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

        $.ajax({
            url: '?ajax=assign_teachers', method: 'POST', dataType: 'json',
            data: { csrf_token: CSRF, class_id: currentClassId, class_section_id: currentClassSectionId, teacher_ids: teacher_ids }
        }).done(function(res) {
            if (res && res.ok) {
                var $row = $('tr:has(.btn-assign-teachers[data-class-id="' + currentClassId + '"][data-class-section-id="' + currentClassSectionId + '"])');
                var pill = $row.find('.teacher-count-pill');
                pill.removeClass('zero').html('<i class="fas fa-user-tie"></i> ' + res.count);
                if (res.count === 0) pill.addClass('zero');
                $('#teacherModal').modal('hide');
                var $alert = $('<div class="alert alert-success alert-dismissible fade show" style="position:fixed; top:20px; right:20px; z-index:9999; min-width:260px;"></div>')
                    .html(res.count + ' teacher(s) assigned.<button type="button" class="close" data-dismiss="alert">&times;</button>');
                $('body').append($alert);
                setTimeout(function() { $alert.fadeOut(400, function() { $(this).remove(); }); }, 3000);
            } else {
                alert('Save failed: ' + (res.error || 'Unknown'));
            }
        }).always(function() {
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Assignments');
        });
    });

});
</script>
</body>
</html>
<?php $conn->close(); ?>