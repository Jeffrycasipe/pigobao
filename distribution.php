<?php
session_start();
require '../db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

$message = "";
$messageType = "info";

/* =========================================================
   HANDLE DISTRIBUTION
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

        /* =====================================================
           FIND TECHNICIAN ASSIGNED TO BENEFICIARY BARANGAY
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

        $techLookup->bind_param("i", $beneficiary_id);
        $techLookup->execute();

        $techResult = $techLookup->get_result();
        $techRow = $techResult->fetch_assoc();

        $tech_id = $techRow ? (int)$techRow['technician_id'] : 0;

        $techLookup->close();


        /* =====================================================
           CHECK LIVESTOCK AVAILABILITY
           (also pulls its batch_no — the batch no is always
           sourced from the livestock record, never typed by hand)
        ===================================================== */

        $checkStock = $conn->prepare("
            SELECT status, batch_no
            FROM livestock
            WHERE livestock_id = ?
            LIMIT 1
        ");

        $checkStock->bind_param("i", $livestock_id);
        $checkStock->execute();

        $stockResult = $checkStock->get_result();
        $stockRow = $stockResult->fetch_assoc();

        $checkStock->close();

        $batch_no = $stockRow ? $stockRow['batch_no'] : null;


        /* =====================================================
           CHECK BENEFICIARY HASN'T ALREADY RECEIVED A DISTRIBUTION
        ===================================================== */

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


        /* =====================================================
           VALIDATE TECHNICIAN, LIVESTOCK, AND BENEFICIARY
        ===================================================== */

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

            /* =================================================
               START TRANSACTION
            ================================================= */

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
                    $beneficiary_id,
                    $livestock_id,
                    $quantity,
                    $date,
                    $location,
                    $batch_no,
                    $remarks
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
                   AUTO-FULFILL PENDING RENEWAL (if any)
                   If this beneficiary had an "Add Renewal Record"
                   pending from the Farmer Profile page, this new
                   distribution is the result of that renewal —
                   link it back and close the renewal out, instead
                   of leaving it as a disconnected, separate entry.
                ============================================= */

                $renewalLookup = $conn->prepare("
                    SELECT renewal_id
                    FROM renewals
                    WHERE beneficiary_id = ?
                      AND status = 'pending'
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $renewalLookup->bind_param("i", $beneficiary_id);
                $renewalLookup->execute();
                $pendingRenewal = $renewalLookup->get_result()->fetch_assoc();
                $renewalLookup->close();

                if ($pendingRenewal) {

                    $fulfillRenewal = $conn->prepare("
                        UPDATE renewals
                        SET new_distribution_id = ?,
                            status = 'fulfilled'
                        WHERE renewal_id = ?
                    ");
                    $fulfillRenewal->bind_param(
                        "ii",
                        $newDistributionId,
                        $pendingRenewal['renewal_id']
                    );
                    $fulfillRenewal->execute();
                    $fulfillRenewal->close();
                }

            } catch(mysqli_sql_exception $e) {

                $ok = false;

                error_log("Distribution insert failed: ".$e->getMessage());
            }


            /* =================================================
               COMMIT / ROLLBACK
            ================================================= */

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


/* =========================================================
   FETCH AVAILABLE LIVESTOCK
   (this is now the main list on the page — pick a livestock
   item first, then choose who it goes to)
========================================================= */

$kindFilter = trim($_GET['kind'] ?? '');

if($kindFilter !== '') {

    $livestockList = $conn->prepare("
        SELECT
            livestock_id,
            batch_no,
            kind,
            breed,
            sex,
            birth_date,
            status
        FROM livestock
        WHERE status = 'available'
          AND kind = ?
        ORDER BY batch_no ASC
    ");

    $livestockList->bind_param("s", $kindFilter);
    $livestockList->execute();
    $livestockList = $livestockList->get_result();

} else {

    $livestockList = $conn->query("
        SELECT
            livestock_id,
            batch_no,
            kind,
            breed,
            sex,
            birth_date,
            status
        FROM livestock
        WHERE status = 'available'
        ORDER BY batch_no ASC
    ");

}


/* =========================================================
   FETCH BENEFICIARIES
   (excludes beneficiaries who already have an active
   distribution, so they disappear from the dropdown once
   they've received livestock. Used to populate the
   beneficiary select inside the Distribute modal.)
========================================================= */

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

/* =========================================================
   GROUP AVAILABLE LIVESTOCK FOR DISPLAY
   (same kind + breed collapse into one row with a Qty pill,
   so the table doesn't get long. The DB still has one row per
   animal — grouping is display-only. Each animal keeps its own
   Distribute button inside the expandable group.)
========================================================= */

$availableGroups = [];
if($livestockList) {
    while($l = $livestockList->fetch_assoc()) {
        $gkey = 'd' . md5($l['kind'] . '||' . ($l['breed'] ?? ''));
        if(!isset($availableGroups[$gkey])) {
            $availableGroups[$gkey] = ['sample' => $l, 'items' => []];
        }
        $availableGroups[$gkey]['items'][] = $l;
    }
}

/**
 * Human-readable age from a birth_date, e.g. "1 yr 4 mo". Returns
 * an em dash when there's no birth date on record.
 */
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

/**
 * Renders one "Distribute" button for a specific livestock record.
 * Used both for a lone/group-shortcut button and for each child row.
 */
function renderDistributeButton($l) {
    ?>
    <button
        type="button"
        class="action-btn distribute-btn"
        data-bs-toggle="modal"
        data-bs-target="#distributeModal"
        data-livestock-id="<?= (int)$l['livestock_id'] ?>"
        data-livestock-name="<?= htmlspecialchars(
            ucfirst($l['kind']).($l['breed'] ? ", ".$l['breed'] : ""),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
        data-batch-no="<?= htmlspecialchars(
            $l['batch_no'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >
        <i class="fas fa-exchange-alt"></i>
        Distribute
    </button>
    <?php
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<title>Distribution - PIGOBAO</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<!-- Font Awesome -->
<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
>

<!-- Bootstrap -->
<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
>

<!-- Google Fonts -->
<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap"
    rel="stylesheet"
>


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

    --shadow-soft:
        0 1px 2px rgba(18,60,22,0.04),
        0 8px 24px -12px rgba(18,60,22,0.16);

    --shadow-lift:
        0 14px 30px -14px rgba(18,60,22,0.30);
}


/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Poppins', sans-serif;
}

body {

    min-height: 100vh;

    display: flex;

    background: var(--pg-canvas);

    background-image:
        radial-gradient(
            1100px 480px at 100% -10%,
            rgba(46,125,50,0.07),
            transparent 60%
        ),
        radial-gradient(
            700px 380px at -5% 100%,
            rgba(27,94,32,0.05),
            transparent 55%
        );

    color: var(--pg-ink);
}


/* =========================================================
   HEADER
========================================================= */

header {

    position: fixed;

    top: 0;
    left: 0;

    width: 100%;
    height: 65px;

    background:
        linear-gradient(
            120deg,
            var(--pg-forest-900),
            var(--pg-forest-800) 55%,
            var(--pg-forest-600)
        );

    color: #fff;

    display: flex;

    justify-content: space-between;
    align-items: center;

    padding: 0 30px;

    z-index: 1001;

    box-shadow:
        0 2px 14px rgba(10,40,12,0.25);
}


header .brand {

    display: flex;

    align-items: center;

    gap: 12px;
}


header .brand .mark {

    width: 34px;
    height: 34px;

    border-radius: 9px;

    background: rgba(255,255,255,0.14);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 15px;

    color: #eafbea;

    border:
        1px solid rgba(255,255,255,0.22);
}


header h1 {

    font-size: 17px;

    font-weight: 600;

    letter-spacing: .2px;
}


header .brand small {

    display: block;

    font-size: 11px;

    font-weight: 400;

    color: rgba(255,255,255,0.65);

    letter-spacing: .4px;

    text-transform: uppercase;
}


header a.logout {

    color: var(--pg-forest-800);

    background: #fff;

    padding: 8px 20px;

    border-radius: 20px;

    font-weight: 600;

    text-decoration: none;

    font-size: 14px;

    box-shadow:
        0 2px 8px rgba(0,0,0,0.12);

    transition: .2s ease;
}


header a.logout:hover {

    background: var(--pg-forest-100);

    transform: translateY(-1px);

    box-shadow:
        0 4px 12px rgba(0,0,0,0.16);
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    position: fixed;

    top: 65px;
    left: 0;

    width: 240px;

    height: calc(100% - 65px);

    background:
        linear-gradient(
            195deg,
            var(--pg-forest-800),
            var(--pg-forest-600) 120%
        );

    padding-top: 22px;

    box-shadow:
        inset -1px 0 0 rgba(255,255,255,0.06);
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

    transition: .2s ease;

    position: relative;
}


.sidebar a i {

    width: 18px;

    text-align: center;

    font-size: 15px;

    opacity: .9;
}


.sidebar a:hover {

    background: rgba(255,255,255,0.12);

    transform: translateX(2px);
}


.sidebar a.active {

    background: var(--pg-forest-300);

    color: var(--pg-forest-900);

    border-left:
        3px solid var(--pg-forest-900);

    font-weight: 600;

    box-shadow:
        0 4px 12px rgba(0,0,0,0.18);
}


.sidebar a.active i {

    opacity: 1;
}


/* =========================================================
   MAIN CONTENT
========================================================= */

.main-content {

    margin-left: 240px;

    padding: 100px 38px 40px;

    flex: 1;

    width: auto;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.page-card {

    background: #fff;

    border-radius: 16px;

    padding: 26px 30px;

    box-shadow: var(--shadow-soft);

    border:
        1px solid var(--pg-line);

    display: flex;

    align-items: center;

    gap: 22px;

    flex-wrap: wrap;

    margin-bottom: 28px;

    position: relative;

    overflow: hidden;
}


.page-card::before {

    content: "";

    position: absolute;

    top: 0;
    left: 0;

    height: 5px;

    width: 100%;

    background:
        linear-gradient(
            90deg,
            var(--pg-forest-800),
            var(--pg-forest-300)
        );
}


.page-icon {

    width: 60px;
    height: 60px;

    border-radius: 16px;

    flex: none;

    background:
        linear-gradient(
            145deg,
            var(--pg-forest-600),
            var(--pg-forest-900)
        );

    display: flex;

    align-items: center;
    justify-content: center;

    color: #fff;

    font-size: 22px;

    box-shadow:
        0 8px 18px -6px rgba(18,60,22,0.5);
}


.page-info h2 {

    font-family: 'Space Grotesk', sans-serif;

    font-weight: 700;

    font-size: 21px;

    margin: 0 0 4px;

    color: var(--pg-forest-900);
}


.page-info p {

    font-size: 13.5px;

    color: var(--pg-ink-soft);

    margin: 0;
}


/* =========================================================
   SECTION LABEL
========================================================= */

.section-label {

    font-family: 'Space Grotesk', sans-serif;

    font-size: 13px;

    font-weight: 600;

    letter-spacing: .6px;

    text-transform: uppercase;

    color: var(--pg-ink-soft);

    margin: 28px 0 14px 2px;
}


.section-label:first-of-type {

    margin-top: 4px;
}


/* =========================================================
   ALERT
========================================================= */

.pg-alert {

    background: var(--pg-forest-100);

    color: var(--pg-forest-900);

    border:
        1px solid var(--pg-forest-300);

    border-left:
        4px solid var(--pg-forest-600);

    border-radius: 10px;

    padding: 13px 18px;

    margin-bottom: 20px;

    font-size: 14px;

    font-weight: 500;

    display: flex;

    align-items: center;

    gap: 10px;
}


.pg-alert.danger {

    background: #fbeceb;

    color: #8a2e22;

    border-color: #f3d3d0;

    border-left-color: var(--pg-clay);
}


.pg-alert.info {

    background: #eaf2f8;

    color: #2c536e;

    border-color: #d4e6f0;

    border-left-color: var(--pg-sky);
}


/* =========================================================
   FILTER BAR
========================================================= */

.filter-bar {

    display: flex;

    align-items: center;

    gap: 12px;

    margin: 0 0 16px 2px;
}


.filter-bar .form-label {

    font-size: 13.5px;

    font-weight: 600;

    color: var(--pg-ink-soft);

    margin: 0;
}


.filter-bar .form-select {

    width: auto;

    min-width: 180px;

    border-radius: 10px;

    border: 1px solid var(--pg-line);

    padding: 8px 34px 8px 14px;

    font-size: 14px;

    font-weight: 500;

    color: var(--pg-ink);

    background-color: #fff;
}


.filter-bar .form-select:focus {

    outline: none;

    border-color: var(--pg-forest-600);

    box-shadow: 0 0 0 3px rgba(46,125,50,0.15);
}


.filter-bar .filter-clear {

    font-size: 13px;

    font-weight: 600;

    color: var(--pg-clay);

    text-decoration: none;
}


.filter-bar .filter-clear:hover {

    text-decoration: underline;
}


/* =========================================================
   TABLE
========================================================= */

.table-card {

    background: #fff;

    border-radius: 16px;

    border:
        1px solid var(--pg-line);

    box-shadow: var(--shadow-soft);

    overflow: hidden;
}


.table-responsive {

    width: 100%;

    overflow-x: auto;
}


table.pg-table {

    width: 100%;

    border-collapse: collapse;

    font-size: 14px;
}


table.pg-table thead th {

    background: var(--pg-forest-900);

    color: #eafbea;

    font-family: 'Space Grotesk', sans-serif;

    font-weight: 600;

    font-size: 12.5px;

    text-transform: uppercase;

    letter-spacing: .4px;

    padding: 15px 18px;

    text-align: left;

    border: none;

    white-space: nowrap;
}
table.pg-table tbody td {

    padding: 14px 18px;

    border-bottom:
        1px solid var(--pg-line);

    color: var(--pg-ink);

    vertical-align: middle;

    white-space: nowrap;
}
table.pg-table tbody tr:hover {

    background: var(--pg-forest-100);
}
table.pg-table tbody tr:last-child td {

    border-bottom: none;
}
table.pg-table tbody td.empty-row {

    white-space: normal;

    text-align: center;

    padding: 30px 18px;

    color: var(--pg-ink-soft);

    font-style: italic;
}
.qty-pill{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:30px;height:24px;padding:0 8px;border-radius:12px;
  background:var(--pg-forest-100);color:var(--pg-forest-800);
  font-weight:700;font-size:12.5px;border:1px solid var(--pg-forest-300);
}
.tag-range{font-weight:600;letter-spacing:.2px;}
.action-btn.toggle{
  background:#fff;color:var(--pg-ink-soft);
  border:1px solid var(--pg-line);
}
.action-btn.toggle:hover{background:var(--pg-forest-100);color:var(--pg-forest-800);}
tr.child-row{background:rgba(232,245,233,0.45);}
tr.child-row td{font-size:13px;}
.child-tag{color:var(--pg-forest-800);font-weight:600;}
.child-note{color:var(--pg-ink-soft);}
.kind-filter-bar{
  display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px 2px;
}
.kind-filter-btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:9px 18px;border-radius:999px;
  border:1px solid var(--pg-line);
  background:#fff;color:var(--pg-ink-soft);
  font-size:13.5px;font-weight:600;
  text-decoration:none;transition:.2s ease;
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
.kind-pill {

    padding: 5px 13px;

    border-radius: 999px;

    font-size: 12px;

    font-weight: 600;

    letter-spacing: .3px;

    background: #fdf3e3;

    color: var(--pg-amber);

    text-transform: capitalize;
}
.action-btn {

    border: none;

    border-radius: 8px;

    padding: 8px 15px;

    font-size: 12.5px;

    font-weight: 600;

    display: inline-flex;

    align-items: center;

    gap: 6px;

    text-decoration: none;

    transition: .2s ease;

    cursor: pointer;

    background:
        linear-gradient(
            120deg,
            var(--pg-forest-800),
            var(--pg-forest-600)
        );

    color: #fff;
}
.action-btn:hover {

    transform: translateY(-1px);

    box-shadow:
        0 8px 16px -6px rgba(18,60,22,0.5);

    color: #fff;
}
.modal {

    z-index: 2000 !important;
}
.modal-backdrop {

    z-index: 1990 !important;
}
.modal-dialog {

    margin-top: 80px;
}
.modal-content {

    border-radius: 16px;

    border: none;

    overflow: hidden;

    box-shadow: var(--shadow-lift);
}
.modal-header {

    background:
        linear-gradient(
            120deg,
            var(--pg-forest-900),
            var(--pg-forest-800) 55%,
            var(--pg-forest-600)
        );

    color: #fff;

    border: none;

    padding: 18px 24px;
}
.modal-header .modal-title {

    font-family: 'Space Grotesk', sans-serif;

    font-weight: 600;

    font-size: 16px;

    display: flex;

    align-items: center;

    gap: 10px;
}
.modal-header .btn-close {

    filter:
        invert(1)
        brightness(2);
}
.modal-body {

    padding: 24px;

    background: var(--pg-canvas);

    max-height: 70vh;

    overflow-y: auto;
}
.modal-body .form-label {

    font-size: 13px;

    font-weight: 600;

    color: var(--pg-ink-soft);

    margin-bottom: 6px;

    text-transform: uppercase;

    letter-spacing: .3px;
}
.modal-body .form-control,
.modal-body select.form-control,
.modal-body textarea.form-control {

    border:
        1px solid var(--pg-line);

    border-radius: 9px;

    padding: 10px 14px;

    font-size: 14px;
}


.modal-body .form-control:focus {

    border-color:
        var(--pg-forest-600);

    box-shadow:
        0 0 0 3px rgba(46,125,50,0.14);
}


.modal-body input[readonly] {

    background: #eef2ef;

    color: var(--pg-ink);

    cursor: default;
}


.modal-footer {

    border: none;

    padding: 16px 24px 22px;

    background: var(--pg-canvas);
}


.modal-footer .btn-success {

    background:
        linear-gradient(
            120deg,
            var(--pg-forest-800),
            var(--pg-forest-600)
        );

    border: none;

    border-radius: 9px;

    padding: 10px 20px;

    font-weight: 600;

    font-size: 14px;

    box-shadow:
        0 6px 16px -6px rgba(18,60,22,0.5);
}


.modal-footer .btn-success:hover {

    transform: translateY(-1px);
}


.modal-footer .btn-secondary {

    border-radius: 9px;

    padding: 10px 20px;

    font-weight: 600;

    font-size: 14px;
}
@media(max-width:900px){
    .sidebar{ width:200px; }
    .main-content{ margin-left:200px; padding:98px 24px 30px; }
}

@media(max-width:768px) {
    header{ padding:0 16px; }
    header h1{ font-size:15px; }

    .sidebar {
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

    .main-content {
        margin-left: 0;
        padding: 20px 16px 30px;
    }

    .page-card {
        padding: 22px;
    }

    .modal-dialog {
        margin: 70px 10px 10px;
    }
}

</style>

</head>


<body>
<header>

    <div class="brand">

        <div class="mark">
            <i class="fas fa-paw"></i>
        </div>

        <div>

            <h1>PIGOBAO</h1>

            <small>
                Admin Dashboard
            </small>

        </div>

    </div>


    <a
        href="../adminlog_out.php"
        class="logout"
    >
        Logout
    </a>

</header>
<div class="sidebar">
    <a href="../admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="./add-technician.php"><i class="fas fa-user-cog"></i>Technicians</a>
    <a href="./farmer-beneficiary.php"><i class="fas fa-id-card"></i> Farmer Beneficiary</a>
    <a href="./add-livestock.php"><i class="fas fa-drumstick-bite"></i> Add Livestock</a>
    <a href="./distribution.php"class="active"><i class="fas fa-exchange-alt"></i> Distribution</a>
    <a href="./tracking.php"><i class="fas fa-eye"></i> Livestock Tracking</a>
    <a href="./dispersal.php"><i class="fas fa-people-carry-box"></i> Dispersal</a>
    <a href="./farmer-success-rate.php" ><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="./reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="./generate-report.php"><i class="fas fa-file-alt"></i> Generate Report</a>
</div>



<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<div class="main-content">


    <!-- PAGE HEADER -->

    <div class="page-card">

        <div class="page-icon">
            <i class="fas fa-exchange-alt"></i>
        </div>

        <div class="page-info">

            <h2>
                Livestock Distribution
            </h2>

            <p>
                Distribute available livestock to registered beneficiaries.
            </p>

        </div>

    </div>



    <!-- ALERT -->

    <?php if($message): ?>

        <div class="pg-alert <?= htmlspecialchars($messageType) ?>">

            <i class="fas fa-circle-info"></i>

            <?= htmlspecialchars($message) ?>

        </div>

    <?php endif; ?>
    <p class="section-label">
        Livestock Available for Distribution
    </p>

    <div class="kind-filter-bar">

        <a
            href="distribution.php"
            class="kind-filter-btn <?= $kindFilter === '' ? 'active' : '' ?>"
        >
            <i class="fas fa-layer-group"></i> All
        </a>

        <a
            href="distribution.php?kind=pig"
            class="kind-filter-btn <?= $kindFilter === 'pig' ? 'active' : '' ?>"
        >
            <i class="fas fa-piggy-bank"></i> Pig
        </a>

        <a
            href="distribution.php?kind=goat"
            class="kind-filter-btn <?= $kindFilter === 'goat' ? 'active' : '' ?>"
        >
            <i class="fas fa-paw"></i> Goat
        </a>

        <a
            href="distribution.php?kind=carabao"
            class="kind-filter-btn <?= $kindFilter === 'carabao' ? 'active' : '' ?>"
        >
            <i class="fas fa-cow"></i> Carabao
        </a>

    </div>


    <div class="table-card">

        <div class="table-responsive">

            <table class="pg-table">

                <thead>

                    <tr>

                        <th>
                            Eartag no
                        </th>

                        <th>
                            Qty
                        </th>

                        <th>
                            Kind
                        </th>

                        <th>
                            Breed
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php if(count($availableGroups) > 0): ?>

                        <?php
                        $viewModalsHtml = "";
                        foreach($availableGroups as $gkey => $group):
                            $sample = $group['sample'];
                            $count  = count($group['items']);
                            $first  = $group['items'][0];
                            $last   = $group['items'][$count - 1];
                        ?>

                            <tr>

                                <td>
                                    <?php if($count > 1): ?>
                                        <span class="tag-range"><?= htmlspecialchars($first['batch_no']) ?> – <?= htmlspecialchars($last['batch_no']) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($first['batch_no']) ?>
                                    <?php endif; ?>
                                </td>

                                <td><span class="qty-pill"><?= $count ?></span></td>

                                <td>
                                    <span class="kind-pill">
                                        <?= htmlspecialchars($sample['kind']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="kind-pill">
                                        <?= htmlspecialchars($sample['breed'] ?: '—') ?>
                                    </span>
                                </td>

                                <td>
                                    <?= htmlspecialchars($sample['status']) ?>
                                </td>

                                <td>
                                    <?php if($count === 1): ?>
                                        <?php renderDistributeButton($first); ?>
                                    <?php else: ?>
                                        <button
                                            type="button"
                                            class="action-btn toggle"
                                            data-bs-toggle="modal"
                                            data-bs-target="#viewGroupModal<?= $gkey ?>"
                                        >
                                            <i class="fas fa-list"></i> View <?= $count ?>
                                        </button>
                                    <?php endif; ?>
                                </td>

                            </tr>

                            <?php if($count > 1):
                                ob_start();
                            ?>

                                <div class="modal fade" id="viewGroupModal<?= $gkey ?>" tabindex="-1" aria-hidden="true">
                                  <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h5 class="modal-title">
                                            <i class="fas fa-list"></i>
                                            <?= htmlspecialchars(ucfirst($sample['kind'])) ?><?= $sample['breed'] ? ' — ' . htmlspecialchars($sample['breed']) : '' ?>
                                            (<?= $count ?> heads)
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      <div class="modal-body">
                                        <div class="table-responsive">
                                            <table class="table pg-table">
                                                <thead>
                                                    <tr>
                                                        <th>Eartag no</th>
                                                        <th>Qty</th>
                                                        <th>Kind</th>
                                                        <th>Breed</th>
                                                        <th>Status</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach($group['items'] as $l): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($l['batch_no']) ?></td>
                                                        <td><span class="qty-pill">1</span></td>
                                                        <td><span class="kind-pill"><?= htmlspecialchars($l['kind']) ?></span></td>
                                                        <td><span class="kind-pill"><?= htmlspecialchars($l['breed'] ?: '—') ?></span></td>
                                                        <td><?= htmlspecialchars($l['status']) ?></td>
                                                        <td><?php renderDistributeButton($l); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                      </div>
                                    </div>
                                  </div>
                                </div>

                            <?php
                                $viewModalsHtml .= ob_get_clean();
                            endif; ?>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="6"
                                class="empty-row"
                            >
                                <em>
                                    <?= $kindFilter !== ''
                                        ? "No available livestock matches the selected kind."
                                        : "No livestock available for distribution."
                                    ?>
                                </em>
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- View Group Modals (rendered outside the table for valid HTML) -->
    <?= $viewModalsHtml ?? '' ?>

    <div
        class="modal fade"
        id="distributeModal"
        tabindex="-1"
        aria-labelledby="distributeModalLabel"
        aria-hidden="true"
    >

        <div class="modal-dialog modal-lg modal-dialog-centered">

            <form
                method="POST"
                action="distribution.php"
                class="modal-content"
            >


                <!-- MODAL HEADER -->

                <div class="modal-header">

                    <h5
                        class="modal-title"
                        id="distributeModalLabel"
                    >

                        <i class="fas fa-exchange-alt"></i>

                        Distribute Livestock

                    </h5>


                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>

                </div>



                <!-- MODAL BODY -->

                <div class="modal-body">


                    <!-- LIVESTOCK ID -->

                    <input
                        type="hidden"
                        name="livestock_id"
                        id="modalLivestockId"
                        value=""
                    >


                    <!-- LIVESTOCK (auto, read-only) -->

                    <div class="mb-3">

                        <label class="form-label">
                            Livestock
                        </label>

                        <input
                            type="text"
                            id="modalLivestockName"
                            class="form-control"
                            readonly
                        >

                    </div>



                    <!-- BENEFICIARY -->

                    <div class="mb-3">

                        <label class="form-label">
                            Select Beneficiary
                        </label>


                        <select
                            name="beneficiary_id"
                            id="modalBeneficiarySelect"
                            class="form-control"
                            required
                        >

                            <option value="" data-barangay="">
                                -- Choose Beneficiary --
                            </option>


                            <?php

                            if(
                                $beneficiaries &&
                                $beneficiaries->num_rows > 0
                            ):

                                $beneficiaries->data_seek(0);

                                while(
                                    $b = $beneficiaries->fetch_assoc()
                                ):

                            ?>

                                <option
                                    value="<?= (int)$b['beneficiary_id'] ?>"
                                    data-barangay="<?= htmlspecialchars($b['barangay_name'], ENT_QUOTES, 'UTF-8') ?>"
                                >

                                    <?= htmlspecialchars(
                                        $b['firstname']." ".$b['lastname']." — ".$b['barangay_name']
                                        . (!empty($b['pending_renewal_id']) ? ' (Renewal)' : '')
                                    ) ?>

                                </option>

                            <?php

                                endwhile;

                            endif;

                            ?>

                        </select>

                    </div>
                    <!-- BATCH NO (auto, read-only) -->

                    <div class="mb-3">

                        <label class="form-label">
                            Eartag no
                        </label>


                        <input
                            type="text"
                            id="modalBatchNo"
                            class="form-control"
                            readonly
                        >

                    </div>
                    <!-- QUANTITY: not a form field — always 1, enforced
                         server-side in distribution.php regardless of
                         what the client sends. -->
                    <!-- DATE -->

                    <div class="mb-3">

                        <label class="form-label">
                            Distribution Date
                        </label>
                        <input
                            type="date"
                            name="distribution_date"
                            class="form-control"
                            value="<?= date('Y-m-d') ?>"
                            required
                        >

                    </div>
                    <!-- LOCATION (auto-filled from beneficiary's barangay) -->

                    <div class="mb-3">

                        <label class="form-label">
                            Location
                        </label>
                        <input
                            type="text"
                            name="distribution_location"
                            id="modalLocation"
                            class="form-control"
                            placeholder="Enter distribution location"
                        >

                    </div>



                    <!-- REMARKS -->

                    <div class="mb-3">

                        <label class="form-label">
                            Remarks
                        </label>


                        <textarea
                            name="remarks"
                            class="form-control"
                            rows="3"
                            placeholder="Optional remarks"
                        ></textarea>

                    </div>


                </div>



                <!-- MODAL FOOTER -->

                <div class="modal-footer">


                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >

                        Cancel

                    </button>


                    <button
                        type="submit"
                        name="distribute"
                        class="btn btn-success"
                    >

                        <i class="fas fa-save"></i>

                        Confirm Distribution

                    </button>

                </div>


            </form>

        </div>

    </div>


</div>

<!-- =====================================================
     BOOTSTRAP JAVASCRIPT
===================================================== -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
></script>


<!-- =====================================================
     DISTRIBUTION MODAL JAVASCRIPT
===================================================== -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const distributeModal =
            document.getElementById(
                'distributeModal'
            );
        if(!distributeModal) {
            return;
        }

        const beneficiarySelect =
            document.getElementById(
                'modalBeneficiarySelect'
            );
        const batchNoField =
            document.getElementById(
                'modalBatchNo'
            );
        const locationField =
            document.getElementById(
                'modalLocation'
            );

        if(beneficiarySelect) {

            // Default the Location field to the selected beneficiary's
            // barangay. Still editable in case the actual distribution
            // point differs.
            beneficiarySelect.addEventListener(
                'change',
                function() {

                    const selectedOption =
                        beneficiarySelect.options[
                            beneficiarySelect.selectedIndex
                        ];
                    const barangay =
                        selectedOption
                            ? selectedOption.getAttribute('data-barangay')
                            : '';

                    if(locationField) {
                        locationField.value =
                            barangay || '';
                    }

                }
            );

        }

        distributeModal.addEventListener(
            'show.bs.modal',
            function(event) {

                const button =
                    event.relatedTarget;
                if(!button) {
                    return;
                }
                const livestockId =
                    button.getAttribute(
                        'data-livestock-id'
                    );
                const livestockName =
                    button.getAttribute(
                        'data-livestock-name'
                    );
                const batchNo =
                    button.getAttribute(
                        'data-batch-no'
                    );

                document.getElementById(
                    'modalLivestockId'
                ).value =
                    livestockId || '';
                document.getElementById(
                    'modalLivestockName'
                ).value =
                    livestockName || '';

                if(batchNoField) {
                    batchNoField.value =
                        batchNo || '';
                }

            }
        );
        distributeModal.addEventListener(
            'hidden.bs.modal',
            function() {

                const form =
                    distributeModal.querySelector(
                        'form'
                    );
                if(form) {

                    form.reset();
                }
                document.getElementById(
                    'modalLivestockId'
                ).value = '';
                document.getElementById(
                    'modalLivestockName'
                ).value = '';
                if(batchNoField) {
                    batchNoField.value = '';
                }
                if(locationField) {
                    locationField.value = '';
                }

            }
        );

    }
);

</script>
</body>
</html>