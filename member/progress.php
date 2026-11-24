<?php
declare(strict_types=1);
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('member');
$memberId = (string) ($_SESSION['member_id'] ?? '');
require_once __DIR__ . '/../includes/WorkoutSplits.php';
$workoutPrograms = WorkoutSplits::all();
$currentWorkoutProgram = row('SELECT split_code FROM member_workout_programs WHERE member_id = ?', [$memberId]);
$currentSplitCode = (string) ($currentWorkoutProgram['split_code'] ?? '');
$currentSplit = WorkoutSplits::get($currentSplitCode);
$redirectTo = 'progress.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
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
        if (strlen($notes) > 1000 || !array_filter($values, static fn($value) => $value !== null)) {
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
                $memberId, $u['id'], $checkDate, $values['weight_kg'], $values['body_fat_percent'],
                $values['waist_cm'], $values['hips_cm'], $notes !== '' ? $notes : null,
            ]);
            log_activity('Record progress check', 'Member Progress', $memberId . ' · ' . $checkDate);
            flash('Progress check saved.');
        }
    } elseif ($action === 'program') {
        $splitCode = trim((string) ($_POST['split_code'] ?? ''));
        if (WorkoutSplits::get($splitCode) === null) {
            flash('Choose a valid workout split.', 'err');
        } else {
            db()->prepare(
                'INSERT INTO member_workout_programs (member_id, split_code)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE split_code = VALUES(split_code), updated_at = CURRENT_TIMESTAMP'
            )->execute([$memberId, $splitCode]);
            log_activity('Choose workout split', 'Member Progress', $memberId . ' · ' . $splitCode);
            flash('Workout split saved. New logs will be tracked under this program.');
        }
        $redirectTo .= '#workout-program';
    } elseif ($action === 'lifting') {
        $workoutDay = trim((string) ($_POST['workout_day'] ?? ''));
        $exerciseName = trim((string) ($_POST['exercise_name'] ?? ''));
        $dayExercises = $currentSplit ? WorkoutSplits::exercises($currentSplitCode, $workoutDay) : [];
        foreach ($dayExercises as $dayExercise) {
            if (strcasecmp($exerciseName, $dayExercise) === 0) {
                $exerciseName = $dayExercise;
                break;
            }
        }
        $loggedDate = trim((string) ($_POST['logged_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $loggedDate);
        $dateIsValid = $date !== false && $date->format('Y-m-d') === $loggedDate
            && $loggedDate <= date('Y-m-d');
        $classAttendanceId = trim((string) ($_POST['class_attendance_id'] ?? ''));
        $classAttendance = null;
        if ($classAttendanceId !== '') {
            $classAttendance = row(
                'SELECT ca.attendance_id, c.class_name
                   FROM class_attendance ca
                   JOIN classes c ON c.class_id = ca.class_id
                  WHERE ca.attendance_id = ? AND ca.member_id = ?
                    AND ca.attendance_date = ? AND ca.attendance_date <= CURDATE()
                    AND ca.attendance_status <> "Absent"',
                [$classAttendanceId, $memberId, $loggedDate]
            );
        }
        $setsRaw = trim((string) ($_POST['sets_count'] ?? ''));
        $repsRaw = trim((string) ($_POST['reps_per_set'] ?? ''));
        $weightRaw = trim((string) ($_POST['lifting_weight_kg'] ?? ''));
        $notes = trim((string) ($_POST['lifting_notes'] ?? ''));
        $setsValid = preg_match('/^\d{1,2}$/D', $setsRaw) && (int) $setsRaw >= 1 && (int) $setsRaw <= 30;
        $repsValid = preg_match('/^\d{1,3}$/D', $repsRaw) && (int) $repsRaw >= 1 && (int) $repsRaw <= 100;
        $weightValid = preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $weightRaw)
            && (float) $weightRaw >= 0 && (float) $weightRaw <= 1000;

        if (!$currentSplit || !$dayExercises
            || !WorkoutSplits::containsExercise($currentSplitCode, $workoutDay, $exerciseName)
            || strlen($exerciseName) > 100 || !$dateIsValid
            || ($classAttendanceId !== '' && !$classAttendance)
            || !$setsValid || !$repsValid || !$weightValid || strlen($notes) > 500) {
            flash('Choose an exercise from your current split and workout day, then enter a valid date, sets, reps, load, and class record.', 'err');
        } else {
            db()->prepare(
                'INSERT INTO member_lifting_logs
                    (member_id, logged_by_user_id, split_code, workout_day, class_attendance_id, exercise_name, logged_date, sets_count, reps_per_set, weight_kg, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $memberId, $u['id'], $currentSplitCode, $workoutDay, $classAttendanceId !== '' ? $classAttendanceId : null,
                $exerciseName, $loggedDate, (int) $setsRaw, (int) $repsRaw, $weightRaw, $notes !== '' ? $notes : null,
            ]);
            $classDetail = $classAttendance ? ' · ' . $classAttendance['class_name'] : '';
            log_activity('Log lifting progress', 'Member Progress', $memberId . ' · ' . $currentSplitCode . ' / ' . $workoutDay . ' / ' . $exerciseName . $classDetail);
            flash('Strength workout saved.');
        }
        $redirectTo .= '?day=' . rawurlencode($workoutDay) . '&exercise=' . rawurlencode($exerciseName) . '#lifting';
    } elseif ($action === 'delete_lifting') {
        $logId = filter_var($_POST['lifting_log_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$logId || $logId < 1) {
            flash('Invalid strength log.', 'err');
        } else {
            $delete = db()->prepare('DELETE FROM member_lifting_logs WHERE lifting_log_id = ? AND member_id = ?');
            $delete->execute([$logId, $memberId]);
            if ($delete->rowCount() !== 1) {
                flash('That strength log was not found or is no longer available.', 'err');
            } else {
                log_activity('Delete lifting log', 'Member Progress', $memberId . ' · log ' . $logId);
                flash('Strength log deleted.');
            }
        }
        $workoutDay = trim((string) ($_POST['workout_day'] ?? ''));
        $exerciseName = trim((string) ($_POST['exercise_name'] ?? ''));
        if ($workoutDay !== '' && $exerciseName !== '') {
            $redirectTo .= '?day=' . rawurlencode($workoutDay) . '&exercise=' . rawurlencode($exerciseName);
        }
        $redirectTo .= '#lifting';
    } elseif ($action === 'goal') {
        $targetWeight = trim((string) ($_POST['target_weight_kg'] ?? ''));
        $targetDate = trim((string) ($_POST['target_date'] ?? ''));
        if ($targetWeight === '' && $targetDate === '') {
            db()->prepare('DELETE FROM member_progress_goals WHERE member_id = ?')->execute([$memberId]);
            log_activity('Clear progress goal', 'Member Progress', $memberId);
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
                )->execute([$memberId, $targetWeight, $targetDate, $u['id']]);
                log_activity('Update progress goal', 'Member Progress', $memberId);
                flash('Progress goal saved.');
            }
        }
    }
    redirect($redirectTo);
}

