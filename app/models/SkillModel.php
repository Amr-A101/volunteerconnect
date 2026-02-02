<?php
// app/models/SkillModel.php

class SkillModel
{
    protected $dbc;
    protected $table = 'skills';

    public function __construct($dbc)
    {
        $this->dbc = $dbc;
    }

    /** Return all skills as associative arrays [skill_id, skill_name] */
    public function all(): array
    {
        $sql = "SELECT skill_id, skill_name FROM {$this->table} ORDER BY skill_name ASC";
        $res = $this->dbc->query($sql);
        if (!$res) return [];
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    /** Find skill by exact (case-insensitive) name. Return id or false */
    public function findByName(string $name)
    {
        $sql = "SELECT skill_id FROM {$this->table} WHERE LOWER(skill_name) = LOWER(?) LIMIT 1";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return false;
        return (int)$row['skill_id'];
    }

    /** Create a new skill and return inserted id or false */
    public function create(string $name)
    {
        $name = trim($name);
        if ($name === '') return false;
        $sql = "INSERT INTO {$this->table} (skill_name) VALUES (?)";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('s', $name);
        $ok = $stmt->execute();
        if (!$ok) {
            $stmt->close();
            return false;
        }
        $newId = $this->dbc->insert_id;
        $stmt->close();
        return (int)$newId;
    }

    /** Find by id (returns row or null) */
    public function findById(int $id)
    {
        $sql = "SELECT skill_id, skill_name FROM {$this->table} WHERE skill_id = ? LIMIT 1";
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
