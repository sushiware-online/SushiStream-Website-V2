<?php
namespace Sushi\SushiStreamWebsite\Services;

use MongoDB\Database;

class AuthService
{
    private $db;
    private $users;
    private $inviteKeys;

    public function __construct(Database $db)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->db = $db;
        $this->users = $db->users;
        $this->inviteKeys = $db->invite_keys;
    }

    public function register(string $username, string $password, string $inviteKey): bool
    {
        $existing = $this->users->findOne(['username' => $username]);
        if ($existing) {
            return false;
        }

        if ($inviteKey) {
            $invite = $this->inviteKeys->findOne(['key' => $inviteKey, 'used' => false]);
            if (!$invite) {
                return false;
            }
        } else {
            return false;
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $this->users->insertOne([
            'username'   => $username,
            'password'   => $hashed,
            'created_at' => new \MongoDB\BSON\UTCDateTime()
        ]);

        $this->inviteKeys->updateOne(
            ['_id' => $invite['_id']],
            ['$set' => ['used' => true]]
        );

        return true;
    }

    public function login(string $username, string $password): bool
    {
        $user = $this->users->findOne(['username' => $username]);
        if (!$user) return false;

        if (password_verify($password, $user['password'])) {
            $_SESSION['username'] = $username;
            return true;
        }
        return false;
    }

    public function logout(): void
    {
        unset($_SESSION['username']);
        session_destroy();
    }

    public function isAuthorized(?string $username = null): bool
    {
        if (!isset($_SESSION['username'])) {
            return false;
        }

        if ($username !== null && $_SESSION['username'] !== $username) {
            return false;
        }

        return true;
    }

    public function getLoggedInUsername(): ?string
    {
        return $_SESSION['username'] ?? null;
    }
}