$page = 'progress';
$title = 'My Progress';
$subtitle = 'See your measurements, class records, and workouts in one place. Only you and authorized administrators can view this page.';
$progress = rows(
    'SELECT * FROM member_progress WHERE member_id = ? ORDER BY check_date DESC, progress_id DESC LIMIT 100',
    [$memberId]
);
$classAttendanceOptions = rows(
    'SELECT ca.attendance_id, ca.attendance_date, ca.attendance_status, c.class_name
       FROM class_attendance ca
       JOIN classes c ON c.class_id = ca.class_id
      WHERE ca.member_id = ? AND ca.attendance_date <= CURDATE()
        AND ca.attendance_status <> "Absent"
      ORDER BY ca.attendance_date DESC, ca.attendance_id DESC LIMIT 50',
    [$memberId]
);
$classWeekStart = new DateTimeImmutable('monday this week');
$classTrendStart = $classWeekStart->modify('-7 weeks')->format('Y-m-d');
$classWeekCounts = [];
foreach (rows(
    'SELECT YEARWEEK(attendance_date, 1) AS week_key, COUNT(*) AS total
       FROM class_attendance
      WHERE member_id = ? AND attendance_date BETWEEN ? AND CURDATE()
      GROUP BY YEARWEEK(attendance_date, 1)',
    [$memberId, $classTrendStart]
) as $weekCount) {
    $classWeekCounts[(string) $weekCount['week_key']] = (int) $weekCount['total'];
}
$classBookingPoints = [];
for ($weekOffset = 0; $weekOffset < 8; $weekOffset++) {
    $week = $classWeekStart->modify('-' . (7 - $weekOffset) . ' weeks');
    $classBookingPoints[] = [
        'label' => $week->format('M j'),
        'total' => $classWeekCounts[$week->format('oW')] ?? 0,
    ];
}
$recentClassSessions = rows(
    'SELECT ca.attendance_id, ca.attendance_date, ca.attendance_status, c.class_name,
            (SELECT COUNT(*) FROM member_lifting_logs ml
              WHERE ml.class_attendance_id = ca.attendance_id) AS linked_workouts
       FROM class_attendance ca
       JOIN classes c ON c.class_id = ca.class_id
      WHERE ca.member_id = ? AND ca.attendance_date <= CURDATE()
      ORDER BY ca.attendance_date DESC, ca.attendance_id DESC LIMIT 10',
    [$memberId]
);
$goal = row('SELECT * FROM member_progress_goals WHERE member_id = ?', [$memberId]);
$programDays = $currentSplit['days'] ?? [];
$programExerciseNames = [];
foreach ($programDays as $dayExercises) {
    $programExerciseNames = array_merge($programExerciseNames, $dayExercises);
}
$exerciseNames = array_values(array_unique($programExerciseNames));
$requestedDay = trim((string) ($_GET['day'] ?? ''));
$selectedDay = isset($programDays[$requestedDay]) ? $requestedDay : (string) (array_key_first($programDays) ?? '');
$selectedDayExercises = $programDays[$selectedDay] ?? [];
$selectedExercise = trim((string) ($_GET['exercise'] ?? ''));
if (!in_array($selectedExercise, $selectedDayExercises, true)) {
    $selectedExercise = (string) (scalar(
        'SELECT exercise_name FROM member_lifting_logs
          WHERE member_id = ? AND split_code = ? AND workout_day = ?
          ORDER BY logged_date DESC, lifting_log_id DESC LIMIT 1',
        [$memberId, $currentSplitCode, $selectedDay]
    ) ?: ($selectedDayExercises[0] ?? ''));
}
$liftingLogs = $selectedExercise !== ''
    ? rows(
        'SELECT ml.*, c.class_name AS linked_class_name
           FROM member_lifting_logs ml
           LEFT JOIN class_attendance ca ON ca.attendance_id = ml.class_attendance_id
           LEFT JOIN classes c ON c.class_id = ca.class_id
          WHERE ml.member_id = ? AND ml.exercise_name = ?
            AND ((ml.split_code = ? AND ml.workout_day = ?) OR ml.split_code IS NULL)
          ORDER BY ml.logged_date DESC, ml.lifting_log_id DESC LIMIT 100',
        [$memberId, $selectedExercise, $currentSplitCode, $selectedDay]
    )
    : [];
