<?php
// app/models/UserModel.php

class UserModel {

    private $dbc;
    protected $table = 'users';

    public function __construct($dbc) { $this->dbc = $dbc; }

    public function getUserByLoginId($loginId) 
    {
        // SQL matches either email or username
        $sql = "SELECT * FROM " . $this->table . " WHERE email = ? OR username = ? LIMIT 1";

        $stmt = $this->dbc->prepare($sql);
        $stmt->bind_param('ss', $loginId, $loginId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

     // Check if email exists in users table
    public function usernameExists($username)
    {
        $sql = "SELECT user_id FROM " . $this->table . " WHERE username = ? LIMIT 1";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

     // Check if email exists in users table
    public function emailExists($email)
    {
        $sql = "SELECT user_id FROM " . $this->table . " WHERE email = ? LIMIT 1";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    // Create user; returns inserted user_id or false
    public function createUser($username, $email, $passwordHash, $role = 'vol', $status = 'pending', $verifyToken = null)
    {
        $sql = "INSERT INTO " . $this->table . " (username, email, password, role, status, verify_token) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ssssss', $username, $email, $passwordHash, $role, $status, $verifyToken);
        $ok = $stmt->execute();
        if (!$ok) {
            $stmt->close();
            return false;
        }
        $newId = $this->dbc->insert_id;
        $stmt->close();
        return $newId;
    }

    public function deleteById($userId)
    {
        $sql = "DELETE FROM " . $this->table . " WHERE user_id = ?";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('i', $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    // Fetch user by email
    public function getUserByEmail($email) {
        $stmt = $this->dbc->prepare("SELECT * FROM " . $this->table . " WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    // Fetch volunteer profile
    public function getVolunteerName($id) {
        $stmt = $this->dbc->prepare("SELECT first_name, last_name FROM volunteers WHERE vol_id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    // Fetch organization profile
    public function getOrganizationName($id) {
        $stmt = $this->dbc->prepare("SELECT name FROM organizations WHERE org_id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
?>