<?php
declare(strict_types=1);
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');
require_once __DIR__ . '/../includes/WorkoutSplits.php';

$members = rows('SELECT member_id, member_name FROM members ORDER BY member_name');
$selectedId = trim((string) ($_GET['member_id'] ?? $_POST['member_id'] ?? ''));
$selectedMember = $selectedId !== ''
    ? row('SELECT member_id, member_name FROM members WHERE member_id = ?', [$selectedId])
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$selectedMember) {
        flash('Choose a valid member.', 'err');
        redirect('progress.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'record') {
        $checkDate = trim((string) ($_POST['check_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $checkDate);
        $dateIsValid = $date !== false && $date->format('Y-m-d') === $checkDate
            && $checkDate <= date('Y-m-d');
        $limits = [
            'weight_kg' => [20, 500],
            'body_fat_percent' => [1, 75],
            'waist_cm' => [20, 300],
            'hips_cm' => [20, 300],
        ];
        $values = [];
        $valid = $dateIsValid;
        foreach ($limits as $field => [$minimum, $maximum]) {
            $raw = trim((string) ($_POST[$field] ?? ''));
            if ($raw === '') {
                $values[$field] = null;
            } elseif (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $raw)
                || (float) $raw < $minimum || (float) $raw > $maximum) {
                $valid = false;
            } else {
                $values[$field] = $raw;
            }
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (strlen($notes) > 4000 || !array_filter($values, static fn($value) => $value !== null)) {
            $valid = false;
        }

        if (!$valid) {
            flash('Enter a valid past or current date and at least one measurement within the listed ranges.', 'err');
        } else {
            db()->prepare(
                'INSERT INTO member_progress
                    (member_id, recorded_by_user_id, check_date, weight_kg, body_fat_percent, waist_cm, hips_cm, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $selectedId, $u['id'], $checkDate, $values['weight_kg'], $values['body_fat_percent'],
                $values['waist_cm'], $values['hips_cm'], $notes !== '' ? $notes : null,
            ]);
            log_activity('Record progress check', 'Member Progress', $selectedId . ' · ' . $checkDate);
            flash('Progress check saved.');
        }
    } elseif ($action === 'goal') {
        $targetWeight = trim((string) ($_POST['target_weight_kg'] ?? ''));
        $targetDate = trim((string) ($_POST['target_date'] ?? ''));
        if ($targetWeight === '' && $targetDate === '') {
            db()->prepare('DELETE FROM member_progress_goals WHERE member_id = ?')->execute([$selectedId]);
            log_activity('Clear progress goal', 'Member Progress', $selectedId);
            flash('Progress goal cleared.');
        } else {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $targetDate);
            $dateIsValid = $date !== false && $date->format('Y-m-d') === $targetDate
                && $targetDate >= date('Y-m-d');
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $targetWeight)
                || (float) $targetWeight < 20 || (float) $targetWeight > 500 || !$dateIsValid) {
                flash('Enter a target weight from 20 to 500 kg and a target date today or later.', 'err');
            } else {
                db()->prepare(
                    'INSERT INTO member_progress_goals (member_id, target_weight_kg, target_date, updated_by_user_id)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE target_weight_kg = VALUES(target_weight_kg),
                        target_date = VALUES(target_date), updated_by_user_id = VALUES(updated_by_user_id)'
                )->execute([$selectedId, $targetWeight, $targetDate, $u['id']]);
                log_activity('Update progress goal', 'Member Progress', $selectedId);
                flash('Progress goal saved.');
            }
        }
    }
    redirect('progress.php?member_id=' . rawurlencode($selectedId));
}

$page = 'progress';
$title = 'Member Progress';
$subtitle = 'See progress activity across all members, then drill into individual measurements, goals, and workouts.';
$progress = $selectedMember
    ? rows(
        'SELECT * FROM member_progress WHERE member_id = ? ORDER BY check_date DESC, progress_id DESC LIMIT 100',
        [$selectedId]
    )
    : [];
$goal = $selectedMember
    ? row('SELECT * FROM member_progress_goals WHERE member_id = ?', [$selectedId])
    : null;
