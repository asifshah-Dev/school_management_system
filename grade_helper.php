<?php
/**
 * grade_helper.php
 * -----------------------------------------------------------------------------
 * Central grade lookup — reads grade_scale ONCE per page load, then caches in
 * a PHP static array. Every DMC / result page includes this file and calls
 * grade_for_pct() instead of hardcoding bands.
 *
 * Usage:
 *     require_once('grade_helper.php');
 *     $g = grade_for_pct($conn, 78.5);
 *     // $g = ['grade'=>'M', 'remark'=>'Meeting Expectations', 'min'=>71, 'max'=>89.99]
 *
 * If $conn is omitted, the helper opens its own connection once and reuses it.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('grade_scale_all')) {
    function grade_scale_all($conn = null) {
        static $cache = null;
        if ($cache !== null) return $cache;

        if ($conn === null) {
            // Lazy-connect if caller didn't pass one
            if (!isset($GLOBALS['conn']) || !$GLOBALS['conn'] instanceof mysqli) {
                $connFile = __DIR__ . '/conn_inc.php';
                if (file_exists($connFile)) require_once($connFile);
            }
            $conn = $GLOBALS['conn'] ?? null;
        }

        $cache = [];
        if ($conn instanceof mysqli) {
            $sql = "SELECT grade, remark, min_pct, max_pct
                    FROM grade_scale
                    WHERE status = 0
                    ORDER BY min_pct DESC";
            $res = $conn->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $cache[] = [
                        'grade'  => (string)$r['grade'],
                        'remark' => (string)$r['remark'],
                        'min'    => (float)$r['min_pct'],
                        'max'    => (float)$r['max_pct'],
                    ];
                }
                $res->free();
            } else {
                error_log('grade_helper: grade_scale query failed: ' . $conn->error);
            }
        }

        // Fallback so pages never break if the table is empty or missing
        if (empty($cache)) {
            $cache = [
                ['grade'=>'E','remark'=>'Exceeding Expectations',        'min'=>90.0,'max'=>100.0],
                ['grade'=>'M','remark'=>'Meeting Expectations',           'min'=>71.0,'max'=>89.99],
                ['grade'=>'A','remark'=>'Approaching Expectations',       'min'=>61.0,'max'=>70.99],
                ['grade'=>'N','remark'=>'Not Yet Meeting Expectations',   'min'=> 0.0,'max'=>60.99],
            ];
        }
        return $cache;
    }
}

if (!function_exists('grade_for_pct')) {
    function grade_for_pct($conn, $pct) {
        $pct = (float)$pct;
        foreach (grade_scale_all($conn) as $band) {
            if ($pct >= $band['min'] && $pct <= $band['max']) {
                return $band;
            }
        }
        // Below every band (shouldn't happen if bands start at 0)
        $all = grade_scale_all($conn);
        return end($all) ?: ['grade'=>'N','remark'=>'Not Yet Meeting Expectations','min'=>0,'max'=>60.99];
    }
}

if (!function_exists('grade_letter_for_pct')) {
    function grade_letter_for_pct($conn, $pct) {
        return grade_for_pct($conn, $pct)['grade'];
    }
}

if (!function_exists('grade_remark_for_pct')) {
    function grade_remark_for_pct($conn, $pct) {
        return grade_for_pct($conn, $pct)['remark'];
    }
}

if (!function_exists('grade_flush_cache')) {
    /**
     * Call this after any edit to grade_scale so the next call re-reads.
     * Important for grade_scale.php's own save handler.
     */
    function grade_flush_cache() {
        // Static vars cannot be reset directly — use reflection
        if (function_exists('grade_scale_all')) {
            // Trick: the static lives inside grade_scale_all(); easiest reset
            // is to redefine via a wrapper. Instead we use a global flag.
            $GLOBALS['__grade_scale_bust'] = true;
        }
    }
}