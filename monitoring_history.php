<?php
session_start();
require '../db.php';

/* ===== Farmer profile photo helpers =====
   Auto-detects which column of `beneficiaries` holds the farmer's picture and
   which uploads folder it lives in. If your column/folder has an unusual name,
   add it to the two lists below. */
if (!function_exists('pg_farmer_photo_col')) {
    function pg_farmer_photo_col(mysqli $conn): ?string {
        static $col = false;
        if ($col !== false) return $col;
        $col = null; $cols = [];
        if ($res = $conn->query("SHOW COLUMNS FROM beneficiaries")) {
            while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = $r['Field'];
        }
        $candidates = ['photo','picture','profile_picture','profile_pic','profile_photo','profile_image',
                       'image','img','avatar','farmer_photo','farmer_image','farmer_picture','pic',
                       'photo_path','image_path','picture_path'];
        foreach ($candidates as $c) {
            if (isset($cols[$c])) { $col = $cols[$c]; break; }
        }
        return $col;
    }
}
if (!function_exists('pg_farmer_photo_sql')) {
    /* Returns ", b.`col` AS farmer_photo" (or ", NULL AS farmer_photo") to drop into a SELECT. */
    function pg_farmer_photo_sql(mysqli $conn, string $alias = 'b'): string {
        $c = pg_farmer_photo_col($conn);
        return $c ? ", $alias.`$c` AS farmer_photo" : ", NULL AS farmer_photo";
    }
}
if (!function_exists('pg_farmer_photo_url')) {
    function pg_farmer_photo_url($file): ?string {
        $file = trim((string)$file);
        if ($file === '') return null;
        if (preg_match('#^(https?:)?//#i', $file) || strpos($file, 'data:') === 0) return $file;
        $base  = basename($file);
        $tries = [];
        if (strpos($file, '/') !== false) { $tries[] = ltrim($file, '/'); $tries[] = '../' . ltrim($file, '/'); }
        foreach (['../uploads/','../uploads/beneficiaries/','../uploads/farmers/','../uploads/profiles/','../uploads/profile/','uploads/'] as $d) {
            $tries[] = $d . $base;
        }
        foreach ($tries as $t) {
            if (is_file(__DIR__ . '/' . $t)) return $t;
        }
        return '../uploads/' . $base;   // best guess; the initials show if it fails to load
    }
}
if (!function_exists('pg_farmer_avatar')) {
    /* Round profile picture with an initial as fallback (no photo / broken image). */
    function pg_farmer_avatar($photo, string $name, string $extraClass = ''): string {
        $initial = mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: '?';
        $url  = pg_farmer_photo_url($photo);
        $html = '<span class="farmer-avatar ' . htmlspecialchars($extraClass) . '">';
        $html .= '<span class="fa-init">' . htmlspecialchars($initial) . '</span>';
        if ($url) {
            $html .= '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($name) . '" loading="lazy" onerror="this.remove()">';
        }
        return $html . '</span>';
    }
}
$farmerPhotoCol = pg_farmer_photo_col($conn);
$photoSql       = pg_farmer_photo_sql($conn);


if(!isset($_SESSION['technician_id'])){
    header("Location: ../technicianindex.php");
    exit();
}

$tech_id = (int)$_SESSION['technician_id'];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$barangayFilter = isset($_GET['barangay']) ? (int)$_GET['barangay'] : 0;

/* =========================================================
   BENEFICIARY MONITORING HISTORY (JSON, used by the History button)
   Returns every monitoring of one beneficiary, oldest first,
   so the modal can show 1st, 2nd, 3rd... and the total count.
========================================================= */
if (isset($_GET['history_for'])) {
    header('Content-Type: application/json; charset=utf-8');
    $bid = (int)$_GET['history_for'];
    try {
        $hs = $conn->prepare("
            SELECT m.monitoring_id, m.health_status, m.weight, m.pregnancy_status,
                   m.offspring_count, m.offspring_alive, m.offspring_dead, m.photo, m.notes, m.date_recorded,
                   l.batch_no, l.kind
            FROM monitoring m
            JOIN distributions d ON m.distribution_id = d.distribution_id
            JOIN livestock l ON d.livestock_id = l.livestock_id
            WHERE m.created_by = ?
              AND d.beneficiary_id = ?
              AND m.action <> 'death'
              AND m.health_status <> 'Dead'
            ORDER BY m.date_recorded ASC, m.monitoring_id ASC
        ");
        $hs->bind_param("ii", $tech_id, $bid);
        $hs->execute();
        $hr = $hs->get_result();
        $out = [];
        $n = 0;
        while ($r = $hr->fetch_assoc()) {
            $n++;
            $ts = strtotime((string)$r['date_recorded']);
            $out[] = [
                'n'       => $n,
                'eartag'  => $r['batch_no'],
                'kind'    => $r['kind'],
                'health'  => $r['health_status'],
                'weight'  => ($r['weight'] !== null && $r['weight'] !== '') ? $r['weight'] : null,
                'preg'    => $r['pregnancy_status'],
                'off'     => (int)($r['offspring_count'] ?? 0),
                'off_alive' => (int)($r['offspring_alive'] ?? 0),
                'off_dead'  => (int)($r['offspring_dead'] ?? 0),
                'photo'   => $r['photo'],
                'remarks' => $r['notes'],
                'date'    => $ts ? date('M j, Y', $ts) : (string)$r['date_recorded'],
            ];
        }
        echo json_encode(['ok' => true, 'total' => $n, 'records' => $out]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'total' => 0, 'records' => []]);
    }
    exit();
}

/* =========================================================
   EDIT / DELETE (own records only)
========================================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flashOk  = $_SESSION['flash_ok']  ?? null;
$flashErr = $_SESSION['flash_err'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

/* Saves an optional uploaded photo into ../uploads/. Returns the new file name,
   or null when nothing was uploaded (or on error, with $err filled in). */
function pg_save_photo(string $field, ?string &$err): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) { $err = 'Photo upload failed. Please try again.'; return null; }
    if ($f['size'] > 5 * 1024 * 1024) { $err = 'Photo must be 5MB or smaller.'; return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) { $err = 'Photo must be a JPG, PNG or WEBP image.'; return null; }
    $name = 'rec_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../uploads/' . $name)) {
        $err = 'Could not save the photo.'; return null;
    }
    return $name;
}

