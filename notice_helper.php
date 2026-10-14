<?php
/**
 * notice_helper.php
 * Shared helpers for the Notice module.
 */

if (!function_exists('notice_user_is_parent')) {
    function notice_user_is_parent($conn) {
        static $is = null;
        if ($is !== null) return $is;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) { $is = false; return $is; }
        $q = $conn->query("SELECT role_id FROM users WHERE id = " . (int)$uid);
        if ($q && $r = $q->fetch_assoc()) { $is = ((int)$r['role_id'] === 6); }
        else { $is = false; }
        return $is;
    }
}

if (!function_exists('notice_parent_student_ids')) {
    function notice_parent_student_ids($conn) {
        static $ids = null;
        if ($ids !== null) return $ids;
        $ids = array();
        if (!notice_user_is_parent($conn)) return $ids;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $q = $conn->query("SELECT student_id FROM parent_students WHERE parent_user_id = " . (int)$uid);
        if ($q) while ($r = $q->fetch_assoc()) $ids[] = (int)$r['student_id'];
        return $ids;
    }
}

if (!function_exists('notice_parent_class_ids')) {
    function notice_parent_class_ids($conn) {
        static $ids = null;
        if ($ids !== null) return $ids;
        $ids = array();
        $student_ids = notice_parent_student_ids($conn);
        if (empty($student_ids)) return $ids;
        $in = implode(',', array_map('intval', $student_ids));
        $q = $conn->query("SELECT DISTINCT class_id FROM student_class WHERE student_registration_id IN ($in) AND status = 0");
        if ($q) while ($r = $q->fetch_assoc()) $ids[] = (int)$r['class_id'];
        return $ids;
    }
}

if (!function_exists('notice_user_role_id')) {
    function notice_user_role_id($conn) {
        static $role = null;
        if ($role !== null) return $role;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) { $role = 0; return $role; }
        $q = $conn->query("SELECT role_id FROM users WHERE id = " . (int)$uid);
        if ($q && $r = $q->fetch_assoc()) $role = (int)$r['role_id'];
        else $role = 0;
        return $role;
    }
}

if (!function_exists('notice_audience_sql_for_user')) {
    function notice_audience_sql_for_user($conn, $alias = 'n') {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return "1=0";

        $role_id = notice_user_role_id($conn);

        // Super admin sees everything
        if ($role_id === 1) return "1=1";

        $is_parent = ($role_id === 6);
        $parts = array("$alias.audience = 'all'");

        if ($is_parent) {
            $parts[] = "$alias.audience = 'parents'";
            $class_ids = notice_parent_class_ids($conn);
            $student_ids = notice_parent_student_ids($conn);
            if (!empty($class_ids)) {
                $parts[] = "($alias.audience = 'class' AND $alias.class_id IN (" . implode(',', $class_ids) . "))";
            }
            if (!empty($student_ids)) {
                $parts[] = "($alias.audience = 'student' AND $alias.student_id IN (" . implode(',', $student_ids) . "))";
            }
        } else {
            // Staff
            $parts[] = "$alias.audience = 'staff'";
        }

        return '(' . implode(' OR ', $parts) . ')';
    }
}

if (!function_exists('notice_active_sql')) {
    function notice_active_sql($alias = 'n') {
        return "$alias.status = 0 AND $alias.publish_from <= NOW() AND ($alias.publish_to IS NULL OR $alias.publish_to >= NOW())";
    }
}

if (!function_exists('notice_unread_count')) {
    function notice_unread_count($conn) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return 0;

        $audience = notice_audience_sql_for_user($conn);
        $active   = notice_active_sql();

        $sql = "SELECT COUNT(*) AS c
                FROM notices n
                WHERE $active
                  AND $audience
                  AND NOT EXISTS (
                      SELECT 1 FROM notice_reads r
                      WHERE r.notice_id = n.id AND r.user_id = $uid
                  )";
        $q = $conn->query($sql);
        if ($q && $r = $q->fetch_assoc()) return (int)$r['c'];
        error_log('notice_unread_count failed: ' . $conn->error);
        return 0;
    }
}

if (!function_exists('notice_priority_badge')) {
    function notice_priority_badge($priority) {
        if ($priority === 'urgent') return '<span class="np-badge np-urgent">Urgent</span>';
        if ($priority === 'high')   return '<span class="np-badge np-high">High</span>';
        return '<span class="np-badge np-normal">Normal</span>';
    }
}

if (!function_exists('notice_audience_label')) {
    function notice_audience_label($row) {
        $a = $row['audience'];
        if ($a === 'all')     return 'Everyone';
        if ($a === 'parents') return 'Parents';
        if ($a === 'staff')   return 'Staff';
        if ($a === 'class')   return 'Class #' . (int)$row['class_id'];
        if ($a === 'student') return 'Student #' . (int)$row['student_id'];
        return $a;
    }
}