<?php
session_start();
require '../db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

$message = "";
$messageType = "info";
$allowed_kinds  = ['goat', 'pig', 'carabao'];
$allowed_status = ['available', 'dead'];
$allowed_sex    = ['Male', 'Female'];

/**
 * Generates the next batch number, e.g. "0001", "0002", ...
 * Based on the highest existing numeric batch_no in the table.
 */
function generateNextBatchNo($conn) {
    $result = $conn->query("SELECT MAX(CAST(batch_no AS UNSIGNED)) AS max_batch FROM livestock");
    $row = $result ? $result->fetch_assoc() : null;
    $next = ($row && $row['max_batch']) ? intval($row['max_batch']) + 1 : 1;
    return str_pad($next, 4, '0', STR_PAD_LEFT);
}

/**
 * Builds the Nth eartag after a starting eartag.
 * "0007" + 2  => "0009"   (numeric: keeps the zero padding)
 * "PIG-A" + 2 => "PIG-A-3" (non numeric: adds a suffix)
 */
function eartagAtOffset($startTag, $offset) {
    if($offset === 0) return $startTag;
    if(ctype_digit($startTag)) {
        $next = intval($startTag) + $offset;
        return str_pad($next, strlen($startTag), '0', STR_PAD_LEFT);
    }
    return $startTag . '-' . ($offset + 1);
}

/** TRUE if the eartag is already used by another livestock. */
function eartagExists($conn, $tag) {
    $stmt = $conn->prepare("SELECT 1 FROM livestock WHERE batch_no=? LIMIT 1");
    $stmt->bind_param("s", $tag);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function livestockAge($birthDate) {
    if(empty($birthDate)) return '—';
    try {
        $birth = new DateTime($birthDate);
        $today = new DateTime('today');
        if($birth > $today) return '—';
        $diff = $birth->diff($today);
        return $diff->y . ' yr' . ($diff->y === 1 ? '' : 's') . ' ' . $diff->m . ' mo';
    } catch(Exception $e) {
        return '—';
    }
}

// Handle add livestock
if(isset($_POST['add_livestock'])) {
    $batch_no = trim($_POST['batch_no']);
    $kind = $_POST['kind'];
    $breed = trim($_POST['breed']);
    $sex = in_array($_POST['sex'] ?? '', $allowed_sex, true) ? $_POST['sex'] : 'Male';
    $birth_date = trim($_POST['birth_date'] ?? '');
    $birth_date = $birth_date !== '' ? $birth_date : NULL;
    if($birth_date !== null && $birth_date > date('Y-m-d')) {
        $birth_date = date('Y-m-d');
    }
    $status = in_array($_POST['status'] ?? 'available', $allowed_status, true) ? $_POST['status'] : 'available';

    // How many identical heads to record in one go
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    if($quantity < 1)   $quantity = 1;
    if($quantity > 200) $quantity = 200;

    if($batch_no == "" || !in_array($kind, $allowed_kinds, true)) {
        $message = "Please provide a valid eartag no and kind.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO livestock (batch_no, kind, breed, sex, birth_date, status) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("ssssss", $tag, $kind, $breed, $sex, $birth_date, $status);

        $inserted = 0;
        $skipped  = [];
        $first    = null;
        $last     = null;
        $error    = "";

        for($i = 0, $offset = 0; $i < $quantity; $i++, $offset++) {
            // Find the next free eartag (skips ones already taken)
            $tag = eartagAtOffset($batch_no, $offset);
            $guard = 0;
            while(eartagExists($conn, $tag) && $guard < 500) {
                $skipped[] = $tag;
                $offset++;
                $guard++;
                $tag = eartagAtOffset($batch_no, $offset);
            }

            if($stmt->execute()) {
                $inserted++;
                if($first === null) $first = $tag;
                $last = $tag;
            } else {
                $error = $stmt->error;
                break;
            }
        }
        $stmt->close();

        if($error !== "") {
            $message = "Error after adding {$inserted} livestock: " . $error;
            $messageType = "danger";
        } elseif($inserted === 1) {
            $message = "Livestock {$first} added successfully!";
            $messageType = "success";
        } else {
            $message = "{$inserted} livestock added successfully (eartag {$first} to {$last}).";
            $messageType = "success";
        }
        if(!empty($skipped)) {
            $message .= " Skipped already-used eartag: " . implode(', ', array_slice($skipped, 0, 8)) . ".";
        }
    }
}

// Handle update livestock
if(isset($_POST['update_livestock'])) {
    $id = intval($_POST['livestock_id']);
    $batch_no = trim($_POST['batch_no']);
    $kind = $_POST['kind'];
    $breed = trim($_POST['breed']);
    $sex = in_array($_POST['sex'] ?? '', $allowed_sex, true) ? $_POST['sex'] : 'Male';
    $birth_date = trim($_POST['birth_date'] ?? '');
    $birth_date = $birth_date !== '' ? $birth_date : NULL;
    if($birth_date !== null && $birth_date > date('Y-m-d')) {
        $birth_date = date('Y-m-d');
    }
    $status = in_array($_POST['status'] ?? 'available', $allowed_status, true) ? $_POST['status'] : 'available';

    if($batch_no == "" || !in_array($kind, $allowed_kinds, true)) {
        $message = "Please provide a valid batch no and kind.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE livestock SET batch_no=?, kind=?, breed=?, sex=?, birth_date=?, status=? WHERE livestock_id=?");
        $stmt->bind_param("ssssssi", $batch_no, $kind, $breed, $sex, $birth_date, $status, $id);
        if($stmt->execute()){
            $message = "Livestock updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error: " . $stmt->error;
            $messageType = "danger";
        }
    }
}

// Handle delete livestock
if(isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM distributions WHERE livestock_id=?");
    $check->bind_param("i", $id);
    $check->execute();
    $used = (int)($check->get_result()->fetch_assoc()['cnt'] ?? 0);
    $check->close();

    if($used > 0) {
        $message = "This livestock cannot be deleted because it already has distribution records.";
        $messageType = "danger";
    } else {
        $stmt = $conn->prepare("DELETE FROM livestock WHERE livestock_id=?");
        $stmt->bind_param("i", $id);
        if($stmt->execute()) {
            $message = "Livestock deleted successfully!";
            $messageType = "success";
        } else {
            $message = "Error: " . $stmt->error;
            $messageType = "danger";
        }
        $stmt->close();
    }
}

