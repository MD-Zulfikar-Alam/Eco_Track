<?php
$page_title='Calculator';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_login();

// CSV export of full history
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=history.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Date','Total kg','Electricity kg','Transport kg','Meat kg','Flights kg']);
  $stmt = $pdo->prepare('SELECT recorded_on, total_kg, electricity_kg, transport_kg, meat_kg, flights_kg FROM footprints WHERE user_id = ? ORDER BY recorded_on DESC, id DESC');
  $stmt->execute([$_SESSION['user']['id']]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [$r['recorded_on'], $r['total_kg'], $r['electricity_kg'], $r['transport_kg'], $r['meat_kg'], $r['flights_kg']]);
  }
  fclose($out);
  exit;
}

include __DIR__.'/partials/header.php';

/**
 * Emission factors (rough demo values)
 * electricity: kg CO2 / kWh
 * car_km: kg CO2 / km
 * meat_kg: kg CO2 / kg
 * flight_hour: kg CO2 / hour (annual, averaged monthly)
 */
$EF = [
  'electricity' => 0.233,
  'car_km'      => 0.12,
  'meat_kg'     => 27.0,
  'flight_hour' => 90.0
];

$errors = [];
$result = null;
$saved  = false;
$deleted = false;

function num($v){ return is_numeric($v) ? (float)$v : 0.0; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Row deletion
  if (isset($_POST['delete_id'])) {
    $csrf = $_POST['csrf'] ?? '';
    if (!verify_csrf($csrf)) { $errors[] = 'Invalid CSRF token.'; }
    else {
      $delId = (int)$_POST['delete_id'];
      $stmt = $pdo->prepare('DELETE FROM footprints WHERE id = ? AND user_id = ?');
      $stmt->execute([$delId, $_SESSION['user']['id']]);
      if ($stmt->rowCount() > 0) { $deleted = true; }
    }
  }
  $csrf = $_POST['csrf'] ?? '';
  if (!verify_csrf($csrf)) $errors[] = 'Invalid CSRF token.';

  $el = num($_POST['elKwh'] ?? 0);
  $carWeek = num($_POST['carKmWeek'] ?? 0);
  $meatWeek = num($_POST['meatKgWeek'] ?? 0);
  $flyYear = num($_POST['flightHoursYear'] ?? 0);

  if ($el < 0 || $carWeek < 0 || $meatWeek < 0 || $flyYear < 0) $errors[] = 'Inputs cannot be negative.';

  if (!$errors) {
    $transportMonthly   = $carWeek * 4.33 * $EF['car_km'];
    $meatMonthly        = $meatWeek * 4.33 * $EF['meat_kg'];
    $flightsMonthly     = ($flyYear / 12) * $EF['flight_hour'];
    $electricityMonthly = $el * $EF['electricity'];
    $total = $transportMonthly + $meatMonthly + $flightsMonthly + $electricityMonthly;

    $result = [
      'electricity' => $electricityMonthly,
      'transport'   => $transportMonthly,
      'meat'        => $meatMonthly,
      'flights'     => $flightsMonthly,
      'total'       => $total
    ];

    if (isset($_POST['save']) && $_POST['save'] === '1') {
      $stmt = $pdo->prepare('INSERT INTO footprints (user_id, recorded_on, electricity_kg, transport_kg, meat_kg, flights_kg, total_kg) VALUES (?,?,?,?,?,?,?)');
      $stmt->execute([
        $_SESSION['user']['id'],
        date('Y-m-d'),
        $result['electricity'],
        $result['transport'],
        $result['meat'],
        $result['flights'],
        $result['total']
      ]);
      $saved = true;
    }
  }
}

// Fetch last 10 results for this user
$history = [];
try {
$limit = (isset($_GET['all']) && $_GET['all'] == '1') ? '' : ' LIMIT 10';

  $stmt = $pdo->prepare('SELECT id, recorded_on, total_kg, electricity_kg, transport_kg, meat_kg, flights_kg FROM footprints WHERE user_id = ? ORDER BY recorded_on DESC, id DESC ' . $limit);
  $stmt->execute([ $_SESSION['user']['id'] ]);
  $history = $stmt->fetchAll();

// Averages for this user
$averages = null;
try {
  $stmt = $pdo->prepare('SELECT COUNT(*) as n, AVG(total_kg) as avg_total, AVG(electricity_kg) as avg_elec, AVG(transport_kg) as avg_trans, AVG(meat_kg) as avg_meat, AVG(flights_kg) as avg_fly FROM footprints WHERE user_id = ?');
  $stmt->execute([ $_SESSION['user']['id'] ]);
  $averages = $stmt->fetch();
} catch (Throwable $e) { /* ignore */ }

} catch (Throwable $e) { /* ignore if table missing */ }

