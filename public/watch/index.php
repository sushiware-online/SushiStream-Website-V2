<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use MongoDB\BSON\ObjectId;

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushi_stream");

$id = $_GET['v'] ?? '';
$video = null;

try {
    if (preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
        $video = $db->videos->findOne(['_id' => new ObjectId($id)]);
    }
} catch (Exception $e) {}

if (!$video) {
    http_response_code(404);
    exit('Video not found.');
}

$status = $video['status'] ?? 'ready';
$mp4Url = !empty($video['files']['mp4']) ? '/user-content/videos/' . rawurlencode($video['files']['mp4']) : null;
$tsUrl = !empty($video['files']['ts']) ? '/user-content/videos/' . rawurlencode($video['files']['ts']) : null;
$thumbUrl = !empty($video['files']['thumb']) ? '/user-content/videos/' . rawurlencode($video['files']['thumb']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($video['title']); ?> - SushiStream v2</title>
    <link rel="stylesheet" href="/global.css">
    <link rel="stylesheet" href="/watch/watch.css">
    <script src="https://cdn.jsdelivr.net/gh/phoboslab/jsmpeg@master/jsmpeg.min.js"></script>
</head>
<body>
    <p><a href="/videos/index.php">&larr; Back to Videos</a></p>
    <h1><?php echo htmlspecialchars($video['title']); ?></h1>

    <?php if ($status === 'processing'): ?>
        <div class="status-banner banner-processing">
            <h2>Video is currently processing...</h2>
            <p>FFmpeg is encoding MP4 (H.264+AAC) and MPEG-1 (TS+MP2). Refresh in a few moments.</p>
        </div>
    <?php elseif ($status === 'failed'): ?>
        <div class="status-banner banner-failed">
            <h2>Video conversion failed.</h2>
            <pre><?php echo htmlspecialchars($video['error_log'] ?? 'Unknown conversion error'); ?></pre>
        </div>
    <?php else: ?>
        <div class="tabs">
            <button class="tab-btn active" onclick="switchPlayer('mp4')">HTML5 Player (MP4)</button>
            <button class="tab-btn" onclick="switchPlayer('jsmpeg')">WebAssembly Player (MPEG-1)</button>
        </div>

        <div id="mp4-container" class="player-container">
            <video id="mp4-player" controls poster="<?php echo htmlspecialchars($thumbUrl); ?>">
                <source src="<?php echo htmlspecialchars($mp4Url); ?>" type="video/mp4">
                Your browser does not support standard HTML5 video playback.
            </video>
        </div>

        <div id="jsmpeg-container" class="player-container" style="display: none;">
            <canvas id="jsmpeg-canvas"></canvas>
            <div class="player-controls">
                <button onclick="jsmpegPlayer.play()">Play</button>
                <button onclick="jsmpegPlayer.pause()">Pause</button>
                <button onclick="jsmpegPlayer.volume = 0">Mute</button>
                <button onclick="jsmpegPlayer.volume = 1">Unmute</button>
            </div>
        </div>

        <div class="meta-box">
            <div><strong>Uploader:</strong> <?php echo htmlspecialchars($video['uploader']); ?></div>
            <div><strong>Uploaded:</strong> <?php echo date("F j, Y, g:i a", $video['uploaded_at']->toDateTime()->getTimestamp()); ?></div>
            <div><strong>Resolution:</strong> 240x136</div>
            <div class="downloads">
                <strong>Raw Streams:</strong>
                <a href="<?php echo htmlspecialchars($mp4Url); ?>" download>Download MP4 (H.264)</a> |
                <a href="<?php echo htmlspecialchars($tsUrl); ?>" download>Download MPEG-1 TS (For M5 / Clients)</a>
            </div>
        </div>

        <script>
            let jsmpegPlayer = null;
            function switchPlayer(type) {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                if (type === 'mp4') {
                    document.querySelectorAll('.tab-btn')[0].classList.add('active');
                    document.getElementById('mp4-container').style.display = 'block';
                    document.getElementById('jsmpeg-container').style.display = 'none';
                    if (jsmpegPlayer) jsmpegPlayer.pause();
                } else {
                    document.querySelectorAll('.tab-btn')[1].classList.add('active');
                    document.getElementById('mp4-container').style.display = 'none';
                    document.getElementById('jsmpeg-container').style.display = 'block';
                    document.getElementById('mp4-player').pause();

                    if (!jsmpegPlayer) {
                        jsmpegPlayer = new JSMpeg.Player("<?php echo $tsUrl; ?>", {
                            canvas: document.getElementById('jsmpeg-canvas'),
                            autoplay: true,
                            audio: true,
                            loop: false
                        });
                    } else {
                        jsmpegPlayer.play();
                    }
                }
            }
        </script>
    <?php endif; ?>
</body>
</html>
