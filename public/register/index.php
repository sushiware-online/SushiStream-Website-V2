<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use Sushi\SushiStreamWebsite\Services\AuthService;

session_start();

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushi_stream");

$auth = new AuthService($db);
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $inviteKey = $_POST['invite_key'] ?? '';

    if ($auth->register($username, $password, $inviteKey)) {
        $message = "Registration successful! You can now log in.";
    } else {
        $message = "Registration failed: Username exists or invalid invite key.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SushiStream v2 - Register</title>
    <link rel="stylesheet" href="/global.css">
</head>
<body>
    <h1>Register for SushiStream v2</h1>
    <?php if ($message): ?><p style="color: #00e5ff;"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
    <form method="post">
        Username:<br><input type="text" name="username" required><br>
        Password:<br><input type="password" name="password" required><br>
        Invite Key:<br><input type="text" name="invite_key" required><br>
        <button type="submit">Register</button>
    </form>
    <p><a href="/index.php">&larr; Back to Homepage</a></p>
</body>
</html>
