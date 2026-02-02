<?php
// app/controllers/UpdateProfileVolController.php

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/../core/auth.php";
require_once __DIR__ . "/../core/validators.php";
require_once __DIR__ . "/../models/VolunteerModel.php";
require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../models/SkillModel.php";
require_once __DIR__ . "/../models/InterestModel.php";
require_once __DIR__ . "/../models/VolunteerSkillModel.php";
require_once __DIR__ . "/../models/VolunteerInterestModel.php";

class UpdateProfileVolController
{
    protected $dbc;
    protected $volModel;
    protected $userModel;
    protected $skillModel;
    protected $interestModel;
    protected $volSkillModel;
    protected $volInterestModel;
    protected $maxImageSize = 2 * 1024 * 1024; // 2MB

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->volModel = new VolunteerModel($this->dbc);
        $this->userModel = new UserModel($this->dbc);
        $this->skillModel = new SkillModel($this->dbc);
        $this->interestModel = new InterestModel($this->dbc);
        $this->volSkillModel = new VolunteerSkillModel($this->dbc);
        $this->volInterestModel = new VolunteerInterestModel($this->dbc);
    }

    public function handle()
    {
        // Must be logged in
        $current = current_user();
        if (!$current) {
            header('Location: login.php');
            exit;
        }

        $volId= $current['user_id'];

        // Only volunteers may edit their volunteer profile (admins may be allowed - optional)
        if ($current['role'] !== 'vol' && $current['role'] !== 'admin') {
            die("Forbidden");
        }

        // Load existing
        $vol = $this->volModel->getByUserId($volId);
        if (!$vol) {
            die("Volunteer profile not found.");
        }

        $errors = [];
        $old = []; // to repopulate view

        // Load all tags for view
        $allSkills = $this->skillModel->all();
        $allInterests = $this->interestModel->all();

        // load volunteer's current tags
        $userSkills = $this->volSkillModel->getForVolunteer($volId); // [{skill_id,skill_name},...]
        $userInterests = $this->volInterestModel->getForVolunteer($volId);

        $userSkillIds = array_map(function($r){ return (int)$r['skill_id']; }, $userSkills);
        $userInterestIds = array_map(function($r){ return (int)$r['interest_id']; }, $userInterests);

        // GET -> render form
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            // prepare old values from $vol
            $old = [
                'first_name' => $vol['first_name'],
                'last_name'  => $vol['last_name'],
                'city'       => $vol['city'],
                'state'      => $vol['state'],
                'country'    => $vol['country'] ?: 'Malaysia',
                'availability'=> $vol['availability'],
                'bio'        => $vol['bio'],
                'phone_no'   => $vol['phone_no'],
                'birthdate'  => $vol['birthdate'],
                'profile_picture' => $vol['profile_picture'],
                // emergency contacts will be an associative array in $vol['emergency_contacts']
                'emergency_contacts' => $vol['emergency_contacts'] ?? [],
                // tags for TomSelect prefill
                'userSkillIds' => $userSkillIds,
                'userInterestIds' => $userInterestIds,
                'allSkills' => $allSkills,
                'allInterests' => $allInterests
            ];

            // show view
            $page_title = "Edit Profile";
            require_once "views/layout/header.php";
            require __DIR__ . "/../views/vol/update_profile_vol_view.php";
            require_once __DIR__ . "/../views/layout/footer.php";
            return;
        }

        // POST -> process form submission
        // Fetch and trim inputs
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $city       = trim($_POST['city'] ?? '');
        $state      = trim($_POST['state'] ?? '');
        $country    = trim($_POST['country'] ?? 'Malaysia');
        $availability = trim($_POST['availability'] ?? 'flexible');
        $bio        = trim($_POST['bio'] ?? '');
        $phone_no   = trim($_POST['phone_no'] ?? '');
        $birthdate  = trim($_POST['birthdate'] ?? '');

        $ec_names  = $_POST['ec_name'] ?? [];
        $ec_phones = $_POST['ec_phone'] ?? [];

        $emergency_contacts = [];
        $usedNames = [];

        foreach ($ec_names as $i => $name) {

            $name  = trim($name);
            $phone = trim($ec_phones[$i] ?? '');

            // skip empty rows
            if ($name === '' || $phone === '') {
                continue;
            }

            // prevent duplicate names (case-insensitive)
            $key = mb_strtolower($name);
            if (isset($usedNames[$key])) {
                $errors[] = "Duplicate emergency contact name: {$name}.";
                continue;
            }
            $usedNames[$key] = true;

            // validate phone (Malaysia)
            if (!preg_match('/^(?:\+?60|0)(?:1[0-9]-?[0-9]{7,8}|[3-9][0-9]-?[0-9]{7})$/', $phone)) {
                $errors[] = "Invalid phone number for emergency contact: {$name}.";
                continue;
            }

            // normalize AFTER validation
            $phone = normalize_phone($phone);

            // prevent duplicate phone numbers
            if (in_array($phone, $emergency_contacts, true)) {
                $errors[] = "Emergency phone number already used.";
                continue;
            }

            $emergency_contacts[$name] = $phone;
        }


        // Require at least one contact
        if (empty($emergency_contacts)) {
            $errors[] = "At least one emergency contact is required.";
        }

        // Limit max contacts
        if (count($emergency_contacts) > 3) {
            $errors[] = "Maximum 3 emergency contacts allowed.";
        }

        /* ===============================
            JSON ENCODING
        =============================== */

        $ec_json = null;

        if (empty($errors) && !empty($emergency_contacts)) {
            $ec_json = json_encode($emergency_contacts, JSON_UNESCAPED_SLASHES);
            if ($ec_json === false) {
                $errors[] = 'Failed to encode emergency contacts.';
            }
        }


        // Validate server-side (same rules as earlier)
        if ($first_name === '') $errors[] = 'First name is required.';
        if ($last_name === '') $errors[] = 'Last name is required.';
        if ($city === '') $errors[] = 'Town / Area is required.';
        if ($state === '') $errors[] = 'State is required.';
        if (empty($phone_no) || !preg_match('/^(?:\+?60|0)(?:1[0-9]-?[0-9]{7,8}|[3-9][0-9]-?[0-9]{7})$/', $phone_no)) {
            $errors[] = 'Please enter a valid Malaysian phone number.';
        }
        
        // Birthdate (date format)
        if (!empty($birthdate)) {
            $d = DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!$d || $d->format('Y-m-d') !== $birthdate) {
                $errors[] = 'Invalid birthdate format.';
            } else {
                // Birthdate format is valid, check age
                $dob = new DateTime($birthdate);
                $today = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
                $age = $today->diff($dob)->y;

                // Check if age is less than 16
                if ($age < 16) {
                    $errors[] = "You must be at least 16 years old.";
                }
            }
        }

        // Handle optional profile picture upload
        $profile_picture_path = $vol['profile_picture'] ?? null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['profile_picture'];
            if ($f['error'] !== UPLOAD_ERR_OK) $errors[] = 'Error uploading image.';
            else {
                if ($f['size'] > $this->maxImageSize) $errors[] = 'Profile image too large (max 2MB).';
                $mime = mime_content_type($f['tmp_name']);
                $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];
                if (!isset($allowed[$mime])) $errors[] = 'Only JPG/PNG/GIF allowed.';
                if (empty($errors)) {
                    $uploadDir = __DIR__ . "/../../assets/uploads/pfp";
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext = $allowed[$mime];
                    $filename = 'vol_pp_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $uploadDir . '/' . $filename;
                    if (!move_uploaded_file($f['tmp_name'], $dest)) {
                        $errors[] = 'Failed to save uploaded file.';
                    } else {
                        // store web path
                        $profile_picture_path = '/volcon/assets/uploads/pfp/' . $filename;
                    }
                }
            }
        }

        // Now handle skills & interests posted from TomSelect
        // Expected: $_POST['skills'] and $_POST['interests'] as arrays; values can be numeric IDs or text names.
        $postedSkills = $_POST['skills'] ?? [];
        $postedInterests = $_POST['interests'] ?? [];

        // normalize arrays
        if (!is_array($postedSkills)) $postedSkills = [$postedSkills];
        if (!is_array($postedInterests)) $postedInterests = [$postedInterests];

        // Helper to map posted values to IDs (create if not found)
        $mapToIds = function(array $items, $model) {
            $ids = [];
            foreach ($items as $raw) {
                $raw = trim((string)$raw);
                if ($raw === '') continue;
                // numeric id?
                if (ctype_digit($raw)) {
                    $id = (int)$raw;
                    // sanity check: ensure exists
                    $exists = $model->findById($id);
                    if ($exists) $ids[] = $id;
                    continue;
                }
                // otherwise treat as name: try find, else create
                $found = $model->findByName($raw);
                if ($found !== false) {
                    $ids[] = (int)$found;
                } else {
                    $created = $model->create($raw);
                    if ($created !== false) $ids[] = (int)$created;
                }
            }
            return array_values(array_unique($ids));
        };

        $skillIdsToSave = $mapToIds($postedSkills, $this->skillModel);
        $interestIdsToSave = $mapToIds($postedInterests, $this->interestModel);
        

        // If there are validation errors -> re-render view with $errors and $old
        if (!empty($errors)) {
            $old = [
                'first_name'=>$first_name,
                'last_name'=>$last_name,
                'city'=>$city,
                'state'=>$state,
                'country'=>$country,
                'availability'=>$availability,
                'bio'=>$bio,
                'phone_no'=>$phone_no,
                'birthdate'=>$birthdate,
                'emergency_contacts'=>$emergency_contacts,
                'profile_picture' => $profile_picture_path,
                'userSkillIds' => $userSkillIds,
                'userInterestIds' => $userInterestIds,
                'allSkills' => $allSkills,
                'allInterests' => $allInterests
            ];

            $page_title = "Edit Profile";
            require_once "views/layout/header.php";
            require __DIR__ . "/../views/vol/update_profile_vol_view.php";
            require_once __DIR__ . "/../views/layout/footer.php";
            return;
        }

        // Normalize name capitalization
        $first_name = mb_convert_case($first_name, MB_CASE_TITLE, "UTF-8");
        $last_name  = mb_convert_case($last_name, MB_CASE_TITLE, "UTF-8");

        // Perform update
        $ok = $this->volModel->updateProfile(
            $volId,
            $first_name,
            $last_name,
            $city,
            $state,
            $country,
            $availability,
            $bio,
            $profile_picture_path,
            $phone_no,
            $birthdate,
            $ec_json
        );

        if (!$ok) {
            $errors[] = 'Database error saving profile.';
            $old = [
                'first_name'=>$first_name,
                'last_name'=>$last_name,
                'city'=>$city,
                'state'=>$state,
                'country'=>$country,
                'availability'=>$availability,
                'bio'=>$bio,
                'phone_no'=>$phone_no,
                'birthdate'=>$birthdate,
                'emergency_contacts'=>$emergency_contacts,
                'profile_picture' => $profile_picture_path,
                'userSkillIds' => $userSkillIds,
                'userInterestIds' => $userInterestIds,
                'allSkills' => $allSkills,
                'allInterests' => $allInterests
            ];
            $page_title = "Edit Profile";
            require_once "views/layout/header.php";
            require __DIR__ . "/../views/vol/update_profile_vol_view.php";
            require_once __DIR__ . "/../views/layout/footer.php";
            return;
        }

        // Save skills & interests (replace relations)
        $okSkills = $this->volSkillModel->setForVolunteer($volId, $skillIdsToSave);
        $okInterests = $this->volInterestModel->setForVolunteer($volId, $interestIdsToSave);

        if (!$okSkills || !$okInterests) {
            // Optionally attempt rollback or notify admin; for now show error
            $errors[] = 'Failed to save skills or interests.';
            $old = [
                'first_name'=>$first_name,
                'last_name'=>$last_name,
                'city'=>$city,
                'state'=>$state,
                'country'=>$country,
                'availability'=>$availability,
                'bio'=>$bio,
                'phone_no'=>$phone_no,
                'birthdate'=>$birthdate,
                'emergency_contacts' => $emergency_contacts,
                'profile_picture' => $profile_picture_path,
                'userSkillIds' => $userSkillIds,
                'userInterestIds' => $userInterestIds,
                'allSkills' => $allSkills,
                'allInterests' => $allInterests
            ];
            $page_title = "Edit Profile";
            require_once "views/layout/header.php";
            require __DIR__ . "/../views/vol/update_profile_vol_view.php";
            require_once __DIR__ . "/../views/layout/footer.php";
            return;
        }

        // Success: redirect (PRG) to profile page
        require_once __DIR__ . "/../core/flash.php";
        flash('success', 'Profile updated successfully.');
        header("Location: profile_vol.php?id={$volId}");
        exit;
    }
}
?>
