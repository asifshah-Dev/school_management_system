<?php


?>

<?php
require_once('session_data.php');
  
    require_once('my_connection.php');
    $warning = '';
    $success = '';
   // $message='';
  if(isset($_POST['btnsave'])){
   // echo 'here is  me'; die();
        
     $title = $_POST['title'];
     $object_code = $_POST['object_code'];
     
          $check_qry = mysqli_query($my_connection, "select title,object_code from account_heads where title = '$title' and object_code = '$object_code'") or die (mysqli_error($my_connection));

echo $num_rows = mysqli_num_rows($check_qry); 


if($num_rows == 0){
    
    
    
    

        
     //$save_query = mysqli_query($my_connection,"INSERT INTO `downloads`( `user_id`, `title`, `details`, `path`) VALUES ('$user_id','$title','$details','$path')") or die (mysqli_error($my_connection)); 
      
      
       $insert_qry = "INSERT INTO `account_heads`( `title`,`object_code`) VALUES ('$title','$object_code')"; 
    mysqli_query($my_connection, $insert_qry) or die(mysqli_error($my_connection));
    
        
        $success = '<div class="alert alert-success alert-block alert-dismissible fade in iconic-alert" role="alert">
  									<div class="alert-icon">
  										<span class="gcon gcon-emoji-happy centered-xy"></span>
  									</div>
  									<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true"><span class="mcon mcon-close"></span></span></button>
  									<strong>Success!</strong> Record Inserted.
 							</div>'; 
                            
                
}

else{
    
 $warning = '<div class="alert alert-danger alert-block alert-dismissible fade in iconic-alert" role="alert">
									<div class="alert-icon">
										<span class="gcon gcon-hand centered-xy"></span>
									</div>
									<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true"><span class="mcon mcon-close"></span></span></button>
									<strong>Oh snap!</strong> Record Already exist
								</div>';  
 
}
       
       
        // our target folder
        
         

   }
   
    


?>
  
<!DOCTYPE html>
<html>
    

<?php require_once('my_meta.php'); ?>


    <body>


        <!-- Navigation Bar-->
        <head>
        
         
         <?php require_once('my_header.php'); ?>
        
        <link href="assets/plugins/bootstrap-sweetalert/sweet-alert.css" rel="stylesheet" type="text/css" />
           <!-- DataTables -->
        <link href="assets/plugins/datatables/jquery.dataTables.min.css" rel="stylesheet" type="text/css"/>
        <link href="assets/plugins/datatables/buttons.bootstrap.min.css" rel="stylesheet" type="text/css"/>
        <link href="assets/plugins/datatables/fixedHeader.bootstrap.min.css" rel="stylesheet" type="text/css"/>
        <link href="assets/plugins/datatables/responsive.bootstrap.min.css" rel="stylesheet" type="text/css"/>
        <link href="assets/plugins/datatables/scroller.bootstrap.min.css" rel="stylesheet" type="text/css"/>
        <link href="assets/plugins/datatables/dataTables.colVis.css" rel="stylesheet" type="text/css"/>
        <link href="assets/plugins/datatables/dataTables.bootstrap.min.css" rel="stylesheet" type="text/css"/>
        <link href="assets/plugins/datatables/fixedColumns.dataTables.min.css" rel="stylesheet" type="text/css"/>
        </head>
        
       
        <!-- End Navigation Bar-->


        <div class="wrapper">
            <div class="container">
            
            
                <!-- Page-Title -->
                <div class="row">
                    <div class="col-sm-12">
                        <div class="btn-group pull-right m-t-15">
                            <button type="button" class="btn btn-default dropdown-toggle waves-effect waves-light" data-toggle="dropdown" aria-expanded="false">Settings <span class="m-l-5"><i class="fa fa-cog"></i></span></button>
                            <ul class="dropdown-menu drop-menu-right" role="menu">
                                <li><a href="#">Action</a></li>
                                <li><a href="#">Another action</a></li>
                                <li><a href="#">Something else here</a></li>
                                <li class="divider"></li>
                                <li><a href="#">Separated link</a></li>
                            </ul>
                        </div>

                        <h4 class="page-title">Add Accounts Heads</h4>
                        <ol class="breadcrumb">
                        
                        </ol>
                    </div>
                </div>


                <div class="row">
                    <div class="col-sm-12">
                        <div class="card-box">
                            
                            
                            <div class="row">
                            <?php echo $success; ?>
            <?php echo $warning; ?>
            <?php
            
            if(isset($_GET['msg']) == 'updated'){
                echo '<div class="alert alert-info alert-block alert-dismissible fade in iconic-alert" role="alert">
									<div class="alert-icon">
										<span class="gcon gcon-hand centered-xy"></span>
									</div>
									<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true"><span class="mcon mcon-close"></span></span></button>
									<strong>Info </strong> Record updated
								</div>';
            }
            
             ?>
                                <div class="col-md-6">
                                    <form class="form-horizontal" role="form" method="post">
                                        
                                        
                                        

                                        <div class="form-group">
                                            <label class="col-md-4 control-label">Title</label>
                                            <div class="col-md-8">
                                                <input type="text" class="form-control" placeholder="" name = "title" />
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="col-md-4 control-label">Object Code</label>
                                            <div class="col-md-8">
                                                <input type="text" class="form-control" placeholder="" name = "object_code" />
                                            </div>
                                        </div>
                                        
                                        