?>
<div class="grid">
  <div class="card span-6">
    <div class="section-title"><h2>Carbon Footprint Calculator</h2><span class="small">Estimates per month</span></div>
    <?php if (!empty($errors)): ?>
      <div class="card" style="background:#fdecea;border-color:#f5c2c7">
        <p class="small" style="color:#b91c1c"><?= htmlspecialchars(implode(' ', $errors)); ?></p>
      </div>
    <?php endif; ?>
    <?php if ($deleted): ?>
      <div class="card" style="background:#ecfdf5;border-color:#a7f3d0"><p class="small" style="color:#065f46">Deleted history entry.</p></div>
    <?php endif; ?>
    <?php if ($saved): ?>
      <div class="card" style="background:#e0f2fe;border-color:#bae6fd">
        <p class="small" style="color:#075985">Saved to your history.</p>
      </div>
    <?php endif; ?>
    <form method="post" novalidate>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">

      <label>Electricity usage (kWh / month)</label>
      <input type="number" name="elKwh" min="0" step="0.1" value="<?= htmlspecialchars($_POST['elKwh'] ?? '') ?>" placeholder="e.g., 120">

      <label>Car travel (km / week)</label>
      <input type="number" name="carKmWeek" min="0" step="1" value="<?= htmlspecialchars($_POST['carKmWeek'] ?? '') ?>" placeholder="e.g., 50">

      <label>Meat consumption (kg / week)</label>
      <input type="number" name="meatKgWeek" min="0" step="0.1" value="<?= htmlspecialchars($_POST['meatKgWeek'] ?? '') ?>" placeholder="e.g., 1">

      <label>Flight hours (hours / year)</label>
      <input type="number" name="flightHoursYear" min="0" step="0.1" value="<?= htmlspecialchars($_POST['flightHoursYear'] ?? '') ?>" placeholder="e.g., 10">

      <div class="actions">
        <button class="btn primary" type="submit">Calculate</button>
        <button class="btn" type="submit" name="save" value="1">Calculate & Save</button>
      </div>
    </form>
    <p class="small">Reference: a rough global average is ~400–600 kg CO₂ / month per person.</p>
  </div>

  <div class="card span-6">
    <h3>Result</h3>
    <?php if ($result): ?>
      <div class="notice">Estimated total: <strong><?= number_format($result['total'], 2) ?></strong> kg CO₂ / month</div>
      <table class="table" style="margin-top:10px">
        <tr><th>Electricity</th><td><?= number_format($result['electricity'], 2) ?> kg</td></tr>
        <tr><th>Transport</th><td><?= number_format($result['transport'], 2) ?> kg</td></tr>
        <tr><th>Meat</th><td><?= number_format($result['meat'], 2) ?> kg</td></tr>
        <tr><th>Flights</th><td><?= number_format($result['flights'], 2) ?> kg</td></tr>
      </table></div>
      <div class="card span-6">
        <canvas id="breakdown" height="380" style="background:#fff;border-radius:12px;width:100%;"></canvas>
      </div>
      <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
      <script>
        const ctx = document.getElementById('breakdown').getContext('2d');
        const data = {
          labels: ['Electricity','Transport','Meat','Flights'],
          datasets: [{ data: [<?= $result['electricity'] ?>, <?= $result['transport'] ?>, <?= $result['meat'] ?>, <?= $result['flights'] ?>] }]
        };
        new Chart(ctx, { 
          type: 'doughnut', 
          data, 
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            layout: { padding: 8 }
          } 
        });
      </script>
    <?php else: ?>
      <p class="small">Fill the form and press <strong>Calculate</strong> to see your breakdown.</p>
    <?php endif; ?>
  </div>
</div>


