<?php
session_start();
require '../db.php'; // adjust path if db.php is elsewhere

if(!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

/*
 * Matches pigo.sql schema:
 *   beneficiaries: beneficiary_id, firstname, middlename, lastname, extension_name,
 *                  gender, barangay_id, contact, civil_status, status,
 *                  birthdate, photo   <-- require migration_add_photo_birthdate.sql
 *   barangays:     barangay_id, barangay_name
 *   distributions: distribution_id, technician_id, beneficiary_id, livestock_id,
 *                  quantity, distribution_date, status, batch_no
 *   livestock:     livestock_id, kind, breed
 *   technicians:   technician_id, name
 */

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: farmer-beneficiary.php");
    exit();
}

$renewalMessage = "";
$renewalMessageType = "info";

/* =========================================================
   HANDLE "RENEW & DISTRIBUTE"
   Renewing used to only log intent, requiring a second trip to
   the Distribution page to actually hand over an animal. This
   now does both in one step: the admin picks the new livestock
   right here, we run the exact same checks/inserts as
   distribution.php (technician lookup, stock check, active-
   distribution guard, transaction), and the renewal record is
   saved already 'fulfilled' and linked to the brand new
   distribution row — no separate Distribution-page trip needed.
========================================================= */
if (isset($_POST['renew_distribute'])) {

    $renewBeneficiaryId = (int)($_POST['beneficiary_id'] ?? 0);
    $previousDistributionId = isset($_POST['previous_distribution_id']) && $_POST['previous_distribution_id'] !== ''
        ? (int)$_POST['previous_distribution_id']
        : null;
    $renewalDate  = $_POST['renewal_date'] ?? '';
    $renewalRemarks = trim($_POST['remarks'] ?? '');
    $adminId = (int)$_SESSION['admin_id'];

    $livestock_id = (int)($_POST['livestock_id'] ?? 0);
    $quantity     = (int)($_POST['quantity'] ?? 0);
    $distLocation = trim($_POST['distribution_location'] ?? '');

    if (
        $renewBeneficiaryId !== $id ||
        $renewalDate === '' ||
        $livestock_id <= 0 ||
        $quantity < 1
    ) {

        $renewalMessage = "Please choose a date and a livestock to distribute before saving.";
        $renewalMessageType = "danger";

    } else {

        /* =====================================================
           FIND TECHNICIAN ASSIGNED TO BENEFICIARY BARANGAY
           (same lookup as distribution.php)
        ===================================================== */
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
        $techLookup->bind_param("i", $id);
        $techLookup->execute();
        $techRow = $techLookup->get_result()->fetch_assoc();
        $tech_id = $techRow ? (int)$techRow['technician_id'] : 0;
        $techLookup->close();

        /* =====================================================
           CHECK LIVESTOCK AVAILABILITY (+ batch_no, same as
           distribution.php — batch_no always comes from the
           livestock record, never typed by hand)
        ===================================================== */
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

        /* =====================================================
           CHECK BENEFICIARY DOESN'T ALREADY HAVE AN ACTIVE
           DISTRIBUTION (same guard as distribution.php)
        ===================================================== */
        $checkExisting = $conn->prepare("
            SELECT distribution_id
            FROM distributions
            WHERE beneficiary_id = ?
              AND status = 'active'
            LIMIT 1
        ");
        $checkExisting->bind_param("i", $id);
        $checkExisting->execute();
        $existingRow = $checkExisting->get_result()->fetch_assoc();
        $checkExisting->close();

        if (!$tech_id) {

            $renewalMessage = "No technician is assigned to this beneficiary's barangay yet. Assign one first under Assign Technician.";
            $renewalMessageType = "danger";

        } elseif (!$stockRow || $stockRow['status'] !== 'available') {

            $renewalMessage = "That livestock is no longer available. Please choose another.";
            $renewalMessageType = "danger";

        } elseif ($existingRow) {

            $renewalMessage = "This beneficiary already has an active distribution.";
            $renewalMessageType = "danger";

        } else {

            $conn->begin_transaction();
            $ok = true;

            try {

                /* =============================================
                   INSERT DISTRIBUTION
                ============================================= */
                $stmt = $conn->prepare("
                    INSERT INTO distributions
                    (
                        technician_id,
                        beneficiary_id,
                        livestock_id,
                        quantity,
                        distribution_date,
                        distribution_location,
                        batch_no,
                        remarks,
                        status
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, 'active'
                    )
                ");
                $stmt->bind_param(
                    "iiiissss",
                    $tech_id,
                    $id,
                    $livestock_id,
                    $quantity,
                    $renewalDate,
                    $distLocation,
                    $batch_no,
                    $renewalRemarks
                );
                $stmt->execute();
                $newDistributionId = $stmt->insert_id;
                $stmt->close();

                /* =============================================
                   UPDATE LIVESTOCK STATUS
                ============================================= */
                $update = $conn->prepare("
                    UPDATE livestock
                    SET status = 'distributed'
                    WHERE livestock_id = ?
                ");
                $update->bind_param("i", $livestock_id);
                $update->execute();
                $update->close();

                /* =============================================
                   SAVE RENEWAL RECORD, ALREADY FULFILLED
                   Linked straight to the new distribution row
                   instead of being left 'pending' for a later
                   Distribution-page visit.
                ============================================= */
                $insertRenewal = $conn->prepare("
                    INSERT INTO renewals
                        (beneficiary_id, previous_distribution_id, renewal_date, remarks, status, created_by, new_distribution_id)
                    VALUES (?, ?, ?, ?, 'fulfilled', ?, ?)
                ");
                $insertRenewal->bind_param(
                    "iissii",
                    $id,
                    $previousDistributionId,
                    $renewalDate,
                    $renewalRemarks,
                    $adminId,
                    $newDistributionId
                );
                $insertRenewal->execute();
                $insertRenewal->close();

            } catch (mysqli_sql_exception $e) {

                $ok = false;
                error_log("Renew & distribute failed: " . $e->getMessage());
            }

            if ($ok) {

                $conn->commit();
                $renewalMessage = "Beneficiary renewed and distributed successfully!";
                $renewalMessageType = "success";

            } else {

                $conn->rollback();
                $renewalMessage = "Something went wrong while saving the renewal and distribution.";
                $renewalMessageType = "danger";
            }
        }
    }
}

$stmt = $conn->prepare(
    "SELECT b.*, br.barangay_name
     FROM beneficiaries b
     JOIN barangays br ON br.barangay_id = b.barangay_id
     WHERE b.beneficiary_id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$farmer = $stmt->get_result()->fetch_assoc();

if (!$farmer) {
    header("Location: farmer-beneficiary.php");
    exit();
}

// Livestock received / distributed by this farmer
$stmt2 = $conn->prepare(
    "SELECT d.distribution_id, d.quantity, d.distribution_date, d.status, d.batch_no,
            l.kind, l.breed, t.name AS technician_name
     FROM distributions d
     JOIN livestock l ON l.livestock_id = d.livestock_id
     JOIN technicians t ON t.technician_id = d.technician_id
     WHERE d.beneficiary_id = ?
     ORDER BY d.distribution_date DESC"
);
$stmt2->bind_param("i", $id);
$stmt2->execute();
$distributions = $stmt2->get_result();

/* =========================================================
   DISTRIBUTIONS ELIGIBLE TO BE PICKED FOR A RENEWAL
   Only closed records (returned / death) make sense to renew —
   an 'active' one is still ongoing and shouldn't be renewed yet.
========================================================= */
$stmt2b = $conn->prepare(
    "SELECT d.distribution_id, d.batch_no, d.distribution_date, d.status,
            l.kind, l.breed
     FROM distributions d
     JOIN livestock l ON l.livestock_id = d.livestock_id
     WHERE d.beneficiary_id = ?
       AND d.status IN ('returned', 'death')
     ORDER BY d.distribution_date DESC"
);
$stmt2b->bind_param("i", $id);
$stmt2b->execute();
$renewableDistributions = $stmt2b->get_result();

/* =========================================================
   RENEWAL HISTORY FOR THIS BENEFICIARY
========================================================= */
$stmt3 = $conn->prepare(
    "SELECT r.renewal_id, r.renewal_date, r.remarks, r.status,
            pd.batch_no AS prev_batch_no, pl.kind AS prev_kind,
            nd.batch_no AS new_batch_no, nl.kind AS new_kind, nd.distribution_date AS new_distribution_date
     FROM renewals r
     LEFT JOIN distributions pd ON pd.distribution_id = r.previous_distribution_id
     LEFT JOIN livestock pl ON pl.livestock_id = pd.livestock_id
     LEFT JOIN distributions nd ON nd.distribution_id = r.new_distribution_id
     LEFT JOIN livestock nl ON nl.livestock_id = nd.livestock_id
     WHERE r.beneficiary_id = ?
     ORDER BY r.created_at DESC"
);
$stmt3->bind_param("i", $id);
$stmt3->execute();
$renewals = $stmt3->get_result();

/* =========================================================
   AVAILABLE LIVESTOCK FOR THE RENEW & DISTRIBUTE MODAL
   Same source distribution.php uses — lets the admin pick the
   animal right here instead of going to the Distribution page.
========================================================= */
$availableLivestock = $conn->query("
    SELECT livestock_id, batch_no, kind, breed, sex, birth_date, status
    FROM livestock
    WHERE status = 'available'
    ORDER BY kind ASC, batch_no ASC
");

$renewalStatusMap = [
    'pending'   => ['label' => 'Pending Distribution', 'class' => 'd-active'],
    'fulfilled' => ['label' => 'Fulfilled',             'class' => 'd-returned'],
    'cancelled' => ['label' => 'Cancelled',              'class' => 'd-death'],
];

$fullName = trim($farmer['firstname'] . ' ' . ($farmer['middlename'] ? $farmer['middlename'] . ' ' : '') . $farmer['lastname'] . ' ' . ($farmer['extension_name'] ?? ''));
$photo = !empty($farmer['photo']) ? '../uploads/beneficiaries/' . htmlspecialchars($farmer['photo']) : 'https://via.placeholder.com/240?text=No+Photo';
$statusClass = ($farmer['status'] === 'active') ? 'active' : 'inactive';
$birthdateDisplay = !empty($farmer['birthdate']) ? date('F j, Y', strtotime($farmer['birthdate'])) : '—';

$distStatusMap = [
    'active'   => ['label' => 'Pending Return', 'class' => 'd-active'],
    'returned' => ['label' => 'Returned',       'class' => 'd-returned'],
    'death'    => ['label' => 'Deceased',       'class' => 'd-death'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($fullName) ?> - Farmer Profile - PIGOBAO</title>
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
  --shadow-soft:0 1px 2px rgba(18,60,22,0.04), 0 8px 24px -12px rgba(18,60,22,0.16);
  --shadow-lift:0 14px 30px -14px rgba(18,60,22,0.30);
}
* { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
body {
    min-height: 100vh; display: flex; background: var(--pg-canvas);
    background-image:
        radial-gradient(1100px 480px at 100% -10%, rgba(46,125,50,0.07), transparent 60%),
        radial-gradient(700px 380px at -5% 100%, rgba(27,94,32,0.05), transparent 55%);
    color: var(--pg-ink);
}
header {
    position: fixed; top: 0; left: 0; width: 100%; height: 65px;
    background: linear-gradient(120deg, var(--pg-forest-900), var(--pg-forest-800) 55%, var(--pg-forest-600));
    color: #fff; display: flex; justify-content: space-between; align-items: center;
    padding: 0 30px; z-index: 1001; box-shadow: 0 2px 14px rgba(10,40,12,0.25);
}
header .brand{display:flex;align-items:center;gap:12px;}
header .brand .mark{
    width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,0.14);
    display:flex;align-items:center;justify-content:center;font-size:15px;color:#eafbea;
    border:1px solid rgba(255,255,255,0.22);
}
header h1 { font-size: 17px; font-weight: 600; letter-spacing:.2px; }
header .brand small{display:block;font-size:11px;font-weight:400;color:rgba(255,255,255,0.65);letter-spacing:.4px;text-transform:uppercase;}
header a.logout {
    color: var(--pg-forest-800); background: #fff; padding: 8px 20px; border-radius: 20px;
    font-weight: 600; text-decoration: none; font-size: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}
.sidebar {
    position: fixed; top: 65px; left: 0; width: 240px; height: calc(100% - 65px);
    background: linear-gradient(195deg, var(--pg-forest-800), var(--pg-forest-600) 120%);
    padding-top: 22px; box-shadow: inset -1px 0 0 rgba(255,255,255,0.06);
}
.sidebar a {
    display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.88);
    padding: 13px 22px; text-decoration: none; margin: 2px 12px 4px; border-radius: 9px;
    border-left: 3px solid transparent; font-size: 14.5px; font-weight: 500;
}
.sidebar a i{width:18px;text-align:center;font-size:15px;opacity:.9;}
.sidebar a:hover { background: rgba(255,255,255,0.12); }
.sidebar a.active {
    background: var(--pg-forest-300); color: var(--pg-forest-900);
    border-left: 3px solid var(--pg-forest-900); font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.18);
}
.sidebar a.active i{opacity:1;}
.main-content { margin-left: 240px; padding: 100px 38px 40px; flex: 1; width: auto; }

.back-link{
    display:inline-flex; align-items:center; gap:8px; font-size:13.5px; font-weight:600;
    color:var(--pg-forest-800); text-decoration:none; margin-bottom:20px;
    background:#fff; border:1px solid var(--pg-line); border-radius:999px;
    padding:9px 18px 9px 14px; box-shadow:var(--shadow-soft);
    transition:transform .18s ease, box-shadow .18s ease, background .18s ease, color .18s ease, border-color .18s ease;
}
.back-link i{
    display:inline-flex; align-items:center; justify-content:center;
    width:22px; height:22px; border-radius:50%;
    background:var(--pg-forest-100); color:var(--pg-forest-800);
    font-size:11px; transition:transform .18s ease, background .18s ease, color .18s ease;
}
.back-link:hover{
    color:#fff; background:var(--pg-forest-800); border-color:var(--pg-forest-800);
    text-decoration:none; transform:translateX(-3px); box-shadow:var(--shadow-lift);
}
.back-link:hover i{ background:rgba(255,255,255,0.2); color:#fff; transform:translateX(-2px); }
.back-link:active{ transform:translateX(-1px) scale(.98); }
.back-link:focus-visible{
    outline:none; box-shadow:var(--shadow-lift), 0 0 0 3px rgba(46,125,50,0.25);
}

/* PROFILE HEADER: photo left, details right */
.profile-panel{
    background:#fff; border:1px solid var(--pg-line); border-radius:16px;
    box-shadow:var(--shadow-soft); padding:30px; display:flex; gap:30px;
    align-items:flex-start; flex-wrap:wrap; margin-bottom:30px; position:relative; overflow:hidden;
}
.profile-panel::before{
    content:""; position:absolute; top:0; left:0; height:5px; width:100%;
    background:linear-gradient(90deg, var(--pg-forest-800), var(--pg-forest-300));
}
.profile-photo{ flex:none; position:relative; }
.profile-photo .photo-trigger{
    display:block; position:relative; border-radius:16px; cursor:pointer;
    overflow:hidden;
}
.profile-photo img{
    width:180px; height:180px; border-radius:16px; object-fit:cover;
    border:4px solid var(--pg-forest-100); box-shadow:0 10px 24px -10px rgba(18,60,22,0.4);
    transition:filter .18s ease, transform .18s ease;
    display:block;
}
.profile-photo .photo-overlay{
    position:absolute; inset:0; border-radius:12px;
    background:rgba(18,60,22,0.55);
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:22px;
    opacity:0; transition:opacity .18s ease;
    pointer-events:none;
}
.profile-photo .photo-trigger:hover img{ transform:scale(1.03); filter:brightness(.92); }
.profile-photo .photo-trigger:hover .photo-overlay{ opacity:1; }
.profile-details{ flex:1; min-width:240px; }
.profile-details h2{
    font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:24px;
    color:var(--pg-forest-900);
}
.profile-details-head{
    display:flex; align-items:center; justify-content:space-between;
    gap:16px; flex-wrap:wrap; margin-bottom:14px;
}
.renewal-btn{
    display:inline-flex; align-items:center; gap:8px; border:none;
    background:var(--pg-forest-800); color:#fff; padding:9px 18px;
    border-radius:10px; font-weight:600; font-size:13.5px; cursor:pointer;
    box-shadow:0 4px 12px -4px rgba(18,60,22,0.4);
}
.renewal-btn:hover{ background:var(--pg-forest-900); }
.pg-alert{
    padding:14px 18px; border-radius:12px; font-size:14px; font-weight:500;
    margin-bottom:20px; border:1px solid transparent;
}
.pg-alert.success{ background:var(--pg-forest-100); color:var(--pg-forest-800); border-color:var(--pg-forest-300); }
.pg-alert.danger{ background:#fdeceb; color:var(--pg-clay); border-color:#f5c6c0; }
.pg-alert.info{ background:#eaf2f8; color:var(--pg-sky); border-color:#cfe3f0; }
.detail-grid{
    display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:16px 24px;
}
.detail-item label{
    display:block; font-size:11.5px; font-weight:600; text-transform:uppercase;
    letter-spacing:.4px; color:var(--pg-ink-soft); margin-bottom:4px;
}
.detail-item div{ font-size:15px; font-weight:500; color:var(--pg-ink); text-transform:capitalize; }
.status-badge{
    display:inline-block; padding:5px 14px; border-radius:999px; font-size:12.5px; font-weight:600;
    text-transform:capitalize;
}
.status-badge.active{background:var(--pg-forest-100); color:var(--pg-forest-800);}
.status-badge.inactive{background:#fdeceb; color:var(--pg-clay);}

.photo-modal-content{ border-radius:16px; overflow:hidden; border:none; box-shadow:var(--shadow-lift); }
.photo-modal-img{
    max-width:100%; max-height:65vh; border-radius:12px; object-fit:contain;
    box-shadow:0 10px 24px -10px rgba(18,60,22,0.35);
}

/* LIVESTOCK RECEIVED TABLE */
.section-label{
    font-family:'Space Grotesk',sans-serif; font-size:13px; font-weight:600;
    letter-spacing:.6px; text-transform:uppercase; color:var(--pg-ink-soft); margin:0 0 14px 2px;
}
.table-card{
    background:#fff; border:1px solid var(--pg-line); border-radius:16px;
    box-shadow:var(--shadow-soft); overflow-x:auto;
}
table.livestock-table{ width:100%; border-collapse:collapse; min-width:640px; }
table.livestock-table thead th{
    background:var(--pg-forest-100); color:var(--pg-forest-900); text-align:left;
    font-size:12.5px; text-transform:uppercase; letter-spacing:.4px; padding:14px 20px; white-space:nowrap;
}
table.livestock-table tbody td{
    padding:14px 20px; font-size:14px; border-top:1px solid var(--pg-line); color:var(--pg-ink);
    text-transform:capitalize;
}
table.livestock-table tbody tr:hover{ background:var(--pg-canvas); }
.empty-row td{ text-align:center; color:var(--pg-ink-soft); padding:36px 20px; text-transform:none; }
.dist-status{
    display:inline-block; padding:4px 12px; border-radius:999px; font-size:11.5px; font-weight:600;
}
.dist-status.d-active{background:var(--pg-forest-100); color:var(--pg-forest-800);}
.dist-status.d-returned{background:#fff4e0; color:var(--pg-amber);}
.dist-status.d-death{background:#fdeceb; color:var(--pg-clay);}

@media(max-width:900px){
    .sidebar{ width:200px; }
    .main-content{ margin-left:200px; padding:98px 24px 30px; }
}

@media(max-width:768px) {
    header{ padding:0 16px; }
    header h1{ font-size:15px; }

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
    .profile-panel{ flex-direction:column; align-items:center; text-align:center; }
    .detail-grid{ text-align:left; }
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
            <small>Farmer Profile &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../adminlog_out.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="./add-technician.php"><i class="fas fa-user-cog"></i>  Technicians</a>
    <a href="./farmer-beneficiary.php" class="active"><i class="fas fa-id-card"></i> Farmer Beneficiary</a>
    <a href="./add-livestock.php"><i class="fas fa-drumstick-bite"></i> Livestock</a>
    <a href="./tracking.php"><i class="fas fa-eye"></i>Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php"><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>

<div class="main-content">
    <div class="page-card">
        <div class="page-icon"><i class="fas fa-user"></i></div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Farmer Profile</h2>
            <p>Personal details and the full livestock distribution history of this beneficiary.</p>
        </div>
        <span class="role-pill"><i class="fas fa-shield-halved"></i> Administrator</span>
    </div>

    <a class="back-link" href="farmer-beneficiary.php"><i class="fas fa-arrow-left"></i> Back</a>

    <?php if ($renewalMessage): ?>
        <div class="pg-alert <?= htmlspecialchars($renewalMessageType) ?>">
            <?= htmlspecialchars($renewalMessage) ?>
        </div>
    <?php endif; ?>

    <div class="profile-panel">
        <div class="profile-photo">
            <a href="#" class="photo-trigger" data-bs-toggle="modal" data-bs-target="#photoModal" title="Click to view full photo">
                <img src="<?= $photo ?>" alt="Farmer photo">
                <span class="photo-overlay"><i class="fas fa-magnifying-glass-plus"></i></span>
            </a>
        </div>
        <div class="profile-details">
            <div class="profile-details-head">
                <h2><?= htmlspecialchars($fullName) ?></h2>
                <button
                    type="button"
                    class="renewal-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#renewalModal"
                >
                    <i class="fas fa-rotate"></i>
                    Renew &amp; Distribute
                </button>
            </div>
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Address (Barangay)</label>
                    <div><?= htmlspecialchars($farmer['barangay_name']) ?></div>
                </div>
                <div class="detail-item">
                    <label>Birthdate</label>
                    <div><?= htmlspecialchars($birthdateDisplay) ?></div>
                </div>
                <div class="detail-item">
                    <label>Status</label>
                    <div><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($farmer['status']) ?></span></div>
                </div>
                <div class="detail-item">
                    <label>Gender</label>
                    <div><?= htmlspecialchars($farmer['gender']) ?></div>
                </div>
                <div class="detail-item">
                    <label>Civil Status</label>
                    <div><?= htmlspecialchars($farmer['civil_status'] ?? '—') ?></div>
                </div>
                <?php if (!empty($farmer['contact'])): ?>
                <div class="detail-item">
                    <label>Contact Number</label>
                    <div><?= htmlspecialchars($farmer['contact']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <p class="section-label">Livestock Received / Distributed</p>
    <div class="table-card">
        <table class="livestock-table">
            <thead>
                <tr>
                    <th>Livestock</th>
                    <th>Breed</th>
                    <th>Eartag No.</th>
                    <th>Date Distributed</th>
                    <th>Technician</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($distributions && $distributions->num_rows > 0): ?>
                    <?php while($d = $distributions->fetch_assoc()): ?>
                        <?php $ds = $distStatusMap[$d['status']] ?? ['label' => $d['status'], 'class' => 'd-active']; ?>
                        <tr>
                            <td><?= htmlspecialchars($d['kind']) ?></td>
                            <td><?= htmlspecialchars($d['breed']) ?></td>
                            <td><?= htmlspecialchars($d['batch_no']) ?></td>
                            <td><?= !empty($d['distribution_date']) ? date('F j, Y', strtotime($d['distribution_date'])) : '—' ?></td>
                            <td><?= htmlspecialchars($d['technician_name']) ?></td>
                            <td><span class="dist-status <?= $ds['class'] ?>"><?= htmlspecialchars($ds['label']) ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-row"><td colspan="7">No livestock received yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="section-label" style="margin-top:30px;">Renewal Records</p>
    <div class="table-card">
        <table class="livestock-table">
            <thead>
                <tr>
                    <th>Renewal Date</th>
                    <th>Previous Record</th>
                    <th>Remarks</th>
                    <th>New Record (once distributed)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($renewals && $renewals->num_rows > 0): ?>
                    <?php while($r = $renewals->fetch_assoc()): ?>
                        <?php $rs = $renewalStatusMap[$r['status']] ?? ['label' => $r['status'], 'class' => 'd-active']; ?>
                        <tr>
                            <td><?= !empty($r['renewal_date']) ? date('F j, Y', strtotime($r['renewal_date'])) : '—' ?></td>
                            <td><?= $r['prev_kind'] ? htmlspecialchars($r['prev_kind'] . ' — Eartag ' . $r['prev_batch_no']) : '—' ?></td>
                            <td style="text-transform:none;"><?= $r['remarks'] !== null && $r['remarks'] !== '' ? htmlspecialchars($r['remarks']) : '—' ?></td>
                            <td>
                                <?php if ($r['new_kind']): ?>
                                    <?= htmlspecialchars($r['new_kind'] . ' — Eartag ' . $r['new_batch_no']) ?>
                                    (<?= date('F j, Y', strtotime($r['new_distribution_date'])) ?>)
                                <?php else: ?>
                                    Awaiting next distribution
                                <?php endif; ?>
                            </td>
                            <td><span class="dist-status <?= $rs['class'] ?>"><?= htmlspecialchars($rs['label']) ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-row"><td colspan="5">No renewal records yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</div>

<!-- =====================================================
     VIEW PHOTO MODAL (view full-size / download)
===================================================== -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content photo-modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= htmlspecialchars($fullName) ?>'s Photo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="<?= $photo ?>" alt="Farmer photo full size" class="photo-modal-img">
            </div>
            <div class="modal-footer">
                <?php if (!empty($farmer['photo'])): ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     ADD RENEWAL RECORD MODAL
===================================================== -->
<div class="modal fade" id="renewalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="beneficiary_id" value="<?= (int)$id ?>">

                <div class="modal-header">
                    <h5 class="modal-title">Renew &amp; Distribute</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <p style="font-size:13.5px; color:var(--pg-ink-soft); margin-bottom:16px;">
                        Pick the livestock to hand over below — saving this
                        distributes it to this beneficiary right away and
                        closes out the renewal in the same step. No separate
                        trip to the Distribution page needed.
                    </p>

                    <div class="mb-3">
                        <label class="form-label">Previous Record Being Renewed</label>
                        <select name="previous_distribution_id" class="form-control">
                            <option value="">-- No specific previous record --</option>
                            <?php if ($renewableDistributions && $renewableDistributions->num_rows > 0): ?>
                                <?php while ($rd = $renewableDistributions->fetch_assoc()): ?>
                                    <option value="<?= (int)$rd['distribution_id'] ?>">
                                        <?= htmlspecialchars(
                                            ucfirst($rd['kind']) . ' — Eartag ' . $rd['batch_no'] .
                                            ' (' . ucfirst($rd['status']) . ', ' .
                                            date('M j, Y', strtotime($rd['distribution_date'])) . ')'
                                        ) ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Livestock to Distribute</label>
                        <select name="livestock_id" id="renewLivestockSelect" class="form-control" required>
                            <option value="" data-batch="">-- Choose available livestock --</option>
                            <?php if ($availableLivestock && $availableLivestock->num_rows > 0): ?>
                                <?php $availableLivestock->data_seek(0); ?>
                                <?php while ($lv = $availableLivestock->fetch_assoc()): ?>
                                    <option
                                        value="<?= (int)$lv['livestock_id'] ?>"
                                        data-batch="<?= htmlspecialchars($lv['batch_no'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <?= htmlspecialchars(
                                            ucfirst($lv['kind']) . ($lv['breed'] ? ', ' . $lv['breed'] : '') .
                                            ' — Eartag ' . $lv['batch_no']
                                        ) ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <option value="" disabled>No available livestock right now</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Eartag No.</label>
                        <input type="text" id="renewBatchNo" class="form-control" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Distribution / Renewal Date</label>
                        <input type="date" name="renewal_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Location</label>
                        <input
                            type="text"
                            name="distribution_location"
                            class="form-control"
                            value="<?= htmlspecialchars($farmer['barangay_name']) ?>"
                            placeholder="Enter distribution location"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Optional remarks"></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="renew_distribute" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        Renew &amp; Distribute
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const livestockSelect = document.getElementById('renewLivestockSelect');
    const batchNoField = document.getElementById('renewBatchNo');

    if (livestockSelect && batchNoField) {
        livestockSelect.addEventListener('change', function () {
            const selected = livestockSelect.options[livestockSelect.selectedIndex];
            batchNoField.value = selected ? (selected.getAttribute('data-batch') || '') : '';
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>