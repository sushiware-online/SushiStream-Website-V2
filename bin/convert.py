<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use MongoDB\BSON\ObjectId;

$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushistream_v2");

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
$mpgUrl = !empty($video['files']['mpg']) ? '/user-content/videos/' . rawurlencode($video['files']['mpg']) : null;
$thumbUrl = !empty($video['files']['thumb']) ? '/user-content/videos/' . rawurlencode($video['files']['thumb']) : null;

$mp4Meta = $video['metadata']['mp4'] ?? [];
$mpgMeta = $video['metadata']['mpg'] ?? [];

function format_duration($seconds) {
    if (!$seconds) return null;
    $seconds = (int) round($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

function format_bytes($bytes) {
    if (!$bytes) return null;
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function format_bitrate($bps) {
    if (!$bps) return null;
    return round($bps / 1000) . ' kbps';
}

$duration = format_duration($mp4Meta['duration_seconds'] ?? $mpgMeta['duration_seconds'] ?? null);
$resolution = (!empty($mp4Meta['width']) && !empty($mp4Meta['height']))
    ? $mp4Meta['width'] . 'x' . $mp4Meta['height']
    : null;
$fps = $mp4Meta['fps'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($video['title']); ?> - SushiStream v2</title>
    <link rel="stylesheet" href="/global.css">
    <link rel="stylesheet" href="/watch/watch.css">
</head>
<body>
    <p><a href="/videos/index.php">&larr; Back to Videos</a></p>
    <h1><?php echo htmlspecialchars($video['title']); ?></h1>

    <?php if ($status === 'processing'): ?>
        <div class="status-banner banner-processing">
            <h2>Video is currently processing...</h2>
            <p>FFmpeg is encoding MP4 (H.264+AAC) and MPEG-1 (Program Stream). Refresh in a few moments.</p>
        </div>
    <?php elseif ($status === 'failed'): ?>
        <div class="status-banner banner-failed">
            <h2>Video conversion failed.</h2>
            <pre><?php echo htmlspecialchars($video['error_log'] ?? 'Unknown conversion error'); ?></pre>
        </div>
    <?php else: ?>
        <div class="tabs">
            <button class="tab-btn active" onclick="switchPlayer('mp4')">HTML5 Player (MP4)</button>
            <button class="tab-btn" onclick="switchPlayer('mpg')">MPEG-1 Player (MPG)</button>
        </div>

        <div id="mp4-container" class="player-container">
            <video id="mp4-player" controls poster="<?php echo htmlspecialchars($thumbUrl); ?>">
                <source src="<?php echo htmlspecialchars($mp4Url); ?>" type="video/mp4">
                Your browser does not support standard HTML5 video playback.
            </video>
        </div>

        <div id="mpg-container" class="player-container" style="display: none;">
            <video id="mpg-player" controls poster="<?php echo htmlspecialchars($thumbUrl); ?>">
                <source src="<?php echo htmlspecialchars($mpgUrl); ?>" type="video/mpeg">
                Your browser does not support MPEG-1 playback.
            </video>
        </div>

        <div class="meta-box">
            <div><strong>Uploader:</strong> <?php echo htmlspecialchars($video['uploader']); ?></div>
            <div><strong>Uploaded:</strong> <?php echo date("F j, Y, g:i a", $video['uploaded_at']->toDateTime()->getTimestamp()); ?></div>
            <?php if ($duration): ?>
                <div><strong>Duration:</strong> <?php echo htmlspecialchars($duration); ?></div>
            <?php endif; ?>
            <?php if ($resolution): ?>
                <div><strong>Resolution:</strong> <?php echo htmlspecialchars($resolution); ?><?php echo $fps ? ' @ ' . htmlspecialchars($fps) . ' fps' : ''; ?></div>
            <?php endif; ?>

            <?php if (!empty($mp4Meta) || !empty($mpgMeta)): ?>
            <table class="format-table">
                <thead>
                    <tr>
                        <th>Format</th>
                        <th>Video</th>
                        <th>Audio</th>
                        <th>Bitrate</th>
                        <th>Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($mp4Meta)): ?>
                    <tr>
                        <td>MP4</td>
                        <td><?php echo htmlspecialchars(strtoupper($mp4Meta['video_codec'] ?? 'n/a')); ?></td>
                        <td><?php echo htmlspecialchars(strtoupper($mp4Meta['audio_codec'] ?? 'n/a')); ?></td>
                        <td><?php echo htmlspecialchars(format_bitrate($mp4Meta['bitrate'] ?? null) ?? 'n/a'); ?></td>
                        <td><?php echo htmlspecialchars(format_bytes($mp4Meta['size_bytes'] ?? null) ?? 'n/a'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($mpgMeta)): ?>
                    <tr>
                        <td>MPG</td>
                        <td><?php echo htmlspecialchars(strtoupper($mpgMeta['video_codec'] ?? 'n/a')); ?></td>
                        <td><?php echo htmlspecialchars(strtoupper($mpgMeta['audio_codec'] ?? 'n/a')); ?></td>
                        <td><?php echo htmlspecialchars(format_bitrate($mpgMeta['bitrate'] ?? null) ?? 'n/a'); ?></td>
                        <td><?php echo htmlspecialchars(format_bytes($mpgMeta['size_bytes'] ?? null) ?? 'n/a'); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div class="downloads">
                <strong>Raw Streams:</strong>
                <a href="<?php echo htmlspecialchars($mp4Url); ?>" download>Download MP4 (H.264)</a> |
                <a href="<?php echo htmlspecialchars($mpgUrl); ?>" download>Download MPEG-1 Program Stream (MPG)</a>
            </div>
        </div>

        <script src="/watch/watch.js"></script>
    <?php endif; ?>
</body>
</html>
