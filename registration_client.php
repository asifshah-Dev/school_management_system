<!DOCTYPE html>
<html lang="en">
<head>
  <title>Gold Gym</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap CSS & JS -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
  
  <link rel = "stylesheet" href = "css/mystyle.css" />

</head>
<body>


<?php require_once('navbar.php'); ?>


<!-- Dashboard -->
<div class="container">
  <h2 class="dashboard-title">Registration</h2>

  <div class="panel panel-primary">
    <div class="panel-heading">
      User Registration
    </div>
    <div class="panel-body">
      <form action="register_user.php" method="post">
        <div class="row">
          <div class="col-md-4">
            <div class="form-group">
              <label for="first_name">First Name</label>
              <input type="text" class="form-control" id="first_name" name="first_name" required>
            </div>
          </div>

          <div class="col-md-4">
            <div class="form-group">
              <label for="last_name">Last Name</label>
              <input type="text" class="form-control" id="last_name" name="last_name" required>
            </div>
          </div>

          <div class="col-md-4">
            <div class="form-group">
              <label for="email">Email</label>
              <input type="email" class="form-control" id="email" name="email" required>
            </div>
          </div>
        </div> <!-- /.row -->

        <div class="row">
          <div class="col-md-4">
            <div class="form-group">
              <label for="phone">Phone</label>
              <input type="text" class="form-control" id="phone" name="phone">
            </div>
          </div>

          <div class="col-md-4">
            <div class="form-group">
              <label for="username">Username</label>
              <input type="text" class="form-control" id="username" name="username" required>
            </div>
          </div>

          <div class="col-md-4">
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" class="form-control" id="password" name="password" required>
            </div>
          </div>
        </div> <!-- /.row -->

        <button type="submit" class="btn btn-primary">Register</button>
      </form>
    </div>
  </div>
</div>




<!-- Fix for dropdown on mobile -->
<script>
  $('.dropdown-toggle').click(function (e) {
    if ($(window).width() < 768) {
      e.preventDefault();
      var $parentLi = $(this).parent('li');
      if ($parentLi.hasClass('open')) {
        $parentLi.removeClass('open');
      } else {
        $('.navbar-nav li.open').removeClass('open');
        $parentLi.addClass('open');
      }
    }
  });
</script>

<script>
$(document).ready(function() {
  // Better dropdown handling for mobile
  $('.dropdown-toggle').click(function(e) {
    if ($(window).width() < 768) {
      e.preventDefault();
      var $parentLi = $(this).closest('li');
      var $openMenu = $('.navbar-nav li.open');
      
      // Close any other open menus
      $openMenu.not($parentLi).removeClass('open').find('.dropdown-menu').slideUp(200);
      
      // Toggle current menu
      $parentLi.toggleClass('open');
      $parentLi.find('.dropdown-menu').stop(true, true).slideToggle(200);
    }
    // On desktop, let Bootstrap handle it normally
  });

  // Close dropdown when clicking outside on mobile
  $(document).click(function(e) {
    if ($(window).width() < 768) {
      if (!$(e.target).closest('.navbar-nav li.dropdown').length) {
        $('.navbar-nav li.open').removeClass('open').find('.dropdown-menu').slideUp(200);
      }
    }
  });
});
</script>

</body>
</html>