<label class="col-md-4 control-label"></label>
<div class="form-group">
<button type="submit" class="btn btn-success waves-effect waves-light m-l-8 btn-md" name ="btnsave" >Submit</button>
</div>

                                    </form>
                                </div>

                                


                            </div>
                        </div>
                    </div>
                </div>







                <!-- End row -->

                


                <!-- Forms -->
                


                <!-- datatable -->
                
                
                <div class="row">
                    <div class="col-sm-12">
                        <div class="card-box table-responsive">
                            <h4 class="m-t-0 header-title"><b>Accounts Heads</b></h4>
                            

                            <table id="datatable-buttons" class="table table-striped table-bordered">
                                <thead>
                                <tr>
                                    <th>Serial #</th>
                                    <th>Title</th>
                                    <th>Object Code</th>
                                  
                                  <th>Actions</th>
                                </tr>
                                </thead>


                                <tbody>
                 <?php  $select_qry = mysqli_query($my_connection, "select id,title,object_code,dated,status from account_heads where status = 0 order by id desc") or die(mysqli_error($my_connection));
                                 
                                 $i=1;
                                  while($row = mysqli_fetch_array($select_qry)){
                                    $id = $row['id'];
                                    
                                    echo ' <tr role="row" class="odd">';
                                    
                                    echo '<td> '.$i++.'</td>';
							              echo  '<td class="">';
                                          echo $row['title'];
                                          echo '</td>';
                                          echo  '<td class="">';
                                          echo $row['object_code'];
                                          echo '</td>';
                                        echo '<td>';
                                            echo '<a class="btn btn-default waves-effect waves-light btn-md" href = "accounts_heads_edit.php?id='.$id.'"> Edit</a>';
                                            echo '</td>';
							         echo '</tr>'; 
                                    
                                  }
                                  
                                   ?> 
                                
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                
                


                <!-- Footer -->
                <?php require_once('my_footer.php'); ?>
                <!-- End Footer -->

            </div> <!-- end container -->
        </div>
        <!-- end wrapper -->



        <!-- jQuery  -->
        <script src="assets/js/jquery.min.js"></script>
        <script src="assets/js/bootstrap.min.js"></script>
        <script src="assets/js/detect.js"></script>
        <script src="assets/js/fastclick.js"></script>
        <script src="assets/js/jquery.slimscroll.js"></script>
        <script src="assets/js/jquery.blockUI.js"></script>
        <script src="assets/js/waves.js"></script>
        <script src="assets/js/wow.min.js"></script>
        <script src="assets/js/jquery.nicescroll.js"></script>
        <script src="assets/js/jquery.scrollTo.min.js"></script>

  <script src="assets/plugins/bootstrap-sweetalert/sweet-alert.min.js"></script>
        <script src="assets/pages/jquery.sweet-alert.init.js"></script>

        <!-- App core js -->
        <script src="assets/js/jquery.core.js"></script>
        <script src="assets/js/jquery.app.js"></script>
        
        
         <script src="assets/plugins/datatables/jquery.dataTables.min.js"></script>
        <script src="assets/plugins/datatables/dataTables.bootstrap.js"></script>

        <script src="assets/plugins/datatables/dataTables.buttons.min.js"></script>
        <script src="assets/plugins/datatables/buttons.bootstrap.min.js"></script>
        <script src="assets/plugins/datatables/jszip.min.js"></script>
        <script src="assets/plugins/datatables/pdfmake.min.js"></script>
        <script src="assets/plugins/datatables/vfs_fonts.js"></script>
        <script src="assets/plugins/datatables/buttons.html5.min.js"></script>
        <script src="assets/plugins/datatables/buttons.print.min.js"></script>
        <script src="assets/plugins/datatables/dataTables.fixedHeader.min.js"></script>
        <script src="assets/plugins/datatables/dataTables.keyTable.min.js"></script>
        <script src="assets/plugins/datatables/dataTables.responsive.min.js"></script>
        <script src="assets/plugins/datatables/responsive.bootstrap.min.js"></script>
        <script src="assets/plugins/datatables/dataTables.scroller.min.js"></script>
        <script src="assets/plugins/datatables/dataTables.colVis.js"></script>
        <script src="assets/plugins/datatables/dataTables.fixedColumns.min.js"></script>

        <script src="assets/pages/datatables.init.js"></script>

        <!-- App core js -->
        <script src="assets/js/jquery.core.js"></script>
        <script src="assets/js/jquery.app.js"></script>

        <script type="text/javascript">
            $(document).ready(function () {
                $('#datatable').dataTable();
                $('#datatable-keytable').DataTable({keys: true});
                $('#datatable-responsive').DataTable();
                $('#datatable-colvid').DataTable({
                    "dom": 'C<"clear">lfrtip',
                    "colVis": {
                        "buttonText": "Change columns"
                    }
                });
                $('#datatable-scroller').DataTable({
                    ajax: "assets/plugins/datatables/json/scroller-demo.json",
                    deferRender: true,
                    scrollY: 380,
                    scrollCollapse: true,
                    scroller: true
                });
                var table = $('#datatable-fixed-header').DataTable({fixedHeader: true});
                var table = $('#datatable-fixed-col').DataTable({
                    scrollY: "300px",
                    scrollX: true,
                    scrollCollapse: true,
                    paging: false,
                    fixedColumns: {
                        leftColumns: 1,
                        rightColumns: 1
                    }
                });
            });
            TableManageButtons.init();

        </script>
        

    </body>

<!-- Mirrored from coderthemes.com/ubold_2.0/menu_2/form-elements.html by HTTrack Website Copier/3.x [XR&CO'2014], Wed, 17 Aug 2016 06:17:48 GMT -->
</html>