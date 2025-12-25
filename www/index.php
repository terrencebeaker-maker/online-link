<?php
header('Content-Type: text/html; charset=utf-8');

echo "<h2 style='color:green; font-family:sans-serif;'>✅ M-Pesa API is running successfully!</h2>";
echo "<p>Base URL: " . $_SERVER['HTTP_HOST'] . "</p>";
echo "<p>Time: " . date('Y-m-d H:i:s') . "</p>";
?>
