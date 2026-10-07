<?php
ob_start();
require_once('conn_inc.php');



// Handle Add/Edit

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $id = intval($_POST['id']);

    $title = trim($_POST['title']);

    $url = trim($_POST['url']);

    $type = $_POST['type'];

    $parent_id = ($type == 'Child' && !empty($_POST['parent_id'])) ? intval($_POST['parent_id']) : NULL;

    $desc = trim($_POST['description']);



    if ($id) {

        $stmt = $conn->prepare("UPDATE modules SET title=?, url=?, type=?, parent_id=?, description=? WHERE id=?");

        $stmt->bind_param("sssssi", $title, $url, $type, $parent_id, $desc, $id);

    } else {

        $stmt = $conn->prepare("INSERT INTO modules (title,url,type,parent_id,description) VALUES (?,?,?,?,?)");

        $stmt->bind_param("sssss", $title, $url, $type, $parent_id, $desc);

    }



    $stmt->execute();

    $stmt->close();

    header("Location: modules.php");

    exit();

}



// Handle Delete

if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    $conn->query("DELETE FROM modules WHERE id=$id");

    header("Location: modules.php");

    exit();

}



// Fetch modules and parents

$modules_result = $conn->query("SELECT m.*, p.title AS parent_title FROM modules m LEFT JOIN modules p ON m.parent_id = p.id ORDER BY parent_id ");

$parents = $conn->query("SELECT id,title FROM modules WHERE type='Parent' ORDER BY title");



// Organize modules: parents followed by their children

$modules = [];

$children = [];

while ($row = $modules_result->fetch_assoc()) {

    if ($row['parent_id'] === null) {

        $modules[$row['id']] = $row;

        $modules[$row['id']]['children'] = [];

    } else {

        $children[$row['parent_id']][] = $row;

    }

}

foreach ($children as $parent_id => $child_list) {

    if (isset($modules[$parent_id])) {

        $modules[$parent_id]['children'] = $child_list;

    }

}



// Fetch module data if editing

$edit_data = ['id'=>'','title'=>'','url'=>'','type'=>'Parent','parent_id'=>'','description'=>''];

if (isset($_GET['edit'])) {

    $id = intval($_GET['edit']);

    $stmt = $conn->prepare("SELECT * FROM modules WHERE id=?");

    $stmt->bind_param("i",$id);

    $stmt->execute();

    $res = $stmt->get_result();

    if ($res->num_rows > 0) $edit_data = $res->fetch_assoc();

    $stmt->close();

}

?>



<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Modules Management</title>

    

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

    <link rel="stylesheet" href="css/mystyle.css" />

    <style>

        .table th, .table td {

            text-align: left;

        }

        .child-module td:first-child {

            padding-left: 25px;

        }

    </style>

    <script>

        $(document).ready(function() {

            console.log('jQuery loaded, version:', $.fn.jquery);

            document.getElementById('type').addEventListener('change', function(){

                console.log('Type changed to:', this.value);

                document.getElementById('parent_field').style.display = this.value=='Child'?'block':'none';

            });

        });

    </script>

</head>

<body>



<?php require_once('navbar.php'); ?>



