<?php
// ডাটাবেস তথ্য
$servername = "localhost"; 
$username   = "bloodare_bloodarenabd";           
$password   = "3nyZL9645Lk7k6gMgsP6"; 
$dbname     = "bloodare_bloodarenabd";

// কানেকশন তৈরি
$conn = mysqli_connect($servername, $username, $password, $dbname);

// চেক করুন কানেকশন হয়েছে কিনা
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// বাংলা লেখা সাপোর্ট করার জন্য নিচের লাইনটি যোগ করুন
mysqli_set_charset($conn, "utf8mb4");

// সফল হলে এই ফাইলটি আর কিছু দেখাবে না (সিকিউরিটির জন্য ভালো)
?>
