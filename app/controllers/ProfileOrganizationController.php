<?php
// app/controllers/ProfileOrganizationController.php

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../models/OrganizationModel.php';

class ProfileOrganizationController
{
    protected $dbc;
    protected $orgModel;

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->orgModel = new OrganizationModel($this->dbc);
    }

    public function index()
    {
        // Detect logged-in user
        $loggedUser = current_user();
        $role = $_SESSION['role'] ?? 'guest';

        global $dbc;

        // Resolve organization ID
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            // Viewing a specific organization
            $orgId = (int) $_GET['id'];
        } elseif ($loggedUser && $role === 'org') {
            // Viewing own organization profile
            $orgId = (int) $loggedUser['user_id'];
        } else {
            header("Location: /login.php");
            exit;
        }

        // Fetch organization
        $org = $this->orgModel->getByUserId($orgId);
        if (!$org) {
            die("Organization not found.");
        }

        // Detect viewer role
        $is_self  = ($loggedUser && $loggedUser['user_id'] == $orgId);
        $is_org   = ($role === 'org');
        $is_vol   = ($role === 'vol');
        $is_admin = ($role === 'admin');

        /* =========================
          AUTO STATUS TRANSITIONS
        ========================= */
        require_once __DIR__ . '/../core/auto_opp_trans.php';
        runOpportunityAutoTransitions($dbc, $orgId);

        // Get average rating for the organization
        $avg_rating = 0;
        $rating_count = 0;
        if (isset($org['org_id'])) {
            $rating_stmt = $dbc->prepare("
                SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count
                FROM reviews 
                WHERE reviewee_type = 'organization' 
                AND reviewee_id = ?
            ");
            $rating_stmt->bind_param("i", $org['org_id']);
            $rating_stmt->execute();
            $rating_result = $rating_stmt->get_result();
            if ($rating_row = $rating_result->fetch_assoc()) {
                $avg_rating = round($rating_row['avg_rating'] ?? 0, 1);
                $rating_count = $rating_row['rating_count'] ?? 0;
            }
            $rating_stmt->close();
        }

        $open_opportunities = [];
        $completed_opportunities = [];
        
        if (isset($org['org_id'])) {
            $opp_stmt = $dbc->prepare("
                SELECT o.*, 
                        (SELECT AVG(rating) FROM reviews r WHERE r.opportunity_id = o.opportunity_id AND r.reviewee_type = 'organization') as avg_rating,
                        (SELECT COUNT(*) FROM reviews r WHERE r.opportunity_id = o.opportunity_id AND r.reviewee_type = 'organization') as rating_count
                FROM opportunities o
                WHERE o.org_id = ? 
                AND o.status IN ('open', 'completed')
                ORDER BY 
                    CASE o.status 
                        WHEN 'open' THEN 1 
                        WHEN 'completed' THEN 2 
                        ELSE 3 
                    END,
                    o.start_date DESC
            ");
            $opp_stmt->bind_param("i", $org['org_id']);
            $opp_stmt->execute();
            $opp_result = $opp_stmt->get_result();
            
            while ($opp = $opp_result->fetch_assoc()) {
                if ($opp['status'] === 'open') {
                    $open_opportunities[] = $opp;
                } elseif ($opp['status'] === 'completed') {
                    $completed_opportunities[] = $opp;
                }
            }
            $opp_stmt->close();
        }


        $page_title = $is_self ? "My Organization Profile" : $org['name'];

        // success alert
        $alert_msg = "";
        if (isset($_GET['updated']) && $_GET['updated'] == 1) {
            $alert_msg = "Organization profile updated successfully.";
        }

        require_once __DIR__ . '/../views/layout/header.php';
        require_once __DIR__ . '/../views/org/profile_org_view.php';
        require_once __DIR__ . '/../views/layout/footer.php';
    }
}
