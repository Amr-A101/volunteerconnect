<?php
// app/models/OpportunityModel.php

class OpportunityModel
{
    protected $dbc;
    protected $table = "opportunities";

    public function __construct($dbc)
    {
        $this->dbc = $dbc;
    }

    public function getOpenOpportunities($filters, int $volunteerId)
    {
        $volunteerId = (int)$volunteerId;

        $query = "
            SELECT 
                o.*,
                org.name AS org_name,

                -- total applications
                (
                    SELECT COUNT(*) 
                    FROM applications a 
                    WHERE a.opportunity_id = o.opportunity_id
                ) AS applied_count,

                -- remaining slots
                o.number_of_volunteers - (
                    SELECT COUNT(*) 
                    FROM applications a 
                    WHERE a.opportunity_id = o.opportunity_id
                ) AS remaining_slots,

                -- check if this volunteer already applied
                EXISTS (
                    SELECT 1 
                    FROM applications a 
                    WHERE a.opportunity_id = o.opportunity_id
                    AND a.volunteer_id = {$volunteerId}
                ) AS has_applied

            FROM opportunities o
            JOIN organizations org ON o.org_id = org.org_id
            WHERE o.status = 'open'
                AND (o.application_deadline IS NULL OR o.application_deadline >= NOW())
                AND (
                    o.number_of_volunteers IS NULL
                    OR o.number_of_volunteers > (
                        SELECT COUNT(*) 
                        FROM applications a 
                        WHERE a.opportunity_id = o.opportunity_id
                    )
                )
        ";


        if (!empty($filters['title'])) {
            $t = $this->dbc->real_escape_string($filters['title']);
            $query .= " AND o.title LIKE '%$t%'";
        }
        if (!empty($filters['city'])) {
            $c = $this->dbc->real_escape_string($filters['city']);
            $query .= " AND o.city LIKE '%$c%'";
        }
        if (!empty($filters['state'])) {
            $s = $this->dbc->real_escape_string($filters['state']);
            $query .= " AND o.state LIKE '%$s%'";
        }
        if (!empty($filters['country'])) {
            $co = $this->dbc->real_escape_string($filters['country']);
            $query .= " AND o.country LIKE '%$co%'";
        }

        $query .= " ORDER BY o.created_at DESC";

        return $this->dbc->query($query);
    }
}
?>