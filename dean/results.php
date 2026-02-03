<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/calculation_engine.php';
require_once '../components/ui.php';
requireRole('dean');

$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
if (!$event_id) {
    $curr = get_current_event($pdo);
    if($curr) header("Location: results.php?event_id=" . $curr['id']);
    else die("No active event found. Please create an event session.");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM tab_events WHERE id = ?");
$stmt->execute([$event_id]);
$event = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_release'])) {
    $new_status = $_POST['release_status'] ? 0 : 1;
    $pdo->prepare("UPDATE tab_events SET is_results_released = ? WHERE id = ?")->execute([$new_status, $event_id]);
    header("Location: results.php?event_id=" . $event_id);
    exit;
}

// Data Processing
$team_results = calculate_event_results($pdo, $event_id);
$ind_results = calculate_individual_results($pdo, $event_id);
$special_awards = calculate_special_awards($pdo, $event_id);

$overall_winner = !empty($team_results) ? $team_results[0] : null;

// Combine Individual and Group Awards into one list for the Highlights section
$award_highlights = [];
$seen_titles = [];

// 1. DYNAMIC INDIVIDUAL CATEGORY AWARDS
$stmt_ind_cats = $pdo->prepare("
    SELECT DISTINCT rc.category_name 
    FROM tab_rubric_categories rc 
    JOIN tab_criteria c ON c.category_id = rc.id 
    WHERE c.event_id = ? AND c.type = 'individual' AND rc.category_name != 'General'
");
$stmt_ind_cats->execute([$event_id]);
$ind_categories = $stmt_ind_cats->fetchAll(PDO::FETCH_COLUMN);

foreach ($ind_categories as $ind_cat) {
    if (strtolower($ind_cat) == 'presentation') {
        $title = 'Best Presenter';
    } else {
        $title = (stripos($ind_cat, 'Best') === 0) ? $ind_cat : 'Best in ' . $ind_cat;
    }
    
    $winner = get_individual_top5($pdo, $event_id, $ind_cat);
    if (!empty($winner)) {
        $top = $winner[0];
        $award_highlights[] = [
            'type' => 'individual',
            'title' => $title,
            'winner' => $top['member_name'],
            'score' => $top['total_avg'],
            'subtitle' => $top['team_name'],
            'icon' => '🎤',
            'color' => '#10b981'
        ];
        $seen_titles[] = $title;
    }
}

// 2. OVERALL BEST PRESENTER (Only if not already added by category)
if (!empty($ind_results) && !in_array('Best Presenter', $seen_titles)) {
    $top_student = $ind_results[0];
    $award_highlights[] = [
        'type' => 'individual',
        'title' => 'Best Presenter',
        'winner' => $top_student['full_name'],
        'score' => $top_student['final_score'],
        'subtitle' => $top_student['team_name'],
        'icon' => '🎤',
        'color' => '#059669'
    ];
}

// 3. GROUP CATEGORY AWARDS
$award_meta = [
    'Manuscripts' => ['icon' => '📄', 'color' => '#4f46e5'],
    'Poster' => ['icon' => '🖼️', 'color' => '#f59e0b'],
    'Brochure' => ['icon' => '📂', 'color' => '#06b6d4'],
    'Teaser' => ['icon' => '🎬', 'color' => '#f43f5e']
];

foreach ($special_awards as $award) {
    $cat = $award['category'];
    $meta = $award_meta[$cat] ?? ['icon' => '🏆', 'color' => '#6366f1'];
    
    $award_highlights[] = [
        'type' => 'group',
        'title' => $award['award_name'],
        'winner' => $award['team'],
        'score' => $award['score'],
        'subtitle' => 'Team Award',
        'icon' => $meta['icon'],
        'color' => $meta['color']
    ];
}

// Get detailed category rankings (Top 5 for each category)
function get_category_top5($pdo, $event_id, $category_name) {
    // Get criteria IDs for this category via JOIN with rubric_categories
    $stmt = $pdo->prepare("
        SELECT c.id, c.criteria_name 
        FROM tab_criteria c 
        JOIN tab_rubric_categories rc ON c.category_id = rc.id 
        WHERE c.event_id = ? AND c.type = 'group' AND rc.category_name = ?
    ");
    $stmt->execute([$event_id, $category_name]);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($criteria)) return [];
    
    $criteria_ids = array_column($criteria, 'id');
    $placeholders = rtrim(str_repeat('?,', count($criteria_ids)), ',');
    
    // Get team scores for this category with panelist breakdown and comments
    $sql = "
        SELECT 
            t.id as team_id,
            t.team_name,
            t.project_title,
            s.panelist_id,
            u.full_name as judge_name,
            AVG(s.score) as panelist_avg,
            GROUP_CONCAT(CONCAT('<strong>', c.criteria_name, ':</strong> ', IFNULL(s.comments, '<em>No comment</em>')) SEPARATOR '<br>') as detailed_feedback
        FROM tab_scores s
        JOIN tab_teams t ON s.team_id = t.id
        JOIN tab_users u ON s.panelist_id = u.id
        JOIN tab_criteria c ON s.criteria_id = c.id
        WHERE s.criteria_id IN ($placeholders) AND t.event_id = ?
        GROUP BY t.id, s.panelist_id
    ";
    
    $params = array_merge($criteria_ids, [$event_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by team and calculate overall average
    $teams = [];
    foreach ($results as $row) {
        $tid = $row['team_id'];
        if (!isset($teams[$tid])) {
            $teams[$tid] = [
                'team_id' => $tid,
                'team_name' => $row['team_name'],
                'project_title' => $row['project_title'],
                'judge_scores' => [],
                'total_avg' => 0,
                'brochure_path' => null,
                'poster_path' => null,
                'imrad_path' => null
            ];
        }
        $teams[$tid]['judge_scores'][] = [
            'judge_name' => $row['judge_name'],
            'score' => (float)$row['panelist_avg'],
            'feedback' => $row['detailed_feedback']
        ];
    }
    
    // Fetch submission file paths
    if (!empty($teams)) {
        $team_ids = array_keys($teams);
        $placeholders_teams = rtrim(str_repeat('?,', count($team_ids)), ',');
        $sql_submissions = "SELECT team_id, file_type, file_path FROM tab_submissions WHERE team_id IN ($placeholders_teams)";
        $stmt_sub = $pdo->prepare($sql_submissions);
        $stmt_sub->execute($team_ids);
        while($sub = $stmt_sub->fetch()) {
            $tid = $sub['team_id'];
            if (isset($teams[$tid])) $teams[$tid][$sub['file_type'] . '_path'] = $sub['file_path'];
        }
    }
    
    foreach ($teams as &$team) {
        if (!empty($team['judge_scores'])) {
            $sum = array_sum(array_column($team['judge_scores'], 'score'));
            $team['total_avg'] = $sum / count($team['judge_scores']);
        }
    }
    
    usort($teams, function($a, $b) { return $b['total_avg'] <=> $a['total_avg']; });
    return array_slice($teams, 0, 5);
}

function get_individual_top5($pdo, $event_id, $category_name = null) {
    // Get individual criteria via JOIN
    $sql_crit = "
        SELECT c.id, c.criteria_name 
        FROM tab_criteria c 
        LEFT JOIN tab_rubric_categories rc ON c.category_id = rc.id 
        WHERE c.event_id = ? AND c.type = 'individual'";
    
    if ($category_name) {
        $sql_crit .= " AND rc.category_name = ?";
        $params_crit = [$event_id, $category_name];
    } else {
        $params_crit = [$event_id];
    }
    
    $stmt = $pdo->prepare($sql_crit);
    $stmt->execute($params_crit);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($criteria)) return [];
    
    $criteria_ids = array_column($criteria, 'id');
    $placeholders = rtrim(str_repeat('?,', count($criteria_ids)), ',');
    
    // Get individual scores with panelist breakdown and comments
    $sql = "
        SELECT 
            tm.id as member_id,
            tm.member_name,
            t.team_name,
            tm.role_in_project,
            ids.panelist_id,
            u.full_name as judge_name,
            AVG(ids.score) as panelist_avg,
            GROUP_CONCAT(CONCAT('<strong>', c.criteria_name, ':</strong> ', IFNULL(ids.comments, '<em>No comment</em>')) SEPARATOR '<br>') as detailed_feedback
        FROM tab_individual_scores ids
        JOIN tab_team_members tm ON ids.team_member_id = tm.id
        JOIN tab_teams t ON tm.team_id = t.id
        JOIN tab_users u ON ids.panelist_id = u.id
        JOIN tab_criteria c ON ids.criteria_id = c.id
        WHERE ids.criteria_id IN ($placeholders) AND t.event_id = ?
        GROUP BY tm.id, ids.panelist_id
    ";
    
    $params = array_merge($criteria_ids, [$event_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by member
    $members = [];
    foreach ($results as $row) {
        $mid = $row['member_id'];
        if (!isset($members[$mid])) {
            $members[$mid] = [
                'member_id' => $mid,
                'member_name' => $row['member_name'],
                'team_name' => $row['team_name'],
                'role' => $row['role_in_project'],
                'judge_scores' => [],
                'total_avg' => 0
            ];
        }
        $members[$mid]['judge_scores'][] = [
            'judge_name' => $row['judge_name'],
            'score' => (float)$row['panelist_avg'],
            'feedback' => $row['detailed_feedback']
        ];
    }
    
    foreach ($members as &$member) {
        if (!empty($member['judge_scores'])) {
            $sum = array_sum(array_column($member['judge_scores'], 'score'));
            $member['total_avg'] = $sum / count($member['judge_scores']);
        }
    }
    
    usort($members, function($a, $b) { return $b['total_avg'] <=> $a['total_avg']; });
    return array_slice($members, 0, 5);
}

$category_rankings = [];
foreach ($award_highlights as $ah) {
    $ranking_key = $ah['type'] . '_' . $ah['title'];
    if ($ah['type'] == 'individual') {
        if ($ah['title'] == 'Best Presenter') {
            $category_rankings[$ranking_key] = get_individual_top5($pdo, $event_id);
        } else {
            // Check both formats: "Best in X" and just "X"
            $cat_search = str_replace('Best in ', '', $ah['title']);
            $category_rankings[$ranking_key] = get_individual_top5($pdo, $event_id, $cat_search);
        }
    } else {
        // Find the category name from award highlights or engine results
        $target_cat = '';
        foreach($special_awards as $sa) {
            if ($sa['award_name'] == $ah['title']) {
                $target_cat = $sa['category'];
                break;
            }
        }
        if ($target_cat) {
            $category_rankings[$ranking_key] = get_category_top5($pdo, $event_id, $target_cat);
        }
    }
}

// Check if any scores have been submitted at all
$stmt_check = $pdo->prepare("SELECT COUNT(*) FROM tab_scores s JOIN tab_teams t ON s.team_id = t.id WHERE t.event_id = ?");
$stmt_check->execute([$event_id]);
$total_scores = $stmt_check->fetchColumn();

// Check ind scores too
$stmt_check_ind = $pdo->prepare("SELECT COUNT(*) FROM tab_individual_scores ids JOIN tab_team_members tm ON ids.team_member_id = tm.id JOIN tab_teams t ON tm.team_id = t.id WHERE t.event_id = ?");
$stmt_check_ind->execute([$event_id]);
$total_ind_scores = $stmt_check_ind->fetchColumn();

$has_scores = ($total_scores > 0 || $total_ind_scores > 0);

// Criteria
$stmt_crit = $pdo->prepare("SELECT id, criteria_name FROM tab_criteria WHERE event_id = ? AND type = 'group' ORDER BY display_order");
$stmt_crit->execute([$event_id]);
$criteria_headers = $stmt_crit->fetchAll();

render_head("Live Results: " . $event['title']);
render_navbar($_SESSION['full_name'], 'dean', '../', "Tabulation Results");
?>

<div class="container" style="margin-top: 3rem; padding-bottom: 5rem;">
    <div class="page-header" style="margin-bottom: 3rem;">
        <div>
            <h1 style="font-size: 2.25rem; letter-spacing: -0.02em;">Results</h1>
            <div style="display: flex; align-items: center; gap: 1rem; margin-top: 0.5rem;">
                 <span style="color: var(--text-light);"><?= htmlspecialchars($event['title']) ?></span>
                 <span style="width: 4px; height: 4px; border-radius: 50%; background: var(--border);"></span>
                 <span style="color: var(--text-light); font-weight: 500;">Live Feed: <?= date('h:i A') ?></span>
            </div>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
             <form method="POST" style="display: inline;">
                <input type="hidden" name="toggle_release" value="1">
                <input type="hidden" name="release_status" value="<?= $event['is_results_released'] ?>">
                <button type="submit" class="btn <?= $event['is_results_released'] ? 'btn-secondary' : 'btn-primary' ?>" style="font-weight: 700;">
                    <?= $event['is_results_released'] ? ' Unpublish Results' : ' Publish Live Results' ?>
                </button>
            </form>
            <button onclick="toggleCategoryRankings()" id="toggleCategoryBtn" class="btn btn-primary" style="font-weight: 700;">
                 Show Category Rankings
            </button>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="exportToExcel('team_table', 'Team_Rankings.xls')" class="btn btn-secondary" style="font-weight: 700;"> Excel</button>
                <a href="print_grading_sheets.php?event_id=<?= $event_id ?>" target="_blank" class="btn btn-secondary" style="font-weight: 700;"> Print Batch</a>
            </div>
        </div>
    </div>

    <?php if(!$has_scores): ?>
        <div class="card animate-fade-in" style="text-align: center; padding: 5rem; background: white; border: 2px dashed var(--border);">
            <div style="font-size: 5rem; margin-bottom: 2rem; opacity: 0.3;"></div>
            <h2 style="font-size: 2rem; color: var(--primary-dark); margin-bottom: 1rem;">Waiting for Judge Evaluations</h2>
            <p style="color: var(--text-light); max-width: 500px; margin: 0 auto; font-size: 1.1rem; line-height: 1.6;">
                Tabulation results and award winners will automatically appear here once panelists begin submitting their evaluations.
            </p>
            <div style="margin-top: 3rem; display: flex; justify-content: center; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; color: var(--text-light); font-weight: 600;">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #e2e8f0; animation: pulse 2s infinite;"></span>
                    Awaiting Live Data...
                </div>
            </div>
        </div>
        
        <style>
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(226, 232, 240, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(226, 232, 240, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(226, 232, 240, 0); }
        }
        
        @keyframes highlight-pulse {
            0%, 100% { box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); }
            50% { box-shadow: 0 8px 30px rgba(79, 70, 229, 0.4); transform: scale(1.01); }
        }
        </style>

    <?php else: ?>
        <!-- Award Highlights -->
        <div style="margin-bottom: 1.5rem;">
            <h3 style="font-size: 1.125rem; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 800;"> Major Awards & Recognition</h3>
        </div>
        
        <div class="dashboard-grid" style="margin-bottom: 4rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <?php foreach ($award_highlights as $index => $award): ?>
                <div class="card animate-fade-in" 
                     onclick="showCategoryDetail('<?= addslashes($award['title']) ?>', '<?= $award['type'] ?>')" 
                     style="border-top: 4px solid <?= $award['color'] ?>; background: white; text-align: center; padding: 2rem; animation-delay: <?= $index * 0.1 ?>s; cursor: pointer; transition: all 0.3s ease;" 
                     onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 10px 30px <?= $award['color'] ?>4d';" 
                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='';"
                     title="Click to view full rankings">
                    <div style="font-size: 2.5rem; margin-bottom: 1rem;"><?= $award['icon'] ?></div>
                    <p style="color: var(--text-light); font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;"><?= htmlspecialchars($award['title']) ?></p>
                    <h4 style="font-size: 1.25rem; color: var(--primary-dark); margin-bottom: 1rem; min-height: 3em; display: flex; align-items: center; justify-content: center;">
                        <?= htmlspecialchars($award['winner']) ?>
                    </h4>
                    <div style="background: <?= $award['color'] ?>1a; color: <?= $award['color'] ?>; padding: 0.5rem 1.25rem; border-radius: 50px; font-weight: 800; display: inline-block;">
                        <?= number_format($award['score'], 2) ?>
                    </div>
                    <?php if ($award['subtitle']): ?>
                        <div style="font-size: 0.7rem; color: var(--text-light); margin-top: 0.5rem;"><?= htmlspecialchars($award['subtitle']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>


        
        <!-- Detailed Category Rankings Section (Hidden by default) -->
        <div id="categoryRankingsSection" style="display: none; margin-bottom: 4rem;">
            <div style="margin-bottom: 2rem; padding: 1.5rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; color: white;">
                <h2 style="margin: 0; font-size: 1.75rem; letter-spacing: -0.02em; color: white;"> Detailed Category Rankings</h2>
                <p style="margin: 0.5rem 0 0 0; opacity: 0.9; font-size: 0.9375rem;">Top 5 Winners Per Category with Complete Judge Scoring Breakdown</p>
            </div>

            <?php 
            foreach ($award_highlights as $award):
                $category_name = $award['title'];
                $ranking_key = $award['type'] . '_' . $category_name;
                $rankings = $category_rankings[$ranking_key] ?? [];
                if (empty($rankings)) continue;
                
                $bg_color = $award['color'] . '0d'; // Very light version
            ?>
            
            <div id="category-<?= $award['type'] ?>-<?= str_replace(' ', '-', strtolower($category_name)) ?>" class="card" style="margin-bottom: 2.5rem; padding: 0; overflow: hidden; border-top: 4px solid <?= $award['color'] ?>; scroll-margin-top: 100px;">
                <div style="padding: 1.75rem 2rem; background: <?= $bg_color ?>; border-bottom: 2px solid <?= $award['color'] ?>;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span style="font-size: 2.5rem;"><?= $award['icon'] ?></span>
                        <div>
                            <h3 style="margin: 0; font-size: 1.5rem; color: var(--primary-dark); letter-spacing: -0.02em;"><?= htmlspecialchars($category_name) ?></h3>
                            <p style="margin: 0.25rem 0 0 0; color: var(--text-light); font-size: 0.875rem; font-weight: 600;">Top 5 Rankings with Judge Scores</p>
                        </div>
                    </div>
                </div>

                <div style="padding: 2rem;">
                    <?php foreach ($rankings as $index => $entry): 
                        $rank = $index + 1;
                        $medal_icons = ['🥇', '🥈', '🥉', '🏅', '🏅'];
                        $rank_labels = ['CHAMPION', '1st RUNNER-UP', '2nd RUNNER-UP', '3rd RUNNER-UP', '4th RUNNER-UP'];
                    ?>
                    
                    <div class="animate-fade-in" style="margin-bottom: <?= $index < count($rankings) - 1 ? '2rem' : '0' ?>; padding: 1.5rem; background: white; border: 2px solid <?= $rank <= 3 ? $award['color'] : 'var(--border)' ?>; border-radius: 12px; animation-delay: <?= $index * 0.1 ?>s;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
                            <div style="flex: 1; min-width: 250px;">
                                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem;">
                                    <span style="font-size: 2rem;"><?= $medal_icons[$index] ?></span>
                                    <div>
                                        <div style="font-size: 0.75rem; font-weight: 800; color: <?= $award['color'] ?>; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">
                                            <?= $rank_labels[$index] ?>
                                        </div>
                                        <h4 style="margin: 0; font-size: 1.25rem; color: var(--primary-dark); font-weight: 700;">
                                            <?php if (($award['type'] ?? '') === 'individual'): ?>
                                                <?= htmlspecialchars($entry['member_name'] ?? '') ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($entry['team_name'] ?? '') ?>
                                            <?php endif; ?>
                                        </h4>
                                        <?php if (($award['type'] ?? '') === 'individual'): ?>
                                            <p style="margin: 0.25rem 0 0 0; font-size: 0.8125rem; color: var(--text-light);">
                                                <?= htmlspecialchars($entry['role'] ?? '') ?> • <?= htmlspecialchars($entry['team_name'] ?? '') ?>
                                            </p>
                                        <?php else: ?>
                                            <p style="margin: 0.25rem 0 0 0; font-size: 0.8125rem; color: var(--text-light);">
                                                <?= htmlspecialchars($entry['project_title'] ?? 'No Project Title') ?>
                                            </p>
                                            
                                            <!-- Document Submission Links -->
                                            <?php
                                            $doc_link = null;
                                            $doc_label = '';
                                            $doc_icon = '';
                                            
                                            if ($category_name === 'Best Brochure' && !empty($entry['brochure_path'])) {
                                                $doc_link = '../' . $entry['brochure_path'];
                                                $doc_label = 'View Brochure';
                                                $doc_icon = '📂';
                                            } elseif ($category_name === 'Best Poster' && !empty($entry['poster_path'])) {
                                                $doc_link = '../' . $entry['poster_path'];
                                                $doc_label = 'View Poster';
                                                $doc_icon = '🖼️';
                                            } elseif ($category_name === 'Best Capstone Paper' && !empty($entry['imrad_path'])) {
                                                $doc_link = '../' . $entry['imrad_path'];
                                                $doc_label = 'View Paper';
                                                $doc_icon = '📄';
                                            }
                                            
                                            if ($doc_link):
                                                $bg_color_meta = $award['color'] . '1a';
                                            ?>
                                            <a href="<?= htmlspecialchars($doc_link) ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; padding: 0.5rem 1rem; background: <?= $bg_color_meta ?>; color: <?= $award['color'] ?>; border: 1px solid <?= $award['color'] ?>; border-radius: 6px; text-decoration: none; font-size: 0.8125rem; font-weight: 600; transition: all 0.2s ease;" onmouseover="this.style.background='<?= $award['color'] ?>'; this.style.color='white';" onmouseout="this.style.background='<?= $bg_color_meta ?>'; this.style.color='<?= $award['color'] ?>';">
                                                <span><?= $doc_icon ?></span>
                                                <span><?= $doc_label ?></span>
                                                <span style="font-size: 0.7rem;">↗</span>
                                            </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="text-align: right;">
                                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">
                                    Overall Average
                                </div>
                                <div style="font-size: 2.5rem; font-weight: 900; color: <?= $award['color'] ?>; line-height: 1;">
                                    <?= number_format($entry['total_avg'], 2) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Judge Scores & Evaluations Breakdown -->
                        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
                            <div style="font-size: 0.8125rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                <span>📋</span> Panelist Evaluations
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem;">
                                <?php foreach ($entry['judge_scores'] as $judge_score): ?>
                                <div style="padding: 1.25rem; background: var(--light); border-radius: 12px; border: 1px solid var(--border); display: flex; flex-direction: column; gap: 0.75rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div style="font-size: 0.875rem; color: var(--text-main); font-weight: 700;">
                                            <?= htmlspecialchars($judge_score['judge_name']) ?>
                                        </div>
                                        <div style="font-size: 1.25rem; font-weight: 900; color: var(--primary);">
                                            <?= number_format($judge_score['score'], 2) ?>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-light); background: white; padding: 1rem; border-radius: 8px; border: 1px solid var(--border); line-height: 1.5; min-height: 50px;">
                                        <div style="font-weight: 700; text-transform: uppercase; font-size: 0.6rem; color: var(--text-light); margin-bottom: 0.5rem; border-bottom: 1px solid var(--light); padding-bottom: 4px;">Remarks & Criterion Scores</div>
                                        <?= $judge_score['feedback'] ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php endforeach; ?>
        </div>

        

    <?php endif; ?>
</div>

<script>
function exportToExcel(tableId, filename) {
    var table = document.getElementById(tableId);
    var html = table.outerHTML;
    var url = 'data:application/vnd.ms-excel;base64,' + btoa(unescape(encodeURIComponent(html)));
    var link = document.createElement('a');
    link.download = filename;
    link.href = url;
    link.click();
}

function toggleCategoryRankings() {
    const section = document.getElementById('categoryRankingsSection');
    const btn = document.getElementById('toggleCategoryBtn');
    
    if (section.style.display === 'none') {
        section.style.display = 'block';
        btn.innerHTML = ' Hide Category Rankings';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
        
        // Smooth scroll to the section
        setTimeout(() => {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    } else {
        section.style.display = 'none';
        btn.innerHTML = ' Show Category Rankings';
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-primary');
    }
}

function showCategoryDetail(categoryName, type) {
    const section = document.getElementById('categoryRankingsSection');
    const btn = document.getElementById('toggleCategoryBtn');
    
    // Show the section if it's hidden
    if (section.style.display === 'none') {
        section.style.display = 'block';
        btn.innerHTML = ' Hide Category Rankings';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
    }
    
    // Scroll to the specific category
    const categoryId = 'category-' + type + '-' + categoryName.toLowerCase().replace(/ /g, '-');
    const categoryElement = document.getElementById(categoryId);
    
    if (categoryElement) {
        setTimeout(() => {
            categoryElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
            
            // Add a highlight animation
            categoryElement.style.animation = 'none';
            setTimeout(() => {
                categoryElement.style.animation = 'highlight-pulse 2s ease-in-out';
            }, 10);
        }, 100);
    }
}

</script>

<?php render_footer(); ?>
