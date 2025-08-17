
<?php
$page_title='Map';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_login();

$uid = (int)$_SESSION['user']['id'];
$is_admin = in_array($uid, [1,2,3], true);

// Ensure table
$pdo->exec("CREATE TABLE IF NOT EXISTS map_markers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  title VARCHAR(140) NOT NULL,
  description TEXT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  is_global TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (user_id, is_global),
  INDEX (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Ensure new columns for tree planting
try { $pdo->query("ALTER TABLE map_markers ADD COLUMN is_tree TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->query("ALTER TABLE map_markers ADD COLUMN tree_count INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}


// API endpoints
if (isset($_GET['api']) && $_GET['api']==='markers') {
  header('Content-Type: application/json; charset=utf-8');
  // Show global + own markers
  $st = $pdo->prepare('SELECT id, user_id, title, description, lat, lng, is_global, is_tree, tree_count, created_at FROM map_markers WHERE is_global=1 OR user_id=? ORDER BY created_at DESC, id DESC');
  $st->execute([$uid]);
  echo json_encode($st->fetchAll());
  exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(400); echo 'Bad CSRF'; exit; }
  $act = $_POST['action'] ?? '';
  if ($act==='create') {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    $scope = $_POST['scope'] ?? 'personal';
    $is_global = ($scope==='global' && $is_admin) ? 1 : 0;
    if ($title==='' || !$lat || !$lng) { http_response_code(422); echo 'Missing fields'; exit; }
    $ins = $pdo->prepare('INSERT INTO map_markers (user_id, title, description, lat, lng, is_global, is_tree, tree_count) VALUES (?,?,?,?,?,?,?,?)');
    $is_tree = isset($_POST['is_tree']) ? 1 : 0; $tree_count = (int)($_POST['tree_count'] ?? 0); if($is_tree && $tree_count<=0){ $tree_count=1; } $ins->execute([$is_global?null:$uid, $title, $desc!==''?$desc:null, $lat, $lng, $is_global, $is_tree, $tree_count]);
    header('Location: map.php'); exit;
  
  } elseif ($act==='add_trees') {
  $id = (int)($_POST['id'] ?? 0);
  $delta = max(1, (int)($_POST['delta'] ?? 0));
  if ($id>0 && $delta>0) {
    $st = $pdo->prepare('SELECT user_id, is_global, is_tree FROM map_markers WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row && (int)$row['is_tree']===1) {
      $can = $is_admin || ((int)$row['user_id']===$uid && (int)$row['is_global']===0);
      if ($can) {
        $pdo->prepare('UPDATE map_markers SET tree_count = tree_count + ? WHERE id=?')->execute([$delta, $id]);
      }
    }
  }
  header('Location: map.php'); exit;
} elseif ($act==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      $st = $pdo->prepare('SELECT user_id, is_global FROM map_markers WHERE id=?');
      $st->execute([$id]);
      $row = $st->fetch();
      if ($row) {
        $can = $is_admin || ((int)$row['user_id']===$uid && (int)$row['is_global']===0);
        if ($can) {
          $pdo->prepare('DELETE FROM map_markers WHERE id=?')->execute([$id]);
        }
      }
    }
    header('Location: map.php'); exit;
  }
}

include __DIR__.'/partials/header.php';
?>
<div class="grid cols-12 gap-16">
  <div class="card span-12">
    <h3>Environmental map</h3>
    <p class="small muted">Admins can add <strong>global</strong> flags for everyone. You can add <strong>personal</strong> flags only you can see.</p>
    <div id="map" style="height: 460px; border-radius: 12px; overflow: hidden; margin: 8px 0;"></div>
<?php /* tree stats */
$sum1=$pdo->prepare("SELECT COALESCE(SUM(tree_count),0) AS n FROM map_markers WHERE is_tree=1 AND is_global=1"); $sum1->execute(); $globalTrees=(int)$sum1->fetchColumn();
$sum2=$pdo->prepare("SELECT COALESCE(SUM(tree_count),0) AS n FROM map_markers WHERE is_tree=1 AND user_id=? AND is_global=0"); $sum2->execute([$uid]); $yourTrees=(int)$sum2->fetchColumn();
?>
<div class="muted" style="margin:6px 0 4px 0">🌳 Your trees: <strong><?= $yourTrees ?></strong> · Community trees: <strong><?= $globalTrees ?></strong></div>
    <div class="actions" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
      <form id="new-flag-form" method="post" class="form-grid" style="display:none; width:100%; margin-top:8px;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" id="lat" name="lat">
        <input type="hidden" id="lng" name="lng">
        <div class="grid flag-grid gap-12">
          <div class="input-group"><label>Title</label><input id="title" name="title" required></div>
          <div class="input-group"><label>Description</label><input id="description" name="description" placeholder="Optional"></div>
          <div class="input-group"><label>Scope</label>
            <select name="scope" id="scope">
              <option value="personal" selected>Personal</option>
              <?php if ($is_admin): ?><option value="global">Global (everyone)</option><?php endif; ?>
            </select>
          </div>
          <label class="input-group" style="display:flex;align-items:center;gap:8px"><input type="checkbox" id="is_tree" name="is_tree"> Tree planting</label>
          <div class="input-group"><label>Trees planted</label><input type="number" id="tree_count" name="tree_count" min="1" value="1"></div>
          <div class="actions" style="align-self:end;"><button class="btn primary">Save flag</button></div>
        </div>
      </form>
      <div class="muted small">Click on the map to drop a flag.</div>
    </div>
  </div>

  <div class="card span-12">
    <h3>Your flags</h3>
    <div id="flag-list" class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>Type</th><th>Title</th><th>Location</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody id="flag-rows"></tbody>
      </table>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
<script>
(function(){
  // Map init (default view)
  var map = L.map('map');
  var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
  osm.addTo(map);
  map.setView([23.7806, 90.2794], 12); // Dhaka-ish default

  // Try geolocation to center
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(pos){
      map.setView([pos.coords.latitude, pos.coords.longitude], 13);
    });
  }

  const youStyle = { radius: 9, color:'#2563eb', fillColor:'#3b82f6', fillOpacity:0.85, weight:2 };
  const globalStyle = { radius: 9, color:'#166534', fillColor:'#22c55e', fillOpacity:0.85, weight:2 };
  const treeStyle = { radius: 10, color:'#065f46', fillColor:'#10b981', fillOpacity:0.95, weight:2 };

  // Click to start form
  map.on('click', function(e){
    document.getElementById('lat').value = e.latlng.lat.toFixed(6);
    document.getElementById('lng').value = e.latlng.lng.toFixed(6);
    document.getElementById('new-flag-form').style.display = 'block';
    // Scroll into view
    document.getElementById('new-flag-form').scrollIntoView({behavior:'smooth', block:'center'});
  });

  function fmt(n){ return Number(n).toFixed(6); }

  // Fetch markers
  fetch('map.php?api=markers').then(r=>r.json()).then(function(rows){
    var tbody = document.getElementById('flag-rows');
    tbody.innerHTML = '';
    rows.forEach(function(m){
      var style = (m.is_tree==1 ? treeStyle : (m.is_global==1 ? globalStyle : youStyle));
      var marker = L.circleMarker([m.lat, m.lng], style).addTo(map);
      var badge = (m.is_global==1 ? '<span class="badge">Global</span>' : '<span class="badge">Personal</span>');
      var desc = (m.description||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      var treeLine = (m.is_tree==1 ? '<div>🌳 Trees: <strong>'+m.tree_count+'</strong></div>' : '');
      marker.bindPopup('<strong>'+badge+' '+m.title+'</strong><br>'+desc+treeLine);

      var tr = document.createElement('tr');
      tr.innerHTML = '<td>'+(m.is_tree==1?'Tree · ':'')+(m.is_global==1?'Global':'Personal')+'</td>' +
                     '<td>'+m.title+(m.is_tree==1?' <span class="badge">🌳 '+m.tree_count+'</span>':'')+'</td>' +
                     '<td>'+fmt(m.lat)+', '+fmt(m.lng)+'</td>' +
                     '<td>'+m.created_at+'</td>' +
                     '<td>'+buildActions(m)+'</td>';
      tbody.appendChild(tr);
    });
  });

  function buildActions(m){
    var canDelete = <?php echo $is_admin ? 'true' : 'false'; ?> || (m.is_global==0 && m.user_id===<?php echo $uid; ?>);
    if (!canDelete) return '<span class="small muted">—</span>';
    var f = document.createElement('form');
    f.method='post'; f.innerHTML = ''+
      '<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">'+
      '<input type="hidden" name="action" value="delete">'+
      '<input type="hidden" name="id" value="'+m.id+'">'+
      '<button class="btn danger small" onclick="return confirm(\'Delete this flag?\')">Delete</button>';
    // Return outerHTML as string
    var div = document.createElement('div'); div.appendChild(f); return div.innerHTML;
  }
})();
</script>

<?php include __DIR__.'/partials/footer.php'; ?>
