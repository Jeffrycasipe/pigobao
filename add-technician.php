<?php
session_start();
require '../db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

$message = "";
$messageType = "";

// Assign Barangays to a Technician (merged in from assign-technician.php)
if(isset($_POST['assign_barangays'])) {
    $tech_id = intval($_POST['technician_id']);
    $barangays = $_POST['barangays'] ?? [];
    $barangays = array_map('intval', $barangays);

    if(count($barangays) < 1 || count($barangays) > 6){
        $_SESSION['flash_message'] = "You must assign between 1 and 6 barangays.";
        $_SESSION['flash_type'] = "danger";
        $_SESSION['flash_tech_id'] = $tech_id;
        $_SESSION['flash_selected'] = $barangays;
    } else {
        $del = $conn->prepare("DELETE FROM technician_barangay WHERE technician_id=?");
        $del->bind_param("i", $tech_id);
        $del->execute();

        $stmt = $conn->prepare("INSERT INTO technician_barangay (technician_id,barangay_id) VALUES (?,?)");
        foreach($barangays as $brgy_id){
            $stmt->bind_param("ii", $tech_id, $brgy_id);
            $stmt->execute();
        }

        $_SESSION['flash_message'] = "Barangays assigned successfully!";
        $_SESSION['flash_type'] = "success";
        $_SESSION['flash_tech_id'] = null;
        $_SESSION['flash_selected'] = null;
    }

    header("Location: add-technician.php");
    exit();
}

