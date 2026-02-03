<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../components/ui.php';
requireRole('panelist');

$panelist_id = $_SESSION['user_id'];

// Fetch assigned teams for active events (Excluding finalized ones)
$stmt = $pdo->prepare("
    SELECT t.*, e.title as event_title, e.status as event_status,
    (SELECT COUNT(*) FROM tab_scores s WHERE s.team_id = t.id AND s.panelist_id = ?) as score_count,
    (SELECT COUNT(*) FROM tab_criteria c WHERE c.event_id = e.id AND c.type='group') as criteria_count,
    (SELECT COUNT(*) FROM tab_criteria c WHERE c.event_id = e.id AND c.type='individual') as ind_criteria_count,
    (SELECT COUNT(*) FROM tab_individual_scores isc 
     JOIN tab_team_members tm ON isc.team_member_id = tm.id 
     WHERE tm.team_id = t.id AND isc.panelist_id = ? AND tm.role_in_project = 'Study Presenter') as ind_score_count,
    (SELECT COUNT(*) FROM tab_team_members tm WHERE tm.team_id = t.id AND tm.role_in_project = 'Study Presenter') as presenter_count,
    (SELECT GROUP_CONCAT(member_name SEPARATOR ', ') FROM tab_team_members WHERE team_id = t.id AND role_in_project = 'Study Presenter') as presenter_names
    FROM tab_teams t
    JOIN tab_panelist_assignments pa ON t.id = pa.team_id
    JOIN tab_events e ON pa.event_id = e.id
    WHERE pa.panelist_id = ? 
    AND e.status IN ('ongoing', 'upcoming')
    AND t.id NOT IN (SELECT team_id FROM tab_score_locks WHERE panelist_id = ? AND is_locked = 1)
    ORDER BY e.event_date ASC
");
$stmt->execute([$panelist_id, $panelist_id, $panelist_id, $panelist_id]);
$teams = $stmt->fetchAll();

render_head("Evaluations Board");
render_navbar($_SESSION['full_name'], 'panelist', '../', "Evaluation Board");
?>

<div class="container" style="margin-top: 3rem; padding-bottom: 5rem;">
    <div class="page-header" style="margin-bottom: 3rem;">
        <div>
            <h1 style="font-size: 2.25rem; letter-spacing: -0.02em;">Evaluation Board</h1>
            <p style="color: var(--text-light); margin-top: 0.5rem; font-size: 1.1rem;">You have <span style="color: var(--primary); font-weight: 700;"><?= count($teams) ?></span> pending assignments to evaluate.</p>
        </div>
        <div style="background: white; padding: 0.75rem 1.25rem; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 1rem;">
             <span style="font-size: 1.25rem;">⚖️</span>
             <div>
                <span style="display: block; font-size: 0.7rem; color: var(--text-light); font-weight: 700; text-transform: uppercase;">Session Date</span>
                <strong style="color: var(--dark); font-size: 0.9375rem;"><?= date('l, F j') ?></strong>
             </div>
        </div>
    </div>

    <?php 
    $displayItems = [];
    foreach ($teams as $team) {
        // 1. Group/Colloquium Card
        $isComp = ($team['criteria_count'] > 0) ? ($team['score_count'] >= $team['criteria_count']) : false;
        $displayItems[] = [
            'type' => 'colloquium',
            'team' => $team,
            'title' => $team['team_name'],
            'label' => 'Colloquium Evaluation',
            'completed' => $isComp,
            'focus' => 'group',
            'icon' => '🏆',
            'has_criteria' => $team['criteria_count'] > 0
        ];
        
        // 2. Individual Presenter Card (Only if they have presenters)
        if ($team['presenter_count'] > 0) {
            $isComp = ($team['ind_criteria_count'] > 0) ? ($team['ind_score_count'] >= ($team['ind_criteria_count'] * $team['presenter_count'])) : false;
            $displayItems[] = [
                'type' => 'individual',
                'team' => $team,
                'title' => $team['presenter_names'],
                'label' => 'Individual Presenter Evaluation',
                'completed' => $isComp,
                'focus' => 'individual',
                'icon' => '🎤',
                'has_criteria' => $team['ind_criteria_count'] > 0
            ];
        }
    }
    ?>

    <div class="dashboard-grid">
        <?php foreach($displayItems as $item): 
            $team = $item['team'];
            $isCompleted = $item['completed'];
            $statusClass = $isCompleted ? 'btn-secondary' : 'btn-primary';
            $btnText = $isCompleted ? 'Resume Evaluation' : 'Start Scoring';
            $statusText = $isCompleted ? 'DRAFT' : 'PENDING';
            $badgeStyle = $isCompleted ? 'background: var(--success-subtle); color: var(--success); border-color: var(--success);' : 'background: var(--primary-subtle); color: var(--primary); border-color: var(--primary);';
            $typeColor = $item['type'] === 'colloquium' ? 'var(--primary)' : 'var(--secondary)';
        ?>
            <div class="card animate-fade-in" style="display: flex; flex-direction: column; padding: 2rem; border-top: 5px solid <?= $typeColor ?>;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <span style="font-size: 1.25rem;"><?= $item['icon'] ?></span>
                            <span style="font-size: 0.7rem; font-weight: 800; color: <?= $typeColor ?>; text-transform: uppercase; letter-spacing: 0.05em;"><?= $item['label'] ?></span>
                        </div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.05em;"><?= htmlspecialchars($team['event_title']) ?></span>
                        <h3 style="margin-top: 0.5rem; font-size: 1.25rem; letter-spacing: -0.01em; color: var(--dark); line-height: 1.3;"><?= htmlspecialchars($item['title']) ?></h3>
                    </div>
                    <span style="<?= $badgeStyle ?> padding: 0.4rem 0.75rem; border-radius: 20px; font-size: 0.65rem; font-weight: 800; border: 1px solid; letter-spacing: 0.05em;">
                        <?= $statusText ?>
                    </span>
                </div>
                
                <div style="background: var(--light); padding: 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                    <p style="font-size: 0.8125rem; color: var(--text-light); text-transform: uppercase; font-weight: 700; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Project Information</p>
                    <p style="font-size: 1rem; color: var(--dark); font-weight: 600; line-height: 1.4;">
                        <?= htmlspecialchars($team['project_title']) ?>
                        <?php if ($item['type'] === 'individual'): ?>
                            <span style="display: block; font-size: 0.75rem; color: var(--text-light); margin-top: 0.4rem; font-weight: 500;">Group: <?= htmlspecialchars($team['team_name']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--border);">
                    <?php if ($item['has_criteria']): ?>
                        <a href="score_team.php?team_id=<?= $team['id'] ?>&focus=<?= $item['focus'] ?>" class="btn <?= $statusClass ?>" style="width: 100%; height: 50px; font-weight: 700;">
                            <?= $btnText ?> &rarr;
                        </a>
                    <?php else: ?>
                        <div style="background: var(--danger-subtle); color: var(--danger); padding: 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; text-align: center; border: 1px solid rgba(239, 68, 68, 0.2);">
                            <?php if ($item['type'] === 'colloquium' && $team['ind_criteria_count'] > 0): ?>
                                ⚠️ Only Individual criteria defined. No Group criteria.
                            <?php elseif ($item['type'] === 'individual' && $team['criteria_count'] > 0): ?>
                                ⚠️ Only Group criteria defined. No Individual criteria.
                            <?php else: ?>
                                ⚠️ No criteria defined for this event yet.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if(empty($displayItems)): ?>
            <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 5rem 2rem; background: transparent; border: 2px dashed var(--border);">
                <div style="font-size: 3.5rem; margin-bottom: 1rem;">📋</div>
                <h3 style="color: var(--text-light); font-weight: 600;">No assignments or pending evaluations.</h3>
                <p style="color: var(--text-light); margin-top: 0.5rem;">

    
            </div>
        <?php endif; ?>
    </div>
</div>

<?php render_footer(); ?>
