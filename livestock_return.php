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

$tech_id=(int)$_SESSION['technician_id'];
$search=isset($_GET['search'])?trim($_GET['search']):'';
$barangayFilter=isset($_GET['barangay'])?(int)$_GET['barangay']:0;
$message='';
$error='';

/* Return data is stored in distributions; pigo.sql has no returns table.
   A single "batch" (e.g. 2 pigs given to one beneficiary on one
   distribution run) can span several distribution_id/livestock_id rows
   sharing the same batch_no + beneficiary_id. Returning must close out
   every row in that batch, not just the one the technician clicked. */
if(isset($_POST['return_livestock'])){
    $distribution_id=(int)($_POST['distribution_id']??0);
    $reason=trim($_POST['reason']??'');
    $remarks=trim($_POST['remarks']??'');
    $date_returned=$_POST['date_returned']??date('Y-m-d');

    if($distribution_id<=0){
        $error='Invalid distribution record.';
    }elseif($reason===''){
        $error='Please provide a reason for the return.';
    }else{

        /* Look up the batch_no + beneficiary_id for the clicked distribution,
           checking access via the technician's assigned barangay (same
           check used by save_monitoring.php), not distributions.technician_id. */
        $lookup=$conn->prepare("
            SELECT d.batch_no, d.beneficiary_id
            FROM distributions d
            INNER JOIN beneficiaries b ON d.beneficiary_id=b.beneficiary_id
            INNER JOIN technician_barangay tb ON b.barangay_id=tb.barangay_id
            WHERE d.distribution_id=?
              AND tb.technician_id=?
              AND d.status='active'
            LIMIT 1
        ");
        $lookup->bind_param("ii",$distribution_id,$tech_id);
        $lookup->execute();
        $distRow=$lookup->get_result()->fetch_assoc();
        $lookup->close();

        if(!$distRow){
            $error='This livestock distribution is no longer available for return.';
        }else{

            $batch_no=$distRow['batch_no'];
            $beneficiary_id=(int)$distRow['beneficiary_id'];
            $combinedRemarks=$remarks!==''?$remarks:$reason;

            $conn->begin_transaction();
            $ok=true;

            /* Collect every livestock_id in this same batch/beneficiary
               that is still active, so all of them get freed up together. */
            $livestock_ids=[];
            $collect=$conn->prepare("
                SELECT d.livestock_id
                FROM distributions d
                WHERE d.batch_no<=>?
                  AND d.beneficiary_id=?
                  AND d.status='active'
            ");
            $collect->bind_param("si",$batch_no,$beneficiary_id);
            $collect->execute();
            $collectResult=$collect->get_result();
            while($lr=$collectResult->fetch_assoc()){ $livestock_ids[]=(int)$lr['livestock_id']; }
            $collect->close();

            if(count($livestock_ids)===0){
                $ok=false;
            }

            /* Mark every distribution row in the batch as returned. */
            if($ok){
                $stmt=$conn->prepare("
                    UPDATE distributions d
                    INNER JOIN beneficiaries b ON d.beneficiary_id=b.beneficiary_id
                    INNER JOIN technician_barangay tb ON b.barangay_id=tb.barangay_id
                    SET d.status='returned',
                        d.return_date=?,
                        d.return_reason=?,
                        d.remarks=CASE
                            WHEN ?='' THEN d.remarks
                            WHEN d.remarks IS NULL OR d.remarks='' THEN ?
                            ELSE CONCAT(d.remarks,' | ',?)
                        END,
                        d.closed_at=CURRENT_TIMESTAMP,
                        d.closed_by=?
                    WHERE d.batch_no<=>?
                      AND d.beneficiary_id=?
                      AND tb.technician_id=?
                      AND d.status='active'
                ");
                $stmt->bind_param(
                    "sssssisii",
                    $date_returned,$reason,$remarks,
                    $combinedRemarks,$combinedRemarks,
                    $tech_id,$batch_no,$beneficiary_id,$tech_id
                );

                if(!$stmt->execute() || $stmt->affected_rows<1){
                    $ok=false;
                }
                $stmt->close();
            }

            /* Free up every livestock record in the batch so they all
               show again as 'available' in the distribution.php list. */
            if($ok){
                $placeholders=implode(',',array_fill(0,count($livestock_ids),'?'));
                $liveTypes=str_repeat('i',count($livestock_ids));
                $update=$conn->prepare("
                    UPDATE livestock
                    SET status='available'
                    WHERE livestock_id IN ($placeholders)
                ");
                $update->bind_param($liveTypes,...$livestock_ids);

                if(!$update->execute()){
                    $ok=false;
                }
                $update->close();
            }

            if($ok){
                $conn->commit();
                $count=count($livestock_ids);
                $_SESSION['message']=$count>1
                    ? "$count head of livestock have been successfully marked as returned."
                    : 'Livestock has been successfully marked as returned.';
                header("Location: livestock_return.php");
                exit();
            }

            $conn->rollback();
            $error='Unable to record the livestock return.';
        }
    }
}

/* Transfer keeps the same distribution record and changes its beneficiary. */
if(isset($_POST['transfer_livestock'])){
    $distribution_id=(int)($_POST['transfer_distribution_id']??0);
    $new_beneficiary_id=(int)($_POST['new_beneficiary_id']??0);
    $transfer_remarks=trim($_POST['transfer_remarks']??'');

    if($distribution_id<=0||$new_beneficiary_id<=0){
        $error='Please select a valid beneficiary.';
    }else{
        $check=$conn->prepare("
            SELECT d.distribution_id,d.beneficiary_id
            FROM distributions d
            INNER JOIN beneficiaries b ON d.beneficiary_id=b.beneficiary_id
            INNER JOIN technician_barangay tb ON b.barangay_id=tb.barangay_id
            WHERE d.distribution_id=? AND tb.technician_id=? AND d.status='active'
            LIMIT 1
        ");
        $check->bind_param("ii",$distribution_id,$tech_id);
        $check->execute();
        $distribution=$check->get_result()->fetch_assoc();
        $check->close();

        if(!$distribution){
            $error='This livestock distribution is no longer available for transfer.';
        }elseif((int)$distribution['beneficiary_id']===$new_beneficiary_id){
            $error='Please select a different beneficiary.';
        }else{
            $beneficiaryCheck=$conn->prepare("
                SELECT beneficiary_id
                FROM beneficiaries
                WHERE beneficiary_id=? AND status='active'
                LIMIT 1
            ");
            $beneficiaryCheck->bind_param("i",$new_beneficiary_id);
            $beneficiaryCheck->execute();
            $newBeneficiary=$beneficiaryCheck->get_result()->fetch_assoc();
            $beneficiaryCheck->close();

            if(!$newBeneficiary){
                $error='The selected beneficiary does not exist or is inactive.';
            }else{
                $update=$conn->prepare("
                    UPDATE distributions d
                    INNER JOIN beneficiaries b ON d.beneficiary_id=b.beneficiary_id
                    INNER JOIN technician_barangay tb ON b.barangay_id=tb.barangay_id
                    SET d.beneficiary_id=?,
                        d.remarks=CASE
                            WHEN ?='' THEN d.remarks
                            WHEN d.remarks IS NULL OR d.remarks='' THEN CONCAT('Transferred: ',?)
                            ELSE CONCAT(d.remarks,' | Transferred: ',?)
                        END
                    WHERE d.distribution_id=? AND tb.technician_id=? AND d.status='active'
                ");
                $update->bind_param(
                    "isssii",
                    $new_beneficiary_id,$transfer_remarks,
                    $transfer_remarks,$transfer_remarks,
                    $distribution_id,$tech_id
                );

                if($update->execute()&&$update->affected_rows>0){
                    $update->close();
                    $_SESSION['message']='Livestock has been successfully transferred to the selected beneficiary.';
                    header("Location: livestock_return.php");
                    exit();
                }

                $error='Unable to transfer the livestock.';
                $update->close();
            }
        }
    }
}

if(isset($_SESSION['message'])){
    $message=$_SESSION['message'];
    unset($_SESSION['message']);
}

$beneficiaries=[];
/* received_count = pila ka sapat ang nabaton na sang beneficiary (tanan nga distribution record).
   Ang wala pa ka-baton (0) una sa listahan para ma-prioritize. */
$beneficiaryQuery=$conn->query("
    SELECT b.beneficiary_id,b.firstname,b.middlename,b.lastname,b.contact,br.barangay_name,
           COALESCE(rc.received_count,0) AS received_count
    FROM beneficiaries b
    LEFT JOIN barangays br ON b.barangay_id=br.barangay_id
    LEFT JOIN (
        SELECT beneficiary_id,SUM(quantity) AS received_count
        FROM distributions
        GROUP BY beneficiary_id
    ) rc ON rc.beneficiary_id=b.beneficiary_id
    WHERE b.status='active'
    ORDER BY received_count ASC,b.lastname,b.firstname
");
if($beneficiaryQuery){
    while($b=$beneficiaryQuery->fetch_assoc()) $beneficiaries[]=$b;
}

$records=[];
$sql="
    SELECT
        m.monitoring_id,
        m.distribution_id,
        m.health_status AS health,
        m.weight,
        m.pregnancy_status,
        m.offspring_count AS offspring,
        m.date_recorded,
        m.action AS monitoring_action,
        d.quantity,
        d.distribution_date,
        d.distribution_location,
        d.batch_no AS distribution_batch_no,
        b.beneficiary_id,
        b.firstname,b.middlename,b.lastname,b.contact{$photoSql},
        br.barangay_id,
        br.barangay_name,
        l.livestock_id,l.kind,l.breed,
        TIMESTAMPDIFF(YEAR,l.birth_date,CURDATE()) AS age,
        l.status AS livestock_status,
        l.batch_no AS livestock_batch_no,
        d.status AS distribution_status
    FROM monitoring m
    INNER JOIN (
        SELECT distribution_id, MAX(monitoring_id) AS latest_id
        FROM monitoring
        GROUP BY distribution_id
    ) latest ON latest.distribution_id=m.distribution_id AND latest.latest_id=m.monitoring_id
    INNER JOIN distributions d ON m.distribution_id=d.distribution_id
    INNER JOIN beneficiaries b ON d.beneficiary_id=b.beneficiary_id
    LEFT JOIN barangays br ON b.barangay_id=br.barangay_id
    INNER JOIN livestock l ON d.livestock_id=l.livestock_id
    INNER JOIN technician_barangay tb ON b.barangay_id=tb.barangay_id
    WHERE tb.technician_id=?
      AND d.status='active'
      AND LOWER(TRIM(m.health_status))='healthy'
";
$params=[$tech_id];
$types="i";

if($barangayFilter>0){
    $sql.=" AND br.barangay_id=? ";
    $params[]=$barangayFilter;
    $types.="i";
}

if($search!==''){
    $sql.=" AND (
        b.firstname LIKE ? OR b.middlename LIKE ? OR b.lastname LIKE ?
        OR br.barangay_name LIKE ? OR l.batch_no LIKE ? OR l.kind LIKE ? OR l.breed LIKE ?
    )";
    $like="%".$search."%";
    for($i=0;$i<7;$i++){ $params[]=$like; $types.="s"; }
}

$sql.=" ORDER BY m.date_recorded DESC,m.monitoring_id DESC";

$stmt=$conn->prepare($sql);
if($stmt){
    $stmt->bind_param($types,...$params);
    $stmt->execute();
    $result=$stmt->get_result();
    while($row=$result->fetch_assoc()) $records[]=$row;
    $stmt->close();
}

$totalRecords=count($records);

/* =========================================================
   BARANGAY LIST FOR THE FILTER PILLS
   Only barangays with at least one successfully-monitored,
   active distribution under this technician show up, and the
   list is independent of the current filters so it stays
   complete and stable while filtering.
========================================================= */
$barangayList=[];
$barangayListStmt=$conn->prepare("
    SELECT DISTINCT br.barangay_id, br.barangay_name
    FROM monitoring m
    INNER JOIN (
        SELECT distribution_id, MAX(monitoring_id) AS latest_id
        FROM monitoring
        GROUP BY distribution_id
    ) latest ON latest.distribution_id=m.distribution_id AND latest.latest_id=m.monitoring_id
    INNER JOIN distributions d ON m.distribution_id=d.distribution_id
    INNER JOIN beneficiaries b ON d.beneficiary_id=b.beneficiary_id
    INNER JOIN barangays br ON b.barangay_id=br.barangay_id
    INNER JOIN technician_barangay tb ON b.barangay_id=tb.barangay_id
    WHERE tb.technician_id=?
      AND d.status='active'
      AND LOWER(TRIM(m.health_status))='healthy'
    ORDER BY br.barangay_name ASC
");
if($barangayListStmt){
    $barangayListStmt->bind_param("i",$tech_id);
    $barangayListStmt->execute();
    $barangayList=$barangayListStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $barangayListStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Livestock Return & Transfer - PIGOBAO</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<style>
:root{
    --green-900:#123c16;
    --green-800:#1b5e20;
    --green-600:#2e7d32;
    --green-500:#43a047;
    --green-300:#a5d6a7;
    --green-100:#e8f5e9;
    --canvas:#f3f6f4;
    --ink:#233126;
    --soft:#657269;
    --line:#e1e9e3;
    --amber:#c98a1f;
    --red:#b64032;
    --blue:#3f7cac;
    --shadow:0 8px 28px -16px rgba(18,60,22,.25);
}

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
    font-family:'Poppins',sans-serif;
}

body{
    min-height:100vh;
    background:var(--canvas);
    color:var(--ink);
}

header{
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:65px;
    z-index:1000;
    background:linear-gradient(120deg,var(--green-900),var(--green-800) 55%,var(--green-600));
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 28px;
    box-shadow:0 3px 15px rgba(0,0,0,.2);
}

.brand{
    display:flex;
    align-items:center;
    gap:12px;
}

.brand-mark{
    width:36px;
    height:36px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.2);
}

.brand h1{
    font-family:'Space Grotesk',sans-serif;
    font-size:17px;
    font-weight:700;
    margin:0;
}

.brand small{
    display:block;
    color:rgba(255,255,255,.7);
    font-size:10px;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.logout{
    color:var(--green-800);
    background:#fff;
    text-decoration:none;
    padding:8px 18px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    transition:.2s;
}

.logout:hover{
    color:var(--green-900);
    background:var(--green-100);
    transform:translateY(-1px);
}

.sidebar{
    position:fixed;
    top:65px;
    left:0;
    bottom:0;
    width:230px;
    background:linear-gradient(195deg,var(--green-800),var(--green-600));
    padding-top:22px;
    z-index:999;
    box-shadow:inset -1px 0 rgba(255,255,255,.06);
}

.sidebar-title{
    color:rgba(255,255,255,.55);
    font-size:10px;
    font-weight:600;
    letter-spacing:.8px;
    text-transform:uppercase;
    padding:0 12px 12px;
}

.sidebar a{
    display:flex;
    align-items:center;
    gap:12px;
    color:rgba(255,255,255,.88);
    text-decoration:none;
    padding:13px 22px;
    margin:2px 12px;
    border-radius:9px;
    font-size:14.5px;
    font-weight:500;
    transition:.2s;
}

.sidebar a i{
    width:18px;
    text-align:center;
    font-size:15px;
    opacity:.9;
}

.sidebar a:hover{
    background:rgba(255,255,255,.12);
    transform:translateX(2px);
}

.sidebar a.active{
    background:var(--green-300);
    color:var(--green-900);
    font-weight:600;
    box-shadow:0 4px 12px rgba(0,0,0,.18);
}

.main-content{
    margin-left:240px;
    padding:92px 34px 35px;
    min-height:100vh;
}

.page-head{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px;
    padding:20px 23px;
    margin-bottom:18px;
    box-shadow:var(--shadow);
    position:relative;
    overflow:hidden;
}

.page-head:before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    width:100%;
    height:4px;
    background:linear-gradient(90deg,var(--green-800),var(--green-300));
}

.page-head-content{
    display:flex;
    align-items:center;
    gap:15px;
}

.page-icon{
    width:48px;
    height:48px;
    flex:none;
    border-radius:13px;
    background:linear-gradient(145deg,var(--green-600),var(--green-900));
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
}

.page-head h2{
    font-family:'Space Grotesk',sans-serif;
    font-size:20px;
    color:var(--green-900);
    font-weight:700;
    margin:0 0 3px;
}

.page-head p{
    color:var(--soft);
    font-size:12px;
    margin:0;
}

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin-bottom:18px;
}

.stat-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:13px;
    padding:15px 17px;
    display:flex;
    align-items:center;
    gap:12px;
    box-shadow:var(--shadow);
}

.stat-icon{
    width:39px;
    height:39px;
    border-radius:10px;
    background:var(--green-100);
    color:var(--green-800);
    display:flex;
    align-items:center;
    justify-content:center;
}

.stat-card:nth-child(2) .stat-icon{
    background:#eaf3f8;
    color:var(--blue);
}

.stat-card:nth-child(3) .stat-icon{
    background:#fdf3e3;
    color:var(--amber);
}

.stat-label{
    color:var(--soft);
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.4px;
}

.stat-value{
    font-family:'Space Grotesk',sans-serif;
    font-size:20px;
    font-weight:700;
    color:var(--green-900);
    margin-top:1px;
}

.alert-box{
    border-radius:11px;
    padding:12px 15px;
    margin-bottom:15px;
    font-size:12px;
    display:flex;
    align-items:center;
    gap:9px;
}

.alert-success-custom{
    background:#e8f5e9;
    color:#1b5e20;
    border:1px solid #c8e6c9;
}

.alert-error-custom{
    background:#fbeceb;
    color:#8a2e22;
    border:1px solid #f0ceca;
}

.search-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:13px;
    padding:13px;
    margin-bottom:15px;
    box-shadow:var(--shadow);
}

.barangay-filter{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-bottom:12px;
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
    background:var(--canvas);
    color:var(--soft);
    border:1px solid var(--line);
    transition:background .15s ease,color .15s ease,border-color .15s ease;
}

.brgy-pill:hover{
    background:var(--green-100);
    color:var(--green-800);
    border-color:var(--green-300);
}

.brgy-pill.active{
    background:linear-gradient(120deg,var(--green-800),var(--green-600));
    color:#fff;
    border-color:transparent;
    box-shadow:0 4px 10px -4px rgba(18,60,22,.4);
}

.search-form{
    display:flex;
    align-items:center;
    gap:9px;
}

.search-wrap{
    position:relative;
    flex:1;
}

.search-wrap i{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    color:#91a096;
    font-size:12px;
}

.search-wrap input{
    width:100%;
    height:41px;
    border:1px solid var(--line);
    border-radius:9px;
    outline:none;
    padding:0 13px 0 36px;
    color:var(--ink);
    font-size:12px;
    transition:.2s;
}

.search-wrap input:focus{
    border-color:var(--green-500);
    box-shadow:0 0 0 3px rgba(67,160,71,.1);
}

.search-btn{
    height:41px;
    border:0;
    border-radius:9px;
    background:linear-gradient(120deg,var(--green-800),var(--green-600));
    color:#fff;
    padding:0 17px;
    font-size:11px;
    font-weight:600;
    cursor:pointer;
}

.search-btn:hover{
    filter:brightness(1.06);
}

.table-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px;
    overflow:hidden;
    box-shadow:var(--shadow);
}

.table-card-head{
    padding:16px 18px;
    border-bottom:1px solid var(--line);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}

.table-card-head h3{
    font-family:'Space Grotesk',sans-serif;
    font-size:14px;
    font-weight:700;
    color:var(--green-900);
    margin:0;
}

.table-card-head p{
    color:var(--soft);
    font-size:10.5px;
    margin:3px 0 0;
}

.record-count{
    background:var(--green-100);
    color:var(--green-800);
    border-radius:999px;
    padding:5px 10px;
    font-size:10px;
    font-weight:700;
    white-space:nowrap;
}

.table-scroll{
    width:100%;
    overflow-x:auto;
}

.data-table{
    width:100%;
    border-collapse:collapse;
    min-width:1080px;
}

.data-table th{
    background:var(--green-900);
    color:#eafbea;
    padding:11px 12px;
    font-family:'Space Grotesk',sans-serif;
    font-size:9.5px;
    text-transform:uppercase;
    letter-spacing:.3px;
    white-space:nowrap;
    text-align:left;
}

.data-table td{
    padding:11px 12px;
    border-bottom:1px solid var(--line);
    font-size:11px;
    color:var(--ink);
    vertical-align:middle;
    white-space:nowrap;
}

.data-table tbody tr:hover{
    background:#f7fbf7;
}

.data-table tbody tr:last-child td{
    border-bottom:0;
}

.name-cell{
    font-weight:600;
    color:var(--green-900);
}

.sub-text{
    display:block;
    color:var(--soft);
    font-size:9px;
    margin-top:2px;
}

.badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 8px;
    font-size:9px;
    font-weight:700;
}

.badge-healthy{
    background:#e8f5e9;
    color:#1b5e20;
}

.badge-kind{
    background:#fdf3e3;
    color:#a86d0e;
}

.badge-batch{
    background:#edf4f8;
    color:#326681;
}

.action-buttons{
    display:flex;
    align-items:center;
    gap:5px;
}

.action-btn{
    border:0;
    border-radius:7px;
    padding:7px 9px;
    font-size:9.5px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    gap:5px;
    cursor:pointer;
    transition:.2s;
}

.return-btn{
    background:#fbeceb;
    color:#a6372b;
}

.return-btn:hover{
    background:#f4d7d3;
    transform:translateY(-1px);
}

.transfer-btn{
    background:#eaf3f8;
    color:#326681;
}

.transfer-btn:hover{
    background:#d9e9f1;
    transform:translateY(-1px);
}

.empty-state{
    text-align:center;
    padding:50px 20px!important;
    color:var(--soft);
}

.empty-state i{
    display:block;
    font-size:30px;
    margin-bottom:9px;
    color:#aab5ad;
}

.empty-state strong{
    display:block;
    color:var(--green-900);
    font-size:13px;
    margin-bottom:3px;
}

.modal-content{
    border:0;
    border-radius:15px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
}

.modal-header{
    border:0;
    padding:17px 20px;
    background:linear-gradient(120deg,var(--green-900),var(--green-600));
    color:#fff;
}

.modal-title{
    font-family:'Space Grotesk',sans-serif;
    font-size:15px;
    font-weight:700;
}

.modal-header .btn-close{
    filter:brightness(0) invert(1);
    opacity:.9;
}

.modal-body{
    padding:20px;
}

.modal-footer{
    border-top:1px solid var(--line);
    padding:13px 20px;
    background:#fafcfb;
}

.form-label{
    color:var(--green-900);
    font-size:11px;
    font-weight:600;
    margin-bottom:6px;
}

.form-control,
.form-select{
    border:1px solid var(--line);
    border-radius:8px;
    font-size:11px;
    min-height:40px;
}

.form-control:focus,
.form-select:focus{
    border-color:var(--green-500);
    box-shadow:0 0 0 3px rgba(67,160,71,.1);
}

.info-box{
    background:var(--green-100);
    border:1px solid #cde4cf;
    border-radius:10px;
    padding:11px 13px;
    margin-bottom:15px;
}

.info-box-title{
    color:var(--green-900);
    font-size:11px;
    font-weight:700;
    margin-bottom:5px;
}

.info-box-text{
    color:var(--soft);
    font-size:10px;
    line-height:1.6;
}

.selected-info{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:8px;
    margin-bottom:15px;
}

.selected-item{
    background:#f7faf8;
    border:1px solid var(--line);
    border-radius:9px;
    padding:9px 11px;
}

.selected-item small{
    display:block;
    color:var(--soft);
    font-size:8.5px;
    text-transform:uppercase;
}

.selected-item strong{
    display:block;
    color:var(--green-900);
    font-size:11px;
    margin-top:2px;
}

.btn-save{
    border:0;
    border-radius:8px;
    background:linear-gradient(120deg,var(--green-800),var(--green-600));
    color:#fff;
    padding:9px 15px;
    font-size:11px;
    font-weight:600;
}

.btn-cancel{
    border:1px solid var(--line);
    border-radius:8px;
    background:#fff;
    color:var(--soft);
    padding:8px 14px;
    font-size:11px;
    font-weight:600;
}

@media(max-width:900px){
    .sidebar{
        width:230px;
    }

    .main-content{
        margin-left:230px;
        padding:88px 18px 30px;
    }

    .stats{
        grid-template-columns:1fr;
    }
}

@media(max-width:700px){
    header{
        padding:0 15px;
    }

    .sidebar{
        position:relative;
        top:auto;
        width:100%;
        height:auto;
        padding:8px;
    }

    .sidebar-title{
        display:none;
    }

    .sidebar a{
        display:inline-flex;
        margin:2px;
        padding:9px 10px;
        font-size:10px;
    }

    .main-content{
        margin-left:0;
        padding:18px 12px 25px;
    }

    .search-form{
        flex-direction:column;
        align-items:stretch;
    }

    .search-btn{
        width:100%;
    }

    .page-head-content{
        align-items:flex-start;
    }

    .selected-info{
        grid-template-columns:1fr;
    }
}

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
            <small>Livestock Return &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../tech_logout.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<nav class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../technician_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="assign_beneficiaries.php"><i class="fas fa-user-check"></i> Assign Beneficiaries</a>
    <a href="monitoring.php"><i class="fas fa-stethoscope"></i> Monitoring</a>
    <a href="monitoring_history.php"><i class="fas fa-history"></i> Monitoring History</a>
    <a href="farmer_success_rate.php"><i class="fas fa-chart-pie"></i> Farmer Success Rate</a>
    <a href="mortality_cases.php"><i class="fas fa-skull-crossbones"></i> Mortality Cases</a>
    <a href="livestock_return.php" class="active"><i class="fas fa-undo"></i> Livestock Return</a>
    <a href="tech_reports.php"><i class="fas fa-file-alt"></i> Reports</a>
    <a href="generate_report.php" ><i class="fas fa-file-pdf"></i> Generate Report</a>
</nav>

<main class="main-content">

    <section class="page-head">
        <div class="page-head-content">
            <div class="page-icon"><i class="fas fa-rotate-left"></i></div>
            <div class="page-text">
                <div class="page-date"><i class="fas fa-calendar-day"></i> <?=date('l, F j, Y')?></div>
                <h2>Livestock Return & Transfer</h2>
                <p>Manage livestock from beneficiaries with successful monitoring results.</p>
            </div>
        </div>
        <span class="role-pill"><i class="fas fa-user-gear"></i> Livestock Technician</span>
    </section>

    <?php if($message): ?>
        <div class="alert-box alert-success-custom">
            <i class="fas fa-circle-check"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="alert-box alert-error-custom">
            <i class="fas fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-label">Successful Monitoring</div>
                <div class="stat-value"><?= $totalRecords ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-heart-pulse"></i></div>
            <div>
                <div class="stat-label">Healthy Livestock</div>
                <div class="stat-value"><?= $totalRecords ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-arrows-turn-to-dots"></i></div>
            <div>
                <div class="stat-label">Available Actions</div>
                <div class="stat-value"><?= $totalRecords ?></div>
            </div>
        </div>
    </section>

    <div class="barangay-filter">
        <a
            href="?barangay=0&search=<?= urlencode($search) ?>"
            class="brgy-pill <?= $barangayFilter===0?'active':'' ?>"
        >
            <i class="fas fa-layer-group"></i> All Barangays
        </a>
        <?php foreach($barangayList as $br): ?>
            <a
                href="?barangay=<?= (int)$br['barangay_id'] ?>&search=<?= urlencode($search) ?>"
                class="brgy-pill <?= $barangayFilter===(int)$br['barangay_id']?'active':'' ?>"
            >
                <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($br['barangay_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <section class="search-card">
        <form method="GET" class="search-form">
            <input type="hidden" name="barangay" value="<?= (int)$barangayFilter ?>">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search beneficiary, barangay, batch no., kind or breed...">
            </div>
            <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
        </form>
    </section>

    <section class="table-card">
        <div class="table-card-head">
            <div>
                <h3><i class="fas fa-list-check"></i> Successfully Monitored Beneficiaries</h3>
                <p>Only active distributions with a healthy monitoring result are displayed.</p>
            </div>
            <span class="record-count"><?= $totalRecords ?> Record<?= $totalRecords==1?'':'s' ?></span>
        </div>

        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Beneficiary</th>
                        <th>Barangay</th>
                        <th>Kind</th>
                        <th>Eartag No.</th>
                        <th>Breed</th>
                        <th>Age</th>
                        <th>Health</th>
                        <th>Monitoring Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($totalRecords>0): ?>
                    <?php foreach($records as $row): ?>
                        <?php
                        $fullname=trim(
                            $row['firstname'].' '.
                            (!empty($row['middlename'])?$row['middlename'].' ':'').
                            $row['lastname']
                        );
                        $batch=$row['livestock_batch_no']??$row['distribution_batch_no']??'N/A';
                        ?>
                        <tr>
                            <td>
                                <div class="farmer-cell">
                                    <?= pg_farmer_avatar($row['farmer_photo'] ?? null, $fullname) ?>
                                    <div>
                                        <div class="name-cell"><?= htmlspecialchars($fullname) ?></div>
                                        <?php if(!empty($row['contact'])): ?>
                                            <span class="sub-text"><?= htmlspecialchars($row['contact']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($row['barangay_name']??'N/A') ?></td>
                            <td><span class="badge badge-kind"><?= htmlspecialchars(ucfirst($row['kind']??$row['livestock_type']??'N/A')) ?></span></td>
                            <td><span class="badge badge-batch"><?= htmlspecialchars($batch) ?></span></td>
                            <td><?= htmlspecialchars($row['breed']??'N/A') ?></td>
                            <td><?= $row['age']!==null?(int)$row['age'].' yrs':'N/A' ?></td>
                            <td><span class="badge badge-healthy"><i class="fas fa-circle-check"></i> Healthy</span></td>
                            <td><?= htmlspecialchars($row['date_recorded']) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="action-btn return-btn" data-bs-toggle="modal" data-bs-target="#returnModal" onclick='openReturnModal(<?= json_encode([
                                        "distribution_id"=>(int)$row["distribution_id"],
                                        "beneficiary_id"=>(int)$row["beneficiary_id"],
                                        "name"=>$fullname,
                                        "kind"=>$row["kind"]??$row["livestock_type"]??"N/A",
                                        "batch"=>$batch,
                                        "quantity"=>(int)$row["quantity"]
                                    ],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                                        <i class="fas fa-undo"></i> Return
                                    </button>
                                    <button type="button" class="action-btn transfer-btn" data-bs-toggle="modal" data-bs-target="#transferModal" onclick='openTransferModal(<?= json_encode([
                                        "distribution_id"=>(int)$row["distribution_id"],
                                        "beneficiary_id"=>(int)$row["beneficiary_id"],
                                        "name"=>$fullname,
                                        "kind"=>$row["kind"]??$row["livestock_type"]??"N/A",
                                        "batch"=>$batch,
                                        "quantity"=>(int)$row["quantity"]
                                    ],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'>
                                        <i class="fas fa-right-left"></i> Transfer
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="empty-state">
                            <i class="fas fa-clipboard-check"></i>
                            <strong>No successful monitoring records found</strong>
                            There are currently no active livestock records with a healthy monitoring result.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<div class="gov-footer">
    <div class="inner">
        <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
        <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
    </div>
</div>
</main>

<!-- RETURN MODAL -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-rotate-left"></i> Return Livestock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="distribution_id" id="return_distribution_id">

                <div class="info-box">
                    <div class="info-box-title">Return Confirmation</div>
                    <div class="info-box-text">
                        This action will mark the selected distribution as <strong>returned</strong> and create a return record.
                    </div>
                </div>

                <div class="selected-info">
                    <div class="selected-item">
                        <small>Beneficiary</small>
                        <strong id="return_name">—</strong>
                    </div>
                    <div class="selected-item">
                        <small>Livestock Kind</small>
                        <strong id="return_kind">—</strong>
                    </div>
                    <div class="selected-item">
                        <small>Eartag No.</small>
                        <strong id="return_batch">—</strong>
                    </div>
                    <div class="selected-item">
                        <small>Quantity</small>
                        <strong id="return_quantity">—</strong>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Reason for Return</label>
                    <input type="text" name="reason" class="form-control" placeholder="Enter reason for returning livestock" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Optional remarks"></textarea>
                </div>

                <div>
                    <label class="form-label">Date Returned</label>
                    <input type="date" name="date_returned" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="return_livestock" class="btn-save">
                    <i class="fas fa-save"></i> Save Return
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TRANSFER MODAL -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-right-left"></i> Transfer Livestock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" name="transfer_distribution_id" id="transfer_distribution_id">

                <div class="info-box">
                    <div class="info-box-title">Transfer Livestock to Another Beneficiary</div>
                    <div class="info-box-text">
                        Select the beneficiary who will receive this livestock. The existing distribution record will be updated to the selected beneficiary.
                    </div>
                </div>

                <div class="selected-info">
                    <div class="selected-item">
                        <small>Current Beneficiary</small>
                        <strong id="transfer_name">—</strong>
                    </div>
                    <div class="selected-item">
                        <small>Livestock Kind</small>
                        <strong id="transfer_kind">—</strong>
                    </div>
                    <div class="selected-item">
                        <small>Batch No.</small>
                        <strong id="transfer_batch">—</strong>
                    </div>
                    <div class="selected-item">
                        <small>Quantity</small>
                        <strong id="transfer_quantity">—</strong>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Transfer To</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="only_no_received" onchange="applyTransferFilter()">
                        <label class="form-check-label" for="only_no_received" style="font-size:13px;">
                            Ipakita lang ang wala pa ka-baton nga sapat
                        </label>
                    </div>
                    <select name="new_beneficiary_id" id="new_beneficiary_id" class="form-select" required>
                        <option value="">-- Select New Beneficiary --</option>
                        <?php
                        $noReceived=array_filter($beneficiaries,function($x){ return (int)$x['received_count']===0; });
                        $hasReceived=array_filter($beneficiaries,function($x){ return (int)$x['received_count']>0; });
                        $groups=[
                            'Wala pa ka-baton (Priority)'=>$noReceived,
                            'Naka-baton na'=>$hasReceived,
                        ];
                        foreach($groups as $groupLabel=>$groupRows):
                            if(!$groupRows) continue;
                        ?>
                        <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                        <?php foreach($groupRows as $b): ?>
                            <?php
                            $bname=trim(
                                $b['firstname'].' '.
                                (!empty($b['middlename'])?$b['middlename'].' ':'').
                                $b['lastname']
                            );
                            $rc=(int)$b['received_count'];
                            ?>
                            <option value="<?= (int)$b['beneficiary_id'] ?>" data-received="<?= $rc ?>">
                                <?= htmlspecialchars($bname) ?>
                                — <?= htmlspecialchars($b['barangay_name']??'N/A') ?>
                                — <?= $rc===0?'Wala pa ka-baton':'Nabaton: '.$rc ?>
                            </option>
                        <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Transfer Remarks</label>
                    <textarea name="transfer_remarks" class="form-control" rows="3" placeholder="Optional reason or remarks for the transfer"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="transfer_livestock" class="btn-save">
                    <i class="fas fa-check"></i> Save Transfer
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function openReturnModal(data){
    document.getElementById('return_distribution_id').value=data.distribution_id;
    document.getElementById('return_name').textContent=data.name||'N/A';
    document.getElementById('return_kind').textContent=data.kind||'N/A';
    document.getElementById('return_batch').textContent=data.batch||'N/A';
    document.getElementById('return_quantity').textContent=data.quantity||'0';
}

function openTransferModal(data){
    document.getElementById('transfer_distribution_id').value=data.distribution_id;
    document.getElementById('transfer_name').textContent=data.name||'N/A';
    document.getElementById('transfer_kind').textContent=data.kind||'N/A';
    document.getElementById('transfer_batch').textContent=data.batch||'N/A';
    document.getElementById('transfer_quantity').textContent=data.quantity||'0';

    transferCurrentBeneficiary=String(data.beneficiary_id);
    document.getElementById('new_beneficiary_id').value='';
    document.getElementById('only_no_received').checked=false;
    applyTransferFilter();
}

/* Itago ang current nga beneficiary; kung naka-check, wala pa ka-baton lang ang makita */
var transferCurrentBeneficiary='';
function applyTransferFilter(){
    const select=document.getElementById('new_beneficiary_id');
    const onlyNone=document.getElementById('only_no_received').checked;
    if(select.selectedOptions.length && select.selectedOptions[0].hidden){ select.value=''; }
    select.querySelectorAll('option[data-received]').forEach(function(option){
        const isCurrent=(option.value===transferCurrentBeneficiary);
        const hasReceived=(parseInt(option.dataset.received,10)>0);
        option.hidden=isCurrent||(onlyNone&&hasReceived);
        option.disabled=option.hidden;
    });
    select.querySelectorAll('optgroup').forEach(function(g){
        g.hidden=!g.querySelector('option:not([hidden])');
    });
}
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
</body>
</html>