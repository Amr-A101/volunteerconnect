<?php
// app/controllers/DashboardVolunteerController.php

require_once __DIR__ . "/../core/db.php";
require_once __DIR__ . "/../core/auth.php";
require_once __DIR__ . '/../models/VolunteerModel.php';
require_once __DIR__ . '/../models/OpportunityModel.php';
require_once __DIR__ . '/../models/ApplicationModel.php';
require_once __DIR__ . '/../core/RecommendationService.php';

class DashboardVolunteerController
{
    protected $dbc;
    protected $volModel;
    protected $oppModel;
    protected $appModel;

    public function __construct()
    {
        $this->dbc = $GLOBALS['dbc'];
        $this->volModel = new VolunteerModel($this->dbc);
        $this->oppModel = new OpportunityModel($this->dbc);
        $this->appModel = new ApplicationModel($this->dbc);
    }

    public function index()
    {
        require_role('vol'); 
        $loggedUser = current_user();
        $volunteerId = $loggedUser['user_id'];

        // Fetch volunteer profile
        $volunteer = $this->volModel->getByUserId($volunteerId);
        
        // Get volunteer's actual ID (not user_id)
        $volId = $volunteer['vol_id'] ?? $this->getVolunteerIdByUserId($volunteerId);

        // 1. Pending Applications
        $pendingApplications = $this->getPendingApplications($volId);
        $pendingApplicationsCount = count($pendingApplications);

        // 2. Upcoming Assignments
        $upcomingAssignments = $this->getUpcomingAssignments($volId);
        $upcomingAssignmentsCount = count($upcomingAssignments);

        // 3. Completed Engagements
        $completedEngagements = $this->getCompletedEngagements($volId);
        $completedEngagementsCount = count($completedEngagements);

        // 4. Saved Opportunities
        $savedOpportunities = $this->getSavedOpportunities($volId);
        $savedCount = count($savedOpportunities);

        // 5. Feedback & Ratings Received
        $receivedFeedback = $this->getReceivedFeedback($volId);

        // 6. Recommended Opportunities (using the RecommendationService)
        $recommendedOpportunities = [];
        if (class_exists('RecommendationService')) {
            $recommendationService = new RecommendationService($this->dbc, $volId);
            $recommendationResults = $recommendationService->getRecommendedOpportunities(5);

            $recommendedOpportunities = [];
            if (!empty($recommendationResults)) {
                $recommendedOpportunities = $this->getOpportunityDetails(array_keys($recommendationResults));

                foreach ($recommendedOpportunities as &$opp) {
                    $oppId = $opp['opportunity_id'];
                    if (isset($recommendationResults[$oppId])) {
                        $opp['match'] = $recommendationResults[$oppId];
                    }
                }
                unset($opp);
            }
        }

        // Deleted applications
        $deletedCount = $this->appModel->countDeletedApplications($volId);

        // Load header
        $page_title = "My Dashboard";
        require_once __DIR__ . '/../views/layout/header.php';  

        // Load view
        require __DIR__ . "/../views/vol/dashboard_vol_view.php";

        require_once __DIR__ . "/../views/layout/footer.php";
    }

    // Helper methods to fetch data (inline as requested)
    
