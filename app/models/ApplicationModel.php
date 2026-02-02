<?php
// app/models/ApplicationModel.php

class ApplicationModel
{
    protected $dbc;
    protected $table = "applications";

    public function __construct($dbc)
    {
        $this->dbc = $dbc;
    }

    public function countDeletedApplications($volunteerId)
    {
        $sql = "
            SELECT COUNT(*) AS cnt
            FROM applications a
            JOIN opportunities o ON a.opportunity_id = o.opportunity_id
            WHERE a.volunteer_id = ? AND o.status = 'deleted'
        ";

        $stmt = $this->dbc->prepare($sql);
        $stmt->bind_param("i", $volunteerId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc()['cnt'];
    }
}
?>