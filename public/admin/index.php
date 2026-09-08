<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use Dotenv\Dotenv;

session_start();

if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
    $dotenv->safeLoad();
}

$masterPass = $_ENV['MASTER_PASSWORD'] ?? getenv('MASTER_PASSWORD') ?: 'SushiMasterPass2026';

if (!isset($_SESSION['is_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['master_password'] ?? '') === $masterPass) {
            $_SESSION['is_admin'] = true;
        } else {
            $error = "Invalid master password!";
        }
    }

    if (!isset($_SESSION['is_admin'])) {
        ?>
        <!DOCTYPE html>
        <html><head><title>Admin Login - SushiStream v2</title><link rel="stylesheet" href="/global.css"></head><body>
        <h1>SushiStream v2 Admin Login</h1>
        <?php if (!empty($error)) echo "<p style='color:#ff5252;'>$error</p>"; ?>
        <form method="post">
            Master Password: <input type="password" name="master_password" required>
            <button type="submit">Authorize</button>
        </form>
        </body></html>
        <?php
        exit;
    }
}

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushistream_v2");
$message = "";

if (isset($_POST['generate_key'])) {
    $key = bin2hex(random_bytes(16));
    $db->invite_keys->insertOne([
        'key' => $key,
        'used' => false,
        'created_at' => new \MongoDB\BSON\UTCDateTime()
    ]);
    $message = "Generated Key: $key";
}

$inviteKeys = $db->invite_keys->find([], ['sort' => ['created_at' => -1]]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>SushiStream v2 Admin Panel</title>
    <link rel="stylesheet" href="/global.css">
</head>
<body>
    <h1>SushiStream v2 Admin Panel</h1>
    <?php if ($message): ?><p style="color:#00e5ff;"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>

    <h2>Invite Key Manager</h2>
    <form method="post">
        <button type="submit" name="generate_key">Generate New Key</button>
    </form>

    <h3>Existing Keys</h3>
    <table border="1" cellpadding="8" style="border-collapse: collapse; border-color: #444;">
        <tr><th>Key</th><th>Used</th><th>Created At</th></tr>
        <?php foreach ($inviteKeys as $keyDoc): ?>
        <tr>
            <td><code><?php echo htmlspecialchars($keyDoc['key']); ?></code></td>
            <td><?php echo $keyDoc['used'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo $keyDoc['created_at']->toDateTime()->format('Y-m-d H:i:s'); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p><a href="/index.php">&larr; Back to Homepage</a></p>
</body>
</html>