$workoutProgram = $selectedMember
    ? row('SELECT split_code FROM member_workout_programs WHERE member_id = ?', [$selectedId])
    : null;
$workoutProgramDetails = $workoutProgram
    ? WorkoutSplits::get((string) $workoutProgram['split_code'])
    : null;
$liftingLogs = $selectedMember
    ? rows(
        'SELECT ml.*, c.class_name AS linked_class_name
           FROM member_lifting_logs ml
           LEFT JOIN class_attendance ca ON ca.attendance_id = ml.class_attendance_id
           LEFT JOIN classes c ON c.class_id = ca.class_id
          WHERE ml.member_id = ? ORDER BY ml.logged_date DESC, ml.lifting_log_id DESC LIMIT 100',
        [$selectedId]
    )
    : [];
$progressOverview = null;
$activityCharts = [];
$memberOverview = [];
if (!$selectedMember) {
    $progressOverview = row(
        'SELECT
            (SELECT COUNT(*) FROM members) AS total_members,
            (SELECT COUNT(*) FROM (
                SELECT member_id FROM member_progress
                UNION SELECT member_id FROM member_progress_goals
                UNION SELECT member_id FROM member_workout_programs
                UNION SELECT member_id FROM member_lifting_logs
                UNION SELECT member_id FROM class_attendance
            ) tracked) AS tracked_members,
            (SELECT COUNT(*) FROM member_progress
              WHERE check_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND CURDATE()) AS recent_checkins,
            (SELECT COUNT(*) FROM member_lifting_logs
              WHERE logged_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND CURDATE()) AS recent_workouts,
            (SELECT COUNT(*) FROM class_attendance
              WHERE attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND CURDATE()) AS recent_classes,
            (SELECT COUNT(*) FROM member_progress_goals WHERE target_date >= CURDATE()) AS active_goals'
    );
    $trendStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
    $activityCounts = [
        'measurements' => [],
        'workouts' => [],
        'classes' => [],
    ];
    $activityRows = rows(
        'SELECT DATE_FORMAT(check_date, "%Y-%m") AS month_key, "measurements" AS activity_type, COUNT(*) AS total
           FROM member_progress WHERE check_date BETWEEN ? AND CURDATE()
          GROUP BY DATE_FORMAT(check_date, "%Y-%m")
         UNION ALL
         SELECT DATE_FORMAT(logged_date, "%Y-%m"), "workouts", COUNT(*)
           FROM member_lifting_logs WHERE logged_date BETWEEN ? AND CURDATE()
          GROUP BY DATE_FORMAT(logged_date, "%Y-%m")
         UNION ALL
         SELECT DATE_FORMAT(attendance_date, "%Y-%m"), "classes", COUNT(*)
           FROM class_attendance WHERE attendance_date BETWEEN ? AND CURDATE()
          GROUP BY DATE_FORMAT(attendance_date, "%Y-%m")',
        [$trendStart->format('Y-m-d'), $trendStart->format('Y-m-d'), $trendStart->format('Y-m-d')]
    );
    foreach ($activityRows as $activityRow) {
        $activityCounts[$activityRow['activity_type']][$activityRow['month_key']] = (int) $activityRow['total'];
    }
    $activityLabels = [
        'measurements' => 'Fitness check-ins',
        'workouts' => 'Strength workouts',
        'classes' => 'Class records',
    ];
    for ($monthOffset = 0; $monthOffset < 6; $monthOffset++) {
        $month = $trendStart->modify('+' . $monthOffset . ' months');
        $monthKey = $month->format('Y-m');
        foreach ($activityLabels as $activityType => $activityLabel) {
            $activityCharts[$activityType]['label'] = $activityLabel;
            $activityCharts[$activityType]['points'][] = [
                'label' => $month->format('M y'),
                'total' => $activityCounts[$activityType][$monthKey] ?? 0,
            ];
        }
    }
    $memberOverview = rows(
        'SELECT m.member_id, m.member_name, m.status,
                p.check_date, p.weight_kg, p.body_fat_percent, p.waist_cm, p.hips_cm,
                g.target_weight_kg, g.target_date, wp.split_code,
                (SELECT COUNT(*) FROM member_progress mp WHERE mp.member_id = m.member_id) AS checkin_count,
                (SELECT COUNT(*) FROM member_lifting_logs ml WHERE ml.member_id = m.member_id) AS workout_count,
                (SELECT COUNT(*) FROM class_attendance ca WHERE ca.member_id = m.member_id) AS class_count
           FROM members m
           LEFT JOIN member_progress p ON p.progress_id = (
                SELECT p2.progress_id FROM member_progress p2
                 WHERE p2.member_id = m.member_id
                 ORDER BY p2.check_date DESC, p2.progress_id DESC LIMIT 1
           )
           LEFT JOIN member_progress_goals g ON g.member_id = m.member_id
           LEFT JOIN member_workout_programs wp ON wp.member_id = m.member_id
          ORDER BY p.check_date IS NULL, p.check_date DESC, m.member_name'
    );
}

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-user-check"></i> Choose a member</h2>
  <?php if (!$members): ?>
    <div class="empty"><i class="fa-solid fa-users-slash"></i>No members to track yet.</div>
  <?php else: ?>
    <form method="get" class="form-grid">
      <div class="field-row"><label for="progressMember">Member</label>
        <select id="progressMember" name="member_id" required>
          <option value="">Select a member…</option>
          <?php foreach ($members as $member): ?>
            <option value="<?= e($member['member_id']) ?>" <?= $selectedId === $member['member_id'] ? 'selected' : '' ?>>
              <?= e($member['member_name']) ?> · <?= e($member['member_id']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-row" style="align-self:end"><button class="btn red"><i class="fa-solid fa-arrow-right"></i> View progress</button></div>
    </form>
  <?php endif; ?>
</div>

<?php if (!$selectedMember): ?>
<div class="stats progress-admin-summary" aria-label="All-member progress summary">
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-users"></i></div>
    <div class="label">Members with progress data</div>
    <div class="value"><?= (int) ($progressOverview['tracked_members'] ?? 0) ?> <small>/ <?= (int) ($progressOverview['total_members'] ?? 0) ?></small></div>
    <div class="note">Measurements, goals, plans, workouts, or class records</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-ruler"></i></div>
    <div class="label">Check-ins · last 30 days</div>
    <div class="value"><?= (int) ($progressOverview['recent_checkins'] ?? 0) ?></div>
    <div class="note">Fitness measurement records</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-dumbbell"></i></div>
    <div class="label">Workouts · last 30 days</div>
    <div class="value"><?= (int) ($progressOverview['recent_workouts'] ?? 0) ?></div>
    <div class="note">Strength exercise logs</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-people-group"></i></div>
    <div class="label">Class records · last 30 days</div>
    <div class="value"><?= (int) ($progressOverview['recent_classes'] ?? 0) ?></div>
    <div class="note">Bookings recorded, not verified visits</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-bullseye"></i></div>
    <div class="label">Current goals</div>
    <div class="value"><?= (int) ($progressOverview['active_goals'] ?? 0) ?></div>
    <div class="note">Targets dated today or later</div>
  </div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-chart-column"></i> Progress activity · last 6 months</h2>
  <p class="note">Monthly record counts across all members. Measurements are shown per member below; they are not averaged together.</p>
  <div class="grid c3 progress-admin-charts">
    <?php foreach ($activityCharts as $activityType => $activityChart): ?>
      <div class="panel glass progress-admin-chart">
        <h3><?= e($activityChart['label']) ?></h3>
        <?php if (array_sum(array_column($activityChart['points'], 'total')) > 0): ?>
          <?= Chart::bars($activityChart['points'], [
              'h' => 190,
              'ariaLabel' => $activityChart['label'] . ' per month',
              'title' => $activityChart['label'] . ' per month',
          ]) ?>
        <?php else: ?>
          <div class="empty"><i class="fa-solid fa-chart-column"></i>No <?= e(strtolower($activityChart['label'])) ?> recorded in this period.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel glass">
  <h2 class="progress-admin-table-heading">
    <span><i class="fa-solid fa-table-list"></i> All members</span>
    <input class="progress-admin-filter" type="search" placeholder="Filter members…" data-filter-for="tblProgressMembers" aria-label="Filter members by name or progress">
  </h2>
  <?php if (!$memberOverview): ?>
    <div class="empty"><i class="fa-solid fa-users-slash"></i>No members to review.</div>
  <?php else: ?>
    <div class="table-wrap"><table id="tblProgressMembers">
      <thead><tr><th>Member</th><th>Latest check-in</th><th>Measurements</th><th>Goal</th><th>Workout plan</th><th>Check-ins</th><th>Workouts</th><th>Class records</th><th data-nosort></th></tr></thead>
      <tbody>
      <?php foreach ($memberOverview as $member): ?>
        <?php
          $latestMeasures = [];
          foreach ([
              'Weight' => ['weight_kg', 'kg'],
              'Body fat' => ['body_fat_percent', '%'],
              'Waist' => ['waist_cm', 'cm'],
              'Hips' => ['hips_cm', 'cm'],
          ] as $measureLabel => [$measureField, $measureUnit]) {
              if ($member[$measureField] !== null) {
                  $latestMeasures[] = $measureLabel . ' ' . number_format((float) $member[$measureField], 1) . $measureUnit;
              }
          }
          $memberProgram = $member['split_code'] ? WorkoutSplits::get((string) $member['split_code']) : null;
        ?>
        <tr>
          <td><div><b><?= e($member['member_name']) ?></b><div class="mono"><?= e($member['member_id']) ?></div><?= badge($member['status']) ?></div></td>
          <td><?= $member['check_date'] ? dt($member['check_date']) : '—' ?></td>
          <td><?= e($latestMeasures ? implode(' · ', $latestMeasures) : 'No measurements on latest check-in') ?></td>
          <td><span><?= $member['target_weight_kg'] !== null
                ? e(number_format((float) $member['target_weight_kg'], 1)) . ' kg<span class="mono progress-admin-goal-date">' . e(dt($member['target_date'])) . '</span>'
                : '—' ?></span></td>
          <td><?= $memberProgram ? e($memberProgram['name']) : 'Not selected' ?></td>
          <td><?= (int) $member['checkin_count'] ?></td>
          <td><?= (int) $member['workout_count'] ?></td>
          <td><?= (int) $member['class_count'] ?></td>
          <td><a class="btn sm" href="progress.php?member_id=<?= e(rawurlencode($member['member_id'])) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($selectedMember): ?>
<div class="panel glass">
  <h2><i class="fa-solid fa-user-check"></i> Member detail · <?= e($selectedMember['member_name']) ?>
    <span class="spacer"></span>
    <a class="btn sm" href="progress.php"><i class="fa-solid fa-arrow-left"></i> All-member overview</a>
  </h2>
</div>
<div class="panel glass">
  <h2><i class="fa-solid fa-list-check"></i> Current workout split · <?= e($selectedMember['member_name']) ?></h2>
  <?php if ($workoutProgramDetails): ?>
    <strong><?= e($workoutProgramDetails['name']) ?></strong>
    <p class="note"><?= e($workoutProgramDetails['description']) ?></p>
  <?php elseif ($workoutProgram): ?>
    <p class="note">Saved split code <?= e($workoutProgram['split_code']) ?> is unavailable. The member should choose another program.</p>
  <?php else: ?>
    <p class="note">This member has not selected a workout split yet.</p>
  <?php endif; ?>
</div>
<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-bullseye"></i> Target · <?= e($selectedMember['member_name']) ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="goal">
      <input type="hidden" name="member_id" value="<?= e($selectedId) ?>">
      <div class="form-grid">
        <div class="field-row"><label for="targetWeight">Target weight (kg)</label>
          <input id="targetWeight" name="target_weight_kg" type="number" min="20" max="500" step="0.01"
                 value="<?= e($goal['target_weight_kg'] ?? '') ?>" placeholder="e.g. 68.00"></div>
        <div class="field-row"><label for="targetDate">Target date</label>
          <input id="targetDate" name="target_date" type="date" min="<?= date('Y-m-d') ?>"
                 value="<?= e($goal['target_date'] ?? '') ?>"></div>
      </div>
      <p class="note">Leave both fields empty to clear the goal. The member can see and update this goal.</p>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save goal</button>
    </form>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-clipboard-check"></i> New check-in</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record">
      <input type="hidden" name="member_id" value="<?= e($selectedId) ?>">
      <div class="field-row"><label for="checkDate">Check date</label>
        <input id="checkDate" name="check_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-grid">
        <div class="field-row"><label for="weight">Weight (kg)</label><input id="weight" name="weight_kg" type="number" min="20" max="500" step="0.01"></div>
        <div class="field-row"><label for="bodyFat">Body fat (%)</label><input id="bodyFat" name="body_fat_percent" type="number" min="1" max="75" step="0.01"></div>
        <div class="field-row"><label for="waist">Waist (cm)</label><input id="waist" name="waist_cm" type="number" min="20" max="300" step="0.01"></div>
        <div class="field-row"><label for="hips">Hips (cm)</label><input id="hips" name="hips_cm" type="number" min="20" max="300" step="0.01"></div>
      </div>
      <div class="field-row"><label for="progressNotes">Notes (optional)</label>
        <textarea id="progressNotes" name="notes" maxlength="1000" rows="2"></textarea></div>
      <p class="note">Enter at least one measurement. Only the member and authorized administrators can view these records.</p>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save check-in</button>
    </form>
  </div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-chart-line"></i> Check-in history · <?= e($selectedMember['member_name']) ?></h2>
  <?php if (!$progress): ?>
    <div class="empty"><i class="fa-solid fa-chart-line"></i>No fitness check-ins have been recorded.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Weight</th><th>Body fat</th><th>Waist</th><th>Hips</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($progress as $check): ?>
        <tr>
          <td><?= dt($check['check_date']) ?></td>
          <td><?= $check['weight_kg'] !== null ? e($check['weight_kg']) . ' kg' : '—' ?></td>
          <td><?= $check['body_fat_percent'] !== null ? e($check['body_fat_percent']) . '%' : '—' ?></td>
          <td><?= $check['waist_cm'] !== null ? e($check['waist_cm']) . ' cm' : '—' ?></td>
          <td><?= $check['hips_cm'] !== null ? e($check['hips_cm']) . ' cm' : '—' ?></td>
          <td><?= e($check['notes'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<div class="panel glass">
  <h2><i class="fa-solid fa-dumbbell"></i> Strength training logs · <?= e($selectedMember['member_name']) ?></h2>
  <?php if (!$liftingLogs): ?>
    <div class="empty"><i class="fa-solid fa-dumbbell"></i>No strength workouts have been logged.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Split / day</th><th>Exercise</th><th>Class</th><th>Sets × reps</th><th>External load</th><th>Total reps</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($liftingLogs as $liftingLog): ?>
        <?php $logProgram = $liftingLog['split_code'] ? WorkoutSplits::get((string) $liftingLog['split_code']) : null; ?>
        <tr>
          <td><?= dt($liftingLog['logged_date']) ?></td>
          <td><?= $logProgram
              ? e($logProgram['name']) . ' · ' . e($liftingLog['workout_day'])
              : ($liftingLog['split_code'] ? e($liftingLog['split_code']) . ' · ' . e($liftingLog['workout_day']) : 'Unassigned') ?></td>
          <td><?= e($liftingLog['exercise_name']) ?></td>
          <td><?= e($liftingLog['linked_class_name'] ?: '—') ?></td>
          <td><?= (int) $liftingLog['sets_count'] ?> × <?= (int) $liftingLog['reps_per_set'] ?></td>
          <td><?= (float) $liftingLog['weight_kg'] > 0
              ? e(number_format((float) $liftingLog['weight_kg'], 2)) . ' kg'
              : 'Bodyweight' ?></td>
          <td><?= (int) $liftingLog['sets_count'] * (int) $liftingLog['reps_per_set'] ?></td>
          <td><?= e($liftingLog['notes'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
