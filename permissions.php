<?php
/**
 * permissions.php — per-user module permissions.
 *
 * Rule:
 *   - Role 6 (Parent): hard-denied from every staff page.
 *   - Admin (role_id = 1): always allowed.
 *   - If a user_modules row exists for (user, module): its flags win.
 *     can_view=0 → DENIED. can_view=1 → allowed (and per-action flags apply).
 *   - If NO row exists: fall back to role_modules.
 *   - If neither row exists: allowed (fail-open).
 */

if (!defined('ADMIN_ROLE_ID')) {
    define('ADMIN_ROLE_ID', 1);
}

function gl_user_can(mysqli $conn, int $userId, string $moduleUrl, ?int $roleId = null): array
{
    $deny  = ['can_view'=>false,'can_add'=>false,'can_edit'=>false,'can_delete'=>false];
    $allow = ['can_view'=>true,'can_add'=>true,'can_edit'=>true,'can_delete'=>true];

    if ($userId <= 0) return $deny;

    if ($roleId === null) {
        $stmt = $conn->prepare("SELECT role_id FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $roleId = $row ? (int)$row['role_id'] : 0;
    }

    if ($roleId === ADMIN_ROLE_ID) return $allow;
    if ($roleId === 6) return $deny;   // Parent: hard deny on any module

    $basename = strtolower(basename(parse_url($moduleUrl, PHP_URL_PATH) ?: $moduleUrl));
    if ($basename === '') return $allow;

    $stmt = $conn->prepare("
        SELECT id FROM modules
        WHERE LOWER(SUBSTRING_INDEX(url, '/', -1)) = ?
          AND status = 1
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->bind_param("s", $basename);
    $stmt->execute();
    $mod = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Not a registered module → allow (fail-open)
    if (!$mod) return $allow;
    $moduleId = (int)$mod['id'];

    // 1. user_modules override
    $stmt = $conn->prepare("
        SELECT can_view, can_add, can_edit, can_delete
        FROM user_modules
        WHERE user_id = ? AND module_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $userId, $moduleId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($u) {
        return [
            'can_view'   => (bool)(int)$u['can_view'],
            'can_add'    => (bool)(int)$u['can_add'],
            'can_edit'   => (bool)(int)$u['can_edit'],
            'can_delete' => (bool)(int)$u['can_delete'],
        ];
    }

    // 2. role_modules fallback
    $stmt = $conn->prepare("
        SELECT can_view, can_add, can_edit, can_delete
        FROM role_modules
        WHERE role_id = ? AND module_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $roleId, $moduleId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) {
        return [
            'can_view'   => (bool)(int)$r['can_view'],
            'can_add'    => (bool)(int)$r['can_add'],
            'can_edit'   => (bool)(int)$r['can_edit'],
            'can_delete' => (bool)(int)$r['can_delete'],
        ];
    }

    // 3. no rule anywhere → allow
    return $allow;
}

function current_user_can(mysqli $conn, string $moduleUrl, string $action = 'view'): bool
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) return false;
    $perms = gl_user_can($conn, $userId, $moduleUrl);
    return !empty($perms['can_' . $action]);
}

function current_user_perms(mysqli $conn, string $moduleUrl): array
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) return ['can_view'=>false,'can_add'=>false,'can_edit'=>false,'can_delete'=>false];
    return gl_user_can($conn, $userId, $moduleUrl);
}

function detect_requested_action(string $scriptBasename): string
{
    $script = strtolower($scriptBasename);

    if (isset($_GET['delete']) || isset($_GET['del']) || isset($_GET['remove'])) return 'delete';

    $postAction = isset($_POST['action']) ? strtolower(trim($_POST['action'])) : '';
    if (in_array($postAction, ['delete','remove'], true)) return 'delete';
    if (in_array($postAction, ['edit','update'],   true)) return 'edit';
    if (in_array($postAction, ['add','create','insert','store','save_new'], true)) return 'add';

    if (preg_match('/^(delete|del)_/', $script) || preg_match('/_delete\.php$/', $script)) return 'delete';
    if (preg_match('/_add\.php$/', $script)    || preg_match('/^add_/', $script)
     || preg_match('/_create\.php$/', $script) || preg_match('/^create_/', $script)) return 'add';
    if (preg_match('/_edit\.php$/', $script)   || preg_match('/^edit_/', $script)
     || preg_match('/_update\.php$/', $script)) return 'edit';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($_POST as $k => $v) {
            if ($k === 'id' || preg_match('/_id$/', $k)) {
                if (!empty($v)) return 'edit';
            }
        }
        return 'add';
    }
    return 'view';
}

function require_action(mysqli $conn, string $scriptFilePath): void
{
    // Parents are hard-denied from any staff page
    if ((int)($_SESSION['role_id'] ?? 0) === 6) {
        header('Location: 403.php');
        exit;
    }

    $basename = basename($scriptFilePath);
    $action   = detect_requested_action($basename);
    if (!current_user_can($conn, $basename, $action)) {
        header('Location: 403.php');
        exit;
    }
}

function require_current_user_can(mysqli $conn, string $moduleUrl, string $action = 'view'): void
{
    // Parents are hard-denied
    if ((int)($_SESSION['role_id'] ?? 0) === 6) {
        header('Location: 403.php');
        exit;
    }

    if (!current_user_can($conn, $moduleUrl, $action)) {
        header('Location: 403.php');
        exit;
    }
}