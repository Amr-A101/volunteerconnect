<?php
// app/models/VolunteerInterestModel.php

class VolunteerInterestModel
{
    protected $dbc;
    protected $table = 'volunteer_interests';

    public function __construct($dbc)
    {
        $this->dbc = $dbc;
    }

    public function getInterests(int $vol_id)
    {
        $sql = "SELECT i.interest_id, i.interest_name
                FROM volunteer_interests vi
                JOIN interests i ON vi.interest_id = i.interest_id
                WHERE vi.vol_id = ?";
        $stmt = $this->dbc->prepare($sql);
        $stmt->bind_param("i", $vol_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getForVolunteer(int $vol_id): array
    {
        $sql = "SELECT i.interest_id, i.interest_name
                FROM interests i
                JOIN {$this->table} vi ON vi.interest_id = i.interest_id
                WHERE vi.vol_id = ? ORDER BY i.interest_name";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $vol_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    public function setForVolunteer(int $vol_id, array $interestIds): bool
    {
        $interestIds = array_values(array_unique(array_map('intval', $interestIds)));

        $this->dbc->begin_transaction();

        $delSql = "DELETE FROM {$this->table} WHERE vol_id = ?";
        $stmt = $this->dbc->prepare($delSql);
        if (!$stmt) { $this->dbc->rollback(); return false; }
        $stmt->bind_param('i', $vol_id);
        if (!$stmt->execute()) { $stmt->close(); $this->dbc->rollback(); return false; }
        $stmt->close();

        if (empty($interestIds)) {
            $this->dbc->commit();
            return true;
        }

        $values = [];
        foreach ($interestIds as $iid) $values[] = "(" . intval($vol_id) . "," . intval($iid) . ")";
        $sql = "INSERT INTO {$this->table} (vol_id, interest_id) VALUES " . implode(',', $values);
        if (!$this->dbc->query($sql)) {
            $this->dbc->rollback();
            return false;
        }

        $this->dbc->commit();
        return true;
    }
}
