<?php
// app/controllers/ProfileVolunteerController.php

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/../core/auth.php";
require_once __DIR__ . "/../models/VolunteerModel.php";
require_once __DIR__ . "/../models/VolunteerSkillModel.php";
require_once __DIR__ . "/../models/VolunteerInterestModel.php";

class ProfileVolunteerController
{
    protected $dbc;
    protected $volModel, $volSkillModel, $volInterestModel;

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->volModel = new VolunteerModel($this->dbc);
        $this->volSkillModel = new VolunteerSkillModel($this->dbc);
        $this->volInterestModel = new VolunteerInterestModel($this->dbc);
    }

    public function index()
    {
        // Detect roles
        $loggedUser = current_user(); // fetch logged-in user data if logged in

        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            // Viewing someone else's profile
            $volunteerId = (int) $_GET['id'];
        } elseif ($loggedUser && $loggedUser['role'] === 'vol') {
            // Viewing own profile
            $volunteerId = (int) $loggedUser['user_id'];
        } else {
            // Not logged in or invalid access
            header("Location: login.php");
            exit;
        }

        global $dbc;

        $skills = $this->volSkillModel->getSkills($volunteerId);
        $interests = $this->volInterestModel->getInterests($volunteerId);


        // Fetch volunteer
        $volunteer = $this->volModel->getByUserId($volunteerId);
        if (!$volunteer) {
            die("Volunteer not found.");
        }

        $role = $_SESSION['role'] ?? 'guest';

        $is_self = ($loggedUser && $loggedUser['user_id'] == $volunteerId);
        $is_vol = ($role === 'vol');
        $is_org = ($role === 'org');
        $is_admin = ($role === 'admin');

        $availability = strtolower($volunteer['availability'] ?? 'flexible');

        switch ($availability) {
            case 'weekdays':
                $availability_icon = '<i class="fa-solid fa-calendar-week"></i>';
                $availability_class = 'vc-availability-weekdays';
                $availability_label = 'Weekdays';
                break;

            case 'weekends':
                $availability_icon = '<i class="fa-solid fa-calendar-day"></i>';
                $availability_class = 'vc-availability-weekends';
                $availability_label = 'Weekends';
                break;

            case 'part-time':
                $availability_icon = '<i class="fa-solid fa-clock"></i>';
                $availability_class = 'vc-availability-parttime';
                $availability_label = 'Part-Time';
                break;

            default:
                // flexible
                $availability_icon = '<i class="fa-solid fa-arrows-rotate"></i>';
                $availability_class = 'vc-availability-flexible';
                $availability_label = 'Flexible';
        }

        $skillCount = count($skills ?? []);
        $interestCount = count($interests ?? []);
        
        // Get average rating for the volunteer
        $avg_rating = 0;
        $rating_count = 0;
        if (isset($volunteer['vol_id'])) {
            $rating_stmt = $dbc->prepare("
                SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count
                FROM reviews 
                WHERE reviewee_type = 'volunteer' 
                AND reviewee_id = ?
            ");
            $rating_stmt->bind_param("i", $volunteer['vol_id']);
            $rating_stmt->execute();
            $rating_result = $rating_stmt->get_result();
            if ($rating_row = $rating_result->fetch_assoc()) {
                $avg_rating = round($rating_row['avg_rating'] ?? 0, 1);
                $rating_count = $rating_row['rating_count'] ?? 0;
            }
            $rating_stmt->close();
        }
        

        $page_title = $is_self ? "My Profile" : $volunteer['full_name'] . "'s Profile";


        $alert_msg = "";
        $alert_type = "";

        if (isset($_GET['updated']) && $_GET['updated'] == 1) {
            $alert_msg = "Your profile has been updated successfully!";
            $alert_type = "success";
        }

        require_once __DIR__ . '/../views/layout/header.php';  

        // Pass to view
        require __DIR__ . "/../views/vol/profile_vol_view.php";

        require_once __DIR__ . "/../views/layout/footer.php";
    }
}
?>