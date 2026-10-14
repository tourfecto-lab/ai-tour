<?php
$password = "123123123";

// توليد الهاش الآمن
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

echo "كلمة المرور: " . $password . "<br>";
echo "الهاش الناتج: " . $hashed_password;
?>