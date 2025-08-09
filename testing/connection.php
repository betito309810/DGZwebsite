<?php
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'accounts';

$sqlconnection =  mysqli_connect($host, $user, $password, $database);
if (mysqli_connect_error()) {
    echo "Failed to connect to MySQL: " . mysqli_connect_error();
    echo "Please check your database connection settings.";
}
else {
    echo "Database connection successful!";
}
?>