<?php 
header('Content-Type: text/html; charset=utf-8');
require_once('security.php');
require_once('conn_inc.php');

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $class_id = $_POST['class_id'];
    $section_id = $_POST['section_id'];

    if (!empty($class_id) && !empty($section_id)) {
        $stmt = $conn->prepare("INSERT INTO class_sections (class_id, section_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $class_id, $section_id);
        $stmt->execute();
        $stmt->close();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch classes & sections for dropdown
$classes = $conn->query("SELECT * FROM classes ORDER BY title");
$sections = $conn->query("SELECT * FROM sections ORDER BY title");

// Fetch class_sections list with JOIN
$result = $conn->query("
    SELECT cs.id, c.title AS class_name, s.title AS section_name
    FROM class_sections cs
    JOIN classes c ON cs.class_id = c.id
    JOIN sections s ON cs.section_id = s.id
    ORDER BY c.id, s.id
");
?>
<!DOCTYPE html>
<html lang="en">
<head><?php require_once('meta_inc.php'); ?></head>
<body>
<?php require_once('navbar.php'); ?>

<div class="container">
  <div class="row">
    <div class="col-md-12 ">

      <!-- Panel Add -->
      <div class="panel panel-primary">
        <div class="panel-heading"><h3 class="panel-title">Add Class + Section</h3></div>
        <div class="panel-body">
          <form method="post">
            <div class="form-group">
              <label>Select Class</label>
              <select name="class_id" class="form-control" required>
                <option value="">-- Select Class --</option>
                <?php while ($c = $classes->fetch_assoc()): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Select Section</label>
              <select name="section_id" class="form-control" required>
                <option value="">-- Select Section --</option>
                <?php while ($s = $sections->fetch_assoc()): ?>
                  <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="text-right">
              <button type="submit" class="btn btn-success">Submit</button>
              <button type="reset" class="btn btn-default">Reset</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Panel List -->
      <div class="panel panel-info">
        <div class="panel-heading"><h3 class="panel-title">Class Sections List</h3></div>
        <div class="panel-body">
          <?php if ($result->num_rows > 0): ?>
            <table class="table table-bordered table-hover">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Class</th>
                  <th>Section</th>
                </tr>
              </thead>
              <tbody>
                <?php $i=1; while($row=$result->fetch_assoc()): ?>
                  <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($row['class_name']) ?></td>
                    <td><?= htmlspecialchars($row['section_name']) ?></td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="alert alert-info">No records found.</div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>