/* Deletes an old photo file, but only if no other record still uses it. */
function pg_remove_photo(mysqli $conn, ?string $photo): void {
    if (!$photo) return;
    $st = $conn->prepare("SELECT COUNT(*) FROM monitoring WHERE photo = ?");
    $st->bind_param("s", $photo);
    $st->execute();
    $st->bind_result($n);
    $st->fetch();
    $st->close();
    if ((int)$n === 0) @unlink(__DIR__ . '/../uploads/' . basename($photo));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crud_action'])) {
    $back = basename($_SERVER['PHP_SELF']) . '?' . http_build_query([
        'barangay' => (int)($_POST['return_barangay'] ?? 0),
        'search'   => trim($_POST['return_search'] ?? '')
    ]);
    $finish = function (string $type, string $msg) use ($back) {
        $_SESSION[$type === 'ok' ? 'flash_ok' : 'flash_err'] = $msg;
        header('Location: ' . $back);
        exit();
    };

    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $finish('err', 'Your session expired. Please try again.');
    }

    $rid = (int)($_POST['record_id'] ?? 0);
    $act = $_POST['crud_action'];

    try {
        $own = $conn->prepare("
            SELECT photo, distribution_id, date_recorded
            FROM monitoring
            WHERE monitoring_id = ? AND created_by = ? AND (action <> 'death' AND health_status <> 'Dead')
        ");
        $own->bind_param("ii", $rid, $tech_id);
        $own->execute();
        $rec = $own->get_result()->fetch_assoc();
        $own->close();

        if (!$rec) {
            $finish('err', 'Record not found, or you are not allowed to change it.');
        }


        if ($act === 'edit') {
            $health   = trim($_POST['health_status'] ?? '');
            $weightIn = trim($_POST['weight'] ?? '');
            $preg     = trim($_POST['pregnancy_status'] ?? '');
            $aliveIn  = trim($_POST['offspring_alive'] ?? '');
            $deadIn   = trim($_POST['offspring_dead'] ?? '');
            $notes    = trim($_POST['remarks'] ?? '');
            $dateIn   = trim($_POST['monitor_date'] ?? '');

            if ($health === '' || strlen($health) > 50) $finish('err', 'Please choose a health status.');
            if (strcasecmp($health, 'Dead') === 0)      $finish('err', 'Deaths are recorded in Mortality Cases, not here.');
            if ($weightIn !== '' && (!is_numeric($weightIn) || (float)$weightIn < 0)) $finish('err', 'Weight must be a positive number.');
            if ($aliveIn !== '' && !ctype_digit($aliveIn)) $finish('err', 'Offspring alive must be a whole number.');
            if ($deadIn  !== '' && !ctype_digit($deadIn))  $finish('err', 'Offspring dead must be a whole number.');
            if (!in_array($preg, ['', 'pregnant', 'not_pregnant', 'gave_birth'], true)) $finish('err', 'Invalid pregnancy status.');

            $d = DateTime::createFromFormat('Y-m-d', $dateIn);
            if (!$d || $d->format('Y-m-d') !== $dateIn) $finish('err', 'Please enter a valid date.');
            // keep the original time part if the column stores date + time
            $orig = (string)$rec['date_recorded'];
            $dateVal = $dateIn . (strlen($orig) > 10 ? substr($orig, 10) : '');

            $weight = $weightIn === '' ? null : (float)$weightIn;
            $offAlive = $aliveIn === '' ? 0 : (int)$aliveIn;
            $offDead  = $deadIn  === '' ? 0 : (int)$deadIn;
            $off      = $offAlive + $offDead;   // total offspring
            $pregV  = $preg === '' ? null : $preg;

            $photoErr = null;
            $newPhoto = pg_save_photo('photo', $photoErr);
            if ($photoErr) $finish('err', $photoErr);
            $photoV = $newPhoto ?? $rec['photo'];

            $up = $conn->prepare("
                UPDATE monitoring
                SET health_status = ?, weight = ?, pregnancy_status = ?, offspring_count = ?, offspring_alive = ?, offspring_dead = ?,
                    notes = ?, date_recorded = ?, photo = ?
                WHERE monitoring_id = ? AND created_by = ?
            ");
            $up->bind_param("sdsiiisssii", $health, $weight, $pregV, $off, $offAlive, $offDead, $notes, $dateVal, $photoV, $rid, $tech_id);
            $up->execute();
            $up->close();

            if ($newPhoto && $rec['photo']) pg_remove_photo($conn, $rec['photo']);
            $finish('ok', 'Monitoring record updated.');
        }

        if ($act === 'delete') {
            $del = $conn->prepare("DELETE FROM monitoring WHERE monitoring_id = ? AND created_by = ?");
            $del->bind_param("ii", $rid, $tech_id);
            $del->execute();
            $del->close();

            pg_remove_photo($conn, $rec['photo']);
            $finish('ok', 'Monitoring record deleted.');
        }

    } catch (Throwable $e) {
        $finish('err', 'Something went wrong while saving. Please try again.');
    }
}

$sql = "
SELECT
    m.monitoring_id AS monitor_id,
    m.health_status,
    m.weight,
    m.pregnancy_status,
    m.offspring_count AS offspring,
    m.offspring_alive,
    m.offspring_dead,
    m.photo,
    m.cause_of_death,
    m.death_type,
    m.notes AS remarks,
    m.date_recorded AS monitor_date,
    b.beneficiary_id,
    b.firstname,
    b.middlename,
    b.lastname{$photoSql},
    br.barangay_id,
    br.barangay_name,
    l.batch_no AS livestock_name,
    l.kind,
    d.status AS livestock_status
FROM monitoring m
JOIN distributions d ON m.distribution_id = d.distribution_id
JOIN beneficiaries b ON d.beneficiary_id = b.beneficiary_id
JOIN barangays br ON b.barangay_id = br.barangay_id
JOIN livestock l ON d.livestock_id = l.livestock_id
WHERE m.created_by = ?
  AND m.action <> 'death'
  AND m.health_status <> 'Dead'
  AND (br.barangay_id = ? OR ? = 0)
  AND (
      b.firstname LIKE ?
      OR b.middlename LIKE ?
      OR b.lastname LIKE ?
      OR CONCAT(b.firstname, ' ', b.lastname) LIKE ?
      OR CONCAT_WS(' ', b.firstname, b.middlename, b.lastname) LIKE ?
      OR br.barangay_name LIKE ?
      OR l.batch_no LIKE ?
      OR ? = ''
  )
ORDER BY m.date_recorded DESC, m.monitoring_id DESC
";

$stmt = $conn->prepare($sql);

if(!$stmt){
    die("Database query error: ".$conn->error);
}

$like = "%".$search."%";

$stmt->bind_param(
    "iii" . str_repeat("s", 8),
    $tech_id,
    $barangayFilter,
    $barangayFilter,
    $like,
    $like,
    $like,
    $like,
    $like,
    $like,
    $like,
    $search
);

$stmt->execute();
$result = $stmt->get_result();

$history = [];

while($row = $result->fetch_assoc()){
    $history[] = $row;
}

/* =========================================================
   BARANGAY LIST FOR THE FILTER PILLS
   Only barangays that actually have a monitoring record under
   this technician show up — not the whole barangays table —
   so the pills stay short and relevant. Independent of the
   current search/barangay filter so the full set of pills
   always stays visible.
========================================================= */
$barangayListSql = "
SELECT DISTINCT br.barangay_id, br.barangay_name
FROM monitoring m
JOIN distributions d ON m.distribution_id = d.distribution_id
JOIN beneficiaries b ON d.beneficiary_id = b.beneficiary_id
JOIN barangays br ON b.barangay_id = br.barangay_id
WHERE m.created_by = ?
  AND m.action <> 'death'
  AND m.health_status <> 'Dead'
ORDER BY br.barangay_name ASC
";
$barangayListStmt = $conn->prepare($barangayListSql);
$barangayListStmt->bind_param("i", $tech_id);
$barangayListStmt->execute();
$barangayList = $barangayListStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$justSaved = isset($_GET['saved']) && $_GET['saved'] == '1';
$savedId = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : null;

$totalRecords = count($history);
$healthyCount = count(array_filter($history, fn($h) => stripos($h['health_status'] ?? '', 'healthy') !== false));
$sickCount = count(array_filter($history, fn($h) => in_array(strtolower(trim($h['health_status'] ?? '')), ['sick','critical'], true)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Monitoring History - PIGOBAO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<style>
:root{
--pg-forest-900:#123c16;
--pg-forest-800:#1b5e20;
--pg-forest-600:#2e7d32;
--pg-forest-300:#a5d6a7;
--pg-forest-100:#e8f5e9;
--pg-canvas:#f3f6f4;
--pg-ink:#233126;
--pg-ink-soft:#5d6b60;
--pg-line:#e2e9e3;
--pg-amber:#e8a33d;
--pg-sky:#3f7cac;
--pg-clay:#c1543f;
--shadow-soft:0 1px 2px rgba(18,60,22,.04),0 8px 24px -12px rgba(18,60,22,.16);
--shadow-lift:0 12px 28px -14px rgba(18,60,22,.28);
}

*{box-sizing:border-box;margin:0;padding:0;}

body{
min-height:100vh;
display:flex;
background:var(--pg-canvas);
background-image:
radial-gradient(1100px 480px at 100% -10%,rgba(46,125,50,.07),transparent 60%),
radial-gradient(700px 380px at -5% 100%,rgba(27,94,32,.05),transparent 55%);
font-family:'Poppins',sans-serif;
color:var(--pg-ink);
}

header{
position:fixed;
top:0;
left:0;
width:100%;
height:65px;
background:linear-gradient(120deg,var(--pg-forest-900),var(--pg-forest-800) 55%,var(--pg-forest-600));
color:#fff;
display:flex;
justify-content:space-between;
align-items:center;
padding:0 30px;
z-index:1001;
box-shadow:0 2px 14px rgba(10,40,12,.25);
}

header .brand{display:flex;align-items:center;gap:12px;}
header .brand .mark{
width:34px;
height:34px;
border-radius:9px;
background:rgba(255,255,255,.14);
display:flex;
align-items:center;
justify-content:center;
font-size:15px;
color:#eafbea;
border:1px solid rgba(255,255,255,.22);
}
header h1{font-size:17px;font-weight:600;letter-spacing:.2px;margin:0;}
header .brand small{
display:block;
font-size:11px;
font-weight:400;
color:rgba(255,255,255,.65);
letter-spacing:.4px;
text-transform:uppercase;
}
header a.logout{
color:var(--pg-forest-800);
background:#fff;
padding:8px 20px;
border-radius:20px;
font-weight:600;
text-decoration:none;
font-size:14px;
transition:.15s ease;
box-shadow:0 2px 8px rgba(0,0,0,.12);
}
header a.logout:hover{
background:var(--pg-forest-100);
transform:translateY(-1px);
}

.sidebar{
position:fixed;
top:65px;
left:0;
width:230px;
height:calc(100% - 65px);
background:linear-gradient(195deg,var(--pg-forest-800),var(--pg-forest-600) 120%);
padding-top:22px;
box-shadow:inset -1px 0 0 rgba(255,255,255,.06);
}

.sidebar a{
display:flex;
align-items:center;
gap:12px;
color:rgba(255,255,255,.88);
padding:13px 22px;
text-decoration:none;
margin:2px 12px;
border-radius:9px;
font-size:14.5px;
font-weight:500;
transition:.15s ease;
position:relative;
}
.sidebar a i{width:18px;text-align:center;font-size:15px;opacity:.9;}
.sidebar a:hover{background:rgba(255,255,255,.12);transform:translateX(2px);}
.sidebar a.active{
background:var(--pg-forest-300);
color:var(--pg-forest-900);
font-weight:600;
box-shadow:0 4px 12px rgba(0,0,0,.18);
}

.main-content{
margin-left:230px;
padding:98px 38px 40px;
flex:1;
width:auto;
}

.page-head{margin-bottom:22px;}
.page-head h2{
font-family:'Space Grotesk',sans-serif;
font-weight:700;
font-size:22px;
color:var(--pg-forest-900);
margin:0;
}
.page-head p{
margin:4px 0 0;
font-size:13.5px;
color:var(--pg-ink-soft);
}

.stat-row{
display:flex;
gap:16px;
margin-bottom:24px;
flex-wrap:wrap;
}

.stat-card{
background:#fff;
border:1px solid var(--pg-line);
border-radius:14px;
padding:16px 20px;
box-shadow:var(--shadow-soft);
flex:1;
min-width:180px;
display:flex;
align-items:center;
gap:14px;
transition:transform .15s ease,box-shadow .15s ease;
}

.stat-card:hover{
transform:translateY(-2px);
box-shadow:var(--shadow-lift);
}

.stat-card .icon{
width:42px;
height:42px;
border-radius:11px;
display:flex;
align-items:center;
justify-content:center;
font-size:17px;
flex-shrink:0;
background:var(--pg-forest-100);
color:var(--pg-forest-800);
}

.stat-card.sick .icon{background:#fbe9e7;color:#b3402c;}
.stat-card.healthy .icon{background:#e6f5e8;color:#1e7e34;}

.stat-card .num{
font-family:'Space Grotesk',sans-serif;
font-weight:700;
font-size:22px;
color:var(--pg-forest-900);
line-height:1;
}

.stat-card .label{
font-size:12px;
color:var(--pg-ink-soft);
margin-top:3px;
}

.success-banner{
background:#e6f5e8;
border:1px solid #bfe6c4;
color:#1e7e34;
padding:13px 18px;
border-radius:12px;
margin-bottom:20px;
display:flex;
align-items:center;
gap:10px;
font-size:14px;
font-weight:500;
animation:fadeSlide .3s ease;
}

@keyframes fadeSlide{
from{opacity:0;transform:translateY(-6px);}
to{opacity:1;transform:translateY(0);}
}

.search-card{
background:#fff;
border-radius:14px;
padding:16px 18px;
border:1px solid var(--pg-line);
box-shadow:var(--shadow-soft);
margin-bottom:24px;
}

.barangay-filter{
display:flex;
flex-wrap:wrap;
gap:8px;
margin-bottom:14px;
}

.brgy-pill{
display:inline-flex;
align-items:center;
gap:6px;
padding:7px 16px;
border-radius:999px;
font-size:13px;
font-weight:600;
text-decoration:none;
background:var(--pg-canvas);
color:var(--pg-ink-soft);
border:1px solid var(--pg-line);
transition:.15s ease;
}

.brgy-pill:hover{
background:var(--pg-forest-100);
color:var(--pg-forest-800);
border-color:var(--pg-forest-300);
}

.brgy-pill.active{
background:linear-gradient(120deg,var(--pg-forest-800),var(--pg-forest-600));
color:#fff;
border-color:transparent;
box-shadow:0 4px 10px -4px rgba(18,60,22,.4);
}

.search-card form{display:flex;gap:10px;}
.search-card .input-wrap{position:relative;flex:1;}
.search-card .input-wrap i{
position:absolute;
left:14px;
top:50%;
transform:translateY(-50%);
color:var(--pg-forest-600);
font-size:14px;
}

.search-card input{
width:100%;
padding:11px 14px 11px 40px;
border-radius:10px;
border:1px solid var(--pg-line);
font-size:14px;
font-family:'Poppins',sans-serif;
background:var(--pg-canvas);
color:var(--pg-ink);
transition:.15s ease;
}

.search-card input:focus{
outline:none;
border-color:var(--pg-forest-600);
background:#fff;
box-shadow:0 0 0 3px rgba(46,125,50,.15);
}

.search-card button{
border:none;
border-radius:10px;
padding:11px 22px;
font-weight:600;
font-size:14px;
background:linear-gradient(120deg,var(--pg-forest-800),var(--pg-forest-600));
color:#fff;
display:flex;
align-items:center;
gap:8px;
cursor:pointer;
box-shadow:0 8px 18px -8px rgba(18,60,22,.55);
transition:.15s ease;
}

.search-card button:hover{
transform:translateY(-2px);
box-shadow:var(--shadow-lift);
}

.table-card{
background:#fff;
border-radius:14px;
border:1px solid var(--pg-line);
box-shadow:var(--shadow-soft);
overflow:hidden;
}

.table-scroll{overflow-x:auto;}

table.history-table{
width:100%;
border-collapse:collapse;
font-size:13.5px;
min-width:1220px;
}

table.history-table thead th{
background:var(--pg-forest-900);
color:#fff;
font-weight:600;
text-align:left;
padding:13px 16px;
letter-spacing:.2px;
text-transform:uppercase;
font-size:11.5px;
white-space:nowrap;
}

table.history-table tbody td{
padding:12px 16px;
border-bottom:1px solid var(--pg-line);
color:var(--pg-ink);
vertical-align:middle;
}

table.history-table tbody tr:last-child td{border-bottom:none;}
table.history-table tbody tr:hover{background:var(--pg-forest-100);}
table.history-table tbody tr:nth-child(even){background:#fafcfa;}
table.history-table tbody tr:nth-child(even):hover{background:var(--pg-forest-100);}
table.history-table tbody tr.just-saved{
background:var(--pg-forest-100);
animation:highlightFade 2.5s ease forwards;
}

@keyframes highlightFade{
0%{background:#d5efd8;}
100%{background:transparent;}
}

table.history-table td.empty-row{
text-align:center;
color:var(--pg-ink-soft);
font-style:italic;
padding:26px;
}

.badge-barangay{
display:inline-flex;
align-items:center;
gap:6px;
background:var(--pg-forest-100);
color:var(--pg-forest-800);
padding:4px 10px;
border-radius:999px;
font-size:12px;
font-weight:600;
}

.badge-health{
display:inline-flex;
align-items:center;
padding:4px 10px;
border-radius:999px;
font-size:12px;
font-weight:600;
text-transform:capitalize;
}

.badge-health.healthy{background:#e6f5e8;color:#1e7e34;}
.badge-health.sick{background:#fbe9e7;color:#b3402c;}
.badge-health.observation{background:#fff5e0;color:#a86c00;}
.badge-health.other{background:#eef1ef;color:#5d6b60;}

.badge-pregnancy{
display:inline-flex;
align-items:center;
padding:4px 10px;
border-radius:999px;
font-size:12px;
font-weight:600;
}

.badge-pregnancy.pregnant{background:#e8f0fb;color:var(--pg-sky);}
.badge-pregnancy.not_pregnant{background:#eef1ef;color:var(--pg-ink-soft);}
.badge-pregnancy.gave_birth{background:#fdf1e0;color:var(--pg-amber);}
.badge-pregnancy.unknown{background:#eef1ef;color:var(--pg-ink-soft);}

.thumb{
width:54px;
height:54px;
border-radius:10px;
object-fit:cover;
border:1px solid var(--pg-line);
cursor:pointer;
transition:.15s ease;
}

.thumb:hover{
transform:scale(1.06);
box-shadow:var(--shadow-soft);
}

.no-photo{
font-size:12px;
color:var(--pg-ink-soft);
font-style:italic;
}

.remarks-cell{
max-width:220px;
white-space:normal;
line-height:1.4;
color:var(--pg-ink-soft);
}

#photoLightbox .modal-content{
background:transparent;
border:none;
box-shadow:none;
}

#photoLightbox img{
width:100%;
border-radius:14px;
box-shadow:0 20px 50px rgba(0,0,0,.5);
}

#photoLightbox .btn-close{
filter:invert(1) brightness(2);
position:absolute;
top:-40px;
right:0;
opacity:.9;
}

@media(max-width:900px){
.sidebar{width:200px;}
.main-content{margin-left:200px;padding:98px 20px 30px;}
.search-card form{flex-direction:column;}
.stat-row{flex-direction:column;}
}

@media(max-width:768px){
header{padding:0 15px;}
.brand h1{font-size:15px;}
.brand small{font-size:9px;}
.logout{padding:7px 14px;font-size:12px;}
.sidebar{
position:static;
width:100%;
height:auto;
margin-top:65px;
padding:8px;
display:flex;
flex-wrap:wrap;
gap:4px;
}
.sidebar a{margin:2px;padding:9px 10px;font-size:11px;}
.main-content{margin-left:0;padding:20px 15px 30px;}
}

/* ROW ACTIONS (edit / delete) */
.action-cell{white-space:nowrap;}
.btn-act{
display:inline-flex;align-items:center;justify-content:center;
width:34px;height:34px;border-radius:9px;
border:1px solid #e2e9e3;background:#fff;
cursor:pointer;font-size:13px;transition:.15s ease;margin-right:4px;
}
.btn-act.edit{color:#3f7cac;}
.btn-act.edit:hover{background:#e8f0fb;border-color:#b9d1e6;}
.btn-act.del{color:#b3402c;}
.btn-act.del:hover{background:#fbe9e7;border-color:#efc3ba;}

.error-banner{
background:#fbe9e7;border:1px solid #efc3ba;color:#b3402c;
padding:13px 18px;border-radius:12px;margin-bottom:20px;
display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500;
}
.crud-modal .modal-content{border:none;border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,.25);}
.crud-modal .modal-header{background:#123c16;color:#fff;border-radius:16px 16px 0 0;border:none;}
.crud-modal .modal-header .btn-close{filter:invert(1) brightness(2);}
.crud-modal .modal-title{font-size:16px;font-weight:600;}
.crud-modal label{font-size:12.5px;font-weight:600;color:#5d6b60;margin-bottom:4px;}
.crud-modal .form-control,.crud-modal .form-select{font-size:14px;border-radius:9px;border-color:#e2e9e3;}
.crud-modal .form-control:focus,.crud-modal .form-select:focus{border-color:#2e7d32;box-shadow:0 0 .2rem rgba(46,125,50,.25);}
.crud-modal .who{background:#e8f5e9;color:#1b5e20;border-radius:10px;padding:9px 12px;font-size:13px;margin-bottom:14px;}
.crud-modal .btn-save{background:#2e7d32;color:#fff;border:none;border-radius:9px;padding:8px 18px;font-weight:600;}
.crud-modal .btn-save:hover{background:#1b5e20;color:#fff;}
.crud-modal .btn-danger-solid{background:#c1543f;color:#fff;border:none;border-radius:9px;padding:8px 18px;font-weight:600;}
.crud-modal .btn-danger-solid:hover{background:#a5412e;color:#fff;}

/* ===== BENEFICIARY HISTORY BUTTON + MODAL ===== */
.btn-history{
display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 14px;margin-right:6px;
border-radius:8px;border:1.5px solid #1b5e20;background:#fff;color:#1b5e20;
font-family:'Poppins',sans-serif;font-size:12.5px;font-weight:600;cursor:pointer;
transition:.2s ease;vertical-align:middle;
}
.btn-history:hover{background:#1b5e20;color:#fff;transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(18,60,22,.5);}
.hist-summary{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:#f7faf7;border:1px solid #dce5de;border-left:5px solid #0b2f5b;border-radius:10px;padding:12px 16px;margin-bottom:16px;}
.hist-summary .hs-name{font-weight:700;color:#123c16;font-size:15px;}
.hist-summary .hs-sub{font-size:12.5px;color:#5d6b60;}
.hist-summary .hs-total{margin-left:auto;text-align:center;background:#123c16;color:#fff;border-radius:10px;padding:8px 18px;line-height:1.1;}
.hist-summary .hs-total b{display:block;font-family:'Space Grotesk',sans-serif;font-size:24px;}
.hist-summary .hs-total span{font-size:11px;letter-spacing:.5px;text-transform:uppercase;opacity:.85;}
.hist-list{list-style:none;margin:0;padding:0;position:relative;}
.hist-list::before{content:"";position:absolute;left:19px;top:6px;bottom:6px;width:2px;background:#dce5de;}
.hist-item{position:relative;display:flex;gap:14px;margin-bottom:14px;}
.hist-item:last-child{margin-bottom:0;}
.hist-num{flex:none;width:40px;height:40px;border-radius:50%;background:linear-gradient(145deg,#2e7d32,#123c16);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:14px;box-shadow:0 0 0 4px #fff;z-index:1;}
.hist-card{flex:1;min-width:0;border:1px solid #dce5de;border-radius:10px;padding:12px 14px;background:#fff;}
.hist-card .hc-top{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;}
.hist-card .hc-ord{font-weight:700;color:#123c16;font-size:14px;}
.hist-card .hc-date{font-size:12.5px;color:#5d6b60;}
.hist-card .hc-tags{display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
.hist-card .hc-meta{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:13px;color:#233126;margin-top:8px;}
.hist-card .hc-meta span em{font-style:normal;color:#5d6b60;margin-right:4px;}
.hist-card .hc-remarks{margin-top:8px;font-size:12.5px;color:#5d6b60;line-height:1.4;}
.hist-card .hc-photo{margin-top:10px;}
.hist-card .hc-photo img{width:64px;height:64px;object-fit:cover;border-radius:10px;border:1px solid #dce5de;}
.hist-state{text-align:center;color:#5d6b60;padding:26px 10px;font-size:14px;}

/* ===== FARMER PROFILE PHOTO ===== */
.farmer-cell{display:flex;align-items:center;gap:12px;}
.farmer-cell > div{min-width:0;}
.farmer-avatar{
    position:relative;display:inline-flex;align-items:center;justify-content:center;
    flex:none;width:42px;height:42px;border-radius:50%;overflow:hidden;
    background:linear-gradient(145deg,#2e7d32,#123c16);color:#fff;
    font-weight:700;font-size:15px;line-height:1;vertical-align:middle;
    box-shadow:0 0 0 2px #fff,0 4px 10px -4px rgba(18,60,22,.55);
}
.farmer-avatar img{
    position:absolute;inset:0;width:100%;height:100%;object-fit:cover;cursor:zoom-in;
    transition:transform .2s ease;
}
.farmer-avatar img:hover{transform:scale(1.08);}
.modal-farmer{display:flex;align-items:center;gap:10px;}
.modal-farmer input{flex:1;min-width:0;}
.pg-photo-view{
    position:fixed;inset:0;z-index:5000;background:rgba(10,30,12,.78);
    display:flex;align-items:center;justify-content:center;padding:20px;
    animation:pgFade .15s ease;cursor:zoom-out;
}
.pg-photo-view figure{position:relative;margin:0;text-align:center;}
.pg-photo-view img{
    max-width:min(90vw,520px);max-height:78vh;border-radius:16px;object-fit:contain;
    background:#fff;box-shadow:0 24px 60px -20px rgba(0,0,0,.6);border:4px solid #fff;
}
.pg-photo-view figcaption{color:#fff;font-weight:600;margin-top:12px;font-size:15px;}
.pg-photo-view button{
    position:absolute;top:-14px;right:-14px;width:34px;height:34px;border-radius:50%;
    border:none;background:#fff;color:#123c16;font-size:20px;line-height:1;cursor:pointer;
    box-shadow:0 4px 12px rgba(0,0,0,.3);
}
@keyframes pgFade{from{opacity:0}to{opacity:1}}

/* =========================================================
   GOVERNMENT PORTAL THEME (matches the dashboards)
   Screen only - PHP, JS and forms are untouched.
========================================================= */
.gov-strip,.gov-footer{display:none;}
@media screen{
:root{
--gov-navy:#0b2f5b;
--gov-gold:#c9a227;
--gov-red:#b3262e;
--gv-line:#dce5de;
--gv-ink:#1f2b22;
--gv-soft:#4f5d53;
--gv-shadow:0 1px 2px rgba(18,60,22,.05),0 10px 26px -14px rgba(18,60,22,.22);
--strip-h:32px;
--header-h:70px;
--top-offset:calc(var(--strip-h) + var(--header-h));
}
body{background:#f3f6f4;background-image:none;color:var(--gv-ink);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased;}

/* ---------- government strip ---------- */
.gov-strip{
display:flex;position:fixed;top:0;left:0;right:0;height:var(--strip-h);
background:#0a1f3d;color:#dbe5f3;align-items:center;justify-content:space-between;
padding:0 30px;font-size:12px;letter-spacing:.3px;z-index:1003;
border-bottom:3px solid transparent;
border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;
}
.gov-strip .left{display:flex;align-items:center;gap:8px;}
.gov-strip .left i{color:var(--gov-gold);}
.gov-strip .right{opacity:.85;}
.gov-strip strong{color:#fff;font-weight:600;}

/* ---------- header ---------- */
header{
top:var(--strip-h);height:var(--header-h);padding:0 30px;z-index:1002;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 4px 16px rgba(10,40,12,.28);
}
header .brand{display:flex;align-items:center;gap:14px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:#1b5e20;}
header .brand .titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
header .brand .titles h1{font-family:'Merriweather',serif;font-size:19px;font-weight:700;letter-spacing:1.2px;line-height:1.15;margin:0;color:#fff;}
header .brand .titles small{display:block;font-size:12px;font-weight:400;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}
header a.logout{
color:#123c16;background:#fff;padding:9px 22px;border-radius:8px;font-weight:600;font-size:14px;
text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.14);transition:.2s ease;
}
header a.logout:hover{background:#e8f5e9;transform:translateY(-1px);color:#123c16;}

/* ---------- sidebar ---------- */
.sidebar{
top:var(--top-offset);width:250px;height:calc(100% - var(--top-offset));
background:linear-gradient(195deg,#164f1b,#1b5e20 45%,#2e7d32 130%);
padding-top:14px;overflow-y:auto;z-index:1000;
}
.sidebar .menu-title{font-size:11.5px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.55);padding:6px 26px 8px;}
.sidebar a{display:flex;align-items:center;margin:2px 12px 3px;padding:12px 20px;font-size:15px;gap:13px;border-radius:8px;border-left:4px solid transparent;color:rgba(255,255,255,.92);}
.sidebar a i{width:20px;text-align:center;font-size:15.5px;}
.sidebar a.active{background:#fff;color:#123c16;border-left:4px solid var(--gov-gold);font-weight:600;box-shadow:0 6px 16px rgba(0,0,0,.2);}
.sidebar a.active i{color:#123c16;}

/* ---------- full-width content ---------- */
.main-content{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
flex:1;width:auto;min-width:0;min-height:100vh;
display:flex;flex-direction:column;
}

/* ---------- page banner ---------- */
.page-head,.profile-card{
background:#fff;border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;
padding:22px 26px;box-shadow:var(--gv-shadow);margin-bottom:26px;position:relative;overflow:hidden;
display:flex;align-items:center;gap:18px;flex-wrap:wrap;
}
.profile-card::before{display:none;}
.page-head-content{display:flex;align-items:center;gap:18px;flex:1;min-width:0;}
.page-icon,.profile-card .avatar-badge{
width:60px;height:60px;border-radius:50%;flex:none;font-size:22px;color:#fff;
display:flex;align-items:center;justify-content:center;
background:linear-gradient(145deg,#2e7d32,#123c16);box-shadow:0 0 0 4px #e8f5e9;
}
.page-date{font-size:12.5px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--gov-navy);margin-bottom:2px;display:flex;align-items:center;gap:7px;}
.page-head h2,.profile-info h2{font-family:'Space Grotesk',sans-serif;font-size:23px;font-weight:700;color:#123c16;margin:0 0 3px;}
.page-head p,.profile-info p{font-size:14px;color:var(--gv-soft);margin:0;max-width:700px;}
.page-text{flex:1;min-width:0;}
.role-pill{
margin-left:auto;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#123c16;color:#fff;
display:inline-flex;align-items:center;gap:8px;letter-spacing:.3px;
}
.role-pill::before{display:none;}
.role-pill i{color:var(--gov-gold);}

/* ---------- section label ---------- */
.section-label{
font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
color:#123c16;display:flex;align-items:center;gap:10px;
}
.section-label::before{content:"";width:5px;height:20px;border-radius:3px;background:linear-gradient(180deg,var(--gov-gold),#2e7d32);}

/* ---------- alerts ---------- */
.success-banner,.alert-success-custom{border-radius:10px;border-left:5px solid #1b5e20;font-size:14px;}
.error-banner,.alert-error-custom{border-radius:10px;border-left:5px solid var(--gov-red);font-size:14px;}

/* ---------- stat cards ---------- */
.stat-row,.stats{gap:18px;margin-bottom:26px;}
.stat-row .stat-card,.stats .stat-card{
border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;
padding:20px 22px;box-shadow:var(--gv-shadow);
}
.stat-row .stat-card:hover,.stats .stat-card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.stat-card.healthy,.stat-card.normal{border-top-color:#1b5e20;}
.stat-card.sick{border-top-color:#b7791f;}
.stat-card.neglected{border-top-color:#9b2c2c;}
.stat-card .num,.stat-value{font-size:32px;line-height:1.1;}
.stat-card .label,.stat-label{font-size:12.5px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;}
.stat-grid .stat-card{border-radius:12px;box-shadow:var(--gv-shadow);}
.stat-body h3{font-size:12.5px;font-family:'Space Grotesk',sans-serif;letter-spacing:.7px;font-weight:700;}
.stat-body .value{font-size:34px;}

/* ---------- filters / search ---------- */
.search-card,.table-card{
border:1px solid var(--gv-line);border-radius:12px;box-shadow:var(--gv-shadow);background:#fff;
}
.table-card{border-top:5px solid var(--gov-navy);}
.search-card{border-left:6px solid #1b5e20;}
.search-card input,.search-wrap input{font-size:14px;border-radius:8px;border:1.5px solid #d3ddd5;}
.search-card input:focus,.search-wrap input:focus{border-color:#2e7d32;box-shadow:0 0 0 3px rgba(46,125,50,.15);outline:none;}
.brgy-pill{font-size:13px;font-weight:600;border-radius:8px;padding:7px 14px;border:1.5px solid #cfd9d1;transition:.2s ease;}
.brgy-pill:hover{border-color:#1b5e20;color:#1b5e20;}
.brgy-pill.active{background:#123c16;border-color:#123c16;color:#fff;box-shadow:inset 0 -3px 0 var(--gov-gold);}
.filter-bar{border-radius:10px;border:1px solid var(--gv-line);}
.filter-bar label{font-size:12.5px;}
.filter-bar select{font-size:14px;background:#f3f6f4;border:1px solid var(--gv-line);border-radius:7px;padding:6px 10px;}

/* ---------- buttons ---------- */
.search-card button,.search-btn,.btn-save,.crud-modal .btn-save,
.btn-cancel,.btn-danger-solid,.crud-modal .btn-danger-solid,.crud-modal .btn-light,
.btn-act,.action-btn{
font-family:'Poppins',sans-serif;letter-spacing:.3px;
transition:background .2s ease,transform .2s ease,box-shadow .2s ease,border-color .2s ease,color .2s ease;
}
.search-card button:focus-visible,.search-btn:focus-visible,.btn-save:focus-visible,.btn-cancel:focus-visible,
.btn-danger-solid:focus-visible,.btn-act:focus-visible,.action-btn:focus-visible,.brgy-pill:focus-visible{
outline:2px solid var(--gov-gold);outline-offset:2px;
}
.search-card button,.search-btn{
border-radius:8px;padding:11px 24px;font-size:14px;font-weight:600;
background:linear-gradient(120deg,#123c16,#1b5e20);
box-shadow:0 4px 12px -4px rgba(18,60,22,.5);
}
.search-card button:hover,.search-btn:hover{transform:translateY(-1px);box-shadow:0 8px 16px -6px rgba(18,60,22,.55);}

/* row action icons (edit / delete) */
.btn-act{width:38px;height:38px;border-radius:8px;font-size:14px;border-width:1.5px;background:#fff;}
.btn-act.edit{color:var(--gov-navy);border-color:var(--gov-navy);}
.btn-act.edit:hover{background:var(--gov-navy);color:#fff;border-color:var(--gov-navy);transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(11,47,91,.5);}
.btn-act.del{color:var(--gov-red);border-color:var(--gov-red);}
.btn-act.del:hover{background:var(--gov-red);color:#fff;border-color:var(--gov-red);transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(179,38,46,.5);}

/* return / transfer row buttons */
.action-buttons{gap:8px;}
.action-btn{padding:9px 16px;font-size:12.5px;border-radius:8px;border:1.5px solid transparent;background:#fff;}
.return-btn{color:var(--gov-red);border-color:var(--gov-red);background:#fff;}
.return-btn:hover{background:var(--gov-red);color:#fff;transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(179,38,46,.5);}
.transfer-btn{color:var(--gov-navy);border-color:var(--gov-navy);background:#fff;}
.transfer-btn:hover{background:var(--gov-navy);color:#fff;transform:translateY(-1px);box-shadow:0 8px 14px -6px rgba(11,47,91,.5);}

/* modal footer buttons */
.btn-save,.crud-modal .btn-save{
border:0;border-radius:8px;padding:10px 24px;font-size:13.5px;font-weight:600;color:#fff;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 6px 14px -6px rgba(18,60,22,.6);
display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.btn-save:hover,.crud-modal .btn-save:hover{background:linear-gradient(120deg,#0d2e10,#123c16);color:#fff;transform:translateY(-1px);box-shadow:0 10px 18px -8px rgba(18,60,22,.65);}
.btn-save i{color:var(--gov-gold);}
.btn-danger-solid,.crud-modal .btn-danger-solid{
border:0;border-radius:8px;padding:10px 24px;font-size:13.5px;font-weight:600;color:#fff;
background:linear-gradient(120deg,#8f1c23,var(--gov-red));
box-shadow:0 6px 14px -6px rgba(179,38,46,.6);
display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.btn-danger-solid:hover,.crud-modal .btn-danger-solid:hover{background:linear-gradient(120deg,#761319,#8f1c23);color:#fff;transform:translateY(-1px);}
.btn-cancel,.crud-modal .btn-light{
border:1.5px solid #cfd9d1;border-radius:8px;background:#fff;color:#4f5d53;
padding:10px 22px;font-size:13.5px;font-weight:600;
}
.btn-cancel:hover,.crud-modal .btn-light:hover{background:#f3f6f4;border-color:#9fb0a3;color:#233126;}

/* ---------- tables ---------- */
table.history-table thead th,table.success-table thead th,.data-table thead th{
background:#123c16;color:#fff;font-family:'Space Grotesk',sans-serif;font-size:11.5px;font-weight:600;
letter-spacing:.6px;text-transform:uppercase;padding:13px 16px;white-space:nowrap;
}
table.history-table tbody td,table.success-table tbody td,.data-table tbody td{font-size:13.5px;padding:12px 16px;}
table.history-table tbody tr:nth-child(even):not(.just-saved),
table.success-table tbody tr:nth-child(even),
.data-table tbody tr:nth-child(even){background:#f8fbf8;}
table.history-table tbody tr:hover:not(.just-saved),
table.success-table tbody tr:hover,
.data-table tbody tr:hover{background:#e8f5e9;}
.table-card-head{background:#fbfdfb;border-bottom:1px dashed var(--gv-line);padding:16px 22px;}
.record-count{border-radius:6px;font-size:12px;}
.badge-barangay,.badge-health,.badge-pregnancy,.badge-death,.status-badge,.badge{font-size:11.5px;}
.remarks-cell,.cause-cell,.sub-text{font-size:12.5px;}
.empty-state,td.empty-row{font-size:14px;}

/* ---------- modals ---------- */
.modal-backdrop.show{opacity:.55;background:#0a1f3d;}
.modal-content{
border:1px solid #dce5de;border-radius:12px;overflow:hidden;
box-shadow:0 30px 70px -20px rgba(6,20,10,.55);
}
.crud-modal .modal-content{border:1px solid #dce5de;border-radius:12px;box-shadow:0 30px 70px -20px rgba(6,20,10,.55);overflow:hidden;}
.modal-header,.crud-modal .modal-header{
padding:18px 24px;border:0;border-radius:0;color:#fff;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
border-bottom:4px solid transparent;
border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;
}
.modal-title,.crud-modal .modal-title{
display:flex;align-items:center;gap:12px;font-family:'Space Grotesk',sans-serif;
font-size:17px;font-weight:700;letter-spacing:.3px;
}
.modal-title i{
width:38px;height:38px;border-radius:50%;background:#fff;color:#1b5e20;flex:none;
display:inline-flex;align-items:center;justify-content:center;font-size:15px;
box-shadow:0 0 0 2px rgba(255,255,255,.35);
}
.modal-header .btn-close{opacity:.9;background-color:rgba(255,255,255,.14);border-radius:8px;padding:10px;margin:0;}
.modal-header .btn-close:hover{opacity:1;background-color:rgba(255,255,255,.28);}
.modal-body{padding:24px;}
.modal-footer,.crud-modal .modal-footer{padding:16px 24px;gap:10px;background:#f7faf7;border-top:1px dashed #cfd9d1 !important;}
.modal-body label,.form-label,.crud-modal label{
font-family:'Space Grotesk',sans-serif;font-size:11.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;
color:#123c16;margin-bottom:7px;
}
.form-control,.form-select,.crud-modal .form-control,.crud-modal .form-select{
font-size:13.5px;padding:10px 13px;border:1.5px solid #d3ddd5;border-radius:8px;min-height:42px;
transition:border-color .2s ease,box-shadow .2s ease;
}
.form-control:focus,.form-select:focus,.crud-modal .form-control:focus,.crud-modal .form-select:focus{
border-color:#2e7d32;box-shadow:0 0 0 3px rgba(46,125,50,.15);
}
.form-control[readonly]{background:#f3f6f4;color:#4f5d53;}
.crud-modal .who,.info-box{background:#f7faf7;border:1px solid #dce5de;border-left:5px solid var(--gov-navy);border-radius:10px;color:#123c16;font-size:13.5px;}
.info-box-title{font-size:12.5px;}
.info-box-text{font-size:12.5px;}
.selected-item{font-size:13px;}

/* ---------- footer ---------- */
.gov-footer{display:block;margin-top:auto;padding-top:30px;}
.gov-footer .inner{border-top:1px solid var(--gv-line);padding-top:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:12.5px;color:var(--gv-soft);}

/* ---------- responsive ---------- */
@media(max-width:1000px){
.stat-grid{grid-template-columns:repeat(2,1fr);}
.stat-grid .stat-card.wide,.stat-grid .stat-card.narrow{grid-column:auto;}
}
@media(max-width:900px){
.sidebar{width:215px;}
.main-content{margin-left:215px;padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media(max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
body{flex-direction:column;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header{padding:0 14px;}
header .brand .titles h1{font-size:15px;letter-spacing:.8px;}
header .brand .titles small{display:block;font-size:10px;}
.seal{width:38px;height:38px;}
header .brand .titles{padding-left:10px;}
header a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;display:flex;flex-wrap:wrap;gap:3px;}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a span{display:inline !important;}
.sidebar a:hover{transform:none;}
.main-content{margin-left:0;width:100%;padding:18px 15px;min-height:auto;}
.page-head,.profile-card{padding:18px;margin-bottom:20px;}
.page-head h2,.profile-info h2{font-size:19px;}
.role-pill{margin-left:0;}
.modal-header,.crud-modal .modal-header{padding:14px 16px;}
.modal-title{font-size:15px;}
.modal-body{padding:16px;}
.modal-footer{padding:12px 16px;}
}
@media(max-width:520px){
.stat-grid{grid-template-columns:1fr;}
.modal-footer .btn-save,.modal-footer .btn-cancel,.modal-footer .btn-danger-solid,.modal-footer .btn-light{flex:1;}
}
}
@media print{
.gov-strip,.gov-footer,.role-pill,.page-date{display:none !important;}
}
</style>
</head>
<body>

<div class="gov-strip">
    <div class="left">
        <i class="fas fa-landmark"></i>
        <span>An official website of the <strong>Municipality of Cabatuan, Iloilo</strong></span>
    </div>
    <div class="right">Republic of the Philippines &bull; Municipal Agriculture Office</div>
</div>

<header>
    <div class="brand">
        <div class="seal">
            <img src="../img/CABATUAN.png" alt="Municipality of Cabatuan seal" onerror="this.parentNode.classList.add('fallback');this.outerHTML='<i class=&quot;fas fa-paw&quot;></i>'">
        </div>
        <div class="seal">
            <img src="../img/DA.png" alt="Department of Agriculture seal" onerror="this.parentNode.style.display='none'">
        </div>
        <div class="titles">
            <h1>PIGOBAO</h1>
            <small>Monitoring History &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../tech_logout.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
<a href="../technician_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
<a href="assign_beneficiaries.php"><i class="fas fa-user-check"></i> Assign Beneficiaries</a>
<a href="monitoring.php"><i class="fas fa-stethoscope"></i> Monitoring</a>
<a href="monitoring_history.php" class="active"><i class="fas fa-history"></i> Monitoring History</a>
<a href="farmer_success_rate.php"><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
<a href="mortality_cases.php"><i class="fas fa-skull-crossbones"></i> Mortality Cases</a>
<a href="livestock_return.php"><i class="fas fa-undo"></i> Livestock Return</a>
<a href="tech_reports.php"><i class="fas fa-file-alt"></i> Reports</a>
<a href="generate_report.php" ><i class="fas fa-file-pdf"></i> Generate Report</a>
</div>

<div class="main-content">

<div class="page-head">
<div class="page-icon"><i class="fas fa-history"></i></div>
<div class="page-text">
<div class="page-date"><i class="fas fa-calendar-day"></i> <?=date('l, F j, Y')?></div>
<h2>Monitoring History</h2>
<p>All successful and active monitoring records are shown here. Mortality cases are managed separately.</p>
</div>
<span class="role-pill"><i class="fas fa-user-gear"></i> Livestock Technician</span>
</div>

<?php if($justSaved): ?>
<div class="success-banner">
<i class="fas fa-circle-check"></i>
Monitoring record saved successfully<?= $savedId ? ' (ID #'.$savedId.')' : '' ?>.
</div>
<?php endif; ?>

<?php if($flashOk): ?>
<div class="success-banner"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if($flashErr): ?>
<div class="error-banner"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="stat-row">
<div class="stat-card">
<div class="icon"><i class="fas fa-clipboard-list"></i></div>
<div>
<div class="num"><?= $totalRecords ?></div>
<div class="label">Active Records<?= $search !== '' ? ' (filtered)' : '' ?></div>
</div>
</div>
<div class="stat-card healthy">
<div class="icon"><i class="fas fa-heart-pulse"></i></div>
<div>
<div class="num"><?= $healthyCount ?></div>
<div class="label">Healthy</div>
</div>
</div>
<div class="stat-card sick">
<div class="icon"><i class="fas fa-triangle-exclamation"></i></div>
<div>
<div class="num"><?= $sickCount ?></div>
<div class="label">Sick / Critical</div>
</div>
</div>
</div>

<div class="barangay-filter">
<a
    href="?barangay=0&search=<?= urlencode($search) ?>"
    class="brgy-pill <?= $barangayFilter === 0 ? 'active' : '' ?>"
>
    <i class="fas fa-layer-group"></i> All Barangays
</a>
<?php foreach($barangayList as $br): ?>
<a
    href="?barangay=<?= (int)$br['barangay_id'] ?>&search=<?= urlencode($search) ?>"
    class="brgy-pill <?= $barangayFilter === (int)$br['barangay_id'] ? 'active' : '' ?>"
>
    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($br['barangay_name']) ?>
</a>
<?php endforeach; ?>
</div>

<div class="search-card">
<form method="GET">
<input type="hidden" name="barangay" value="<?= (int)$barangayFilter ?>">
<div class="input-wrap">
<i class="fas fa-search"></i>
<input type="text" name="search" placeholder="Search beneficiary, barangay, or livestock" value="<?= htmlspecialchars($search) ?>">
</div>
<button type="submit"><i class="fas fa-search"></i> Search</button>
</form>
</div>

<div class="table-card">
<div class="table-scroll">
<table class="history-table">
<thead>
<tr>
<th>Beneficiary</th>
<th>Barangay</th>
<th>Eartag no</th>
<th>Kind</th>
<th>Status</th>
<th>Health Status</th>
<th>Weight</th>
<th>Pregnancy</th>
<th>Total Offspring</th>
<th>Offspring Alive</th>
<th>Offspring Dead</th>
<th>Photo</th>
<th>Remarks</th>
<th>Date</th>
<th>Actions</th>
</tr>
</thead>
<tbody>

<?php if(count($history) === 0): ?>

<tr>
<td class="empty-row" colspan="15">
No active monitoring records found<?= $search !== '' ? ' for "'.htmlspecialchars($search).'"' : '' ?>.
</td>
</tr>

<?php else: ?>

<?php foreach($history as $m): ?>

<?php
$healthRaw = strtolower(trim($m['health_status'] ?? ''));
$healthClass = 'other';

if(strpos($healthRaw,'healthy') !== false){
$healthClass = 'healthy';
}elseif(strpos($healthRaw,'sick') !== false){
$healthClass = 'sick';
}elseif(strpos($healthRaw,'observ') !== false){
$healthClass = 'observation';
}

$pregRaw = strtolower(trim($m['pregnancy_status'] ?? ''));
$pregClass = in_array($pregRaw,['pregnant','not_pregnant','gave_birth']) ? $pregRaw : 'unknown';

$pregLabels = [
'pregnant'=>'Pregnant',
'not_pregnant'=>'Not Pregnant',
'gave_birth'=>'Gave Birth'
];

$pregLabel = $pregLabels[$pregRaw] ?? '—';
$rowClass = ($savedId && (int)$m['monitor_id'] === $savedId) ? 'just-saved' : '';
?>

<tr class="<?= $rowClass ?>">

<td>
<?php
$fullName = trim($m['firstname']." ".($m['middlename'] ? $m['middlename']." " : "").$m['lastname']);
?>
<div class="farmer-cell">
<?= pg_farmer_avatar($m['farmer_photo'] ?? null, $fullName) ?>
<div><?= htmlspecialchars($fullName) ?></div>
</div>
</td>

<td>
<span class="badge-barangay">
<i class="fas fa-map-marker-alt"></i>
<?= htmlspecialchars($m['barangay_name']) ?>
</span>
</td>

<td>
<?= htmlspecialchars($m['livestock_name']) ?>
</td>

<td>
<?= htmlspecialchars($m['kind']) ?>
</td>

<td>
<span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#e6f5e8;color:#1e7e34;font-size:12px;font-weight:600;">
<?= htmlspecialchars($m['livestock_status'] ?: 'active') ?>
</span>
</td>

<td>
<span class="badge-health <?= $healthClass ?>">
<?= htmlspecialchars($m['health_status'] ?: '—') ?>
</span>
</td>

<td>
<?= ($m['weight'] !== null && $m['weight'] !== '') ? htmlspecialchars($m['weight']).' kg' : '—' ?>
</td>

<td>
<span class="badge-pregnancy <?= $pregClass ?>">
<?= $pregLabel ?>
</span>
</td>

<td>
<strong><?= (int)($m['offspring'] ?? 0) ?></strong>
</td>

<td>
<span style="color:#1e7e34;font-weight:600;"><?= (int)($m['offspring_alive'] ?? 0) ?></span>
</td>

<td>
<span style="color:#b3262e;font-weight:600;"><?= (int)($m['offspring_dead'] ?? 0) ?></span>
</td>

<td>
<?php if(!empty($m['photo'])): ?>
<img
src="../uploads/<?= htmlspecialchars($m['photo']) ?>"
alt="Monitoring photo"
class="thumb"
data-bs-toggle="modal"
data-bs-target="#photoLightbox"
onclick="document.getElementById('lightboxImg').src=this.src;"
>
<?php else: ?>
<span class="no-photo">No photo</span>
<?php endif; ?>
</td>

<td class="remarks-cell">
<?= !empty($m['remarks']) ? htmlspecialchars($m['remarks']) : '—' ?>
</td>

<td>
<?= htmlspecialchars($m['monitor_date']) ?>
</td>

<td class="action-cell">
<?php
$rowData = [
    'id'      => (int)$m['monitor_id'],
    'who'     => $fullName . ' — ' . $m['livestock_name'] . ' (' . $m['kind'] . ')',
    'health'  => $m['health_status'],
    'weight'  => $m['weight'],
    'preg'    => $m['pregnancy_status'],
    'off'     => $m['offspring'],
    'off_alive' => (int)($m['offspring_alive'] ?? 0),
    'off_dead'  => (int)($m['offspring_dead'] ?? 0),
    'remarks' => $m['remarks'],
    'date'    => substr((string)$m['monitor_date'], 0, 10),
    'photo'   => $m['photo']
];
$rowJson = htmlspecialchars(json_encode($rowData), ENT_QUOTES, 'UTF-8');
?>
<button type="button" class="btn-history" title="View monitoring history of this beneficiary"
    data-bid="<?= (int)$m['beneficiary_id'] ?>"
    data-name="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
    data-brgy="<?= htmlspecialchars($m['barangay_name'], ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-clock-rotate-left"></i> History</button>
<button type="button" class="btn-act edit" title="Edit" data-record="<?= $rowJson ?>"><i class="fas fa-pen"></i></button>
<button type="button" class="btn-act del" title="Delete" data-record="<?= $rowJson ?>"><i class="fas fa-trash"></i></button>
</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>
</div>

<div class="gov-footer">
    <div class="inner">
        <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
        <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
    </div>
</div>
</div>

<div class="modal fade" id="photoLightbox" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
<img id="lightboxImg" src="" alt="Monitoring photo enlarged">
</div>
</div>
</div>

<div class="modal fade crud-modal" id="editModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg">
<div class="modal-content">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
<input type="hidden" name="return_barangay" value="<?= (int)$barangayFilter ?>">
<input type="hidden" name="crud_action" value="edit">
<input type="hidden" name="record_id" id="editId">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-pen"></i> Edit monitoring record</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<div class="who" id="editWho"></div>
<div class="row g-3">
<div class="col-md-6">
<label for="editHealth">Health status</label>
<select class="form-select" name="health_status" id="editHealth" required>
<option value="Healthy">Healthy</option>
<option value="Sick">Sick</option>
<option value="Under Observation">Under Observation</option>
<option value="Critical">Critical</option>
</select>
</div>
<div class="col-md-6">
<label for="editWeight">Weight (kg)</label>
<input type="number" step="0.01" min="0" class="form-control" name="weight" id="editWeight">
</div>
<div class="col-md-6">
<label for="editPreg">Pregnancy</label>
<select class="form-select" name="pregnancy_status" id="editPreg">
<option value="">—</option>
<option value="pregnant">Pregnant</option>
<option value="not_pregnant">Not Pregnant</option>
<option value="gave_birth">Gave Birth</option>
</select>
</div>
<div class="col-md-4">
<label for="editOffAlive">Offspring Alive</label>
<input type="number" min="0" step="1" class="form-control" name="offspring_alive" id="editOffAlive" oninput="editOffTotal()">
</div>
<div class="col-md-4">
<label for="editOffDead">Offspring Dead</label>
<input type="number" min="0" step="1" class="form-control" name="offspring_dead" id="editOffDead" oninput="editOffTotal()">
</div>
<div class="col-md-4">
<label for="editOff">Total Offspring</label>
<input type="number" class="form-control" id="editOff" readonly>
</div>
<div class="col-md-6">
<label for="editDate">Date</label>
<input type="date" class="form-control" name="monitor_date" id="editDate" required>
</div>
<div class="col-md-6">
<label for="editPhoto">Replace photo <span style="font-weight:400;">(optional)</span></label>
<input type="file" class="form-control" name="photo" id="editPhoto" accept="image/jpeg,image/png,image/webp">
<div class="form-text" id="editPhotoNote"></div>
</div>
<div class="col-12">
<label for="editRemarks">Remarks</label>
<textarea class="form-control" name="remarks" id="editRemarks" rows="3"></textarea>
</div>
</div>
</div>
<div class="modal-footer border-0">
<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn-save"><i class="fas fa-floppy-disk"></i> Save changes</button>
</div>
</form>
</div>
</div>
</div>

<div class="modal fade crud-modal" id="deleteModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form method="POST">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
<input type="hidden" name="return_barangay" value="<?= (int)$barangayFilter ?>">
<input type="hidden" name="crud_action" value="delete">
<input type="hidden" name="record_id" id="delId">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-trash"></i> Delete record</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<p class="mb-2">Are you sure you want to delete this record?</p>
<div class="who" id="delWho"></div>
<p class="mb-0" style="font-size:13px;color:#5d6b60;">This cannot be undone. The record and its photo will be removed permanently.</p>
</div>
<div class="modal-footer border-0">
<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn-danger-solid"><i class="fas fa-trash"></i> Yes, delete</button>
</div>
</form>
</div>
</div>
</div>

<div class="modal fade crud-modal" id="historyModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="fas fa-clock-rotate-left"></i> Monitoring history</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body" id="historyBody">
<div class="hist-state">Loading...</div>
</div>
<div class="modal-footer border-0">
<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
if(window.location.search.includes('saved=1')){
setTimeout(function(){
const url=new URL(window.location);
url.searchParams.delete('saved');
url.searchParams.delete('monitor_id');
window.history.replaceState({},'',url);
},4000);
}
</script>

<script>
function editOffTotal(){
    var a=parseInt(document.getElementById('editOffAlive').value,10)||0;
    var d=parseInt(document.getElementById('editOffDead').value,10)||0;
    document.getElementById('editOff').value=a+d;
}

document.querySelectorAll('.btn-act.edit').forEach(function(btn){
    btn.addEventListener('click',function(){
        const r=JSON.parse(btn.dataset.record);
        document.getElementById('editId').value=r.id;
        document.getElementById('editWho').textContent=r.who;

        const sel=document.getElementById('editHealth');
        const cur=(r.health||'').trim();
        const match=Array.from(sel.options).find(function(o){return o.value.toLowerCase()===cur.toLowerCase();});
        if(match){sel.value=match.value;}
        else if(cur){sel.add(new Option(cur,cur));sel.value=cur;}

        document.getElementById('editWeight').value=r.weight??'';
        document.getElementById('editPreg').value=r.preg||'';
        document.getElementById('editOffAlive').value=r.off_alive??0;
        document.getElementById('editOffDead').value=r.off_dead??0;
        editOffTotal();
        document.getElementById('editDate').value=r.date||'';
        document.getElementById('editRemarks').value=r.remarks||'';
        document.getElementById('editPhoto').value='';
        document.getElementById('editPhotoNote').textContent=r.photo?'Current photo is kept if you leave this empty.':'No photo yet.';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
    });
});

document.querySelectorAll('.btn-act.del').forEach(function(btn){
    btn.addEventListener('click',function(){
        const r=JSON.parse(btn.dataset.record);
        document.getElementById('delId').value=r.id;
        document.getElementById('delWho').textContent=r.who;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteModal')).show();
    });
});
</script>


<script>
/* Click a farmer's profile picture to view it larger */
document.addEventListener('click', function(e){
    var img = e.target.closest ? e.target.closest('.farmer-avatar img, .modal-farmer .farmer-avatar img') : null;
    if(!img || !img.getAttribute('src')) return;
    var ov = document.createElement('div'); ov.className = 'pg-photo-view';
    var fig = document.createElement('figure');
    var big = document.createElement('img'); big.src = img.src; big.alt = img.alt || '';
    var cap = document.createElement('figcaption'); cap.textContent = img.alt || '';
    var x = document.createElement('button'); x.type = 'button'; x.innerHTML = '&times;'; x.setAttribute('aria-label','Close');
    fig.appendChild(big); fig.appendChild(cap); fig.appendChild(x); ov.appendChild(fig);
    function close(){ ov.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(ev){ if(ev.key === 'Escape') close(); }
    ov.addEventListener('click', close); document.addEventListener('keydown', onKey);
    document.body.appendChild(ov);
});
</script>
<script>
/* Beneficiary monitoring history: 1st, 2nd, 3rd... + total count */
(function(){
    function esc(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function ordinal(n){
        var s = ['th','st','nd','rd'], v = n % 100;
        return n + (s[(v - 20) % 10] || s[v] || s[0]);
    }
    function healthClass(h){
        h = String(h || '').toLowerCase();
        if(h.indexOf('healthy') !== -1) return 'healthy';
        if(h.indexOf('sick') !== -1 || h.indexOf('critical') !== -1) return 'sick';
        if(h.indexOf('observ') !== -1) return 'observation';
        return 'other';
    }
    var pregLabels = {pregnant:'Pregnant', not_pregnant:'Not Pregnant', gave_birth:'Gave Birth'};

    var body = document.getElementById('historyBody');

    document.querySelectorAll('.btn-history').forEach(function(btn){
        btn.addEventListener('click', function(){
            var bid = btn.dataset.bid, name = btn.dataset.name, brgy = btn.dataset.brgy;
            body.innerHTML = '<div class="hist-state"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('historyModal')).show();

            fetch('?history_for=' + encodeURIComponent(bid), {headers:{'Accept':'application/json'}})
                .then(function(r){ if(!r.ok) throw new Error(); return r.json(); })
                .then(function(d){
                    var html = '<div class="hist-summary">' +
                        '<div><div class="hs-name">' + esc(name) + '</div>' +
                        '<div class="hs-sub"><i class="fas fa-map-marker-alt"></i> ' + esc(brgy) + '</div></div>' +
                        '<div class="hs-total"><b>' + d.total + '</b><span>Time' + (d.total === 1 ? '' : 's') + ' monitored</span></div>' +
                        '</div>';

                    if(!d.records.length){
                        body.innerHTML = html + '<div class="hist-state">No monitoring records yet.</div>';
                        return;
                    }

                    html += '<ul class="hist-list">';
                    d.records.forEach(function(r){
                        var pregHtml = r.preg && pregLabels[r.preg]
                            ? '<span class="badge-pregnancy ' + esc(r.preg) + '">' + pregLabels[r.preg] + '</span>' : '';
                        html += '<li class="hist-item">' +
                            '<div class="hist-num">' + r.n + '</div>' +
                            '<div class="hist-card">' +
                                '<div class="hc-top">' +
                                    '<span class="hc-ord">' + ordinal(r.n) + ' monitoring</span>' +
                                    '<span class="hc-date"><i class="fas fa-calendar-day"></i> ' + esc(r.date) + '</span>' +
                                '</div>' +
                                '<div class="hc-tags">' +
                                    '<span class="badge-health ' + healthClass(r.health) + '">' + esc(r.health || '—') + '</span>' +
                                    pregHtml +
                                '</div>' +
                                '<div class="hc-meta">' +
                                    '<span><em>Eartag no:</em>' + esc(r.eartag) + '</span>' +
                                    '<span><em>Kind:</em>' + esc(r.kind) + '</span>' +
                                    '<span><em>Weight:</em>' + (r.weight !== null ? esc(r.weight) + ' kg' : '—') + '</span>' +
                                    '<span><em>Total Offspring:</em>' + esc(r.off) + '</span>' +
                                    '<span><em>Alive:</em>' + esc(r.off_alive) + '</span>' +
                                    '<span><em>Dead:</em>' + esc(r.off_dead) + '</span>' +
                                '</div>' +
                                (r.remarks ? '<div class="hc-remarks"><em>Remarks:</em> ' + esc(r.remarks) + '</div>' : '') +
                                (r.photo ? '<div class="hc-photo"><a href="../uploads/' + encodeURIComponent(r.photo) + '" target="_blank" rel="noopener"><img src="../uploads/' + encodeURIComponent(r.photo) + '" alt="Monitoring photo" loading="lazy"></a></div>' : '') +
                            '</div>' +
                        '</li>';
                    });
                    html += '</ul>';
                    body.innerHTML = html;
                })
                .catch(function(){
                    body.innerHTML = '<div class="hist-state">Could not load the history. Please try again.</div>';
                });
        });
    });
})();
</script>
</body>
</html>