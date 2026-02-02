<?php
// app/controllers/SignupOrgController.php

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../models/OrganizationModel.php";

class SignupOrgController
{
    protected $dbc;
    protected $userModel;
    protected $orgModel;
    protected $maxImageSize = 2 * 1024 * 1024;

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->userModel = new UserModel($this->dbc);
        $this->orgModel  = new OrganizationModel($this->dbc);
    }

    public function showOrgStep()
    {
        $errors = [];
        $old    = [];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: signup.php");
            exit;
        }

        // ==========================
        // FINAL SUBMIT
        // ==========================
        if (isset($_POST['final_submit'])) {

            // STEP 1 (re-editable)
            $username         = trim($_POST['username']);
            $email            = trim($_POST['email']);
            $password         = $_POST['password'];
            $password_confirm = $_POST['password_confirm'];

            // STEP 2 (organization-specific)
            $org_name    = trim($_POST['org_name'] ?? '');
            $mission     = trim($_POST['mission'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $contact_raw = trim($_POST['contact_info'] ?? '');
            $address      = trim($_POST['address'] ?? '');
            $postcode    = trim($_POST['postcode'] ?? '');
            $state_org   = trim($_POST['state_org'] ?? '');
            $city_org    = trim($_POST['city_org'] ?? '');
            $country_org  = trim($_POST['country_org'] ?? "Malaysia");

            foreach ($_POST as $k => $v) {
                $old[$k] = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            }

            // VALIDATIONS
            if ($username === '') $errors[] = "Username is required.";
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email required.";
            if ($password === '') $errors[] = "Password required.";
            if ($password !== $password_confirm) $errors[] = "Passwords do not match.";

            // Re-check uniqueness
            if ($this->userModel->usernameExists($username)) $errors[] = "Username already taken.";
            if ($this->userModel->emailExists($email)) $errors[] = "Email already registered.";

            // Normalize
            $org_name    = trim($_POST['org_name'] ?? '');
            $mission     = trim($_POST['mission'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $contact_raw = trim($_POST['contact_info'] ?? '');
            $postcode    = trim($_POST['postcode'] ?? '');
            $state_org   = trim($_POST['state_org'] ?? '');
            $city_org    = trim($_POST['city_org'] ?? '');

            // Basic required checks
            if ($org_name === '') $errors[] = 'Organization name required.';
            if (strlen($mission) < 20) $errors[] = 'Mission must be at least 20 characters.';
            if (strlen($description) < 50) $errors[] = 'Description of organization must be at least 50 characters.';

            $contact_raw = preg_replace('/[\s\-().]/', '', $contact_raw); 

            if (!preg_match('/^(?:\+?60|0)(?:1[0-9]\d{7,8}|[3-9][0-9]\d{7})$/', $contact_raw)) {
                $errors[] = 'Contact number must be a valid Malaysian phone number.';
            }

            if (!preg_match('/^\d{5}$/', $postcode)) {
                $errors[] = 'Postcode must be 5 digits.';
            }
            if (empty($state_org)) $errors[] = 'State required.';
            if (empty($city_org)) $errors[] = 'Town/Area required.';

            // Convert contact to JSON for DB
            $contact_info_json = json_encode(['phone' => $contact_raw], JSON_UNESCAPED_SLASHES);

            // If json encode failed
            if ($contact_info_json === false) $errors[] = 'Failed to prepare contact info.';

            // VALIDATE JSON
            if (!json_decode($contact_info_json)) {
                $errors[] = "Invalid contact info JSON.";
            }

            // OPTIONAL profile picture
            $profile_picture_path = null;

            if (!empty($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
                $f = $_FILES['profile_picture'];

                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = "Error uploading picture.";
                } else {
                    if ($f['size'] > $this->maxImageSize) $errors[] = "Image exceeds 2MB.";

                    $mime = mime_content_type($f['tmp_name']);
                    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];

                    if (!isset($allowed[$mime])) {
                        $errors[] = "Invalid image format (only JPG/PNG/GIF).";
                    }

                    // Upload
                    if (empty($errors)) {
                        $uploadDir = __DIR__ . "/../../assets/uploads/pfp";
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                        $ext = $allowed[$mime];
                        $filename = "pp_" . time() . "_" . bin2hex(random_bytes(6)) . "." . $ext;
                        $dest = $uploadDir . "/" . $filename;

                        if (move_uploaded_file($f['tmp_name'], $dest)) {
                            $profile_picture_path = "/volcon/assets/uploads/pfp/" . $filename;
                        }
                    }
                }
            }

            // If no errors → Insert to DB
            if (empty($errors)) {

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $userId = $this->userModel->createUser(
                    $username,
                    $email,
                    $passwordHash,
                    "org",
                    "pending"
                );

                if (!$userId) {
                    $errors[] = "Database error creating user.";
                } else {
                    $ok = $this->orgModel->create(
                        $userId,
                        $org_name,
                        $mission,
                        $description,
                        $contact_info_json,
                        $address,
                        $city_org,
                        $state_org,
                        $postcode,
                        $country_org,
                        $profile_picture_path,
                        null,
                        null
                    );

                    if (!$ok) {
                        $this->userModel->deleteById($userId);
                        $errors[] = "Database error saving organization profile.";
                    } else {
                        require_once __DIR__ . "/../core/flash.php";
                        flash('success', 'Account created. Please verify first.');
                        header("Location: /volcon/app/verify_org.php");
                        exit;
                    }
                }
            }
        }
        else {
            // First entry after step1
            $old['username'] = htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8');
            $old['email']    = htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8');
            $old['password'] = htmlspecialchars($_POST['password'], ENT_QUOTES, 'UTF-8');
        }

        require __DIR__ . "/../views/org/signup_org_view.php";

        require_once __DIR__ . "/../views/layout/footer.php";
    }
}
?>

<!-- require_once __DIR__ . "/../core/flash.php";
flash('success', 'Account created successfully! You can now sign in.');
header("Location: /volcon/app/login.php");
exit; -->