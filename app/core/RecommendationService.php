<?php
/**
 * RecommendationService
 * Hybrid Rule-Based + Collaborative Filtering
 * Volunteer Connect
 */

class RecommendationService
{
    private int $volId;
    protected $dbc;

    public function __construct($dbc, int $volId)
    {
        $this->dbc = $dbc;
        $this->volId = $volId;
    }

    /* ======================================================
       PUBLIC ENTRY POINT
    ====================================================== */

    public function getRecommendedOpportunities(int $limit = 10, string $priority = 'overall'): array
    {
        $profile = $this->getVolunteerProfile();
        if (!$profile) return [];

        $eligibleOpps = $this->getEligibleOpportunities($profile['age']);
        if (empty($eligibleOpps)) return [];

        $ruleScores = $this->computeRuleScores($eligibleOpps, $profile);
        $collabScores = $this->computeCollaborativeScores();

        return $this->mergeAndRank($ruleScores, $collabScores, $limit, $priority);
    }

    /* ======================================================
       VOLUNTEER PROFILE
    ====================================================== */

    private function getVolunteerProfile(): ?array
    {
        $stmt = $this->dbc->prepare("
            SELECT 
                vol_id,
                TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) AS age,
                city, state, country
            FROM volunteers
            WHERE vol_id = ?
        ");
        $stmt->bind_param("i", $this->volId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $res ?: null;
    }

    /* ======================================================
       ELIGIBLE OPPORTUNITIES (HARD RULES)
    ====================================================== */

    private function getEligibleOpportunities(int $age): array
    {
        $stmt = $this->dbc->prepare("
            SELECT opportunity_id, city, state, country
            FROM opportunities
            WHERE status = 'open'
              AND (application_deadline IS NULL OR application_deadline >= NOW())
              AND (min_age IS NULL OR min_age <= ?)
        ");
        $stmt->bind_param("i", $age);
        $stmt->execute();

        $result = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $result[$row['opportunity_id']] = $row;
        }
        $stmt->close();

        return $result;
    }

    /* ======================================================
       RULE-BASED SCORING
    ====================================================== */

    private function computeRuleScores(array $opps, array $profile): array
    {
        $interestScores = $this->computeInterestScores();
        $skillScores    = $this->computeSkillScores();

        $scores = [];

        foreach ($opps as $oppId => $opp) {
            $interest = $interestScores[$oppId] ?? 0;
            $skill    = $skillScores[$oppId] ?? 0;
            $location = $this->computeLocationScore($opp, $profile);

            $scores[$oppId] = [
                'interest' => round($interest, 4),
                'skill'    => round($skill, 4),
                'location' => round($location, 4),
                'total'    => round(
                    (0.4 * $interest) +
                    (0.4 * $skill) +
                    (0.2 * $location),
                    4
                )
            ];
        }

        return $scores;
    }



    /* ======================================================
       INTEREST MATCHING (JACCARD)
    ====================================================== */

    private function computeInterestScores(): array
    {
        $volInterests = [];
        $stmt = $this->dbc->prepare("
            SELECT interest_id FROM volunteer_interests WHERE vol_id = ?
        ");
        $stmt->bind_param("i", $this->volId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $volInterests[] = $r['interest_id'];
        }
        $stmt->close();

        if (empty($volInterests)) return [];

        $oppInterests = [];
        $res = $this->dbc->query("
            SELECT opportunity_id, interest_id FROM opportunity_interests
        ");
        while ($r = $res->fetch_assoc()) {
            $oppInterests[$r['opportunity_id']][] = $r['interest_id'];
        }

        $scores = [];
        foreach ($oppInterests as $oppId => $ints) {
            $intersection = array_intersect($volInterests, $ints);
            $union = array_unique(array_merge($volInterests, $ints));
            $scores[$oppId] = count($union) > 0
                ? count($intersection) / count($union)
                : 0;
        }

        return $scores;
    }

    /* ======================================================
       SKILL MATCHING (WEIGHTED)
    ====================================================== */

    private function computeSkillScores(): array
    {
        $volSkills = [];
        $res = $this->dbc->query("
            SELECT skill_id,
                   CASE proficiency
                     WHEN 'beginner' THEN 1
                     WHEN 'intermediate' THEN 2
                     WHEN 'advanced' THEN 3
                     WHEN 'expert' THEN 4
                   END AS weight
            FROM volunteer_skills
            WHERE vol_id = {$this->volId}
        ");

        while ($r = $res->fetch_assoc()) {
            $volSkills[$r['skill_id']] = $r['weight'];
        }

        if (empty($volSkills)) return [];

        $scores = [];
        $res = $this->dbc->query("
            SELECT opportunity_id, skill_id
            FROM opportunity_skills
        ");

        $oppSkills = [];
        while ($r = $res->fetch_assoc()) {
            $oppSkills[$r['opportunity_id']][] = $r['skill_id'];
        }

        foreach ($oppSkills as $oppId => $skills) {
            $matched = 0;
            foreach ($skills as $skillId) {
                if (isset($volSkills[$skillId])) {
                    $matched += $volSkills[$skillId];
                }
            }
            $required = count($skills);
            $scores[$oppId] = $required > 0 ? $matched / $required : 0;
        }

        return $scores;
    }

    /* ======================================================
       LOCATION SCORE
    ====================================================== */

    private function computeLocationScore(array $opp, array $profile): float
    {
        if ($opp['city'] && $opp['city'] === $profile['city']) return 1.0;
        if ($opp['state'] && $opp['state'] === $profile['state']) return 0.6;
        if ($opp['country'] && $opp['country'] === $profile['country']) return 0.3;
        return 0.0;
    }

    /* ======================================================
       COLLABORATIVE FILTERING
    ====================================================== */

    private function computeCollaborativeScores(): array
    {
        $scores = [];

        $stmt = $this->dbc->prepare("
            SELECT p2.volunteer_id, COUNT(*) AS shared
            FROM participation p1
            JOIN participation p2
              ON p1.opportunity_id = p2.opportunity_id
            WHERE p1.volunteer_id = ?
              AND p1.status = 'attended'
              AND p2.status = 'attended'
              AND p2.volunteer_id != ?
            GROUP BY p2.volunteer_id
        ");
        $stmt->bind_param("ii", $this->volId, $this->volId);
        $stmt->execute();
        $res = $stmt->get_result();

        $similarVols = [];
        while ($r = $res->fetch_assoc()) {
            $similarVols[$r['volunteer_id']] = $r['shared'];
        }
        $stmt->close();

        if (empty($similarVols)) return [];

        $ids = implode(',', array_keys($similarVols));
        $res = $this->dbc->query("
            SELECT opportunity_id,
                   SUM(
                     CASE status
                       WHEN 'attended' THEN 1
                       WHEN 'incomplete' THEN 0.5
                       WHEN 'absent' THEN -0.5
                     END
                   ) AS score
            FROM participation
            WHERE volunteer_id IN ($ids)
            GROUP BY opportunity_id
        ");

        while ($r = $res->fetch_assoc()) {
            $scores[$r['opportunity_id']] = (float)$r['score'];
        }

        return $scores;
    }

    /* ======================================================
       FINAL MERGE
    ====================================================== */

    private function mergeAndRank(array $rule, array $collab, int $limit, string $priority): array
    {
        $allowed = ['overall', 'skill', 'interest', 'location'];
        if (!in_array($priority, $allowed, true)) {
            $priority = 'overall';
        }

        $final = [];

        foreach ($rule as $oppId => $ruleParts) {
            $collabScore = $collab[$oppId] ?? 0;
            $collabScore = max(0, min(1, $collabScore));

            switch ($priority) {
                case 'skill':
                    $finalScore = (0.7 * $ruleParts['skill']) +
                                (0.2 * $ruleParts['interest']) +
                                (0.1 * $ruleParts['location']);
                    break;

                case 'interest':
                    $finalScore = (0.7 * $ruleParts['interest']) +
                                (0.2 * $ruleParts['skill']) +
                                (0.1 * $ruleParts['location']);
                    break;

                case 'location':
                    $finalScore = (0.7 * $ruleParts['location']) +
                                (0.15 * $ruleParts['interest']) +
                                (0.15 * $ruleParts['skill']);
                    break;

                default: // overall
                    $finalScore = (0.6 * $ruleParts['total']) +
                                (0.4 * $collabScore);
            }


            $final[$oppId] = [
                'final'    => round($finalScore, 4),
                'interest' => $ruleParts['interest'],
                'skill'    => $ruleParts['skill'],
                'location' => $ruleParts['location'],
                'collab'   => round($collabScore, 4),
            ];
        }

        uasort($final, fn($a, $b) => $b['final'] <=> $a['final']);

        return array_slice($final, 0, $limit, true);
    }

}
