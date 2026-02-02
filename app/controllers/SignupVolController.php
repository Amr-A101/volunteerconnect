<?php
// app/controllers/SignupVolController.php

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../models/VolunteerModel.php";

class SignupVolController
{
    protected $dbc;
    protected $userModel;
    protected $volModel;
    protected $maxImageSize = 2 * 1024 * 1024;

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->userModel = new UserModel($this->dbc);
        $this->volModel  = new VolunteerModel($this->dbc);
    }

    public function showVolStep()
    {
        $errors = [];
        $old = [];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: signup.php");
            exit;
        }

        // ==== Final submit ====
        if (isset($_POST['final_submit'])) {
            // Step1 + Step2 fields
            $username = trim($_POST['username']);
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            $password_confirm = $_POST['password_confirm'];

            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $city_vol = trim($_POST['city_vol']);
            $state_vol = trim($_POST['state_vol']);
            $country_vol = trim($_POST['country_vol']);
            $phone_no = trim($_POST['phone_no']);
            $birthdate = trim($_POST['birthdate']);

            foreach ($_POST as $k=>$v) {
                $old[$k] = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            }

            // Step2 validations
            if ($password !== $password_confirm) $errors[] = "Passwords do not match.";
            if ($first_name === '') $errors[] = "First name required.";
            if ($last_name === '') $errors[] = "Last name required.";
            if ($city_vol === '') $errors[] = "Town/Area required.";
            if ($state_vol === '') $errors[] = "State required.";
            if ($country_vol === '') $errors[] = "Country required.";
            if ($phone_no === '') $errors[] = "Phone required.";
            if ($birthdate === '') $errors[] = "Birthdate required.";

            // Names: normalize to ucwords and trim
            $first_name = mb_convert_case(trim($first_name), MB_CASE_TITLE, "UTF-8");
            $last_name  = mb_convert_case(trim($last_name), MB_CASE_TITLE, "UTF-8");

            // Phone validation
            $phone_no = preg_replace('/[\s\-().]/', '', $phone_no);

            if (!preg_match('/^(?:\+?60|0)(?:1[0-9]\d{7,8}|[3-9][0-9]\d{7})$/', $phone_no)) {
                $errors[] = 'Invalid phone number (Malaysia).';
            }

            // Required states/cities
            if (empty($state_vol)) $errors[] = 'State is required.';
            if (empty($city_vol)) $errors[] = 'Town / Area is required.';

            // Birthdate validation
            if (!empty($birthdate)) {
                $d = DateTime::createFromFormat('Y-m-d', $birthdate);
                if (!$d || $d->format('Y-m-d') !== $birthdate) {
                    $errors[] = 'Invalid birthdate format.';
                } else {
                    $dob = new DateTime($birthdate);
                    $today = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
                    $age = $today->diff($dob)->y;

                    if ($age < 16) {
                        $errors[] = "You must be at least 16 years old.";
                    }
                }
            }

            // Uniqueness checks
            if ($this->userModel->usernameExists($username)) $errors[] = "Username taken.";
            if ($this->userModel->emailExists($email)) $errors[] = "Email already registered.";

            // Image optional
            $profile_picture_path = null;

            if (!empty($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
                $f = $_FILES['profile_picture'];

                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = "Error uploading picture.";
                } else {
                    if ($f['size'] > $this->maxImageSize) $errors[] = "Image too large.";

                    $mime = mime_content_type($f['tmp_name']);
                    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];
                    if (!isset($allowed[$mime])) $errors[] = "Only JPG/PNG/GIF allowed.";

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

            // All good → insert into DB
            if (empty($errors)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                // Generate unique verification token (64 chars = 32 bytes hex)
                $verifyToken = bin2hex(random_bytes(32));

                // Create user with verification token
                $userId = $this->userModel->createUser(
                    $username,
                    $email,
                    $passwordHash,
                    "vol",
                    "pending",
                    $verifyToken
                );

                if (!$userId) {
                    $errors[] = "Database error creating user account.";
                    error_log("Failed to create user: " . $this->dbc->error);
                } else {
                    // Create volunteer profile
                    $ok = $this->volModel->create(
                        $userId,
                        $first_name,
                        $last_name,
                        $city_vol,
                        $state_vol,
                        $country_vol,
                        "flexible",
                        null,
                        $profile_picture_path,
                        $phone_no,
                        $birthdate,
                        null
                    );

                    if (!$ok) {
                        // Rollback user creation if volunteer profile fails
                        $this->userModel->deleteById($userId);
                        $errors[] = "Database error saving volunteer profile.";
                        error_log("Failed to create volunteer profile for user_id: {$userId}");
                    } else {
                        // Send verification email
                        require_once __DIR__ . "/../core/mailer.php";
                        
                        $emailSent = sendVolunteerVerificationEmail($email, $verifyToken);
                        
                        if ($emailSent) {
                            require_once __DIR__ . "/../core/flash.php";
                            flash('success', 'Account created successfully! Please check your email (' . $email . ') to verify your account before logging in.');
                            error_log("Volunteer account created for: {$email} (user_id: {$userId})");
                        } else {
                            // Email failed but account created
                            require_once __DIR__ . "/../core/flash.php";
                            flash('warning', 'Account created but verification email failed to send. Please contact support at support@volunteerconnect.org.');
                            error_log("Failed to send verification email to: {$email}");
                        }
                        
                        header("Location: /volcon/app/login.php");
                        exit;
                    }
                }
            }
        } 
        else {
            // Initial arrival, forwarded from signup.php
            $old['username'] = htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8');
            $old['email']    = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $old['password'] = htmlspecialchars($_POST['password'] ?? '', ENT_QUOTES, 'UTF-8');
        }

        require __DIR__ . "/../views/vol/signup_vol_view.php";
        require_once __DIR__ . "/../views/layout/footer.php";
    }
}
?>

<!-- require_once __DIR__ . "/../core/flash.php";
flash('success', 'Account created successfully! You can now sign in.');
header("Location: /volcon/app/login.php");
exit; -->