    private function getVolunteerIdByUserId($userId)
    {
        $stmt = $this->dbc->prepare("SELECT vol_id FROM volunteers WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result['vol_id'] ?? 0;
    }

    private function getPendingApplications($volId)
    {
        $stmt = $this->dbc->prepare("
            SELECT 
                p.participation_id as application_id,
                o.title,
                org.name as org_name,
                p.participated_at as applied_at
            FROM participation p
            JOIN opportunities o ON p.opportunity_id = o.opportunity_id
            JOIN organizations org ON o.org_id = org.org_id
            WHERE p.volunteer_id = ? 
            AND p.status = 'pending'
            ORDER BY p.participated_at DESC
            LIMIT 5
        ");
        $stmt->bind_param("i", $volId);
        $stmt->execute();
        $result = $stmt->get_result();
        $applications = [];
        while ($row = $result->fetch_assoc()) {
            $applications[] = $row;
        }
        $stmt->close();
        return $applications;
    }

    private function getUpcomingAssignments($volId)
    {
        $stmt = $this->dbc->prepare("
            SELECT 
                o.opportunity_id,
                o.title,
                o.start_date,
                o.end_date,
                o.start_time,
                o.end_time,
                o.location_name,
                o.city,
                org.name as org_name,
                org.org_id
            FROM participation p
            JOIN opportunities o ON p.opportunity_id = o.opportunity_id
            JOIN organizations org ON o.org_id = org.org_id
            WHERE p.volunteer_id = ? 
            AND p.status = 'attended'
            AND o.start_date >= CURDATE()
            AND o.status IN ('ongoing', 'open')
            ORDER BY o.start_date ASC
            LIMIT 5
        ");
        $stmt->bind_param("i", $volId);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        $stmt->close();
        return $assignments;
    }

    private function getCompletedEngagements($volId)
    {
        $stmt = $this->dbc->prepare("
            SELECT 
                o.opportunity_id,
                o.title,
                o.end_date,
                o.start_date,
                p.hours_worked,
                org.name as org_name,
                org.org_id,
                (SELECT COUNT(*) FROM reviews r 
                 WHERE r.opportunity_id = o.opportunity_id 
                 AND r.reviewer_id = ? 
                 AND r.reviewer_type = 'volunteer') as has_reviewed
            FROM participation p
            JOIN opportunities o ON p.opportunity_id = o.opportunity_id
            JOIN organizations org ON o.org_id = org.org_id
            WHERE p.volunteer_id = ? 
            AND p.status = 'attended'
            AND o.end_date < CURDATE()
            AND o.status = 'completed'
            ORDER BY o.end_date DESC
            LIMIT 5
        ");
        $stmt->bind_param("ii", $volId, $volId);
        $stmt->execute();
        $result = $stmt->get_result();
        $engagements = [];
        while ($row = $result->fetch_assoc()) {
            $engagements[] = $row;
        }
        $stmt->close();
        return $engagements;
    }

    private function getReceivedFeedback($volId)
    {
        $stmt = $this->dbc->prepare("
            SELECT 
                r.review_id,
                r.rating,
                r.review_text,
                r.created_at,
                org.name as org_name,
                o.title as opp_title
            FROM reviews r
            JOIN opportunities o ON r.opportunity_id = o.opportunity_id
            JOIN organizations org ON o.org_id = org.org_id
            WHERE r.reviewee_id = ? 
            AND r.reviewee_type = 'volunteer'
            ORDER BY r.created_at DESC
            LIMIT 5
        ");
        $stmt->bind_param("i", $volId);
        $stmt->execute();
        $result = $stmt->get_result();
        $feedback = [];
        while ($row = $result->fetch_assoc()) {
            $feedback[] = $row;
        }
        $stmt->close();
        return $feedback;
    }

    private function getOpportunityDetails($opportunityIds)
    {
        if (empty($opportunityIds)) return [];
        
        $ids = implode(',', array_map('intval', $opportunityIds));
        $stmt = $this->dbc->prepare("
            SELECT 
                o.opportunity_id,
                o.title,
                o.brief_summary,
                o.description,
                o.city,
                o.state,
                o.start_date,
                o.number_of_volunteers,
                o.status,
                org.org_id,
                org.name as org_name,
                (
                    SELECT COUNT(*) 
                    FROM participation p 
                    WHERE p.opportunity_id = o.opportunity_id 
                    AND p.status IN ('attended', 'pending')
                ) as filled_slots,
                (
                    SELECT COUNT(*) 
                    FROM participation p 
                    WHERE p.volunteer_id = ? 
                    AND p.opportunity_id = o.opportunity_id
                ) as has_applied
            FROM opportunities o
            JOIN organizations org ON o.org_id = org.org_id
            WHERE o.opportunity_id IN ($ids)
            AND o.status = 'open'
        ");
        
        // We need volId for has_applied check, get it from session or pass as parameter
        $volId = $_SESSION['vol_id'] ?? 0;
        $stmt->bind_param("i", $volId);
        $stmt->execute();
        $result = $stmt->get_result();
        $opportunities = [];
        while ($row = $result->fetch_assoc()) {
            $row['remaining_slots'] = max(0, ($row['number_of_volunteers'] ?? 0) - ($row['filled_slots'] ?? 0));
            $row['has_applied'] = ($row['has_applied'] ?? 0) > 0;
            $opportunities[] = $row;
        }
        $stmt->close();
        return $opportunities;
    }

    private function getSavedOpportunities(int $volId): array
    {
        $stmt = $this->dbc->prepare("
            SELECT 
                o.opportunity_id,
                o.title,
                o.brief_summary,
                o.city,
                o.state,
                o.start_date,
                org.name AS org_name,
                s.saved_at
            FROM saved_opportunities s
            JOIN opportunities o ON s.opportunity_id = o.opportunity_id
            JOIN organizations org ON o.org_id = org.org_id
            WHERE s.volunteer_id = ?
            ORDER BY s.saved_at DESC
        ");
        $stmt->bind_param("i", $volId);
        $stmt->execute();

        $result = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $result[] = $row;
        }
        $stmt->close();

        return $result;
    }
}
?>