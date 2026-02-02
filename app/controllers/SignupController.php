<?php
// app/controllers/SignupController.php
// Single register() handler for final POST

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/VolunteerModel.php';
require_once __DIR__ . '/../models/OrganizationModel.php';

class SignupController
{
    protected $dbc;
    protected $userModel;
    protected $volModel;
    protected $orgModel;
    protected $maxImageSize = 2 * 1024 * 1024; // 2MB

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->userModel = new UserModel($this->dbc);
        $this->volModel  = new VolunteerModel($this->dbc);
        $this->orgModel  = new OrganizationModel($this->dbc);
    }

    public function showStepOne()
    {
        $errors = [];
        $old = ['username'=>'','email'=>'','role'=>''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            // Step1 data
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? '';

            $errors = [];

            // Username - same regex as JS
            if ($username === '' || !preg_match('/^[A-Za-z](?!.*__)[A-Za-z0-9_.]{1,18}[A-Za-z0-9]$/', $username)) {
                $errors[] = 'Invalid username. Start with letter, 3-20 chars, no double underscores, no trailing underscore.';
            }

            // Email
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email.';
            }

            // Password
            if ($password === '' || !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,16}$/', $password)) {
                $errors[] = 'Password must be 8-16 chars and include an upper-case, lower-case, digit and special character.';
            }

            // role
            if (!in_array($role, ['vol','org'])) $errors[] = 'Invalid role.';

            // uniqueness checks
            if (empty($errors)) {
                if ($this->userModel->usernameExists($username)) $errors[] = 'Username already taken.';
                if ($this->userModel->emailExists($email)) $errors[] = 'Email already registered.';
            }


            // Success → POST-forward to step2
            if (empty($errors)) {
                $target = ($role === 'vol') ? "signup_vol.php" : "signup_org.php";

                echo "
                <form id='forward' method='POST' action='{$target}'>
                    <input type='hidden' name='username' value=\"".htmlspecialchars($username, ENT_QUOTES, 'UTF-8')."\">
                    <input type='hidden' name='email' value=\"".htmlspecialchars($email, ENT_QUOTES, 'UTF-8')."\">
                    <input type='hidden' name='password' value=\"".htmlspecialchars($password, ENT_QUOTES, 'UTF-8')."\"> 
                </form>
                <script>document.getElementById('forward').submit();</script>";

                exit;
            }
        }
        
        require __DIR__ . "/../views/signup_view.php";

        require_once __DIR__ . "/../views/layout/footer.php";
    }

    protected function handleImageUpload($file, &$errors)
    {
        $errors = [];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Error uploading file.';
            return false;
        }
        if ($file['size'] > $this->maxImageSize) {
            $errors[] = 'Image exceeds maximum size of 2MB.';
            return false;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif'
        ];
        if (!isset($allowed[$mime])) {
            $errors[] = 'Only JPG, PNG or GIF allowed.';
            return false;
        }

        $uploadDir = __DIR__ . '/../../assets/uploads/pfp';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = $allowed[$mime];
        $filename = 'pp_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errors[] = 'Failed to move uploaded file.';
            return false;
        }

        return '/volcon/assets/uploads/pfp/' . $filename;
    }
}
?>