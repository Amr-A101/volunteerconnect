<?php
// app/models/VolunteerModel.php

class VolunteerModel
{
    protected $dbc;
    protected $table = 'volunteers';

    public function __construct($dbc) { $this->dbc = $dbc; }

    public function getByUserId($userId)
    {
        $sql = "SELECT v.*, u.username, u.email, u.status,
                   CONCAT(v.first_name, ' ', v.last_name) AS full_name
            FROM {$this->table} v
            JOIN users u ON u.user_id = v.vol_id
            WHERE v.vol_id = ?
            LIMIT 1";
        $stmt = $this->dbc->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $vol = $stmt->get_result()->fetch_assoc();
        if (!$vol) return null;

        // Build full_name field
        $vol['full_name'] = trim($vol['first_name'] . ' ' . $vol['last_name']);

        $vol['skills'] = $this->getVolunteerSkills((int)$vol['vol_id']);
        $vol['interests'] = $this->getVolunteerInterests((int)$vol['vol_id']);

        // Decode emergency_contacts JSON
        if (!empty($vol['emergency_contacts'])) {
            $vol['emergency_contacts'] = json_decode($vol['emergency_contacts'], true);
        } else {
            $vol['emergency_contacts'] = [];
        }

        return $vol;
    }

    /**
     * Create volunteer row. vol_id is the user_id FK.
     */
    public function create($vol_id, $first_name, $last_name, $city = null, $state = null, $country = null, $availability = 'flexible', $bio = null, $profile_picture = null, $phone_no = '', $birthdate = null, $emergency_contacts = null)
    {
        $sql = "INSERT INTO {$this->table} (vol_id, first_name, last_name, city, state, country, availability, bio, profile_picture, phone_no, birthdate, emergency_contacts)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;

        // Normalize empty strings to null where appropriate
        $city = $city === '' ? null : $city;
        $state = $state === '' ? null : $state;
        $country = $country === '' ? null : $country;
        $availability = $availability === '' ? 'flexible' : $availability;
        $bio = $bio === '' ? null : $bio;
        $profile_picture = $profile_picture === '' ? null : $profile_picture;
        $emergency_contacts = $emergency_contacts === '' ? null : $emergency_contacts;
        $birthdate = $birthdate === '' ? null : $birthdate;

        $stmt->bind_param('isssssssssss',
            $vol_id,
            $first_name,
            $last_name,
            $city,
            $state,
            $country,
            $availability,
            $bio,
            $profile_picture,
            $phone_no,
            $birthdate,
            $emergency_contacts
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }


    public function deleteByUserId($userId)
    {
        $sql = "DELETE FROM {$this->table} WHERE vol_id = ?";
        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('i', $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function findByUserId($userId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE vol_id = ? LIMIT 1";
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
     * Update volunteer profile
     */
    public function updateProfile(
        int $vol_id,
        string $first_name,
        string $last_name,
        ?string $city,
        ?string $state,
        ?string $country,
        string $availability,
        ?string $bio,
        ?string $profile_picture,
        string $phone_no,
        string $birthdate,
        ?string $emergency_contacts_json
    ) {
        $sql = "UPDATE {$this->table}
                SET first_name = ?, last_name = ?, city = ?, state = ?, country = ?, availability = ?, bio = ?, profile_picture = ?, phone_no = ?, birthdate = ?, emergency_contacts = ?
                WHERE vol_id = ?";

        $stmt = $this->dbc->prepare($sql);
        if (!$stmt) return false;

        // Normalize empty strings to null where desired
        $city = $city === '' ? null : $city;
        $state = $state === '' ? null : $state;
        $country = $country === '' ? null : $country;
        $bio = $bio === '' ? null : $bio;
        $profile_picture = $profile_picture === '' ? null : $profile_picture;
        $emergency_contacts_json = $emergency_contacts_json === '' ? null : $emergency_contacts_json;

        $stmt->bind_param(
            'sssssssssssi',
            $first_name,
            $last_name,
            $city,
            $state,
            $country,
            $availability,
            $bio,
            $profile_picture,
            $phone_no,
            $birthdate,
            $emergency_contacts_json,
            $vol_id
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function getVolunteerSkills(int $volId): array
    {
        $stmt = $this->dbc->prepare("
            SELECT s.skill_name
            FROM volunteer_skills vs
            JOIN skills s ON vs.skill_id = s.skill_id
            WHERE vs.vol_id = ?
            ORDER BY s.skill_name
        ");
        $stmt->bind_param("i", $volId);
        $stmt->execute();

        $skills = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $skills[] = $row['skill_name'];
        }
        $stmt->close();

        return $skills;
    }

    public function getVolunteerInterests(int $volId): array
    {
        $stmt = $this->dbc->prepare("
            SELECT i.interest_name
            FROM volunteer_interests vi
            JOIN interests i ON vi.interest_id = i.interest_id
            WHERE vi.vol_id = ?
            ORDER BY i.interest_name
        ");
        $stmt->bind_param("i", $volId);
        $stmt->execute();

        $interests = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $interests[] = $row['interest_name'];
        }
        $stmt->close();

        return $interests;
    }

}
?>