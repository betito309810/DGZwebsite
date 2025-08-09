<?php
require('connection.php');

if(isset($_POST['submit'])){
    $username = $_POST['username'];
    $password = $_POST['password'];

    $querycreate = "INSERT INTO users (user_id, first_name, password_hash) VALUES (null,'$username', '$password')";
    $resultcreate = mysqli_query($sqlconnection, $querycreate);

    echo '<script>alert("User created successfully!");</script>';
    echo '<script>window.location.href = "index.php";</script>';

}
else {
    echo '<script>window.location.href = "index.php";</script>';
}
?>