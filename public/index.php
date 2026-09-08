<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use Sushi\SushiStreamWebsite\Services\AuthService;

session_start();

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushistream_v2");

$auth = new AuthService($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SushiStream v2</title>
    <link rel="stylesheet" href="/global.css">
</head>
<body>
    <h1>SushiStream v2</h1>
    <p>High-efficiency multi-format video streaming (MP4 &amp; WebAssembly MPEG-1).</p>

    <?php if ($auth->isAuthorized()): ?>
        <p>Logged in as: <strong><?php echo htmlspecialchars($auth->getLoggedInUsername()); ?></strong></p>
        <form method="post" action="/logout/index.php" style="display:inline;">
            <button type="submit">Logout</button>
        </form>
        <a href="/upload/index.php"><button>Upload Video</button></a>
    <?php else: ?>
        <a href="/login/index.php"><button>Login</button></a>
        <a href="/register/index.php"><button>Register</button></a>
    <?php endif; ?>

    <a href="/videos/index.php"><button>Watch Videos</button></a>
</body>
</html>

