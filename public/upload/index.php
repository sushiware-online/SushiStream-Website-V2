<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use Sushi\SushiStreamWebsite\Services\AuthService;
use Sushi\SushiStreamWebsite\Services\VideoUploadService;

session_start();

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushi_stream");

$auth = new AuthService($db);
$videoService = new VideoUploadService($db, $auth, __DIR__ . '/..');

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video'])) {
    if ($auth->isAuthorized()) {
        $title = $_POST['title'] ?? 'Untitled';
        $videoId = $videoService->uploadVideo($_FILES['video'], $title);

        if ($videoId) {
            $message = "Video uploaded successfully! Conversion queued. ID: $videoId";
        } else {
            $message = "Upload failed. Verify media format.";
        }
    } else {
        $message = "You must be logged in to upload.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload - SushiStream v2</title>
    <link rel="stylesheet" href="/global.css">
</head>
<body>
    <h1>Upload Video to SushiStream v2</h1>
    <?php if ($message): ?><p style="color: #00e5ff;"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>

    <?php if ($auth->isAuthorized()): ?>
    <form method="post" enctype="multipart/form-data">
        Title:<br><input type="text" name="title" required><br>
        Video File:<br><input type="file" name="video" accept="video/*" required><br>
        <button type="submit">Upload &amp; Encode</button>
    </form>
    <?php else: ?>
    <p>Please <a href="/login/index.php">login</a> to upload videos.</p>
    <?php endif; ?>
    <p><a href="/index.php">&larr; Back to Homepage</a></p>
</body>
</html>