<?php if ($averages && (int)$averages['n'] > 0): ?>
  <div class="card" style="margin-top:16px">
    <h3>Your averages (based on <?= (int)$averages['n'] ?> saved entries)</h3>
    <div class="table-wrap"><table class="table table-history">
      <tr><th>Total</th><td><?= number_format($averages['avg_total'], 2) ?> kg/mo</td></tr>
      <tr><th>Electricity</th><td><?= number_format($averages['avg_elec'], 2) ?> kg/mo</td></tr>
      <tr><th>Transport</th><td><?= number_format($averages['avg_trans'], 2) ?> kg/mo</td></tr>
      <tr><th>Meat</th><td><?= number_format($averages['avg_meat'], 2) ?> kg/mo</td></tr>
      <tr><th>Flights</th><td><?= number_format($averages['avg_fly'], 2) ?> kg/mo</td></tr>
    </table></div>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:16px">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <h3 style="margin:0">Your history</h3>
    <div style="display:flex; gap:8px; align-items:center;">
      <form method="get" action="" style="display:inline">
        <?php if (isset($_GET['all']) && $_GET['all'] == '1'): ?>
          <button class="btn" type="submit" name="all" value="0">Show last 10</button>
        <?php else: ?>
          <button class="btn" type="submit" name="all" value="1">Show all</button>
        <?php endif; ?>
      </form>
      <a class="btn" href="?export=csv">Export CSV</a>
    </div>
  </div>
  <div class="input-group" style="margin-top:10px"><input id="historyFilter" type="text" placeholder="Filter history... (e.g., 2025-08, 120.5)" style="width:100%"></div>
  <?php if ($history): ?>
    <div class="table-wrap"><table class="table table-history">
      <thead><tr><th data-sort='date'>Date</th><th data-sort='num'>Total</th><th data-sort='num'>Electricity</th><th data-sort='num'>Transport</th><th data-sort='num'>Meat</th><th data-sort='num'>Flights</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($history as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['recorded_on']) ?></td>
            <td><?= number_format($row['total_kg'], 2) ?></td>
            <td><?= number_format($row['electricity_kg'], 2) ?></td>
            <td><?= number_format($row['transport_kg'], 2) ?></td>
            <td><?= number_format($row['meat_kg'], 2) ?></td>
            <td><?= number_format($row['flights_kg'], 2) ?></td>
          <td>
              <form method="post" onsubmit="return confirm('Delete this entry?');" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="delete_id" value="<?= (int)$row['id'] ?>">
                <button class="btn" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <p class="small">No history yet. Calculate & Save to keep a record.</p>
  <?php endif; ?>
</div>


<script id="historyEnhance">
(function(){
  const filter = document.getElementById('historyFilter');
  const wrap = document.querySelector('.table-wrap');
  const table = document.querySelector('.table-history');
  if (!table) return;
  const tbody = table.querySelector('tbody');

  // Filter rows
  if (filter) filter.addEventListener('input', () => {
    const q = filter.value.toLowerCase();
    for (const tr of tbody.querySelectorAll('tr')) {
      const text = tr.innerText.toLowerCase();
      tr.style.display = text.includes(q) ? '' : 'none';
    }
  });

  // Sticky header hint: wrap has overflow
  if (wrap) wrap.style.maxHeight = '360px';

  // Sort on header click
  const getCellVal = (tr, idx) => tr.children[idx].innerText.trim();
  const asNum = v => parseFloat(v.replace(/[^0-9.\-]/g,''));
  const asDate = v => new Date(v).getTime() || 0;

  table.querySelectorAll('th[data-sort]').forEach((th, idx) => {
    th.style.cursor = 'pointer';
    th.title = 'Click to sort';
    let asc = true;
    th.addEventListener('click', () => {
      const type = th.getAttribute('data-sort');
      const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.style.display !== 'none');
      rows.sort((a,b)=>{
        const va = getCellVal(a, idx), vb = getCellVal(b, idx);
        let da, db;
        if (type === 'num') { da = asNum(va); db = asNum(vb); }
        else if (type === 'date') { da = asDate(va); db = asDate(vb); }
        else { da = va.toLowerCase(); db = vb.toLowerCase(); }
        return asc ? (da > db ? 1 : da < db ? -1 : 0) : (da < db ? 1 : da > db ? -1 : 0);
      });
      asc = !asc;
      rows.forEach(r => tbody.appendChild(r));
    });
  });
})();
</script>

<?php include __DIR__.'/partials/footer.php'; ?>