/* =========================================================
   HANDLE DISTRIBUTION
   (merged in from distribution.php so livestock can be
   distributed straight from this page, no extra menu.)
========================================================= */
if(isset($_POST['distribute'])) {

    $beneficiary_id = intval($_POST['beneficiary_id']);
    $livestock_id   = intval($_POST['livestock_id']);
    // Quantity is always exactly 1 — one distribution record is tied to
    // one specific animal/eartag. Hardcoded here (not trusted from the
    // client) so it can never be edited or submitted as anything else.
    $quantity       = 1;
    $date           = $_POST['distribution_date'] ?? "";
    $location       = trim($_POST['distribution_location'] ?? "");
    $remarks        = trim($_POST['remarks'] ?? "");

    if(
        $beneficiary_id <= 0 ||
        $livestock_id <= 0 ||
        $quantity < 1 ||
        $date === ""
    ) {

        $message = "Please fill out all required fields before confirming a distribution.";
        $messageType = "danger";

    } else {

        // Find technician assigned to the beneficiary's barangay
        $techLookup = $conn->prepare("
            SELECT tb.technician_id
            FROM beneficiaries b
            JOIN technician_barangay tb
                ON tb.barangay_id = b.barangay_id
            JOIN technicians t
                ON t.technician_id = tb.technician_id
            WHERE b.beneficiary_id = ?
            LIMIT 1
        ");
        $techLookup->bind_param("i", $beneficiary_id);
        $techLookup->execute();
        $techRow = $techLookup->get_result()->fetch_assoc();
        $tech_id = $techRow ? (int)$techRow['technician_id'] : 0;
        $techLookup->close();

        // Check livestock availability (batch no always sourced from the
        // livestock record itself, never typed by hand)
        $checkStock = $conn->prepare("
            SELECT status, batch_no
            FROM livestock
            WHERE livestock_id = ?
            LIMIT 1
        ");
        $checkStock->bind_param("i", $livestock_id);
        $checkStock->execute();
        $stockRow = $checkStock->get_result()->fetch_assoc();
        $checkStock->close();
        $batch_no = $stockRow ? $stockRow['batch_no'] : null;

        // Check beneficiary hasn't already received a distribution
        $checkExisting = $conn->prepare("
            SELECT distribution_id
            FROM distributions
            WHERE beneficiary_id = ?
              AND status = 'active'
            LIMIT 1
        ");
        $checkExisting->bind_param("i", $beneficiary_id);
        $checkExisting->execute();
        $existingRow = $checkExisting->get_result()->fetch_assoc();
        $checkExisting->close();

        if(!$tech_id) {

            $message = "No technician is assigned to this beneficiary's barangay yet. Assign one first under Assign Technician.";
            $messageType = "danger";

        } elseif(!$stockRow || $stockRow['status'] !== 'available') {

            $message = "That livestock is no longer available. Please choose another.";
            $messageType = "danger";

        } elseif($existingRow) {

            $message = "This beneficiary has already received a livestock distribution.";
            $messageType = "danger";

        } else {

            $conn->begin_transaction();
            $ok = true;

            try {

                $stmt = $conn->prepare("
                    INSERT INTO distributions
                    (technician_id, beneficiary_id, livestock_id, quantity, distribution_date, distribution_location, batch_no, remarks, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->bind_param(
                    "iiiissss",
                    $tech_id, $beneficiary_id, $livestock_id, $quantity,
                    $date, $location, $batch_no, $remarks
                );
                $stmt->execute();
                $newDistributionId = $stmt->insert_id;
                $stmt->close();

                $update = $conn->prepare("UPDATE livestock SET status = 'distributed' WHERE livestock_id = ?");
                $update->bind_param("i", $livestock_id);
                $update->execute();
                $update->close();

                // Auto-fulfill a pending renewal for this beneficiary, if any
                $renewalLookup = $conn->prepare("
                    SELECT renewal_id FROM renewals
                    WHERE beneficiary_id = ? AND status = 'pending'
                    ORDER BY created_at DESC LIMIT 1
                ");
                $renewalLookup->bind_param("i", $beneficiary_id);
                $renewalLookup->execute();
                $pendingRenewal = $renewalLookup->get_result()->fetch_assoc();
                $renewalLookup->close();

                if($pendingRenewal) {
                    $fulfillRenewal = $conn->prepare("
                        UPDATE renewals SET new_distribution_id = ?, status = 'fulfilled'
                        WHERE renewal_id = ?
                    ");
                    $fulfillRenewal->bind_param("ii", $newDistributionId, $pendingRenewal['renewal_id']);
                    $fulfillRenewal->execute();
                    $fulfillRenewal->close();
                }

            } catch(mysqli_sql_exception $e) {
                $ok = false;
                error_log("Distribution insert failed: ".$e->getMessage());
            }

            if($ok) {
                $conn->commit();
                $message = "Livestock distributed successfully!";
                $messageType = "success";
            } else {
                $conn->rollback();
                $message = "Something went wrong while recording the distribution. Please try again.";
                $messageType = "danger";
            }
        }
    }
}

// Fetch inventory — every "available" animal gets its own row (no more
// merging same kind/breed/sex/birth date into one grouped row). Distributed,
// dead, and retired records are dropped off this list entirely.
$inventory = $conn->query("SELECT * FROM livestock WHERE status = 'available' ORDER BY livestock_id DESC");

$rows = [];
$breedSet = [];
if($inventory && $inventory->num_rows > 0) {
    while($r = $inventory->fetch_assoc()) {
        $rows[] = $r;
        if(!empty($r['breed'])) {
            $breedSet[$r['breed']] = true;
        }
    }
}
$breedList = array_keys($breedSet);
sort($breedList, SORT_NATURAL | SORT_FLAG_CASE);

// Breeds actually in inventory, grouped per kind — used to make the
// breed filter dropdown follow whichever kind is currently selected.
$breedsByKind = ['pig' => [], 'goat' => [], 'carabao' => []];
foreach($rows as $r) {
    if(!empty($r['breed']) && isset($breedsByKind[$r['kind']])) {
        $breedsByKind[$r['kind']][$r['breed']] = true;
    }
}
foreach($breedsByKind as $k => $set) {
    $breedsByKind[$k] = array_keys($set);
    sort($breedsByKind[$k], SORT_NATURAL | SORT_FLAG_CASE);
}

// Beneficiaries for the Distribute modal — excludes anyone who already
// has an active distribution, so they disappear once they've received one.
$beneficiaries = $conn->query("
    SELECT
        b.*,
        br.barangay_name,
        r.renewal_id AS pending_renewal_id
    FROM beneficiaries b
    JOIN barangays br
        ON b.barangay_id = br.barangay_id
    LEFT JOIN distributions d
        ON d.beneficiary_id = b.beneficiary_id
        AND d.status = 'active'
    LEFT JOIN renewals r
        ON r.beneficiary_id = b.beneficiary_id
        AND r.status = 'pending'
    WHERE d.distribution_id IS NULL
    ORDER BY (r.renewal_id IS NULL) ASC, b.beneficiary_id ASC
");

// Default batch no for the Add form
$nextBatchNo = generateNextBatchNo($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Livestock - PIGOBAO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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
  --pg-amber:#c98a1f;
  --pg-sky:#3f7cac;
  --pg-clay:#c1543f;
  --pg-plum:#7c5cbf;
  --shadow-soft:0 1px 2px rgba(18,60,22,0.04), 0 8px 24px -12px rgba(18,60,22,0.16);
  --shadow-lift:0 14px 30px -14px rgba(18,60,22,0.30);
}

* { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }

.form-hint{
    font-size:12px;color:var(--pg-ink-soft);
    background:var(--pg-forest-100);border:1px solid var(--pg-line);
    border-radius:8px;padding:10px 12px;margin-bottom:16px;line-height:1.5;
}

body {
    min-height: 100vh;
    display: flex;
    background: var(--pg-canvas);
    background-image:
        radial-gradient(1100px 480px at 100% -10%, rgba(46,125,50,0.07), transparent 60%),
        radial-gradient(700px 380px at -5% 100%, rgba(27,94,32,0.05), transparent 55%);
    color: var(--pg-ink);
}

/* HEADER */
header {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 65px;
    background: linear-gradient(120deg, var(--pg-forest-900), var(--pg-forest-800) 55%, var(--pg-forest-600));
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 30px;
    z-index: 1001;
    box-shadow: 0 2px 14px rgba(10,40,12,0.25);
}
header .brand{display:flex;align-items:center;gap:12px;}
header .brand .mark{
    width:34px;height:34px;border-radius:9px;
    background:rgba(255,255,255,0.14);
    display:flex;align-items:center;justify-content:center;
    font-size:15px;color:#eafbea;
    border:1px solid rgba(255,255,255,0.22);
}
header h1 { font-size: 17px; font-weight: 600; letter-spacing:.2px; }
header .brand small{display:block;font-size:11px;font-weight:400;color:rgba(255,255,255,0.65);letter-spacing:.4px;text-transform:uppercase;}
header a.logout {
    color: var(--pg-forest-800);
    background: #fff;
    padding: 8px 20px;
    border-radius: 20px;
    font-weight: 600;
    text-decoration: none;
    font-size: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    transition: 0.2s ease;
}
header a.logout:hover {
    background: var(--pg-forest-100);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.16);
}

/* SIDEBAR */
.sidebar {
    position: fixed;
    top: 65px;
    left: 0;
    width: 240px;
    height: calc(100% - 65px);
    background: linear-gradient(195deg, var(--pg-forest-800), var(--pg-forest-600) 120%);
    padding-top: 22px;
    box-shadow: inset -1px 0 0 rgba(255,255,255,0.06);
}
.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    color: rgba(255,255,255,0.88);
    padding: 13px 22px;
    text-decoration: none;
    margin: 2px 12px 4px;
    border-radius: 9px;
    border-left: 3px solid transparent;
    font-size: 14.5px;
    font-weight: 500;
    transition: 0.2s ease;
    position: relative;
}
.sidebar a i{width:18px;text-align:center;font-size:15px;opacity:.9;}
.sidebar a:hover {
    background: rgba(255,255,255,0.12);
    transform: translateX(2px);
}
.sidebar a.active {
    background: var(--pg-forest-300);
    color: var(--pg-forest-900);
    border-left: 3px solid var(--pg-forest-900);
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0,0,0,0.18);
}
.sidebar a.active i{opacity:1;}

/* MAIN */
.main-content {
    margin-left: 240px;
    padding: 100px 38px 40px;
    flex: 1;
    width: auto;
}

/* PAGE HEADER STRIP */
.page-card{
    background:#fff;border-radius:16px;padding:26px 30px;
    box-shadow:var(--shadow-soft);border:1px solid var(--pg-line);
    display:flex;align-items:center;gap:22px;flex-wrap:wrap;margin-bottom:28px;
    position:relative;overflow:hidden;
}
.page-card::before{
    content:"";position:absolute;top:0;left:0;height:5px;width:100%;
    background:linear-gradient(90deg, var(--pg-forest-800), var(--pg-forest-300));
}
.page-icon{
    width:60px;height:60px;border-radius:16px;flex:none;
    background:linear-gradient(145deg, var(--pg-forest-600), var(--pg-forest-900));
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:22px;
    box-shadow:0 8px 18px -6px rgba(18,60,22,0.5);
}
.page-info h2{
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:21px;
    margin:0 0 4px;color:var(--pg-forest-900);
}
.page-info p{
    font-size:13.5px;color:var(--pg-ink-soft);margin:0;
}
.add-btn{
    margin-left:auto;
    background: linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
    color:#fff;border:none;padding:11px 22px;border-radius:10px;
    font-weight:600;font-size:14px;letter-spacing:.2px;
    display:inline-flex;align-items:center;gap:8px;
    box-shadow:0 6px 16px -6px rgba(18,60,22,0.5);
    transition:0.2s ease;cursor:pointer;
}
.add-btn:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 20px -6px rgba(18,60,22,0.55);
    color:#fff;
}

.section-label{
    font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:600;
    letter-spacing:.6px;text-transform:uppercase;color:var(--pg-ink-soft);
    margin:0 0 14px 2px;
}

/* ALERT */
.pg-alert{
    background:var(--pg-forest-100);color:var(--pg-forest-900);
    border:1px solid var(--pg-forest-300);border-left:4px solid var(--pg-forest-600);
    border-radius:10px;padding:13px 18px;margin-bottom:20px;
    font-size:14px;font-weight:500;display:flex;align-items:center;gap:10px;
}
.pg-alert.danger{background:#fbeceb;color:#8a2e22;border-color:#f3d3d0;border-left-color:var(--pg-clay);}
.pg-alert.info{background:#eaf2f8;color:#2c536e;border-color:#d4e6f0;border-left-color:var(--pg-sky);}

/* FILTER BAR */
.kind-filter-bar{
    display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 16px 2px;
}
.kind-filter-btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:9px 18px;border-radius:999px;
    border:1px solid var(--pg-line);
    background:#fff;color:var(--pg-ink-soft);
    font-size:13.5px;font-weight:600;
    cursor:pointer;transition:.2s ease;
    font-family:'Poppins',sans-serif;
}
.kind-filter-btn:hover{
    border-color:var(--pg-forest-300);
    color:var(--pg-forest-800);
    transform:translateY(-1px);
}
.kind-filter-btn.active{
    background:var(--pg-forest-800);
    border-color:var(--pg-forest-800);
    color:#fff;
    box-shadow:0 6px 14px -6px rgba(18,60,22,0.45);
}
.breed-filter-wrap{
    display:inline-flex;align-items:center;gap:8px;
    margin-left:auto;
}
.breed-filter-wrap label{
    font-size:13px;font-weight:600;color:var(--pg-ink-soft);
    text-transform:uppercase;letter-spacing:.3px;
}
.breed-filter-wrap select{
    min-width:190px;border-radius:999px;
    border:1px solid var(--pg-line);
    padding:9px 16px;font-size:13.5px;font-weight:500;
    color:var(--pg-ink);background-color:#fff;
    font-family:'Poppins',sans-serif;
}
.breed-filter-wrap select:focus{
    outline:none;border-color:var(--pg-forest-600);
    box-shadow:0 0 0 3px rgba(46,125,50,0.14);
}

/* TABLE CARD */
.table-card{
    background:#fff;border-radius:16px;border:1px solid var(--pg-line);
    box-shadow:var(--shadow-soft);overflow:hidden;
}
.table-responsive{width:100%;overflow-x:auto;}
table.pg-table{
    width:100%;border-collapse:collapse;font-size:14px;
}
table.pg-table thead th{
    background:var(--pg-forest-900);
    color:#eafbea;
    font-family:'Space Grotesk',sans-serif;
    font-weight:600;font-size:12.5px;
    text-transform:uppercase;letter-spacing:.4px;
    padding:15px 18px;text-align:left;
    border:none;
    white-space:nowrap;
}
table.pg-table tbody td{
    padding:14px 18px;
    border-bottom:1px solid var(--pg-line);
    color:var(--pg-ink);
    vertical-align:middle;
    white-space:nowrap;
}
table.pg-table tbody tr:hover{
    background:var(--pg-forest-100);
}
table.pg-table tbody tr:last-child td{border-bottom:none;}
table.pg-table tbody td.empty-row{
    white-space:normal;text-align:center;padding:30px 18px;
    color:var(--pg-ink-soft);font-style:italic;
}

.status-pill{
    padding:5px 13px;border-radius:999px;font-size:12px;font-weight:600;
    letter-spacing:.3px;display:inline-flex;align-items:center;gap:6px;
}
.status-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;}
.status-pill.status-available{background:#e8f5e9;color:var(--pg-forest-800);}
.status-pill.status-distributed{background:#e8eefb;color:#315d9a;}
.status-pill.status-dead{background:#fbeceb;color:var(--pg-clay);}
.status-pill.status-retired{background:#f0edf8;color:var(--pg-plum);}

.kind-pill{
    padding:5px 13px;border-radius:999px;font-size:12px;font-weight:600;
    letter-spacing:.3px;background:#fdf3e3;color:var(--pg-amber);text-transform:capitalize;
}

.action-btn{
    border:none;border-radius:8px;padding:7px 13px;font-size:12.5px;font-weight:600;
    display:inline-flex;align-items:center;gap:6px;text-decoration:none;
    transition:0.2s ease;cursor:pointer;
}
.action-btn.edit{background:var(--pg-forest-100);color:var(--pg-forest-800);}
.action-btn.edit:hover{background:var(--pg-forest-300);color:var(--pg-forest-900);}
.action-btn.delete{background:#fbeceb;color:var(--pg-clay);}
.action-btn.delete:hover{background:var(--pg-clay);color:#fff;}
.action-btn.distribute{background:#eaf2f8;color:var(--pg-sky);}
.action-btn.distribute:hover{background:var(--pg-sky);color:#fff;}

/* MODALS */
.modal-content{
    border-radius:16px;border:none;overflow:hidden;
    box-shadow:var(--shadow-lift);
}
.modal-header{
    background:linear-gradient(120deg, var(--pg-forest-900), var(--pg-forest-800) 55%, var(--pg-forest-600));
    color:#fff;border:none;padding:18px 24px;
}
.modal-header .modal-title{
    font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:16px;
    display:flex;align-items:center;gap:10px;
}
.modal-header .btn-close{filter:invert(1) brightness(2);}
.modal-body{padding:24px;background:var(--pg-canvas);}
.modal-body .form-label{
    font-size:13px;font-weight:600;color:var(--pg-ink-soft);margin-bottom:6px;
    text-transform:uppercase;letter-spacing:.3px;
}
.modal-body .form-control, .modal-body select.form-control{
    border:1px solid var(--pg-line);border-radius:9px;padding:10px 14px;
    font-size:14px;
}
.modal-body .form-control:focus{
    border-color:var(--pg-forest-600);
    box-shadow:0 0 0 3px rgba(46,125,50,0.14);
}
.modal-footer{border:none;padding:16px 24px 22px;background:var(--pg-canvas);}
.modal-footer .btn-success{
    background:linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
    border:none;border-radius:9px;padding:10px 20px;font-weight:600;font-size:14px;
    box-shadow:0 6px 16px -6px rgba(18,60,22,0.5);
}
.modal-footer .btn-success:hover{transform:translateY(-1px);}

/* RESPONSIVE */
@media(max-width:900px){
    .sidebar{ width:200px; }
    .main-content{ margin-left:200px; padding:98px 24px 30px; }
}
@media(max-width:768px) {
    header{ padding:0 16px; }
    header h1{ font-size:15px; }
    header a.logout{ padding:7px 14px; font-size:12.5px; }

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
    .sidebar a{ margin:2px; padding:9px 12px; font-size:11.5px; }
    .sidebar a:hover{ transform:none; }

    .main-content { margin-left: 0; padding: 20px 16px 30px; }
    .add-btn{margin-left:0;}
    .breed-filter-wrap{margin-left:0;width:100%;}
    .breed-filter-wrap select{flex:1;}
}

/* =========================================================
   GOVERNMENT PORTAL THEME  (matches reports.php)
   Screen only - print layouts are untouched.
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

/* government strip */
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

/* header */
header{
top:var(--strip-h);height:var(--header-h);padding:0 30px;z-index:1002;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 4px 16px rgba(10,40,12,.28);
}
header .brand{display:flex;align-items:center;gap:14px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:#1b5e20;}
.brand .titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
.brand .titles h1{font-family:'Merriweather',serif;font-size:19px;font-weight:700;letter-spacing:1.2px;line-height:1.15;margin:0;color:#fff;}
.brand .titles small{display:block;font-size:12px;font-weight:400;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}
header a.logout{
color:#123c16;background:#fff;padding:9px 22px;border-radius:8px;font-weight:600;font-size:14px;
text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.14);transition:.2s ease;
}
header a.logout:hover{background:#e8f5e9;transform:translateY(-1px);}

/* sidebar */
.sidebar{
top:var(--top-offset);width:250px;height:calc(100% - var(--top-offset));
background:linear-gradient(195deg,#164f1b,#1b5e20 45%,#2e7d32 130%);
padding-top:14px;overflow-y:auto;z-index:1000;
}
.sidebar .menu-title{font-size:11.5px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.55);padding:6px 26px 8px;}
.sidebar a{margin:2px 12px 3px;padding:12px 20px;font-size:15px;gap:13px;border-radius:8px;border-left:4px solid transparent;color:rgba(255,255,255,.92);}
.sidebar a i{width:20px;font-size:15.5px;}
.sidebar a.active{background:#fff;color:#123c16;border-left:4px solid var(--gov-gold);font-weight:600;box-shadow:0 6px 16px rgba(0,0,0,.2);}

/* full-width content area */
.main-content{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
max-width:none;width:auto;min-height:100vh;
display:flex;flex-direction:column;
}

/* page banner */
.page-card,.page-head.page-card{
background:#fff;border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;
padding:22px 26px;gap:18px;box-shadow:var(--gv-shadow);margin-bottom:26px;position:relative;overflow:hidden;
display:flex;align-items:center;flex-wrap:wrap;justify-content:flex-start;
}
.page-card::before{display:none;}
.page-card::after{
content:"\f200";font-family:"Font Awesome 6 Free";font-weight:900;position:absolute;right:-8px;bottom:-30px;
font-size:120px;color:#e8f5e9;z-index:0;pointer-events:none;
}
.page-card > *{position:relative;z-index:1;}
.page-icon{display:flex;align-items:center;justify-content:center;flex:none;color:#fff;width:60px;height:60px;border-radius:50%;font-size:22px;box-shadow:0 0 0 4px #e8f5e9;background:linear-gradient(145deg,#2e7d32,#123c16);}
.page-date{font-size:12.5px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--gov-navy);margin-bottom:2px;display:flex;align-items:center;gap:7px;}
.page-info h2,.page-card h2{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:23px;margin:0 0 3px;color:#123c16;}
.page-info p,.page-card .page-info > p{font-size:14px;color:var(--gv-soft);max-width:680px;margin-bottom:0;}
.role-pill{
margin-left:auto;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#123c16;color:#fff;
display:inline-flex;align-items:center;gap:8px;letter-spacing:.3px;
}
.role-pill i{color:var(--gov-gold);}
.page-card .add-btn,.page-card .head-actions{margin-left:auto;}

/* section headings */
.section-label{
font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
color:#123c16;display:flex;align-items:center;gap:10px;margin:0 0 14px;
}
.section-label::before{content:"";width:5px;height:20px;border-radius:3px;background:linear-gradient(180deg,var(--gov-gold),#2e7d32);}

/* cards, tables, panels */
.table-card,.profile-panel,.filter-card,.stat-card{
border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);background:#fff;
}
.profile-panel::before{display:none;}
.stat-card.inactive-stat{border-top-color:var(--gov-red);}
.stat-card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.filter-card{padding:16px 22px;}
.stat-row{gap:18px;margin-bottom:26px;}
table.pg-table thead th,table.livestock-table thead th{
background:#123c16;color:#fff;font-size:12px;letter-spacing:.6px;padding:13px 16px;
}
table.pg-table tbody td,table.livestock-table tbody td{padding:12px 16px;}
table.pg-table tbody tr:nth-child(even),table.livestock-table tbody tr:nth-child(even){background:#f8fbf8;}
table.pg-table tbody tr:hover,table.livestock-table tbody tr:hover{background:#e8f5e9;}
.b-card{border:1px solid var(--gv-line);border-top:4px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
.b-card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.total-badge{border-radius:8px;border:1px solid #cfdcee;background:#e6eef8;}
.total-badge i,.total-badge .num{color:var(--gov-navy);}
.back-link{border-radius:8px;border-color:var(--gv-line);}
.status-tab{border-radius:8px;}
.add-btn{border-radius:8px;}

/* modals */
.modal-content{border-radius:12px;border:1px solid var(--gv-line);border-top:5px solid var(--gov-gold);overflow:hidden;}
.pg-alert{border-radius:8px;border-left-width:5px;}

/* footer */
.gov-footer{display:block;margin-top:auto;padding-top:30px;}
.gov-footer .inner{border-top:1px solid var(--gv-line);padding-top:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:12.5px;color:var(--gv-soft);}
}
@media screen and (max-width:900px){
.sidebar{width:215px;}
.main-content{margin-left:215px;padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media screen and (max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header{padding:0 14px;}
.brand .titles h1{font-size:15px;letter-spacing:.8px;}
.brand .titles small{font-size:10px;}
.seal{width:38px;height:38px;}
.brand .titles{padding-left:10px;}
header a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;display:flex;flex-wrap:wrap;gap:3px;}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a:hover{transform:none;}
.main-content{margin-left:0;padding:18px 15px;min-height:auto;}
.page-card{padding:18px;margin-bottom:20px;}
.page-info h2,.page-card h2{font-size:19px;}
.role-pill{margin-left:0;}
}
@media print{
.gov-strip,.gov-footer,.role-pill,.page-date{display:none !important;}
}

/* =========================================================
   RESPONSIVE - same behaviour as admin_dashboard.php
   (menu moves to the top as a wrapped row of buttons)
========================================================= */
@media screen and (max-width:768px){
body{flex-direction:column;}
.sidebar{
position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;
display:flex;flex-wrap:wrap;gap:3px;overflow:visible;transform:none;
}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a:hover{transform:none;}
.main-content{margin-left:0;width:100%;padding:18px 15px;min-height:auto;}
header .brand{min-width:0;}
.brand .titles{min-width:0;}
.brand .titles small{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46vw;}
header a.logout{flex:none;}
.gov-strip .left{min-width:0;white-space:nowrap;overflow:hidden;}
.gov-strip .left span{overflow:hidden;text-overflow:ellipsis;}
}
@media screen and (max-width:480px){
.brand .seal + .seal{display:none;}
.brand .titles small{display:none;}
header a.logout{padding:8px 12px;}
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
            <small>Livestock &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../adminlog_out.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="./add-technician.php"><i class="fas fa-user-cog"></i> Technicians</a>
    <a href="./farmer-beneficiary.php"><i class="fas fa-id-card"></i> Farmer Beneficiary</a>
    <a href="./add-livestock.php"class="active"><i class="fas fa-drumstick-bite"></i> Livestock</a>
    <a href="./tracking.php"><i class="fas fa-eye"></i> Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php" ><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>

<div class="main-content">

    <div class="page-card">
        <div class="page-icon"><i class="fas fa-drumstick-bite"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Livestock Inventory &amp; Distribution</h2>
            <p>Add, update, filter, and distribute livestock — all in one place.</p>
        </div>
        <button class="add-btn" data-bs-toggle="modal" data-bs-target="#addLivestockModal">
            <i class="fas fa-plus"></i> Add Livestock
        </button>
    </div>

    <?php if($message): ?>
        <div class="pg-alert <?= htmlspecialchars($messageType) ?>"><i class="fas fa-circle-info"></i> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <p class="section-label">All Livestock</p>

    <div class="kind-filter-bar">
        <button type="button" class="kind-filter-btn active" data-filter="all" onclick="filterKind('all', this)">
            <i class="fas fa-layer-group"></i> All
        </button>
        <button type="button" class="kind-filter-btn" data-filter="pig" onclick="filterKind('pig', this)">
            <i class="fas fa-piggy-bank"></i> Pig
        </button>
        <button type="button" class="kind-filter-btn" data-filter="goat" onclick="filterKind('goat', this)">
            <i class="fas fa-paw"></i> Goat
        </button>
        <button type="button" class="kind-filter-btn" data-filter="carabao" onclick="filterKind('carabao', this)">
            <i class="fas fa-cow"></i> Carabao
        </button>

        <div class="breed-filter-wrap">
            <label for="breedFilterSelect">Breed</label>
            <select id="breedFilterSelect" onchange="filterBreed(this.value)">
                <option value="all">All Breeds</option>
                <?php foreach($breedList as $b): ?>
                    <option value="<?= htmlspecialchars($b, ENT_QUOTES) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="table-card">
        <div class="table-responsive">
            <table class="pg-table" id="livestockTable">
                <thead>
                    <tr>
                        <th>Eartag No</th>
                        <th>Kind</th>
                        <th>Breed</th>
                        <th>Sex</th>
                        <th>Age</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $editModalsHtml = "";
                    if(!empty($rows)):
                        foreach($rows as $row):
                    ?>
                            <tr class="data-row" data-kind="<?= htmlspecialchars($row['kind']) ?>" data-breed="<?= htmlspecialchars($row['breed'] ?? '', ENT_QUOTES) ?>">
                                <td><?= htmlspecialchars($row['batch_no']) ?></td>
                                <td><span class="kind-pill"><?= htmlspecialchars($row['kind']) ?></span></td>
                                <td><?= htmlspecialchars($row['breed']) ?: '—' ?></td>
                                <td><?= htmlspecialchars($row['sex']) ?></td>
                                <td><?= htmlspecialchars(livestockAge($row['birth_date'] ?? null)) ?></td>
                                <td>
                                    <span class="status-pill status-<?= htmlspecialchars($row['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="action-btn edit" data-bs-toggle="modal" data-bs-target="#editLivestockModal<?= $row['livestock_id'] ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if($row['status'] === 'available'): ?>
                                        <button
                                            type="button"
                                            class="action-btn distribute"
                                            data-bs-toggle="modal"
                                            data-bs-target="#distributeModal"
                                            data-livestock-id="<?= (int)$row['livestock_id'] ?>"
                                            data-livestock-name="<?= htmlspecialchars(ucfirst($row['kind']).($row['breed'] ? ", ".$row['breed'] : ""), ENT_QUOTES, 'UTF-8') ?>"
                                            data-batch-no="<?= htmlspecialchars($row['batch_no'], ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                            <i class="fas fa-exchange-alt"></i> Distribute
                                        </button>
                                    <?php endif; ?>
                                    <a href="add-livestock.php?delete_id=<?= $row['livestock_id'] ?>" class="action-btn delete" onclick="return confirm('Delete this livestock?');">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </td>
                            </tr>
                    <?php
                            // Edit modal for this animal, built separately so it can be
                            // rendered OUTSIDE the <table> (a <div> is not valid inside <tbody>).
                            ob_start();
                    ?>
                            <div class="modal fade" id="editLivestockModal<?= $row['livestock_id'] ?>" tabindex="-1" aria-hidden="true">
                              <div class="modal-dialog modal-lg">
                                <form method="POST" action="add-livestock.php" class="modal-content">
                                  <div class="modal-header">
                                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Livestock</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                  </div>
                                  <div class="modal-body">
                                    <input type="hidden" name="livestock_id" value="<?= $row['livestock_id'] ?>">
                                    <div class="mb-3">
                                        <label class="form-label">Eartag no</label>
                                        <input type="text" name="batch_no" class="form-control" value="<?= htmlspecialchars($row['batch_no']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Kind</label>
                                        <select name="kind" class="form-control kind-select" required>
                                            <option value="">-- Select Kind --</option>
                                            <option value="goat" <?= $row['kind']=='goat'?'selected':'' ?>>Goat</option>
                                            <option value="pig" <?= $row['kind']=='pig'?'selected':'' ?>>Pig</option>
                                            <option value="carabao" <?= $row['kind']=='carabao'?'selected':'' ?>>Carabao</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Breed</label>
                                        <select name="breed" class="form-control breed-select" data-current-breed="<?= htmlspecialchars($row['breed'] ?? '', ENT_QUOTES) ?>">
                                            <option value=""><?= htmlspecialchars($row['breed'] ?: '-- Select Breed --') ?></option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Sex</label>
                                        <select name="sex" class="form-control" required>
                                            <option value="Male" <?= $row['sex']=='Male'?'selected':'' ?>>Male</option>
                                            <option value="Female" <?= $row['sex']=='Female'?'selected':'' ?>>Female</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Birth Date</label>
                                        <input type="date" name="birth_date" class="form-control" value="<?= htmlspecialchars($row['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-control">
                                            <option value="available" <?= $row['status']=='available'?'selected':'' ?>>Available</option>
                                            <option value="dead" <?= $row['status']=='dead'?'selected':'' ?>>Dead</option>
                                        </select>
                                    </div>
                                  </div>
                                  <div class="modal-footer">
                                    <button type="submit" name="update_livestock" class="btn btn-success">
                                        <i class="fas fa-save"></i> Update Livestock
                                    </button>
                                  </div>
                                </form>
                              </div>
                            </div>
                    <?php
                            $editModalsHtml .= ob_get_clean();
                        endforeach;
                    else:
                    ?>
                        <tr><td colspan="7" class="empty-row"><em>No livestock in inventory</em></td></tr>
                    <?php endif; ?>
                    <tr id="noKindMatchRow" style="display:none;">
                        <td colspan="7" class="empty-row"><em>No livestock found for this filter.</em></td>
                    </tr>
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

<!-- Edit Livestock Modals (rendered outside the table for valid HTML) -->
<?= $editModalsHtml ?>

<!-- Add Livestock Modal -->
<div class="modal fade" id="addLivestockModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="add-livestock.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus"></i> Add New Livestock</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row">
            <div class="col-md-8 mb-3">
                <label class="form-label">Starting Eartag No</label>
                <input type="text" name="batch_no" class="form-control" value="<?= htmlspecialchars($nextBatchNo) ?>" required>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Quantity</label>
                <input type="number" name="quantity" class="form-control" value="1" min="1" max="200" required>
            </div>
        </div>
        <p class="form-hint">
            <i class="fas fa-circle-info"></i>
            If the animals are the same (same kind, breed, sex, and birth date), just set the quantity.
            Eartags are numbered automatically from the starting eartag, and each one gets its own row in the list.
        </p>
        <div class="mb-3">
            <label class="form-label">Kind</label>
            <select name="kind" class="form-control kind-select" required>
                <option value="">-- Select Kind --</option>
                <option value="goat">Goat</option>
                <option value="pig">Pig</option>
                <option value="carabao">Carabao</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Breed</label>
            <select name="breed" class="form-control breed-select" data-current-breed="" disabled>
                <option value="">-- Select Kind First --</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Sex</label>
            <select name="sex" class="form-control" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Birth Date</label>
            <input type="date" name="birth_date" class="form-control" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="available">Available</option>
                <option value="dead">Dead</option>
            </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" name="add_livestock" class="btn btn-success">
            <i class="fas fa-save"></i> Save Livestock
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Distribute Livestock Modal (merged in from the old Distribution page) -->
<div class="modal fade" id="distributeModal" tabindex="-1" aria-labelledby="distributeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form method="POST" action="add-livestock.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="distributeModalLabel"><i class="fas fa-exchange-alt"></i> Distribute Livestock</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="livestock_id" id="modalLivestockId" value="">

        <div class="mb-3">
            <label class="form-label">Livestock</label>
            <input type="text" id="modalLivestockName" class="form-control" readonly>
        </div>

        <div class="mb-3">
            <label class="form-label">Select Beneficiary</label>
            <select name="beneficiary_id" id="modalBeneficiarySelect" class="form-control" required>
                <option value="" data-barangay="">-- Choose Beneficiary --</option>
                <?php if($beneficiaries && $beneficiaries->num_rows > 0):
                    $beneficiaries->data_seek(0);
                    while($b = $beneficiaries->fetch_assoc()):
                ?>
                    <option value="<?= (int)$b['beneficiary_id'] ?>" data-barangay="<?= htmlspecialchars($b['barangay_name'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($b['firstname']." ".$b['lastname']." — ".$b['barangay_name'].(!empty($b['pending_renewal_id']) ? ' (Renewal)' : '')) ?>
                    </option>
                <?php
                    endwhile;
                endif;
                ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Eartag no</label>
            <input type="text" id="modalBatchNo" class="form-control" readonly>
        </div>
        <!-- QUANTITY: not a form field — always 1, enforced server-side
             regardless of what the client sends. -->
        <div class="mb-3">
            <label class="form-label">Distribution Date</label>
            <input type="date" name="distribution_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Location</label>
            <input type="text" name="distribution_location" id="modalLocation" class="form-control" placeholder="Enter distribution location">
        </div>
        <div class="mb-3">
            <label class="form-label">Remarks</label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Optional remarks"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="distribute" class="btn btn-success">
            <i class="fas fa-save"></i> Confirm Distribution
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Breeds actually in the inventory right now, grouped per kind — drives
// the breed FILTER dropdown so it only shows breeds relevant to the
// selected kind.
const inventoryBreedsByKind = <?= json_encode($breedsByKind, JSON_UNESCAPED_UNICODE) ?>;
const allInventoryBreeds = <?= json_encode($breedList, JSON_UNESCAPED_UNICODE) ?>;

// Breed choices per livestock kind (used by the Add/Edit forms)
const breedOptions = {
    pig: ['Large White', 'Landrace', 'Duroc', 'Pietrain', 'Philippine Native Pig'],
    goat: ['Boer', 'Anglo-Nubian', 'Saanen', 'Alpine', 'Philippine Native Goat'],
    carabao: ['Philippine Carabao', 'Murrah', 'Jaffarabadi', 'Nili-Ravi']
};

function populateBreedOptions(kind, selectEl, selectedBreed) {
    selectedBreed = selectedBreed || '';
    selectEl.innerHTML = '';

    if (!kind || !breedOptions[kind]) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '-- Select Kind First --';
        selectEl.appendChild(opt);
        selectEl.disabled = true;
        return;
    }

    selectEl.disabled = false;

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '-- Select Breed --';
    selectEl.appendChild(placeholder);

    let matched = false;
    breedOptions[kind].forEach(function (breed) {
        const opt = document.createElement('option');
        opt.value = breed;
        opt.textContent = breed;
        if (breed === selectedBreed) {
            opt.selected = true;
            matched = true;
        }
        selectEl.appendChild(opt);
    });

    // Keep legacy/custom breed values that aren't in the standard list, so nothing is lost
    if (selectedBreed && !matched) {
        const customOpt = document.createElement('option');
        customOpt.value = selectedBreed;
        customOpt.textContent = selectedBreed + ' (custom)';
        customOpt.selected = true;
        selectEl.appendChild(customOpt);
    }
}

function initBreedDropdowns() {
    document.querySelectorAll('.kind-select').forEach(function (kindSel) {
        const form = kindSel.closest('form');
        if (!form) return;
        const breedSel = form.querySelector('.breed-select');
        if (!breedSel) return;

        // Initialize using whatever kind/breed are already set (Edit modals) or blank (Add modal)
        const currentBreed = breedSel.getAttribute('data-current-breed') || '';
        populateBreedOptions(kindSel.value, breedSel, currentBreed);

        kindSel.addEventListener('change', function () {
            populateBreedOptions(kindSel.value, breedSel, '');
        });
    });
}

document.addEventListener('DOMContentLoaded', initBreedDropdowns);

/* =========================================================
   TABLE FILTERS — Kind (buttons) + Breed (dropdown) combine
   together; a row only shows when it matches both.
========================================================= */
let currentKindFilter = 'all';
let currentBreedFilter = 'all';

function applyTableFilters() {
    const rows = document.querySelectorAll('#livestockTable tbody tr.data-row');
    let visibleCount = 0;

    rows.forEach(function (row) {
        const kindMatch  = (currentKindFilter === 'all' || row.getAttribute('data-kind') === currentKindFilter);
        const breedMatch = (currentBreedFilter === 'all' || row.getAttribute('data-breed') === currentBreedFilter);
        const show = kindMatch && breedMatch;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    const noMatchRow = document.getElementById('noKindMatchRow');
    if (noMatchRow) {
        noMatchRow.style.display = (rows.length > 0 && visibleCount === 0) ? '' : 'none';
    }
}

function populateBreedFilterOptions(kind) {
    const select = document.getElementById('breedFilterSelect');
    if (!select) return;

    const breeds = (kind === 'all') ? allInventoryBreeds : (inventoryBreedsByKind[kind] || []);

    select.innerHTML = '';
    const allOpt = document.createElement('option');
    allOpt.value = 'all';
    allOpt.textContent = 'All Breeds';
    select.appendChild(allOpt);

    breeds.forEach(function (breed) {
        const opt = document.createElement('option');
        opt.value = breed;
        opt.textContent = breed;
        select.appendChild(opt);
    });

    // Kind changed, so any previously picked breed no longer applies
    select.value = 'all';
    currentBreedFilter = 'all';
}

function filterKind(kind, btn) {
    document.querySelectorAll('.kind-filter-btn').forEach(function (b) {
        b.classList.remove('active');
    });
    btn.classList.add('active');
    currentKindFilter = kind;
    populateBreedFilterOptions(kind);
    applyTableFilters();
}

function filterBreed(breed) {
    currentBreedFilter = breed;
    applyTableFilters();
}

/* =========================================================
   DISTRIBUTE MODAL — auto-fill livestock + beneficiary info
========================================================= */
document.addEventListener('DOMContentLoaded', function () {

    const distributeModal = document.getElementById('distributeModal');
    if (!distributeModal) return;

    const beneficiarySelect = document.getElementById('modalBeneficiarySelect');
    const batchNoField = document.getElementById('modalBatchNo');
    const locationField = document.getElementById('modalLocation');

    if (beneficiarySelect) {
        // Default the Location field to the selected beneficiary's barangay.
        // Still editable in case the actual distribution point differs.
        beneficiarySelect.addEventListener('change', function () {
            const selectedOption = beneficiarySelect.options[beneficiarySelect.selectedIndex];
            const barangay = selectedOption ? selectedOption.getAttribute('data-barangay') : '';
            if (locationField) {
                locationField.value = barangay || '';
            }
        });
    }

    distributeModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        if (!button) return;

        const livestockId = button.getAttribute('data-livestock-id');
        const livestockName = button.getAttribute('data-livestock-name');
        const batchNo = button.getAttribute('data-batch-no');

        document.getElementById('modalLivestockId').value = livestockId || '';
        document.getElementById('modalLivestockName').value = livestockName || '';
        if (batchNoField) batchNoField.value = batchNo || '';
    });

    distributeModal.addEventListener('hidden.bs.modal', function () {
        const form = distributeModal.querySelector('form');
        if (form) form.reset();
        document.getElementById('modalLivestockId').value = '';
        document.getElementById('modalLivestockName').value = '';
        if (batchNoField) batchNoField.value = '';
        if (locationField) locationField.value = '';
    });
});
</script>
</body>
</html>