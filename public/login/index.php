<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use Sushi\SushiStreamWebsite\Services\AuthService;

session_start();

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushistream_v2");

$auth = new AuthService($db);
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($auth->login($username, $password)) {
        header("Location: /index.php");
        exit;
    } else {
        $message = "Invalid username or password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SushiStream v2 - Login</title>
    <link rel="stylesheet" href="/global.css">
</head>
<body>
    <h1>Login to SushiStream v2</h1>
    <?php if ($message): ?><p style="color: #ff5252;"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
    <form method="post">
        Username:<br><input type="text" name="username" required><br>
        Password:<br><input type="password" name="password" required><br>
        <button type="submit">Login</button>
    </form>
    <p><a href="/index.php">&larr; Back to Homepage</a></p>
</body>
</html>
