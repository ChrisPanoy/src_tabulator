<?php
// Function to compute Group Ranking
function calculate_event_results($pdo, $event_id) {
    // 1. Get Group Criteria with Weights
    $stmt = $pdo->prepare("SELECT id, weight FROM tab_criteria WHERE event_id = ? AND type = 'group'");
    $stmt->execute([$event_id]);
    $group_criteria = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => weight

    // 2. Get All Teams in Event
    $stmt = $pdo->prepare("SELECT id, team_name, project_title FROM tab_teams WHERE event_id = ?");
    $stmt->execute([$event_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($teams as $team) {
        $team_id = $team['id'];
        
        // Fetch All Scores for this team
        $stmt_s = $pdo->prepare("SELECT panelist_id, criteria_id, score FROM tab_scores WHERE team_id = ?");
        $stmt_s->execute([$team_id]);
        $scores = $stmt_s->fetchAll(PDO::FETCH_ASSOC);

        // Group scores by panelist
        $panelist_totals = []; 
        $breakdown = []; // criteria_id => [sum, count]

        foreach ($scores as $s) {
            $pid = $s['panelist_id'];
            $cid = $s['criteria_id'];
            $val = $s['score'];

            // Store breakdown for criteria average
            if (!isset($breakdown[$cid])) $breakdown[$cid] = ['sum' => 0, 'count' => 0];
            $breakdown[$cid]['sum'] += $val;
            $breakdown[$cid]['count']++;

            // Calculate Weighted Score per Panelist (assuming score is 0-100)
            if (isset($group_criteria[$cid])) {
                $weight = $group_criteria[$cid];
                if (!isset($panelist_totals[$pid])) $panelist_totals[$pid] = 0;
                // Weighted contribution: Score * (Weight/100)
                $panelist_totals[$pid] += ($val * ($weight / 100)); 
            }
        }

        // Final Score = Average of Panelists' Weighted Totals
        $final_score = 0;
        if (count($panelist_totals) > 0) {
            $final_score = array_sum($panelist_totals) / count($panelist_totals);
        }

        // Criteria Averages (Raw Average of all panelists for that criteria)
        $criteria_averages = [];
        foreach ($breakdown as $cid => $data) {
            $criteria_averages[$cid] = $data['count'] > 0 ? $data['sum'] / $data['count'] : 0;
        }

        $results[] = [
            'id' => $team_id,
            'team_name' => $team['team_name'],
            'project_title' => $team['project_title'],
            'final_score' => $final_score,
            'criteria_averages' => $criteria_averages
        ];
    }

    // Sort Descending
    usort($results, function($a, $b) {
        return $b['final_score'] <=> $a['final_score'];
    });

    return $results;
}

// Function to compute Individual Ranking
function calculate_individual_results($pdo, $event_id) {
    // 1. Get Ind Criteria
    $stmt = $pdo->prepare("SELECT id, weight FROM tab_criteria WHERE event_id = ? AND type = 'individual'");
    $stmt->execute([$event_id]);
    $ind_criteria = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 2. Get Members (Names)
    $sql = "SELECT tm.id, tm.member_name as full_name, t.team_name, tm.role_in_project 
            FROM tab_team_members tm
            JOIN tab_teams t ON tm.team_id = t.id 
            WHERE t.event_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$event_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($members as $m) {
        $mid = $m['id'];
        
        $stmt_s = $pdo->prepare("SELECT panelist_id, criteria_id, score FROM tab_individual_scores WHERE team_member_id = ?");
        $stmt_s->execute([$mid]);
        $scores = $stmt_s->fetchAll(PDO::FETCH_ASSOC);

        $panelist_totals = [];

        foreach ($scores as $s) {
            $pid = $s['panelist_id'];
            $cid = $s['criteria_id'];
            $val = $s['score'];

            if (isset($ind_criteria[$cid])) {
                $weight = $ind_criteria[$cid];
                if (!isset($panelist_totals[$pid])) $panelist_totals[$pid] = 0;
                 $panelist_totals[$pid] += ($val * ($weight / 100));
            }
        }

        $final_score = 0;
        if (count($panelist_totals) > 0) {
            $final_score = array_sum($panelist_totals) / count($panelist_totals);
        }

        if ($final_score > 0) {
            $results[] = [
                'id' => $mid,
                'full_name' => $m['full_name'],
                'team_name' => $m['team_name'],
                'role' => $m['role_in_project'],
                'final_score' => $final_score
            ];
        }
    }

    usort($results, function($a, $b) {
        return $b['final_score'] <=> $a['final_score'];
    });

    return $results;
}

// Function to Calculate Special Awards dynamically based on categories
function calculate_special_awards($pdo, $event_id) {
    // 1. Get all unique group categories for this event via JOIN
    $stmt = $pdo->prepare("
        SELECT DISTINCT rc.id, rc.category_name 
        FROM tab_rubric_categories rc 
        JOIN tab_criteria c ON c.category_id = rc.id 
        WHERE c.event_id = ? AND c.type = 'group' AND rc.category_name != 'General'
    ");
    $stmt->execute([$event_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $awards = [];
    
    foreach ($categories as $cat) {
        $cat_id = $cat['id'];
        $cat_name = $cat['category_name'];

        // Find all criteria IDs in this category using category_id
        $stmt_c = $pdo->prepare("SELECT id FROM tab_criteria WHERE event_id = ? AND category_id = ? AND type = 'group'");
        $stmt_c->execute([$event_id, $cat_id]);
        $target_ids = $stmt_c->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($target_ids)) {
            $placeholders = rtrim(str_repeat('?,', count($target_ids)), ',');
            $sql = "
                SELECT t.team_name, AVG(s.score) as cat_avg 
                FROM tab_scores s 
                JOIN tab_teams t ON s.team_id = t.id 
                WHERE s.criteria_id IN ($placeholders) 
                GROUP BY t.id 
                ORDER BY cat_avg DESC 
                LIMIT 1
            ";
            $stmt_win = $pdo->prepare($sql);
            $stmt_win->execute($target_ids);
            $winner = $stmt_win->fetch(PDO::FETCH_ASSOC);

            if ($winner) {
                // Prevent "Best Best" naming
                $normalized_cat = strtolower($cat_name);
                if ($normalized_cat == 'manuscripts' || $normalized_cat == 'imrad' || $normalized_cat == 'brochure' || $normalized_cat == 'poster') {
                    $display_name = 'Best in ' . $cat_name;
                } elseif (strpos($normalized_cat, 'best') === 0) {
                    $display_name = $cat_name;
                } else {
                    $display_name = 'Best ' . $cat_name;
                }
                
                $awards[] = [
                    'award_name' => $display_name,
                    'category' => $cat_name,
                    'category_id' => $cat_id,
                    'team' => $winner['team_name'],
                    'score' => (float)$winner['cat_avg']
                ];
            }
        }
    }
    
    return $awards;
}
?>
