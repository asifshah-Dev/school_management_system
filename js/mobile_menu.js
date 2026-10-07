$(document).ready(function () {
    // Basic mobile dropdown toggle
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
  
    // Better dropdown handling for mobile
    $('.dropdown-toggle').click(function (e) {
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
    });
  
    // Close dropdown when clicking outside on mobile
    $(document).click(function (e) {
      if ($(window).width() < 768) {
        if (!$(e.target).closest('.navbar-nav li.dropdown').length) {
          $('.navbar-nav li.open').removeClass('open').find('.dropdown-menu').slideUp(200);
        }
      }
    });
  });
  