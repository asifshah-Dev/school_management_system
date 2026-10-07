<?php
ob_start();
require_once('conn_inc.php');





// Save role_modules assignments with permissions

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role_id = intval($_POST['role_id']);



    // Clear old permissions

    $conn->query("DELETE FROM role_modules WHERE role_id = $role_id");



    // Insert new permissions

    if (isset($_POST['modules'])) {

        foreach ($_POST['modules'] as $module_id => $perms) {

            $can_view   = isset($perms['view']) ? 1 : 0;

            $can_add    = isset($perms['add']) ? 1 : 0;

            $can_edit   = isset($perms['edit']) ? 1 : 0;

            $can_delete = isset($perms['delete']) ? 1 : 0;



            $stmt = $conn->prepare("INSERT INTO role_modules (role_id, module_id, can_view, can_add, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");

            $stmt->bind_param("iiiiii", $role_id, $module_id, $can_view, $can_add, $can_edit, $can_delete);

            $stmt->execute();

        }

    }



    $_SESSION['message'] = "Permissions updated successfully!";

    $_SESSION['message_type'] = "success";

    header("Location: role_modules.php?role_id=".$role_id);

    exit;

}



// Fetch roles

$roles = $conn->query("SELECT * FROM roles ORDER BY id ASC");



// Fetch modules with hierarchy

$modules_result = $conn->query("SELECT * FROM modules ORDER BY parent_id, title ASC");

$modules = [];

$child_modules = [];



while ($module = $modules_result->fetch_assoc()) {

    if ($module['type'] == 'Parent') {

        $modules[$module['id']] = $module;

        $modules[$module['id']]['children'] = [];

    } else {

        $child_modules[] = $module;

    }

}



// Assign children to parents

foreach ($child_modules as $child) {

    if (isset($modules[$child['parent_id']])) {

        $modules[$child['parent_id']]['children'][] = $child;

    }

}



// Selected role

$selected_role = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;



// Fetch assigned modules + permissions

$assigned = [];

if ($selected_role > 0) {

    $res = $conn->query("SELECT * FROM role_modules WHERE role_id = $selected_role");

    while ($row = $res->fetch_assoc()) {

        $assigned[$row['module_id']] = $row;

    }

}

?>



<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Role Modules Management</title>

  

    <style>

        .table th, .table td {

            text-align: left;

            font-size: 14px;

        }

        .child-module td:first-child {

            padding-left: 25px;

        }

        .permission-checkbox {

            margin-right: 5px;

        }

        .role-selector {

            max-width: 400px;

        }

        h1 {

            font-size: 24px !important;

        }

        .panel-title {

            font-size: 16px !important;

        }

        .text-muted {

            font-size: 13px;

        }

        .form-group label {

            font-size: 14px;

        }

        .btn {

            font-size: 13px;

        }

        .alert {

            font-size: 13px;

        }

    </style>

</head>

<body>



<?php require_once('navbar.php'); ?>



