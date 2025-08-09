<?php
require('connection.php');

    $query ='SELECT * FROM users';
    $reader = mysqli_query($sqlconnection, $query);


?> 