$otherLiftingLogs = rows(
    'SELECT ml.*, c.class_name AS linked_class_name
       FROM member_lifting_logs ml
       LEFT JOIN class_attendance ca ON ca.attendance_id = ml.class_attendance_id
       LEFT JOIN classes c ON c.class_id = ca.class_id
      WHERE ml.member_id = ? AND (ml.split_code IS NULL OR ml.split_code <> ?)
      ORDER BY ml.logged_date DESC, ml.lifting_log_id DESC LIMIT 100',
    [$memberId, $currentSplitCode]
);
$liftingChronological = array_reverse($liftingLogs);
$hasExternalLoad = false;
foreach ($liftingLogs as $liftingLog) {
    if ((float) $liftingLog['weight_kg'] > 0) {
        $hasExternalLoad = true;
        break;
    }
}
$liftingPoints = [];
foreach ($liftingChronological as $liftingLog) {
    $liftingPoints[] = [
        'label' => date('M j', strtotime($liftingLog['logged_date'])),
        'total' => $hasExternalLoad
            ? (float) $liftingLog['weight_kg']
            : (int) $liftingLog['sets_count'] * (int) $liftingLog['reps_per_set'],
    ];
}
$chronological = array_reverse($progress);
$measurements = ['weight_kg' => null, 'body_fat_percent' => null, 'waist_cm' => null, 'hips_cm' => null];
$measurementPoints = array_fill_keys(array_keys($measurements), []);
$latestMeasurements = $measurements;
$latestMeasurementDates = array_fill_keys(array_keys($measurements), null);
$measurementCounts = array_fill_keys(array_keys($measurements), 0);
foreach ($chronological as $check) {
    foreach (array_keys($measurements) as $field) {
        if ($check[$field] !== null) {
            $measurements[$field] ??= (float) $check[$field];
            $latestMeasurements[$field] = (float) $check[$field];
            $latestMeasurementDates[$field] = $check['check_date'];
            $measurementCounts[$field]++;
            $measurementPoints[$field][] = [
                'label' => date('M j', strtotime($check['check_date'])),
                'total' => (float) $check[$field],
            ];
        }
    }
}
$weightPoints = $measurementPoints['weight_kg'];
$visitsLast30 = (int) scalar(
    'SELECT COUNT(*) FROM attendance
      WHERE member_id = ? AND attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND CURDATE()',
    [$memberId]
);
$visitsPrevious30 = (int) scalar(
    'SELECT COUNT(*) FROM attendance
      WHERE member_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 59 DAY)
        AND attendance_date < DATE_SUB(CURDATE(), INTERVAL 29 DAY)',
    [$memberId]
);
$lastVisit = scalar('SELECT MAX(attendance_date) FROM attendance WHERE member_id = ?', [$memberId]);
$targetInChartRange = false;
if ($goal && $weightPoints) {
    $weightValues = array_column($weightPoints, 'total');
    $chartMinValue = min($weightValues);
    $chartMaxValue = max($weightValues);
    $chartSpan = $chartMaxValue - $chartMinValue;
    if ($chartSpan < 0.01) {
        $chartSpan = max(1.0, abs($chartMaxValue) * 0.04);
    }
    $targetWeight = (float) $goal['target_weight_kg'];
    $targetInChartRange = $targetWeight >= max(0.0, $chartMinValue - $chartSpan * 0.2)
        && $targetWeight <= $chartMaxValue + $chartSpan * 0.2;
}
$goalProgress = null;
if ($goal && $measurementCounts['weight_kg'] >= 2) {
    $startWeight = $measurements['weight_kg'];
    $latestWeight = $latestMeasurements['weight_kg'];
    $targetWeight = (float) $goal['target_weight_kg'];
    $targetDelta = $targetWeight - $startWeight;
    $goalProgress = abs($targetDelta) < 0.005
        ? (abs($latestWeight - $targetWeight) < 0.05 ? 100.0 : 0.0)
        : max(0.0, min(100.0, (($latestWeight - $startWeight) / $targetDelta) * 100));
}

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass progress-hero">
  <div class="progress-hero-copy">
    <span class="progress-hero-kicker"><i class="fa-solid fa-bolt"></i> YOUR TRAINING JOURNEY</span>
    <h2>Build strength, one workout at a time.</h2>
    <p>Log a workout, add a body check-in, or review your progress over time.</p>
    <div class="progress-hero-actions">
      <a class="btn red" href="<?= $currentSplit ? '#lifting' : '#workout-program' ?>">
        <i class="fa-solid fa-plus"></i> <?= $currentSplit ? 'Log a workout' : 'Choose a split' ?>
      </a>
      <a class="btn" href="#checkin-form"><i class="fa-solid fa-clipboard-check"></i> Add a check-in</a>
      <a class="btn" href="#measurement-trends"><i class="fa-solid fa-chart-line"></i> View measurement trends</a>
    </div>
  </div>
  <div class="progress-hero-stats" aria-label="Progress summary">
    <div><strong><?= count($exerciseNames) ?></strong><span>Exercises in plan</span></div>
    <div><strong><?= count($progress) ?></strong><span>Recent check-ins</span></div>
  </div>