<div class="container">

    <div class="row">

        <div class="col-md-12 ">

            <!-- Page Header -->

            <h1 style="font-size: 24px;"><span class="glyphicon glyphicon-user"></span> Role Modules Management</h1>

            <p class="text-muted" style="font-size: 13px;">Assign module permissions to user roles</p>

            

            <?php if (isset($_SESSION['message'])): ?>

                <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible" style="font-size: 13px;">

                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>

                    <?php echo $_SESSION['message']; unset($_SESSION['message']); unset($_SESSION['message_type']); ?>

                </div>

            <?php endif; ?>

            

            <!-- Role Selection Form -->

            <div class="panel panel-outline-dark">

                <div class="panel-heading bg-dark">

                    <h3 class="panel-title fs-2 text-white" style="font-size: 16px;">Select Role</h3>

                </div>

                <div class="panel-body" >

                    <form method="get" action="" class="form-horizontal">

                        <div class="form-group">

                            <label class="col-sm-3 control-label " style="font-size:14px;">Choose a role</label>

                            <div class="col-sm-9" >

                                <select name="role_id" class="form-control role-selector" onchange="this.form.submit()" required style="font-size: 13px;">

                                    <option value="">-- Select Role --</option>

                                    <?php 

                                    $roles->data_seek(0); // Reset pointer

                                    while ($role = $roles->fetch_assoc()): ?>

                                        <option value="<?php echo $role['id']; ?>" <?php echo $selected_role==$role['id'] ? 'selected' : ''; ?> style="font-size: 13px;">

                                            <?php echo htmlspecialchars($role['title']); ?>

                                        </option>

                                    <?php endwhile; ?>

                                </select>

                            </div>

                        </div>

                        <div class="form-group">

                            <div class="col-sm-offset-3 col-sm-9">

                                <button type="submit" class="btn btn-danger" style="font-size: 13px;">

                                    <span class="glyphicon glyphicon-plus"></span> Load Modules

                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            

            <?php if ($selected_role): ?>

                <!-- Permissions Form -->

                <div class="panel panel-danger">

                    <div class="panel-heading">

                        <h3 class="panel-title text-white fs-2" style="font-size: 16px;">Module Permissions</h3>

                    </div>

                    <div class="panel-body">

                        <form method="post" action="">

                            <input type="hidden" name="role_id" value="<?php echo $selected_role; ?>">

                            <div class="table-responsive">

                                <table class="table table-bordered table-hover" style="font-size: 13px;">

                                    <thead>

                                        <tr>

                                            <th style="font-size: 13px;">Module</th>

                                            <th style="text-align: center; font-size: 13px;">View</th>

                                            <th style="text-align: center; font-size: 13px;">Add</th>

                                            <th style="text-align: center; font-size: 13px;">Edit</th>

                                            <th style="text-align: center; font-size: 13px;">Delete</th>

                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php foreach ($modules as $parent_id => $parent): 

                                            $parent_perms = isset($assigned[$parent_id]) ? $assigned[$parent_id] : ['can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0];

                                        ?>

                                            <tr>

                                                <td style="font-size: 13px;">

                                                    <span class="glyphicon glyphicon-folder-open"></span> 

                                                    <?php echo htmlspecialchars($parent['title']); ?>

                                                </td>

                                                <td style="text-align: center; font-size: 13px;">

                                                    <input type="checkbox" name="modules[<?php echo $parent_id; ?>][view]" class="permission-checkbox" id="view_<?php echo $parent_id; ?>" <?php echo $parent_perms['can_view'] ? 'checked' : ''; ?>>

                                                </td>

                                                <td style="text-align: center; font-size: 13px;">

                                                    <input type="checkbox" name="modules[<?php echo $parent_id; ?>][add]" class="permission-checkbox" id="add_<?php echo $parent_id; ?>" <?php echo $parent_perms['can_add'] ? 'checked' : ''; ?>>

                                                </td>

                                                <td style="text-align: center; font-size: 13px;">

                                                    <input type="checkbox" name="modules[<?php echo $parent_id; ?>][edit]" class="permission-checkbox" id="edit_<?php echo $parent_id; ?>" <?php echo $parent_perms['can_edit'] ? 'checked' : ''; ?>>

                                                </td>

                                                <td style="text-align: center; font-size: 13px;">

                                                    <input type="checkbox" name="modules[<?php echo $parent_id; ?>][delete]" class="permission-checkbox" id="delete_<?php echo $parent_id; ?>" <?php echo $parent_perms['can_delete'] ? 'checked' : ''; ?>>

                                                </td>

                                            </tr>

                                            <?php foreach ($parent['children'] as $child): 

                                                $child_perms = isset($assigned[$child['id']]) ? $assigned[$child['id']] : ['can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0];

                                            ?>

                                                <tr class="child-module">

                                                    <td style="font-size: 13px;">

                                                        <span class="glyphicon glyphicon-file"></span> 

                                                        <?php echo htmlspecialchars($child['title']); ?>

                                                    </td>

                                                    <td style="text-align: center; font-size: 13px;">

                                                        <input type="checkbox" name="modules[<?php echo $child['id']; ?>][view]" class="permission-checkbox" id="view_<?php echo $child['id']; ?>" <?php echo $child_perms['can_view'] ? 'checked' : ''; ?>>

                                                    </td>

                                                    <td style="text-align: center; font-size: 13px;">

                                                        <input type="checkbox" name="modules[<?php echo $child['id']; ?>][add]" class="permission-checkbox" id="add_<?php echo $child['id']; ?>" <?php echo $child_perms['can_add'] ? 'checked' : ''; ?>>

                                                    </td>

                                                    <td style="text-align: center; font-size: 13px;">

                                                        <input type="checkbox" name="modules[<?php echo $child['id']; ?>][edit]" class="permission-checkbox" id="edit_<?php echo $child['id']; ?>" <?php echo $child_perms['can_edit'] ? 'checked' : ''; ?>>

                                                    </td>

                                                    <td style="text-align: center; font-size: 13px;">

                                                        <input type="checkbox" name="modules[<?php echo $child['id']; ?>][delete]" class="permission-checkbox" id="delete_<?php echo $child['id']; ?>" <?php echo $child_perms['can_delete'] ? 'checked' : ''; ?>>

                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                            <div class="form-group ">

                                <div class=" col-sm-9 float-right m-3" >

                                    <button type="submit" class="btn btn-warning " style="font-size: 13px;">

                                        <span class="glyphicon glyphicon-save"></span> Save Permissions

                                    </button>

                                </div>

                            </div>

                        </form>

                    </div>

                </div>

            <?php else: ?>

                <!-- Empty State -->

                <div class="panel panel-info">

                    <div class="panel-body text-center" style="padding: 50px 15px;">

                        <span class="glyphicon glyphicon-user" style="font-size: 24px; color: #777;"></span>

                        <h2 class="text-muted" style="font-size: 20px;">Select a Role to Manage Permissions</h2>

                        <p class="text-muted fs-6" style="font-size: 13px;">Choose a role from the dropdown above to view and edit its module permissions.</p>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>



<script src="js/mobile_menu.js"></script>

</body>

</html>

<?php $conn->close(); ?>