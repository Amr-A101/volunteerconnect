<?php
// app/models/VolunteerSkillModel.php

class VolunteerSkillModel
{
    protected $dbc;
    protected $table = 'volunteer_skills';

    public function __construct($dbc)
    {
        $this->dbc = $dbc;
    }

    public function getSkills(int $vol_id)
    {
        $sql = "SELECT s.skill_id, s.skill_name
                FROM volunteer_skills vs
                JOIN skills s ON vs.skill_id = s.skill_id
                WHERE vs.vol_id = ?";
        $stmt = $this->dbc->prepare($sql);
        $stmt->bind_param("i", $vol_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Get skill ids (and names) for a volunteer */
    public function getForVolunteer(int $vol_id): array
    {
        $sql = "SELECT s.skill_id, s.skill_name
                FROM skills s
                JOIN {$this->table} vs ON vs.skill_id = s.skill_id
                WHERE vs.vol_id = ? ORDER BY s.skill_name";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $vol_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /**
     * Set skills for volunteer: replaces existing relations (transactional).
     * $skillIds = array of integer IDs
     */
    public function setForVolunteer(int $vol_id, array $skillIds): bool
    {
        // sanitize integer ids and unique
        $skillIds = array_values(array_unique(array_map('intval', $skillIds)));

        $this->dbc->begin_transaction();

        // delete existing
        $delSql = "DELETE FROM {$this->table} WHERE vol_id = ?";
        $stmt = $this->dbc->prepare($delSql);
        if (!$stmt) { $this->dbc->rollback(); return false; }
        $stmt->bind_param('i', $vol_id);
        if (!$stmt->execute()) { $stmt->close(); $this->dbc->rollback(); return false; }
        $stmt->close();

        if (empty($skillIds)) {
            $this->dbc->commit();
            return true;
        }

        // bulk insert
        $values = [];
        foreach ($skillIds as $sid) {
            $values[] = "(" . intval($vol_id) . "," . intval($sid) . ")";
        }
        $sql = "INSERT INTO {$this->table} (vol_id, skill_id) VALUES " . implode(',', $values);
        if (!$this->dbc->query($sql)) {
            $this->dbc->rollback();
            return false;
        }

        $this->dbc->commit();
        return true;
    }
}
