<?php
session_start();
session_destroy();
header("Location:  /wms-geo/login.php");
exit();
?>