</div>

<nav class="panel glass progress-section-nav" aria-label="Jump to a progress section">
  <span class="progress-section-nav-label">Jump to</span>
  <a href="#classes"><i class="fa-solid fa-people-group" aria-hidden="true"></i> Classes</a>
  <a href="#workout-program"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Training plan</a>
  <a href="#lifting"><i class="fa-solid fa-dumbbell" aria-hidden="true"></i> Strength tracker</a>
  <a href="#measurement-trends"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Measurement trends</a>
  <a href="#target-form"><i class="fa-solid fa-bullseye" aria-hidden="true"></i> Set a target</a>
  <a href="#checkin-history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Check-in history</a>
</nav>

<div class="stats progress-summary">
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-weight-scale"></i></div>
    <div class="label">Latest weight</div>
    <div class="value"><?= $latestMeasurements['weight_kg'] !== null ? e(number_format($latestMeasurements['weight_kg'], 2)) . ' <small>kg</small>' : '—' ?></div>
    <div class="note"><?= $latestMeasurementDates['weight_kg'] ? 'Checked ' . dt($latestMeasurementDates['weight_kg']) : 'Add a weight check-in to begin' ?></div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-arrows-left-right"></i></div>
    <div class="label">Weight change</div>
    <?php $weightChange = $measurements['weight_kg'] !== null && $latestMeasurements['weight_kg'] !== null
        ? $latestMeasurements['weight_kg'] - $measurements['weight_kg'] : null; ?>
    <div class="value"><?= $measurementCounts['weight_kg'] >= 2 ? e(sprintf('%+.2f', $weightChange)) . ' <small>kg</small>' : '—' ?></div>
    <div class="note">Latest minus oldest shown; + means higher</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-ruler"></i></div>
    <div class="label">Waist change</div>
    <?php $waistChange = $measurements['waist_cm'] !== null && $latestMeasurements['waist_cm'] !== null
        ? $latestMeasurements['waist_cm'] - $measurements['waist_cm'] : null; ?>
    <div class="value"><?= $measurementCounts['waist_cm'] >= 2 ? e(sprintf('%+.2f', $waistChange)) . ' <small>cm</small>' : '—' ?></div>
    <div class="note">Latest minus oldest shown; + means higher</div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-percent"></i></div>
    <div class="label">Latest body fat</div>
    <div class="value"><?= $latestMeasurements['body_fat_percent'] !== null ? e(number_format($latestMeasurements['body_fat_percent'], 2)) . ' <small>%</small>' : '—' ?></div>
    <div class="note">Optional measurement · <?= $latestMeasurementDates['body_fat_percent'] ? 'Checked ' . dt($latestMeasurementDates['body_fat_percent']) : 'No body-fat check yet' ?></div>
  </div>
  <div class="stat glass">
    <div class="ico"><i class="fa-solid fa-dumbbell"></i></div>
    <div class="label">Gym visits · 30 days</div>
    <div class="value"><?= $visitsLast30 ?></div>
    <div class="note"><?= $visitsLast30 - $visitsPrevious30 >= 0 ? '+' : '' ?><?= $visitsLast30 - $visitsPrevious30 ?> vs. previous 30 days
      <?php if ($lastVisit): ?> · Last visit <?= dt((string) $lastVisit) ?><?php endif; ?>
    </div>
  </div>