<div class="container">

    <div class="row">

        <div class="col-md-12 ">

            <!-- Add/Edit Module Form -->

            <div class="panel panel-primary">

                <div class="panel-heading">

                    <h3 class="panel-title"><?php echo $edit_data['id'] ? 'Edit Module' : 'Add New Module'; ?></h3>

                </div>

                <div class="panel-body">

                    <form method="post" class="form-horizontal">

                        <input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>">

                        

                        <div class="form-group">

                            <label class="col-sm-3 control-label">Title <span style="color: red;">*</span></label>

                            <div class="col-sm-9">

                                <input type="text" name="title" class="form-control" required value="<?php echo htmlspecialchars($edit_data['title']); ?>">

                            </div>

                        </div>

                        

                        <div class="form-group">

                            <label class="col-sm-3 control-label">URL</label>

                            <div class="col-sm-9">

                                <input type="text" name="url" class="form-control" value="<?php echo htmlspecialchars($edit_data['url']); ?>">

                                <span class="help-block">Leave empty if this is a parent module</span>

                            </div>

                        </div>

                        

                        <div class="form-group">

                            <label class="col-sm-3 control-label">Type <span style="color: red;">*</span></label>

                            <div class="col-sm-9">

                                <select name="type" id="type" class="form-control" required>

                                    <option value="Parent" <?php echo ($edit_data['type']=='Parent')?'selected':''; ?>>Parent Module</option>

                                    <option value="Child" <?php echo ($edit_data['type']=='Child')?'selected':''; ?>>Child Module</option>

                                </select>

                            </div>

                        </div>

                        

                        <div class="form-group" id="parent_field" style="display: <?php echo ($edit_data['type']=='Child')?'block':'none'; ?>">

                            <label class="col-sm-3 control-label">Parent Module</label>

                            <div class="col-sm-9">

                                <select name="parent_id" class="form-control">

                                    <option value="">-- Select Parent --</option>

                                    <?php while($p=$parents->fetch_assoc()): ?>

                                        <option value="<?php echo $p['id']; ?>" <?php echo ($p['id']==$edit_data['parent_id'])?'selected':''; ?>>

                                            <?php echo htmlspecialchars($p['title']); ?>

                                        </option>

                                    <?php endwhile; ?>

                                    <?php $parents->data_seek(0); ?>

                                </select>

                            </div>

                        </div>

                        

                        <div class="form-group">

                            <label class="col-sm-3 control-label">Description</label>

                            <div class="col-sm-9">

                                <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($edit_data['description']); ?></textarea>

                            </div>

                        </div>

                        

                        <div class="form-group">

                            <div class="col-sm-offset-3 col-sm-9">

                                <button type="submit" class="btn btn-success">

                                    <span class="glyphicon glyphicon-<?php echo $edit_data['id']?'refresh':'plus'; ?>"></span>

                                    <?php echo $edit_data['id']?'Update Module':'Add Module'; ?>

                                </button>

                                <?php if($edit_data['id']): ?>

                                    <a href="modules.php" class="btn btn-default">

                                        <span class="glyphicon glyphicon-remove"></span> Cancel

                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            

            <!-- Modules Table -->

            <div class="panel panel-info">

                <div class="panel-heading">

                    <h3 class="panel-title">Modules List</h3>

                </div>

                <div class="panel-body">

                    <div class="table-responsive">

                        <table class="table table-bordered table-hover">

                            <thead>

                                <tr>

                                    <th>#</th>

                                    <th>Title</th>

                                    <th>Type</th>

                                    <th>Parent</th>

                                    <th>Actions</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php 

                                $i = 1;

                                foreach ($modules as $module): 

                                    $isChild = $module['type'] == 'Child';

                                ?>

                                    <tr class="<?php echo $isChild ? 'child-module' : ''; ?>">

                                        <td><?php echo $i++; ?></td>

                                        <td>

                                            <?php echo htmlspecialchars($module['title']); ?>

                                            <?php if (!empty($module['url'])): ?>

                                                <br><small class="text-muted"><?php echo htmlspecialchars($module['url']); ?></small>

                                            <?php endif; ?>

                                            <?php if (!empty($module['description'])): ?>

                                                <br><small class="text-muted"><?php echo htmlspecialchars($module['description']); ?></small>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php echo $isChild ? 'Child' : 'Parent'; ?>

                                        </td>

                                        <td>

                                            <?php echo $isChild && !empty($module['parent_title']) ? htmlspecialchars($module['parent_title']) : '—'; ?>

                                        </td>

                                        <td>

                                            <a href="?edit=<?php echo $module['id']; ?>" class="btn btn-xs btn-warning">

                                                <span class="glyphicon glyphicon-edit"></span> Edit

                                            </a>

                                            <a href="?delete=<?php echo $module['id']; ?>" class="btn btn-xs btn-danger" onclick="return confirm('Are you sure you want to delete this module?')">

                                                <span class="glyphicon glyphicon-trash"></span> Delete

                                            </a>

                                        </td>

                                    </tr>

                                    <?php foreach ($module['children'] as $child): ?>

                                        <tr class="child-module">

                                            <td><?php echo $i++; ?></td>

                                            <td>

                                                <?php echo htmlspecialchars($child['title']); ?>

                                                <?php if (!empty($child['url'])): ?>

                                                    <br><small class="text-muted"><?php echo htmlspecialchars($child['url']); ?></small>

                                                <?php endif; ?>

                                                <?php if (!empty($child['description'])): ?>

                                                    <br><small class="text-muted"><?php echo htmlspecialchars($child['description']); ?></small>

                                                <?php endif; ?>

                                            </td>

                                            <td>Child</td>

                                            <td><?php echo htmlspecialchars($module['title']); ?></td>

                                            <td>

                                                <a href="?edit=<?php echo $child['id']; ?>" class="btn btn-xs btn-warning">

                                                    <span class="glyphicon glyphicon-edit"></span> Edit

                                                </a>

                                                <a href="?delete=<?php echo $child['id']; ?>" class="btn btn-xs btn-danger" onclick="return confirm('Are you sure you want to delete this module?')">

                                                    <span class="glyphicon glyphicon-trash"></span> Delete

                                                </a>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>



<script src="js/mobile_menu.js"></script>

</body>

</html>

<?php $conn->close(); ?>