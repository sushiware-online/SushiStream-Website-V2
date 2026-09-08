<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushi_stream");

$videos = $db->videos->find([], ['sort' => ['uploaded_at' => -1]]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Browse Videos - SushiStream v2</title>
    <link rel="stylesheet" href="/global.css">
    <link rel="stylesheet" href="videos.css">
</head>
<body>
    <div style="width: 100%; margin-bottom: 20px;">
        <h1>SushiStream v2 Videos</h1>
        <a href="/index.php">&larr; Homepage</a> | <a href="/upload/index.php">Upload New Video</a>
    </div>

    <div class="video-grid">
    <?php foreach ($videos as $video): 
        $id = (string)$video['_id'];
        $status = $video['status'] ?? 'ready';
        $thumb = ($status === 'ready' && !empty($video['files']['thumb']))
            ? '/user-content/videos/' . rawurlencode($video['files']['thumb'])
            : '/sushi.png';
    ?>
        <div class="video-card" onclick="window.location.href='/watch/index.php?v=<?php echo $id; ?>'">
            <img src="<?php echo htmlspecialchars($thumb); ?>" alt="Thumbnail" class="thumbnail">
            <div class="meta">
                <div class="title"><?php echo htmlspecialchars($video['title']); ?></div>
                <div class="uploader">Uploader: <?php echo htmlspecialchars($video['uploader']); ?></div>
                <div class="status-badge status-<?php echo $status; ?>"><?php echo strtoupper($status); ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</body>
</html>
