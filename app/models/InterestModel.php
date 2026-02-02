<?php
// app/models/InterestModel.php

class InterestModel
{
    protected $dbc;
    protected $table = 'interests';

    public function __construct($dbc)
    {
        $this->dbc = $dbc;
    }

    public function all(): array
    {
        $sql = "SELECT interest_id, interest_name FROM {$this->table} ORDER BY interest_name ASC";
        $res = $this->dbc->query($sql);
        if (!$res) return [];
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    public function findByName(string $name)
    {
        $sql = "SELECT interest_id FROM {$this->table} WHERE LOWER(interest_name) = LOWER(?) LIMIT 1";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return false;
        return (int)$row['interest_id'];
    }

    public function create(string $name)
    {
        $name = trim($name);
        if ($name === '') return false;
        $sql = "INSERT INTO {$this->table} (interest_name) VALUES (?)";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('s', $name);
        $ok = $stmt->execute();
        if (!$ok) { $stmt->close(); return false; }
        $id = $this->dbc->insert_id;
        $stmt->close();
        return (int)$id;
    }

    public function findById(int $id)
    {
        $sql = "SELECT interest_id, interest_name FROM {$this->table} WHERE interest_id = ? LIMIT 1";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }
}