</div>

<div class="panel glass" id="classes">
  <h2><i class="fa-solid fa-people-group"></i> Classes &amp; workouts</h2>
  <div class="grid c2">
    <div>
      <h3>Class records by week · last 8 weeks</h3>
      <p class="note">Counts class records, not confirmed visits. Check each record's status below for details.</p>
      <?= Chart::bars($classBookingPoints, [
          'format' => 'number',
          'ariaLabel' => 'Weekly class records',
          'title' => 'Weekly class records',
      ]) ?>
    </div>
    <div>
      <h3>Recent class records &amp; linked workouts</h3>
      <?php if (!$recentClassSessions): ?>
        <div class="empty"><i class="fa-solid fa-people-group"></i>No past class records yet. When a record is available, its status and linked workouts will appear here.</div>
      <?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><th>Class</th><th>Date</th><th>Status</th><th>Linked workouts</th></tr></thead>
          <tbody>
          <?php foreach ($recentClassSessions as $classSession): ?>
            <tr>
              <td><?= e($classSession['class_name']) ?></td>
              <td><?= dt($classSession['attendance_date']) ?></td>
              <td><?= badge($classSession['attendance_status']) ?></td>
              <td><?= (int) $classSession['linked_workouts'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel glass progress-program" id="workout-program">
  <div class="progress-program-heading">
    <div>
      <span class="progress-hero-kicker"><i class="fa-solid fa-list-check"></i> TRAINING PLAN</span>
      <h2><?= $currentSplit ? e($currentSplit['name']) : 'Choose your workout split' ?></h2>
      <p class="note"><?= $currentSplit
          ? e($currentSplit['description']) . ' Your exercise choices and workout logs follow this split.'
          : 'Select a program to see its workout days and log progress exercise by exercise.' ?></p>
    </div>
    <?php if ($currentWorkoutProgram && !$currentSplit): ?>
      <p class="note">The saved split is no longer available. Choose another program to continue tracking.</p>
    <?php endif; ?>
  </div>
  <form method="post" class="search-row progress-program-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="program">
    <label for="workoutSplit">Current split</label>
    <select id="workoutSplit" name="split_code" required>
      <?php foreach ($workoutPrograms as $splitCode => $programOption): ?>
        <option value="<?= e($splitCode) ?>" <?= $currentSplitCode === $splitCode ? 'selected' : '' ?>>
          <?= e($programOption['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn red"><i class="fa-solid fa-check"></i> <?= $currentSplit ? 'Save split' : 'Start tracking' ?></button>
  </form>
  <p class="note">Changing your split keeps earlier workout logs tagged to the split and day used at the time.</p>
</div>

<section id="lifting" class="progress-lifting">
<?php if (!$currentSplit): ?>
  <div class="panel glass"><div class="empty"><i class="fa-solid fa-dumbbell"></i>Choose a workout split above to see its days and exercises.</div></div>
<?php else: ?>
  <div class="progress-section-heading">
    <div>
      <span class="progress-hero-kicker"><i class="fa-solid fa-dumbbell"></i> STRENGTH</span>
      <h2>Track <?= e($selectedDay) ?></h2>
    </div>
    <p class="note">Exercises listed here come from your <?= e($currentSplit['name']) ?> split.</p>
  </div>
  <form method="get" class="search-row progress-workout-picker">
    <label for="workoutDay">Workout day</label>
    <select id="workoutDay" name="day">
      <?php foreach ($programDays as $dayName => $dayExercises): ?>
        <option value="<?= e($dayName) ?>" <?= $selectedDay === $dayName ? 'selected' : '' ?>><?= e($dayName) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn sm"><i class="fa-solid fa-arrow-right"></i> Show workout</button>
  </form>
  <div class="grid c2">
    <div class="panel glass">
      <h2><i class="fa-solid fa-dumbbell"></i> Log an exercise</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="lifting">
        <input type="hidden" name="workout_day" value="<?= e($selectedDay) ?>">
        <div class="field-row"><label for="exerciseName">Exercise · <?= e($selectedDay) ?></label>
          <select id="exerciseName" name="exercise_name" required>
            <?php foreach ($selectedDayExercises as $exercise): ?>
              <option value="<?= e($exercise) ?>" <?= $selectedExercise === $exercise ? 'selected' : '' ?>><?= e($exercise) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row"><label for="liftingDate">Workout date</label>
          <input id="liftingDate" name="logged_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field-row"><label for="classAttendanceId">Class record (optional)</label>
          <select id="classAttendanceId" name="class_attendance_id">
            <option value="">Not linked to a class</option>
            <?php foreach ($classAttendanceOptions as $classRecord): ?>
              <option value="<?= e($classRecord['attendance_id']) ?>" data-date="<?= e($classRecord['attendance_date']) ?>">
                <?= e(dt($classRecord['attendance_date'])) ?> · <?= e($classRecord['class_name']) ?> · <?= e($classRecord['attendance_status']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="note">Choose a class record from the workout date. The date will match automatically.</small>
        </div>
        <div class="form-grid">
          <div class="field-row"><label for="liftingSets">Sets</label>
            <input id="liftingSets" name="sets_count" type="number" min="1" max="30" step="1" value="3" required></div>
          <div class="field-row"><label for="liftingReps">Reps per set</label>
            <input id="liftingReps" name="reps_per_set" type="number" min="1" max="100" step="1" value="8" required></div>
          <div class="field-row"><label for="liftingWeight">External load (kg)</label>
            <input id="liftingWeight" name="lifting_weight_kg" type="number" min="0" max="1000" step="0.25" value="0" required></div>
        </div>
        <div class="field-row"><label for="liftingNotes">Notes (optional)</label>
          <textarea id="liftingNotes" name="lifting_notes" maxlength="500" rows="2" placeholder="Grip, variation, or how the sets felt"></textarea></div>
        <p class="note">Save each exercise separately; use the same workout date and split day for one session. For bodyweight exercises, enter 0 kg when no extra weight is used. Only you and authorized administrators can view these logs.</p>
        <button class="btn red"><i class="fa-solid fa-check"></i> Save workout</button>
      </form>
    </div>

    <div class="panel glass">
      <h2><i class="fa-solid fa-chart-line"></i> Strength trend</h2>
      <?php if ($exerciseNames): ?>
        <form method="get" class="search-row">
          <input type="hidden" name="day" value="<?= e($selectedDay) ?>">
          <label for="liftingExercise">Exercise</label>
          <select id="liftingExercise" name="exercise">
            <?php foreach ($selectedDayExercises as $exercise): ?>
              <option value="<?= e($exercise) ?>" <?= $selectedExercise === $exercise ? 'selected' : '' ?>><?= e($exercise) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn sm"><i class="fa-solid fa-filter"></i> Show</button>
        </form>
        <?php $latestLift = $liftingLogs[0] ?? null; ?>
        <?php if ($latestLift): ?>
          <div class="progress-lift-summary">
            <div><span class="label">Latest logged</span>
              <strong><?= (int) $latestLift['sets_count'] ?> × <?= (int) $latestLift['reps_per_set'] ?>
                @ <?= (float) $latestLift['weight_kg'] > 0
                    ? e(number_format((float) $latestLift['weight_kg'], 2)) . ' kg'
                    : 'Bodyweight' ?></strong>
              <small><?= dt($latestLift['logged_date']) ?></small></div>
            <?php if ($hasExternalLoad): ?>
              <div><span class="label">Best logged load</span>
                <strong><?= e(number_format(max(array_map(
                    static fn(array $log): float => (float) $log['weight_kg'],
                    $liftingLogs
                )), 2)) ?> kg</strong></div>
            <?php else: ?>
              <div><span class="label">Most reps in a workout</span>
                <strong><?= max(array_map(
                    static fn(array $log): int => (int) $log['sets_count'] * (int) $log['reps_per_set'],
                    $liftingLogs
                )) ?> total reps</strong></div>
            <?php endif; ?>
          </div>
          <?php if (count($liftingPoints) >= 2): ?>
            <?= Chart::trend($liftingPoints, [
                'suffix' => $hasExternalLoad ? ' kg' : ' reps',
            ]) ?>
            <p class="note"><?= $hasExternalLoad ? 'Chart shows external load used.' : 'No external load logged yet; chart shows total reps (sets × reps) instead.' ?></p>
          <?php else: ?>
            <div class="empty"><i class="fa-solid fa-chart-line"></i>Log this exercise again to see your trend.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="empty"><i class="fa-solid fa-chart-line"></i>No <?= e($selectedExercise) ?> logs in this workout yet. Log it to start your trend.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="empty"><i class="fa-solid fa-dumbbell"></i>Log your first exercise to start tracking sets, reps, and load.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($liftingLogs): ?>
  <div class="panel glass">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> <?= e($selectedDay) ?> · <?= e($selectedExercise) ?> history</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Program / day</th><th>Class</th><th>Sets × reps</th><th>External load</th><th>Total reps</th><th>Notes</th><th data-nosort></th></tr></thead>
      <tbody>
      <?php foreach ($liftingLogs as $liftingLog): ?>
        <?php $logProgram = $liftingLog['split_code'] ? WorkoutSplits::get((string) $liftingLog['split_code']) : null; ?>
        <tr>
          <td><?= dt($liftingLog['logged_date']) ?></td>
          <td><?= $logProgram
              ? e($logProgram['name']) . ' · ' . e($liftingLog['workout_day'])
              : ($liftingLog['split_code'] ? e($liftingLog['split_code']) . ' · ' . e($liftingLog['workout_day']) : 'Earlier log') ?></td>
          <td><?= e($liftingLog['linked_class_name'] ?: '—') ?></td>
          <td><?= (int) $liftingLog['sets_count'] ?> × <?= (int) $liftingLog['reps_per_set'] ?></td>
          <td><?= (float) $liftingLog['weight_kg'] > 0
              ? e(number_format((float) $liftingLog['weight_kg'], 2)) . ' kg'
              : 'Bodyweight' ?></td>
          <td><?= (int) $liftingLog['sets_count'] * (int) $liftingLog['reps_per_set'] ?></td>
          <td><?= e($liftingLog['notes'] ?: '—') ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this strength log?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_lifting">
              <input type="hidden" name="lifting_log_id" value="<?= (int) $liftingLog['lifting_log_id'] ?>">
              <input type="hidden" name="workout_day" value="<?= e($selectedDay) ?>">
              <input type="hidden" name="exercise_name" value="<?= e($selectedExercise) ?>">
              <button class="btn sm icon danger" title="Delete strength log" aria-label="Delete strength log from <?= e(dt($liftingLog['logged_date'])) ?>">
                <i class="fa-solid fa-trash"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</section>

<?php if ($otherLiftingLogs): ?>
<div class="panel glass">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Workouts outside the current split</h2>
  <p class="note">Previous split and earlier unassigned logs remain available here.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Program / day</th><th>Exercise</th><th>Class</th><th>Sets × reps</th><th>External load</th><th>Notes</th><th data-nosort></th></tr></thead>
    <tbody>
    <?php foreach ($otherLiftingLogs as $liftingLog): ?>
      <?php $logProgram = $liftingLog['split_code'] ? WorkoutSplits::get((string) $liftingLog['split_code']) : null; ?>
      <tr>
        <td><?= dt($liftingLog['logged_date']) ?></td>
        <td><?= $logProgram
            ? e($logProgram['name']) . ' · ' . e($liftingLog['workout_day'])
            : ($liftingLog['split_code'] ? e($liftingLog['split_code']) . ' · ' . e($liftingLog['workout_day']) : 'Earlier log') ?></td>
        <td><?= e($liftingLog['exercise_name']) ?></td>
        <td><?= e($liftingLog['linked_class_name'] ?: '—') ?></td>
        <td><?= (int) $liftingLog['sets_count'] ?> × <?= (int) $liftingLog['reps_per_set'] ?></td>
        <td><?= (float) $liftingLog['weight_kg'] > 0
            ? e(number_format((float) $liftingLog['weight_kg'], 2)) . ' kg'
            : 'Bodyweight' ?></td>
        <td><?= e($liftingLog['notes'] ?: '—') ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete this strength log?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_lifting">
            <input type="hidden" name="lifting_log_id" value="<?= (int) $liftingLog['lifting_log_id'] ?>">
            <input type="hidden" name="workout_day" value="<?= e($liftingLog['workout_day'] ?? '') ?>">
            <input type="hidden" name="exercise_name" value="<?= e($liftingLog['exercise_name']) ?>">
            <button class="btn sm icon danger" title="Delete strength log" aria-label="Delete strength log from <?= e(dt($liftingLog['logged_date'])) ?>">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<script>
document.getElementById('classAttendanceId')?.addEventListener('change', function () {
  const date = this.selectedOptions[0]?.dataset.date;
  if (date) document.getElementById('liftingDate').value = date;
});
</script>

<div class="panel glass progress-measurements" id="measurement-trends">
  <h2><i class="fa-solid fa-chart-line"></i> Measurement trends</h2>
  <p class="note">Showing up to your 100 most recent check-ins. Each chart has its own scale; compare values recorded on the dates shown.</p>
  <div class="grid c2 progress-measurement-grid">
    <?php foreach ([
        'weight_kg' => ['label' => 'Weight', 'unit' => 'kg', 'suffix' => ' kg', 'change_unit' => ' kg'],
        'body_fat_percent' => ['label' => 'Body fat', 'unit' => '%', 'suffix' => '%', 'change_unit' => ' pp'],
        'waist_cm' => ['label' => 'Waist', 'unit' => 'cm', 'suffix' => ' cm', 'change_unit' => ' cm'],
        'hips_cm' => ['label' => 'Hips', 'unit' => 'cm', 'suffix' => ' cm', 'change_unit' => ' cm'],
    ] as $field => $metric):
      $points = $measurementPoints[$field];
      $pointCount = count($points);
      $firstPoint = $pointCount ? $points[0] : null;
      $latestPoint = $pointCount ? $points[$pointCount - 1] : null;
      $change = $pointCount >= 2 ? (float) $latestPoint['total'] - (float) $firstPoint['total'] : null;
      $chartOptions = [
          'suffix' => $metric['suffix'],
          'ariaLabel' => $metric['label'] . ' progress over time',
          'title' => $metric['label'] . ' progress over time',
      ];
      if ($field === 'weight_kg' && $goal) {
          $chartOptions['target'] = (float) $goal['target_weight_kg'];
      }
    ?>
    <section class="panel glass progress-measurement-card">
      <div class="progress-measurement-heading">
        <div>
          <h3><?= e($metric['label']) ?></h3>
          <span class="note"><?= $pointCount ?> check-in<?= $pointCount === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($latestPoint): ?>
          <strong><?= e(number_format((float) $latestPoint['total'], 2)) ?> <?= e($metric['unit']) ?></strong>
        <?php endif; ?>
      </div>
      <?php if ($pointCount >= 2): ?>
        <?= Chart::trend($points, $chartOptions) ?>
        <div class="progress-measurement-summary">
          <span>Oldest shown · <?= e($firstPoint['label']) ?> <strong><?= e(number_format((float) $firstPoint['total'], 2)) ?> <?= e($metric['unit']) ?></strong></span>
          <span>Change <strong><?= e(sprintf('%+.2f', $change)) ?><?= e($metric['change_unit']) ?></strong></span>
          <span>Latest · <?= e($latestPoint['label']) ?> <strong><?= e(number_format((float) $latestPoint['total'], 2)) ?> <?= e($metric['unit']) ?></strong></span>
        </div>
        <?php if ($field === 'weight_kg' && $goal): ?>
          <div class="progress-chart-legend">
            <span><i></i> Recorded weight</span>
            <span>
              <?php if ($targetInChartRange): ?><i class="progress-legend-target"></i><?php endif; ?>
              Target <?= e(number_format((float) $goal['target_weight_kg'], 2)) ?> kg<?= $targetInChartRange ? '' : ' · outside chart scale' ?>
            </span>
          </div>
        <?php endif; ?>
      <?php elseif ($pointCount === 1): ?>
        <p class="progress-measurement-empty">Add another <?= e(strtolower($metric['label'])) ?> check-in to see its trend.</p>
      <?php else: ?>
        <p class="progress-measurement-empty">No <?= e(strtolower($metric['label'])) ?> measurements recorded yet.</p>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid c2" id="measurements">
  <div class="panel glass" id="target-form">
    <h2><i class="fa-solid fa-bullseye"></i> My target</h2>
    <?php if ($goal): ?>
      <div class="progress-goal-summary">
        <div><span class="label">Target weight</span><strong><?= e(number_format((float) $goal['target_weight_kg'], 2)) ?> kg</strong></div>
        <div><span class="label">Target date</span><strong><?= dt($goal['target_date']) ?></strong></div>
      </div>
      <?php if ($goalProgress !== null): ?>
        <div class="progress-goal-meter" role="progressbar" aria-label="Progress from oldest shown weight check-in toward target"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round($goalProgress) ?>">
          <span style="width:<?= e((string) round($goalProgress, 1)) ?>%"></span>
        </div>
        <p class="note">
          <?= e(number_format($goalProgress, 0)) ?>% of the distance from your oldest shown weight check-in toward your target.
          Current weight <?= e(number_format($latestMeasurements['weight_kg'], 2)) ?> kg.
        </p>
      <?php else: ?>
        <p class="note">Record at least two weight check-ins to compare your progress with your starting point.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="note">Set an optional target weight and date. Goals are personal; progress is shown as a reference, not a medical assessment.</p>
    <?php endif; ?>
    <form method="post" class="progress-goal-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="goal">
      <div class="form-grid">
        <div class="field-row"><label for="targetWeight">Target weight (kg)</label>
          <input id="targetWeight" name="target_weight_kg" type="number" min="20" max="500" step="0.01"
                 value="<?= e($goal['target_weight_kg'] ?? '') ?>" placeholder="e.g. 68.00"></div>
        <div class="field-row"><label for="targetDate">Target date</label>
          <input id="targetDate" name="target_date" type="date" min="<?= date('Y-m-d') ?>"
                 value="<?= e($goal['target_date'] ?? '') ?>"></div>
      </div>
      <p class="note">Leave both fields empty to clear your goal.</p>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save target</button>
    </form>
  </div>

  <div class="panel glass" id="checkin-form">
    <h2><i class="fa-solid fa-clipboard-check"></i> New check-in</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record">
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
      <p class="note">Enter at least one measurement. Measurements can fluctuate; use similar conditions when comparing. Only you and authorized administrators can view your progress.</p>
      <button class="btn red"><i class="fa-solid fa-check"></i> Save check-in</button>
    </form>
  </div>
</div>

<div class="panel glass" id="checkin-history">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Check-in history</h2>
  <?php if ($progress): ?><p class="note">Showing your latest <?= count($progress) ?> check-ins.</p><?php endif; ?>
  <?php if (!$progress): ?>
    <div class="empty"><i class="fa-solid fa-chart-line"></i>No fitness check-ins yet. Save your first check-in above.</div>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
