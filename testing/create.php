<?php
require('connection.php');

if (isset($_POST['submit'])){
    $firstname = $_POST['username'];
    $password = $_POST['password'];

$createquery = "INSERT INTO users VALUES (null, '$firstname', '$password')";
$createor = mysqli_query($sqlconnection,$createquery);

 echo '<script>alert("Account Created")</script>';
 echo '<script>window.location.href ="index.php"</script>';
}
else{ echo '<script>window.location.href ="index.php"</script>';
}
?>  