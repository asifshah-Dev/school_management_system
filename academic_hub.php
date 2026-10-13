<?php
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');
require_once('permissions.php');
require_action($conn, __FILE__);
$perms = current_user_perms($conn, __FILE__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Academic Management — Dar-e-Arqm</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #eef1f5; font-family: 'Inter', -apple-system, sans-serif; color: #1e293b; }
    .wrap { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

    .head {
        background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
        color: #fff; padding: 34px 42px 30px 42px;
        border-radius: 12px 12px 0 0;
        box-shadow: 0 4px 16px rgba(30,64,175,0.15);
    }
    .head h1 { font-size: 30px; font-weight: 800; letter-spacing: -0.5px; }
    .head .sub { font-size: 14px; opacity: 0.9; margin-top: 8px; }

    .card { background: #fff; padding: 34px 42px 46px 42px; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

    .section-title {
        font-size: 12px; font-weight: 800; color: #64748b;
        text-transform: uppercase; letter-spacing: 2px;
        margin: 8px 0 18px 0; padding-bottom: 10px;
        border-bottom: 2px solid #e2e8f0;
        display: flex; align-items: center; gap: 10px;
    }
    .section-title .icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px;
        background: #eff6ff; color: #1e40af;
        border-radius: 6px; font-size: 13px;
    }
    .section-title + .section-title,
    .grid + .section-title { margin-top: 34px; }

    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }

    .tile {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 22px 22px 20px 22px; text-decoration: none; color: #1e293b;
        display: flex; flex-direction: column; gap: 14px;
        transition: all 0.15s ease; position: relative; overflow: hidden;
    }
    .tile:hover {
        border-color: #1e40af; background: #f0f5ff;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(30,64,175,0.12);
        text-decoration: none; color: #1e293b;
    }
    .tile::after {
        content: ''; position: absolute; bottom: 0; left: 0;
        height: 3px; width: 100%; background: transparent; transition: background 0.15s;
    }
    .tile:hover::after { background: linear-gradient(90deg, #1e40af 0%, #7c3aed 100%); }

    .tile .head-row { display: flex; align-items: flex-start; gap: 14px; }
    .tile .ico {
        width: 46px; height: 46px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 11px; font-size: 20px; flex-shrink: 0;
    }
    .tile .body { flex: 1; min-width: 0; }
    .tile .t { font-size: 15px; font-weight: 700; color: #0f172a; letter-spacing: -0.2px; }
    .tile .d { font-size: 12.5px; color: #64748b; line-height: 1.5; margin-top: 4px; }
    .tile .arrow { font-size: 13px; color: #94a3b8; margin-left: auto; transition: transform 0.15s; }
    .tile:hover .arrow { transform: translateX(3px); color: #1e40af; }

    /* Icon palettes */
    .ico-hub   { background: #dbeafe; color: #1e40af; }
    .ico-year  { background: #dbeafe; color: #1e40af; }
    .ico-class { background: #e0e7ff; color: #4338ca; }
    .ico-sec   { background: #cffafe; color: #0e7490; }
    .ico-subj  { background: #ede9fe; color: #6d28d9; }
    .ico-tch   { background: #fce7f3; color: #9d174d; }
    .ico-time  { background: #fef3c7; color: #b45309; }
    .ico-prom  { background: #d1fae5; color: #047857; }
    .ico-status{ background: #f1f5f9; color: #334155; }
</style>
</head>
<body>

<?php require_once('navbar.php'); ?>

<div class="wrap">

    <div class="head">
        <h1><i class="fas fa-graduation-cap" style="opacity:0.8;"></i> Academic Management</h1>
        <div class="sub">Sessions, classes, sections, subjects, teachers, timetable, and student promotion</div>
    </div>

    <div class="card">

        <div class="section-title">
            <span class="icon"><i class="fas fa-calendar"></i></span>
            Setup
        </div>
        <div class="grid">

            <a href="sessions.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-year"><i class="fas fa-calendar-alt"></i></div>
                    <div class="body">
                        <div class="t">Academic Years</div>
                        <div class="d">Add, edit, and view academic sessions like 2026–2027</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="classes.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-class"><i class="fas fa-chalkboard"></i></div>
                    <div class="body">
                        <div class="t">Classes</div>
                        <div class="d">Add classes (Play Group, Nursery, Grade 1…) and their sections</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="sections.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-sec"><i class="fas fa-layer-group"></i></div>
                    <div class="body">
                        <div class="t">Sections</div>
                        <div class="d">Master list of sections (A, B, C…) used across all classes</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

        </div>

        <div class="section-title">
            <span class="icon"><i class="fas fa-book"></i></span>
            Subjects &amp; Teachers
        </div>
        <div class="grid">

            <a href="subjects.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-subj"><i class="fas fa-book-open"></i></div>
                    <div class="body">
                        <div class="t">Subjects</div>
                        <div class="d">Subject master — standalone or composite with sub-subjects</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="subject_class.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-subj"><i class="fas fa-list-check"></i></div>
                    <div class="body">
                        <div class="t">Class Subjects</div>
                        <div class="d">Assign subjects to each class with marks configuration</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="assign_teacher.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-tch"><i class="fas fa-chalkboard-user"></i></div>
                    <div class="body">
                        <div class="t">Assign Teachers</div>
                        <div class="d">Pick a class-section, tick the teachers who teach it</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="create_timetable.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-time"><i class="fas fa-clock"></i></div>
                    <div class="body">
                        <div class="t">Subject Timetable</div>
                        <div class="d">Build the weekly timetable per class with subject and teacher</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

        </div>

        <div class="section-title">
            <span class="icon"><i class="fas fa-users"></i></span>
            Students
        </div>
        <div class="grid">

            <a href="student_promotion.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-prom"><i class="fas fa-arrow-up"></i></div>
                    <div class="body">
                        <div class="t">Promote / Transfer Students</div>
                        <div class="d">Move a whole class to the next year, class by class, at year-end</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

            <a href="student_status.php" class="tile">
                <div class="head-row">
                    <div class="ico ico-status"><i class="fas fa-user-tag"></i></div>
                    <div class="body">
                        <div class="t">Student Status</div>
                        <div class="d">Mark students Active, On Leave, or Struck Off</div>
                    </div>
                    <i class="fas fa-arrow-right arrow"></i>
                </div>
            </a>

        </div>

    </div>
</div>

</body>
</html>