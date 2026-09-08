<?php
namespace Sushi\SushiStreamWebsite\Services;

use MongoDB\Database;

class VideoUploadService
{
    private $db;
    private $videos;
    private $authService;
    private $uploadDir;

    public function __construct(Database $db, AuthService $authService, string $uploadDir)
    {
        $this->db = $db;
        $this->videos = $db->videos;
        $this->authService = $authService;
        $this->uploadDir = rtrim($uploadDir, DIRECTORY_SEPARATOR);
    }

    public function uploadVideo(array $file, string $title)
    {
        $username = $this->authService->getLoggedInUsername();
        if (!$this->authService->isAuthorized() || !$username) {
            error_log('Upload failed: User unauthorized');
            return false;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log('Upload failed: Upload error code ' . $file['error']);
            return false;
        }

        $videoDir = $this->uploadDir . DIRECTORY_SEPARATOR . 'user-content' . DIRECTORY_SEPARATOR . 'videos';
        if (!is_dir($videoDir) && !mkdir($videoDir, 0775, true)) {
            error_log('Upload failed: Could not create output directory');
            return false;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $uniqueBase = uniqid('video_', true);
        $originalPath = $videoDir . DIRECTORY_SEPARATOR . $uniqueBase . '.' . $extension;

        if (!move_uploaded_file($file['tmp_name'], $originalPath)) {
            error_log('Upload failed: Failed to move uploaded temporary file');
            return false;
        }

        try {
            $insertResult = $this->videos->insertOne([
                'title'       => htmlspecialchars(trim($title)),
                'base_name'   => $uniqueBase,
                'uploader'    => $username,
                'status'      => 'processing',
                'files'       => null,
                'uploaded_at' => new \MongoDB\BSON\UTCDateTime()
            ]);

            $videoId = (string)$insertResult->getInsertedId();
            $convertScript = realpath(__DIR__ . '/../../bin/convert.php');

            // Trigger non-blocking background conversion
            $cmd = sprintf('php %s %s %s > /dev/null 2>&1 &', escapeshellarg($convertScript), escapeshellarg($videoId), escapeshellarg($originalPath));
            exec($cmd);

            return $videoId;
        } catch (\Exception $e) {
            error_log('MongoDB insert failed: ' . $e->getMessage());
            @unlink($originalPath);
            return false;
        }
    }
}
