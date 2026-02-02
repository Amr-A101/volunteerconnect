<?php
// app/models/OrganizationModel.php

class OrganizationModel
{
    protected $dbc;
    protected $table = 'organizations';

    public function __construct($dbc) { $this->dbc = $dbc; }

    public function getByUserId(int $userId)
    {
        $sql = "SELECT o.*, u.username, u.email, u.status
                FROM {$this->table} o
                JOIN users u ON u.user_id = o.org_id
                WHERE o.org_id = ?
                LIMIT 1";

        $stmt = $this->dbc->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();

        if (!$org) return null;

        // decode JSON fields
        $org['contact_info']    = $org['contact_info']    ? json_decode($org['contact_info'], true) : [];
        $org['external_links']  = $org['external_links']  ? json_decode($org['external_links'], true) : [];
        $org['document_paths']  = $org['document_paths']  ? json_decode($org['document_paths'], true) : [];

        return $org;
    }
    
    /**
     * Create organization row. org_id is the user_id FK.
     */
    public function create(
        $org_id,
        $name,
        $mission = null,
        $description = null,
        $contact_info_json = null,   // MUST be JSON now
        $address = null,
        $city = null,
        $state = null,
        $postcode = null,
        $country = "Malaysia",
        $profile_picture = null,
        $document_paths_json = null,
        $external_links_json = null
    ) {
        $sql = "INSERT INTO {$this->table}
                (org_id, name, mission, description, contact_info, address, city, state, postcode, country, profile_picture, document_paths, external_links)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;

        // Normalize empty → NULL
        $mission = $mission ?: null;
        $description = $description ?: null;
        $contact_info_json = $contact_info_json ?: null;
        $address = $address ?: null;
        $city = $city ?: null;
        $state = $state ?: null;
        $postcode = $postcode ?: null;
        $country = $country ?: null;
        $profile_picture = $profile_picture ?: null;
        $document_paths_json = $document_paths_json ?: null;
        $external_links_json = $external_links_json ?: null;

        $stmt->bind_param(
            "issssssssssss",
            $org_id,
            $name,
            $mission,
            $description,
            $contact_info_json,
            $address,
            $city,
            $state,
            $postcode,
            $country,
            $profile_picture,
            $document_paths_json,
            $external_links_json
        );


        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function deleteByUserId($userId)
    {
        $sql = "DELETE FROM {$this->table} WHERE org_id = ?";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('i', $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function findByUserId($userId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE org_id = ? LIMIT 1";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }


    /**
     * Update organization profile
     */
    public function updateProfile(
        int $org_id,
        string $name,
        ?string $mission,
        ?string $description,
        ?string $address,
        ?string $city,
        ?string $state,
        ?string $postcode,
        ?string $country,
        ?string $profile_picture,
        ?string $contact_info_json,
        ?string $document_paths_json,
        ?string $external_links_json
    ) {
        $sql = "UPDATE {$this->table}
                SET name=?, mission=?, description=?, address=?, city=?, state=?, postcode=?, country=?, profile_picture=?, contact_info=?, document_paths=?, external_links=?
                WHERE org_id=?";

        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;

        $stmt->bind_param(
            "ssssssssssssi",
            $name,
            $mission,
            $description,
            $address,
            $city,
            $state,
            $postcode,
            $country,
            $profile_picture,
            $contact_info_json,
            $document_paths_json,
            $external_links_json,
            $org_id
        );

        return $stmt->execute();
    }
}
?>