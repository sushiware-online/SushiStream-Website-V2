<?php
require __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client as MongoClient;
use MongoDB\BSON\ObjectId;
use Dotenv\Dotenv;

session_start();

if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
    $dotenv->safeLoad();
}

$masterPass = $_ENV['MASTER_PASSWORD'] ?? getenv('MASTER_PASSWORD') ?: 'SushiMasterPass2026';

// 1. Authentication
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

// 2. Database Connection
$mongoHost = getenv('MONGO_HOST') ?: '127.0.0.1';
$mongo = new MongoClient("mongodb://$mongoHost:27017");
$db = $mongo->selectDatabase("sushistream_v2");
$message = "";

// 3. Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Generate Invite Key
    if (isset($_POST['generate_key'])) {
        $key = bin2hex(random_bytes(16));
        $db->invite_keys->insertOne([
            'key' => $key,
            'used' => false,
            'created_at' => new \MongoDB\BSON\UTCDateTime()
        ]);
        $message = "Generated Key: $key";
    }

    // Bulk Video Actions (Reconvert or Delete)
    if (isset($_POST['bulk_action']) && !empty($_POST['video_ids'])) {
        $action = $_POST['bulk_action'];
        $videoDir = realpath(__DIR__ . '/../user-content/videos');
        
        $processedCount = 0;

        foreach ($_POST['video_ids'] as $idStr) {
            try {
                $vidObj = new ObjectId($idStr);
                $video = $db->videos->findOne(['_id' => $vidObj]);
                
                if (!$video) continue;

                if ($action === 'delete') {
                    // Delete all associated files on disk (glob catches original uploads, mp4, mpg, jpg)
                    if (!empty($video['base_name'])) {
                        $pattern = $videoDir . DIRECTORY_SEPARATOR . $video['base_name'] . '.*';
                        foreach (glob($pattern) as $file) {
                            @unlink($file);
                        }
                    }
                    // Delete from database
                    $db->videos->deleteOne(['_id' => $vidObj]);
                    $processedCount++;

                } elseif ($action === 'reconvert') {
                    // Reconvert requires an existing mp4 file to use as the source input
                    if (!empty($video['files']['mp4'])) {
                        $inputPath = $videoDir . DIRECTORY_SEPARATOR . $video['files']['mp4'];
                        
                        if (file_exists($inputPath)) {
                            // Set status to processing
                            $db->videos->updateOne(
                                ['_id' => $vidObj], 
                                ['$set' => ['status' => 'processing', 'error_log' => null]]
                            );

                            // Trigger the background Python script
                            $pythonBinary = '/usr/bin/python3 -u';
                            $convertScript = realpath(__DIR__ . '/../../bin/convert.py');
                            $logPath = $videoDir . DIRECTORY_SEPARATOR . 'ffmpeg_debug.log';
                            
                            $cmd = sprintf(
                                '%s %s %s %s > %s 2>&1 &',
                                $pythonBinary,
                                escapeshellarg($convertScript),
                                escapeshellarg((string)$vidObj),
                                escapeshellarg($inputPath),
                                escapeshellarg($logPath)
                            );
                            exec($cmd);
                            $processedCount++;
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore invalid object IDs or unlinking errors
                continue;
            }
        }
        
        $actionText = $action === 'delete' ? 'Deleted' : 'Queued for reconversion';
        $message = "$actionText $processedCount video(s).";
    }
}

// 4. Fetch Data for Display
$inviteKeys = $db->invite_keys->find([], ['sort' => ['created_at' => -1]]);
$videos = $db->videos->find([], ['sort' => ['uploaded_at' => -1]]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>SushiStream v2 Admin Panel</title>
    <link rel="stylesheet" href="/global.css">
    <script>
        function toggleCheckboxes(source) {
            checkboxes = document.getElementsByName('video_ids[]');
            for(var i=0, n=checkboxes.length;i<n;i++) {
                checkboxes[i].checked = source.checked;
            }
        }
    </script>
</head>
<body>
    <h1>SushiStream v2 Admin Panel</h1>
    <?php if ($message): ?><p style="color:#00e5ff; font-weight:bold;"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>

    <hr style="border-color: #444; margin: 20px 0;">

    <h2>Video Management</h2>
    <form method="post">
        <div style="margin-bottom: 10px;">
            <button type="submit" name="bulk_action" value="reconvert" style="background-color: #ffd600; color: #000;">Reconvert Selected</button>
            <button type="submit" name="bulk_action" value="delete" style="background-color: #d50000; color: #fff;" onclick="return confirm('Are you sure you want to permanently delete the selected videos? This cannot be undone.');">Delete Selected</button>
        </div>
        
        <table border="1" cellpadding="8" style="border-collapse: collapse; border-color: #444; width: 100%; text-align: left;">
            <tr style="background-color: #2a2a32;">
                <th><input type="checkbox" onClick="toggleCheckboxes(this)"></th>
                <th>Title</th>
                <th>Uploader</th>
                <th>Status</th>
                <th>Uploaded At</th>
            </tr>
            <?php foreach ($videos as $vid): ?>
            <tr>
                <td><input type="checkbox" name="video_ids[]" value="<?php echo (string)$vid['_id']; ?>"></td>
                <td><?php echo htmlspecialchars($vid['title']); ?></td>
                <td><?php echo htmlspecialchars($vid['uploader']); ?></td>
                <td>
                    <?php 
                        $status = $vid['status'] ?? 'unknown';
                        $color = $status === 'ready' ? '#00c853' : ($status === 'failed' ? '#ff5252' : '#ffd600');
                    ?>
                    <span style="color: <?php echo $color; ?>;"><?php echo strtoupper($status); ?></span>
                </td>
                <td><?php echo isset($vid['uploaded_at']) ? $vid['uploaded_at']->toDateTime()->format('Y-m-d H:i') : 'N/A'; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </form>

    <hr style="border-color: #444; margin: 30px 0;">

    <h2>Invite Key Manager</h2>
    <form method="post" style="margin-bottom: 10px;">
        <button type="submit" name="generate_key">Generate New Key</button>
    </form>

    <table border="1" cellpadding="8" style="border-collapse: collapse; border-color: #444; min-width: 50%;">
        <tr style="background-color: #2a2a32;">
            <th>Key</th>
            <th>Used</th>
            <th>Created At</th>
        </tr>
        <?php foreach ($inviteKeys as $keyDoc): ?>
        <tr>
            <td><code><?php echo htmlspecialchars($keyDoc['key']); ?></code></td>
            <td><?php echo $keyDoc['used'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo $keyDoc['created_at']->toDateTime()->format('Y-m-d H:i:s'); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <p style="margin-top: 30px;"><a href="/index.php">&larr; Back to Homepage</a></p>
</body>
</html>
