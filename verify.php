<?php
$DATABASE_URL = getenv("DATABASE_URL");
$db = parse_url($DATABASE_URL);
$pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname=".ltrim($db['path'],"/"),
$db['user'],$db['pass']);

$token=$_GET["token"]??"";
if($_SERVER["REQUEST_METHOD"]=="POST"){
$stmt=$pdo->prepare("UPDATE users SET verified=true WHERE verify_token=?");
$stmt->execute([$token]);
echo "<h2>Verification Successful! Go back to Telegram and click Completed Verification.</h2>";
exit;
}
?>
<html>
<head>
<title>Verification</title>
<style>
body{font-family:sans-serif;text-align:center;padding:50px;background:#f5f5f5}
button{padding:15px 30px;font-size:18px;background:#0088cc;color:#fff;border:none;border-radius:8px}
</style>
</head>
<body>
<h2>Verify Your Account</h2>
<form method="post">
<button type="submit">Verify Now</button>
</form>
</body>
</html>
