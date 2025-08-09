<?php
require('connection.php');

if(isset($_POST['submit'])){
    $username = $_POST['username'];
    $password = $_POST['password'];
    $lastname= "benz";
    $email = "@gmail.com";
    $phone = "1234567890";
    $address = "diyan lang";
    $city = "city";
    $state ="antipolo";
    $zip = "1870";
    $country = "country";
    $created_at = date("Y-m-d H:i:s");
    $updated_at = date("Y-m-d H:i:s");
    $isactive = 1;


    $querycreate = "INSERT INTO users VALUES (null,'$username', '$password', '$lastname', '$email', '$phone', '$address', '$city', '$state', '$zip', '$country', '$created_at', '$updated_at', $isactive)";
    $resultcreate = mysqli_query($sqlconnection, $querycreate);

    echo '<script>alert("User created successfully!");</script>';
    echo '<script>window.location.href = "index.php";</script>';

}
else {
    echo '<script>window.location.href = "index.php";</script>';
}
?>