// Add Technician
if(isset($_POST['add_technician'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $contact = trim($_POST['contact']);
    $status = $_POST['status'];

    $stmt = $conn->prepare("INSERT INTO technicians (name,email,username,password,contact,status) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("ssssss",$name,$email,$username,$password,$contact,$status);
    if($stmt->execute()){
        $message = "Technician added successfully!";
    } else {
        $message = "Error: ".$stmt->error;
    }
}

// Update Technician
if(isset($_POST['update_technician'])) {
    $id = intval($_POST['technician_id']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $contact = trim($_POST['contact']);
    $status = $_POST['status'];

    if(!empty($_POST['password'])){
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE technicians SET name=?,email=?,username=?,password=?,contact=?,status=? WHERE technician_id=?");
        $stmt->bind_param("ssssssi",$name,$email,$username,$password,$contact,$status,$id);
    } else {
        $stmt = $conn->prepare("UPDATE technicians SET name=?,email=?,username=?,contact=?,status=? WHERE technician_id=?");
        $stmt->bind_param("sssssi",$name,$email,$username,$contact,$status,$id);
    }
    if($stmt->execute()){
        $message = "Technician updated successfully!";
    } else {
        $message = "Error: ".$stmt->error;
    }
}

// Delete Technician
if(isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $check = $conn->prepare("
        SELECT
            (SELECT COUNT(*) FROM distributions WHERE technician_id=?) AS distribution_count,
            (SELECT COUNT(*) FROM monitoring WHERE created_by=?) AS monitoring_count
    ");
    $check->bind_param("ii", $id, $id);
    $check->execute();
    $usage = $check->get_result()->fetch_assoc();
    $check->close();

    if((int)$usage['distribution_count'] > 0 || (int)$usage['monitoring_count'] > 0) {
        $message = "This technician cannot be deleted because existing distribution or monitoring records reference the account.";
    } else {
        $stmt = $conn->prepare("DELETE FROM technicians WHERE technician_id=?");
        $stmt->bind_param("i", $id);
        if($stmt->execute()) {
            header("Location: add-technician.php");
            exit();
        }
        $message = "Error: " . $stmt->error;
        $stmt->close();
    }
}

// Flash message from the assign-barangays redirect above takes priority
// over a same-request $message (add/update/delete already render inline
// without a redirect, so there's never really both at once).
if(!empty($_SESSION['flash_message'])){
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? "info";
}
$reopenTechId = $_SESSION['flash_tech_id'] ?? null;
$stickySelected = $_SESSION['flash_selected'] ?? [];
unset($_SESSION['flash_message'], $_SESSION['flash_type'], $_SESSION['flash_tech_id'], $_SESSION['flash_selected']);

// Fetch technicians into an array so we can loop over them twice:
// once for table rows, once for edit modals (rendered OUTSIDE the table
// — see note near the bottom of the page).
$result = $conn->query("SELECT * FROM technicians ORDER BY technician_id DESC");
$technicians = [];
while($row = $result->fetch_assoc()){
    $technicians[] = $row;
}

// For each technician, work out which barangays are already assigned to
// them, and which barangays are still available to assign (has at least
// one beneficiary, and not already claimed by a DIFFERENT technician).
// This is the same logic assign-technician.php used to do on its own page.
foreach($technicians as $idx => $t){
    $tech_id = (int)$t['technician_id'];

    $assignedStmt = $conn->prepare("
        SELECT b.barangay_id, b.barangay_name
        FROM technician_barangay tb
        JOIN barangays b ON tb.barangay_id = b.barangay_id
        WHERE tb.technician_id = ?
        ORDER BY b.barangay_name ASC
    ");
    $assignedStmt->bind_param("i", $tech_id);
    $assignedStmt->execute();
    $assignedResult = $assignedStmt->get_result();

    $assignedIds = [];
    $assignedNames = [];
    while($row = $assignedResult->fetch_assoc()){
        $assignedIds[] = (int)$row['barangay_id'];
        $assignedNames[] = $row['barangay_name'];
    }

    $availStmt = $conn->prepare("
        SELECT DISTINCT b.barangay_id, b.barangay_name
        FROM barangays b
        INNER JOIN beneficiaries ben ON ben.barangay_id = b.barangay_id
        WHERE NOT EXISTS(
            SELECT 1
            FROM technician_barangay tb2
            WHERE tb2.barangay_id = b.barangay_id
            AND tb2.technician_id != ?
        )
        ORDER BY b.barangay_name ASC
    ");
    $availStmt->bind_param("i", $tech_id);
    $availStmt->execute();
    $availableResult = $availStmt->get_result();

    $available = [];
    while($row = $availableResult->fetch_assoc()){
        $available[] = ['id' => (int)$row['barangay_id'], 'name' => $row['barangay_name']];
    }

    $technicians[$idx]['assignedIds'] = $assignedIds;
    $technicians[$idx]['assignedNames'] = $assignedNames;
    $technicians[$idx]['available'] = $available;
}

$totalTechCount = count($technicians);
$activeTechCount = count(array_filter($technicians, fn($t) => ($t['status'] ?? '') === 'active'));
$inactiveTechCount = count(array_filter($technicians, fn($t) => ($t['status'] ?? '') === 'inactive'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Technicians - PIGOBAO</title>
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

/* PAGE HEADER STRIP (mirrors dashboard's profile-card) */
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

/* STAT ROW */
.stat-row{
    display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;
}
.stat-card{
    background:#fff;border:1px solid var(--pg-line);border-radius:14px;
    padding:16px 20px;box-shadow:var(--shadow-soft);flex:1;min-width:180px;
    display:flex;align-items:center;gap:14px;
    transition:transform 0.2s ease, box-shadow 0.2s ease;
}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lift);}
.stat-card .icon{
    width:42px;height:42px;border-radius:11px;display:flex;align-items:center;
    justify-content:center;font-size:17px;flex-shrink:0;
    background:var(--pg-forest-100);color:var(--pg-forest-800);
}
.stat-card.inactive-stat .icon{background:#fbeceb;color:var(--pg-clay);}
.stat-card .num{
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:22px;
    color:var(--pg-forest-900);line-height:1;
}
.stat-card .label{font-size:12px;color:var(--pg-ink-soft);margin-top:3px;}

/* ALERT */
.pg-alert{
    background:var(--pg-forest-100);color:var(--pg-forest-900);
    border:1px solid var(--pg-forest-300);border-left:4px solid var(--pg-forest-600);
    border-radius:10px;padding:13px 18px;margin-bottom:20px;
    font-size:14px;font-weight:500;display:flex;align-items:center;gap:10px;
}
.pg-alert.danger{
    background:#fbeceb;color:#8a2e22;
    border-color:#f3d3d0;border-left-color:var(--pg-clay);
}
.pg-alert.info{
    background:#eaf2f8;color:#2c536e;
    border-color:#d4e6f0;border-left-color:var(--pg-sky);
}

/* BARANGAY TAGS (assigned-barangays column) */
.barangay-tags{display:flex;flex-wrap:wrap;gap:6px;}
.barangay-tag{
    background:var(--pg-forest-100);color:var(--pg-forest-800);
    padding:4px 11px;border-radius:999px;font-size:12px;font-weight:600;
}
.no-barangay{color:var(--pg-ink-soft);font-style:italic;font-size:13px;}

/* ASSIGN MODAL CHECK LIST */
.modal-body.assign-body{max-height:70vh;overflow-y:auto;}
.modal-body p.fw-bold{
    font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:14px;
    color:var(--pg-forest-900);margin-bottom:14px;
}
.modal-body small.text-muted{
    display:block;margin-top:10px;color:var(--pg-ink-soft)!important;font-size:12.5px;
}
.form-check{
    background:#fff;border:1px solid var(--pg-line);border-radius:9px;
    padding:10px 14px;display:flex;align-items:center;gap:10px;transition:.15s ease;
}
.form-check:hover{border-color:var(--pg-forest-300);}
.form-check-input{margin:0;accent-color:var(--pg-forest-600);width:16px;height:16px;flex:none;}
.form-check-input:checked~.form-check-label,
.form-check-input:checked+.form-check-label{color:var(--pg-forest-900);font-weight:600;}
.form-check-label{font-size:13.5px;color:var(--pg-ink);margin:0;cursor:pointer;}

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
}
table.pg-table tbody td{
    padding:14px 18px;
    border-bottom:1px solid var(--pg-line);
    color:var(--pg-ink);
    vertical-align:middle;
}
table.pg-table tbody tr:hover{
    background:var(--pg-forest-100);
}
table.pg-table tbody tr:last-child td{border-bottom:none;}
table.pg-table td.empty-row{text-align:center;color:var(--pg-ink-soft);font-style:italic;padding:26px;}

.status-pill{
    padding:5px 13px;border-radius:999px;font-size:12px;font-weight:600;
    letter-spacing:.3px;display:inline-flex;align-items:center;gap:6px;
}
.status-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;}
.status-pill.active{background:var(--pg-forest-100);color:var(--pg-forest-800);}
.status-pill.inactive{background:#fbeceb;color:var(--pg-clay);}

/* ACTION BUTTONS */
.action-col{white-space:nowrap;}
.action-group{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;}
.action-btn{
    --c:var(--pg-forest-800);
    --c-soft:var(--pg-forest-100);
    --c-line:var(--pg-forest-300);
    display:inline-flex;align-items:center;gap:8px;
    padding:5px 14px 5px 5px;
    background:var(--c-soft);color:var(--c);
    border:1px solid var(--c-line);border-radius:999px;
    font-size:12.5px;font-weight:600;letter-spacing:.2px;line-height:1;
    text-decoration:none;cursor:pointer;
    transition:background .18s ease,color .18s ease,transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
.action-btn .ico{
    width:26px;height:26px;border-radius:50%;flex:none;
    display:grid;place-items:center;font-size:11.5px;
    background:var(--c);color:#fff;
    transition:background .18s ease,color .18s ease;
}
.action-btn:hover{
    background:var(--c);color:#fff;border-color:var(--c);
    transform:translateY(-2px);
    box-shadow:0 8px 16px -8px var(--c);
}
.action-btn:hover .ico{background:#fff;color:var(--c);}
.action-btn:active{transform:translateY(0);box-shadow:none;}
.action-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(46,125,50,.25);}
.action-btn.edit{--c:var(--pg-forest-600);--c-soft:var(--pg-forest-100);--c-line:var(--pg-forest-300);}
.action-btn.assign{--c:var(--pg-plum);--c-soft:#f3eefc;--c-line:#dccff3;}
.action-btn.delete{--c:var(--pg-clay);--c-soft:#fdf1ef;--c-line:#f3d3d0;}
.action-btn:disabled,.action-btn.disabled{
    --c:#9aa59d;--c-soft:#f1f3f2;--c-line:#e2e6e3;
    cursor:not-allowed;transform:none;box-shadow:none;
}
.action-btn:disabled:hover,.action-btn.disabled:hover{
    background:var(--c-soft);color:var(--c);border-color:var(--c-line);transform:none;box-shadow:none;
}
.action-btn:disabled:hover .ico,.action-btn.disabled:hover .ico{background:var(--c);color:#fff;}

/* NAME CELL WITH AVATAR */
.tech-cell{display:flex;align-items:center;gap:12px;}
.avatar{
    width:38px;height:38px;border-radius:50%;flex:none;
    display:grid;place-items:center;
    background:linear-gradient(145deg,var(--pg-forest-600),var(--pg-forest-900));
    color:#fff;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:13.5px;
    box-shadow:0 4px 10px -4px rgba(18,60,22,.5);
}
.tech-cell .nm{font-weight:600;color:var(--pg-forest-900);line-height:1.2;}
.tech-cell .un{font-size:12px;color:var(--pg-ink-soft);}

/* MODAL FOOTER BUTTONS */
.btn-cancel{
    background:#fff;color:var(--pg-ink-soft);border:1.5px solid var(--pg-line);
    border-radius:10px;padding:10px 20px;font-weight:600;font-size:14px;transition:.18s ease;
}
.btn-cancel:hover{background:var(--pg-canvas);color:var(--pg-ink);border-color:#cfd9d1;}
.modal-footer .btn-danger-pg{
    background:linear-gradient(120deg,#a8402f,var(--pg-clay));color:#fff;border:none;
    border-radius:10px;padding:10px 20px;font-weight:600;font-size:14px;
    box-shadow:0 6px 16px -6px rgba(193,84,63,.55);transition:.18s ease;
    display:inline-flex;align-items:center;gap:8px;text-decoration:none;
}
.modal-footer .btn-danger-pg:hover{transform:translateY(-1px);color:#fff;}
.modal-footer{display:flex;align-items:center;gap:10px;}
.assign-count{
    margin-right:auto;font-size:12.5px;font-weight:600;color:var(--pg-forest-800);
    background:var(--pg-forest-100);padding:6px 12px;border-radius:999px;
}
.assign-count.max{background:#fbeceb;color:var(--pg-clay);}

/* DELETE CONFIRM MODAL */
.confirm-icon{
    width:64px;height:64px;border-radius:50%;margin:0 auto 14px;
    display:grid;place-items:center;font-size:24px;
    background:#fdf1ef;color:var(--pg-clay);border:6px solid #fbe3df;
}
#deleteModal .modal-body{text-align:center;background:#fff;padding:30px 28px 10px;}
#deleteModal .modal-body h5{font-family:'Space Grotesk',sans-serif;font-weight:700;color:var(--pg-forest-900);margin-bottom:6px;}
#deleteModal .modal-body p{color:var(--pg-ink-soft);font-size:14px;margin:0;}
#deleteModal .modal-footer{background:#fff;justify-content:center;padding-bottom:26px;}

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
.modal-body .form-group{margin-bottom:16px;}
.modal-body .form-label{
    display:block;font-size:12.5px;font-weight:600;
    color:var(--pg-forest-900);margin-bottom:6px;letter-spacing:.2px;
}
.modal-body .form-label .text-danger{color:var(--pg-clay);margin-left:2px;}
.modal-body .form-control, .modal-body select.form-control{
    border:1.5px solid var(--pg-line);border-radius:9px;padding:10px 14px;
    font-size:14px;width:100%;background:#fff;color:var(--pg-ink);
    transition:border-color .15s ease, box-shadow .15s ease;
}
.modal-body .form-control:focus{
    outline:none;
    border-color:var(--pg-forest-600);
    box-shadow:0 0 0 3px rgba(46,125,50,0.14);
}
/* Empty/invalid required fields turn red once the user tries to submit */
.modal-body .invalid-feedback{
    display:none;color:var(--pg-clay);font-size:12px;font-weight:500;
    margin-top:6px;
}
.modal-body form.was-validated .form-control:invalid{
    border-color:var(--pg-clay);
    background:#fdf4f3;
}
.modal-body form.was-validated .form-control:invalid:focus{
    box-shadow:0 0 0 3px rgba(193,84,63,0.16);
}
.modal-body form.was-validated .form-control:invalid ~ .invalid-feedback{
    display:block;
}
.modal-body form.was-validated .form-control:valid{
    border-color:var(--pg-forest-300);
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
@media(max-width:1250px){
    .action-btn{padding:5px;}
    .action-btn .lbl{display:none;}
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
    .stat-row{flex-direction:column;}
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
            <small>Technicians &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../adminlog_out.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="./add-technician.php"class="active"><i class="fas fa-user-cog"></i> Technicians</a>
    <a href="./farmer-beneficiary.php"><i class="fas fa-id-card"></i> Farmer Beneficiary</a>
    <a href="./add-livestock.php"><i class="fas fa-drumstick-bite"></i> Livestock</a>
    <a href="./tracking.php"><i class="fas fa-eye"></i> Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php" ><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>


<div class="main-content">

    <div class="page-card">
        <div class="page-icon"><i class="fas fa-user-cog"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Technician Records</h2>
            <p>Add, update, and manage field technician accounts.</p>
        </div>
        <button class="add-btn" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-plus"></i> Add Technician
        </button>
    </div>

    <?php if($message): ?>
        <div class="pg-alert<?= $messageType ? ' '.htmlspecialchars($messageType) : '' ?>"><i class="fas fa-circle-info"></i> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="stat-row">
        <div class="stat-card">
            <div class="icon"><i class="fas fa-user-cog"></i></div>
            <div>
                <div class="num"><?= $totalTechCount ?></div>
                <div class="label">Total Technicians</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="icon"><i class="fas fa-circle-check"></i></div>
            <div>
                <div class="num"><?= $activeTechCount ?></div>
                <div class="label">Active</div>
            </div>
        </div>
        <div class="stat-card inactive-stat">
            <div class="icon"><i class="fas fa-circle-xmark"></i></div>
            <div>
                <div class="num"><?= $inactiveTechCount ?></div>
                <div class="label">Inactive</div>
            </div>
        </div>
    </div>

    <p class="section-label">All Technicians</p>
    <div class="table-card">
        <div class="table-responsive">
            <table class="pg-table">
                <thead>
                    <tr>
                        <th>Technician</th><th>Email</th><th>Contact</th><th>Status</th><th>Barangays</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($technicians) === 0): ?>
                        <tr><td class="empty-row" colspan="6">No technicians recorded yet.</td></tr>
                    <?php else: foreach($technicians as $t): ?>
                    <tr>
                        <td>
                            <div class="tech-cell">
                                <div class="avatar"><?= htmlspecialchars(strtoupper(mb_substr(trim($t['name']),0,1))) ?></div>
                                <div>
                                    <div class="nm"><?= htmlspecialchars($t['name']) ?></div>
                                    <div class="un">@<?= htmlspecialchars($t['username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($t['email']) ?></td>
                        <td><?= htmlspecialchars($t['contact']) ?></td>
                        <td>
                            <span class="status-pill <?= $t['status']=='active' ? 'active' : 'inactive' ?>">
                                <?= htmlspecialchars(ucfirst($t['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if($t['assignedNames']): ?>
                                <div class="barangay-tags">
                                    <?php foreach($t['assignedNames'] as $bname): ?>
                                        <span class="barangay-tag"><?= htmlspecialchars($bname) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="no-barangay">No barangays assigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="action-col">
                            <div class="action-group">
                                <!-- Edit -->
                                <button type="button" class="action-btn edit" title="Edit technician"
                                        data-bs-toggle="modal" data-bs-target="#editModal<?= $t['technician_id'] ?>">
                                    <span class="ico"><i class="fas fa-pen"></i></span><span class="lbl">Edit</span>
                                </button>
                                <!-- Assign (merged in from assign-technician.php) -->
                                <?php if(count($t['available']) === 0): ?>
                                    <button type="button" class="action-btn assign disabled" disabled
                                            title="All barangays are already assigned to other technicians">
                                        <span class="ico"><i class="fas fa-map-location-dot"></i></span><span class="lbl">Assign</span>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="action-btn assign" title="Assign barangays"
                                            data-bs-toggle="modal" data-bs-target="#assignModal<?= $t['technician_id'] ?>">
                                        <span class="ico"><i class="fas fa-map-location-dot"></i></span><span class="lbl">Assign</span>
                                    </button>
                                <?php endif; ?>
                                <!-- Delete (opens confirm modal instead of the browser confirm box) -->
                                <button type="button" class="action-btn delete" title="Delete technician"
                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                        data-delete-url="add-technician.php?delete=<?= (int)$t['technician_id'] ?>"
                                        data-name="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>">
                                    <span class="ico"><i class="fas fa-trash-can"></i></span><span class="lbl">Delete</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
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

<!-- Edit Technician Modals: rendered OUTSIDE the table on purpose.
     Bootstrap modals must not live inside <tbody>/<table> — browsers strip
     non-<tr> children out of tables to keep the HTML valid, which was
     breaking each modal's positioning/backdrop and making it flash open
     then disappear the moment it was tapped. -->
<?php foreach($technicians as $t): ?>
<div class="modal fade" id="editModal<?= $t['technician_id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="add-technician.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Technician</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="technician_id" value="<?= $t['technician_id'] ?>">
        <div class="form-group">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($t['name']) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Email Address <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($t['email']) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($t['username']) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
        </div>
        <div class="form-group">
            <label class="form-label">Contact Number</label>
            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($t['contact']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="active" <?= $t['status']=='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $t['status']=='inactive'?'selected':'' ?>>Inactive</option>
            </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="update_technician" class="btn btn-success">
            <i class="fas fa-save"></i> Update
        </button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- Assign Barangay Modals: same pattern as the edit modals above — kept
     outside the table for the same reason (Bootstrap modals get their
     structure stripped if nested inside a table). Merged in from
     assign-technician.php so assigning barangays no longer needs its own
     page. -->
<?php foreach($technicians as $t): if(count($t['available']) === 0) continue; ?>
<div class="modal fade" id="assignModal<?= $t['technician_id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" action="add-technician.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-map-location-dot"></i> Assign Barangays to <?= htmlspecialchars($t['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body assign-body">
        <input type="hidden" name="technician_id" value="<?= $t['technician_id'] ?>">
        <p class="fw-bold">Select Barangays (min 1, max 6):</p>
        <div class="row g-2">
          <?php foreach($t['available'] as $brgy):
              if($reopenTechId == $t['technician_id']){
                  $isChecked = in_array($brgy['id'], $stickySelected) ? "checked" : "";
              } else {
                  $isChecked = in_array($brgy['id'], $t['assignedIds']) ? "checked" : "";
              }
          ?>
          <div class="col-md-6">
            <div class="form-check">
              <input class="form-check-input barangay-checkbox" type="checkbox" name="barangays[]"
                     value="<?= $brgy['id'] ?>" id="brgy<?= $t['technician_id'] ?>_<?= $brgy['id'] ?>" <?= $isChecked ?>>
              <label class="form-check-label" for="brgy<?= $t['technician_id'] ?>_<?= $brgy['id'] ?>">
                  <?= htmlspecialchars($brgy['name']) ?>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <small class="text-muted">
            Only barangays with registered beneficiaries are shown.
            Barangays already assigned to another technician are not shown.
            You must select between 1 and 6 barangays.
        </small>
      </div>
      <div class="modal-footer">
        <span class="assign-count">0 / 6 selected</span>
        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="assign_barangays" class="btn btn-success">
            <i class="fas fa-save"></i> Save Assignment
        </button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- Add Technician Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="add-technician.php" class="modal-content needs-validation" novalidate>
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add Technician</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="form-group">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
            <div class="invalid-feedback">Full name is required.</div>
        </div>
        <div class="form-group">
            <label class="form-label">Email Address <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" placeholder="e.g. juan@example.com" required>
            <div class="invalid-feedback">Please enter a valid email address.</div>
        </div>
        <div class="form-group">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" placeholder="e.g. jdelacruz" required>
            <div class="invalid-feedback">Username is required.</div>
        </div>
        <div class="form-group">
            <label class="form-label">Password <span class="text-danger">*</span></label>
            <input type="password" name="password" class="form-control" placeholder="Enter a password" required>
            <div class="invalid-feedback">Password is required.</div>
        </div>
        <div class="form-group">
            <label class="form-label">Contact Number <span class="text-danger">*</span></label>
            <input type="text" name="contact" class="form-control" placeholder="e.g. 09171234567" required>
            <div class="invalid-feedback">Contact number is required.</div>
        </div>
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="add_technician" class="btn btn-success">
            <i class="fas fa-save"></i> Save Technician
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirmation Modal (single shared modal, filled in by JS) -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body">
        <div class="confirm-icon"><i class="fas fa-trash-can"></i></div>
        <h5>Delete technician?</h5>
        <p>You are about to delete <strong id="deleteName"></strong>. This action cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="deleteConfirmBtn" class="btn-danger-pg"><i class="fas fa-trash-can"></i> Delete</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if($reopenTechId !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var modalEl = document.getElementById('assignModal<?= (int)$reopenTechId ?>');
    if(modalEl){
        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    // Highlight empty/invalid required fields in red when the user tries
    // to submit a form without filling them in (Add Technician modal).
    document.querySelectorAll('form.needs-validation').forEach(function(form){
        form.addEventListener('submit', function(e){
            if(!form.checkValidity()){
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);

        // Re-check a single field as the user fixes it, so the red border
        // clears as soon as it's valid instead of waiting for re-submit.
        form.querySelectorAll('.form-control').forEach(function(field){
            field.addEventListener('input', function(){
                if(form.classList.contains('was-validated')){
                    field.checkValidity();
                }
            });
        });
    });
});

document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.modal').forEach(function(modal){
        var checkboxes = modal.querySelectorAll('.barangay-checkbox');
        var counter = modal.querySelector('.assign-count');

        function updateCount(){
            if(!counter) return;
            var n = modal.querySelectorAll('.barangay-checkbox:checked').length;
            counter.textContent = n + ' / 6 selected';
            counter.classList.toggle('max', n >= 6);
        }

        checkboxes.forEach(function(box){
            box.addEventListener('change', function(){
                var checked = modal.querySelectorAll('.barangay-checkbox:checked');
                if(checked.length > 6){
                    this.checked = false;
                    alert('You can assign a maximum of 6 barangays to one technician.');
                }
                updateCount();
            });
        });
        updateCount();
    });

    // Delete confirmation modal: fill in the technician name + link
    var deleteModal = document.getElementById('deleteModal');
    deleteModal.addEventListener('show.bs.modal', function(e){
        var btn = e.relatedTarget;
        if(!btn) return;
        document.getElementById('deleteName').textContent = btn.getAttribute('data-name');
        document.getElementById('deleteConfirmBtn').setAttribute('href', btn.getAttribute('data-delete-url'));
    });

    // Bootstrap tooltips on the action buttons (helpful when labels are hidden on small screens)
    document.querySelectorAll('.action-btn[title]').forEach(function(el){
        new bootstrap.Tooltip(el, {trigger:'hover'});
    });
});
</script>

</body>
</html>