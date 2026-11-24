<?php
session_start();
require '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

$message = "";

// ===============================================================
// Beneficiary Add / Edit / Delete — merged in from add-beneficiaries.php
// so admins can add and edit farmer records right from this page
// instead of hopping to a separate menu.
// ===============================================================

// Handles the optional beneficiary photo upload for both Add and Edit forms.
// Returns [filename_or_null, error_or_null]. If no file was chosen, returns [null, null].
function handleBeneficiaryPhotoUpload($file) {
    if (empty($file['name'])) {
        return [null, null];
    }
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSizeBytes = 3 * 1024 * 1024; // 3MB
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, "There was a problem uploading the photo."];
    }
    if (!in_array($ext, $allowedExt)) {
        return [null, "Photo must be a JPG, PNG, or WEBP file."];
    }
    if ($file['size'] > $maxSizeBytes) {
        return [null, "Photo must be smaller than 3MB."];
    }

    $uploadDir = '../uploads/beneficiaries/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'ben_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return [null, "Failed to save the uploaded photo."];
    }
    return [$filename, null];
}

// Add Beneficiary
if(isset($_POST['add_beneficiary'])) {
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $lastname = trim($_POST['lastname']);
    $extension_name = trim($_POST['extension_name']);
    $gender = $_POST['gender'];
    $barangay_id = intval($_POST['barangay_id']);
    $contact = trim($_POST['contact']);
    $civil_status = $_POST['civil_status'];
    $birthdate = trim($_POST['birthdate'] ?? '');
    $birthdateVal = $birthdate !== '' ? $birthdate : null;

    // Required fields: first name, last name, gender, birthdate, barangay,
    // contact number, civil status, and photo. Middle/extension are optional.
    $requiredMissing = [];
    if ($firstname === '') $requiredMissing[] = 'First Name';
    if ($lastname === '') $requiredMissing[] = 'Last Name';
    if ($gender === '') $requiredMissing[] = 'Gender';
    if ($birthdate === '') $requiredMissing[] = 'Birthdate';
    if ($barangay_id <= 0) $requiredMissing[] = 'Barangay';
    if ($contact === '') $requiredMissing[] = 'Contact Number';
    if ($civil_status === '') $requiredMissing[] = 'Civil Status';

    if (!empty($requiredMissing)) {
        $photoError = 'Please fill in all required fields: ' . implode(', ', $requiredMissing) . '.';
        $photoFilename = null;
    } elseif (empty($_FILES['photo']['name'])) {
        $photoError = "Photo is required.";
        $photoFilename = null;
    } elseif ($birthdateVal !== null && $birthdateVal > date('Y-m-d')) {
        $photoError = "Birthdate cannot be a future date.";
        $photoFilename = null;
    } else {
        [$photoFilename, $photoError] = handleBeneficiaryPhotoUpload($_FILES['photo'] ?? []);
    }

    if ($photoError) {
        $message = $photoError;
    } else {
        $stmt = $conn->prepare("INSERT INTO beneficiaries 
            (firstname, middlename, lastname, extension_name, gender, barangay_id, contact, civil_status, birthdate, photo) 
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssissss", 
            $firstname, $middlename, $lastname, $extension_name, $gender, $barangay_id, $contact, $civil_status, $birthdateVal, $photoFilename);
        if($stmt->execute()){
            $message = "Beneficiary added successfully!";
        } else {
            $message = "Error: ".$stmt->error;
        }
    }
}

// Update Beneficiary
if(isset($_POST['update_beneficiary'])) {
    $id = intval($_POST['beneficiary_id']);
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $lastname = trim($_POST['lastname']);
    $extension_name = trim($_POST['extension_name']);
    $gender = $_POST['gender'];
    $barangay_id = intval($_POST['barangay_id']);
    $contact = trim($_POST['contact']);
    $civil_status = $_POST['civil_status'];
    $birthdate = trim($_POST['birthdate'] ?? '');
    $birthdateVal = $birthdate !== '' ? $birthdate : null;
    $existingPhoto = trim($_POST['existing_photo'] ?? '');

    // Required fields: first name, last name, gender, birthdate, barangay,
    // contact number, and civil status. Middle/extension are optional.
    $requiredMissing = [];
    if ($firstname === '') $requiredMissing[] = 'First Name';
    if ($lastname === '') $requiredMissing[] = 'Last Name';
    if ($gender === '') $requiredMissing[] = 'Gender';
    if ($birthdate === '') $requiredMissing[] = 'Birthdate';
    if ($barangay_id <= 0) $requiredMissing[] = 'Barangay';
    if ($contact === '') $requiredMissing[] = 'Contact Number';
    if ($civil_status === '') $requiredMissing[] = 'Civil Status';

    if (!empty($requiredMissing)) {
        $photoError = 'Please fill in all required fields: ' . implode(', ', $requiredMissing) . '.';
        $newPhotoFilename = null;
    } elseif ($birthdateVal !== null && $birthdateVal > date('Y-m-d')) {
        $photoError = "Birthdate cannot be a future date.";
        $newPhotoFilename = null;
    } else {
        [$newPhotoFilename, $photoError] = handleBeneficiaryPhotoUpload($_FILES['photo'] ?? []);
    }

    if ($photoError) {
        $message = $photoError;
    } else {
        // Keep the old photo if no new one was uploaded
        $photoToSave = $newPhotoFilename ?: ($existingPhoto !== '' ? $existingPhoto : null);

        $stmt = $conn->prepare("UPDATE beneficiaries 
            SET firstname=?, middlename=?, lastname=?, extension_name=?, gender=?, barangay_id=?, contact=?, civil_status=?, birthdate=?, photo=? 
            WHERE beneficiary_id=?");
        $stmt->bind_param("sssssissssi", 
            $firstname, $middlename, $lastname, $extension_name, $gender, $barangay_id, $contact, $civil_status, $birthdateVal, $photoToSave, $id);
        if($stmt->execute()){
            $message = "Beneficiary updated successfully!";
        } else {
            $message = "Error: ".$stmt->error;
        }
    }
}

// Delete Beneficiary — redirects back to whatever search/filter/page the
// admin was already looking at, instead of resetting the view.
if(isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM distributions WHERE beneficiary_id=?");
    $check->bind_param("i", $id);
    $check->execute();
    $used = (int)($check->get_result()->fetch_assoc()['cnt'] ?? 0);
    $check->close();

    if($used > 0) {
        $message = "This beneficiary cannot be deleted because distribution records already reference the account.";
    } else {
        // Remove the stored photo file, if any, before deleting the record
        $photoStmt = $conn->prepare("SELECT photo FROM beneficiaries WHERE beneficiary_id=?");
        $photoStmt->bind_param("i", $id);
        $photoStmt->execute();
        $photoRow = $photoStmt->get_result()->fetch_assoc();
        $photoStmt->close();
        if (!empty($photoRow['photo'])) {
            $photoPath = '../uploads/beneficiaries/' . $photoRow['photo'];
            if (file_exists($photoPath)) {
                unlink($photoPath);
            }
        }

        $stmt = $conn->prepare("DELETE FROM beneficiaries WHERE beneficiary_id=?");
        $stmt->bind_param("i", $id);
        if($stmt->execute()) {
            $qs = $_GET;
            unset($qs['delete']);
            $location = 'farmer-beneficiary.php' . (!empty($qs) ? '?' . http_build_query($qs) : '');
            header("Location: " . $location);
            exit();
        }
        $message = "Error: " . $stmt->error;
        $stmt->close();
    }
}

$search = trim($_GET['q'] ?? '');
$barangayId = isset($_GET['barangay_id']) && $_GET['barangay_id'] !== ''
    ? (int)$_GET['barangay_id']
    : 0;

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/* =========================
   BUILD FILTER
========================= */
$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(
        b.firstname LIKE CONCAT('%', ?, '%')
        OR b.middlename LIKE CONCAT('%', ?, '%')
        OR b.lastname LIKE CONCAT('%', ?, '%')
    )";

    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= 'sss';
}

if ($barangayId > 0) {
    $where[] = "b.barangay_id = ?";
    $params[] = $barangayId;
    $types .= 'i';
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/* =========================
   BIND PARAMETERS
========================= */
function bindParamsByRef($stmt, $types, &$params)
{
    if ($types === '') {
        return;
    }

    $refs = [];
    $refs[] = $types;

    foreach ($params as $key => &$value) {
        $refs[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

/* =========================
   COUNT RESULTS
========================= */
$countSql = "
    SELECT COUNT(*) AS total
    FROM beneficiaries b
    $whereSql
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die("Count query failed: " . $conn->error);
}

$countParams = $params;

if (!empty($countParams)) {
    bindParamsByRef($countStmt, $types, $countParams);
}

$countStmt->execute();

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();

$totalCount = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* =========================
   GET BENEFICIARIES
========================= */
$sql = "
    SELECT
        b.beneficiary_id,
        b.firstname,
        b.middlename,
        b.lastname,
        b.extension_name,
        b.gender,
        b.barangay_id,
        b.contact,
        b.civil_status,
        b.birthdate,
        b.status,
        b.photo,
        br.barangay_name
    FROM beneficiaries b
    LEFT JOIN barangays br
        ON br.barangay_id = b.barangay_id
    $whereSql
    ORDER BY b.lastname ASC, b.firstname ASC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Beneficiary query failed: " . $conn->error);
}

$pageParams = $params;
$pageParams[] = $perPage;
$pageParams[] = $offset;

$pageTypes = $types . 'ii';

bindParamsByRef($stmt, $pageTypes, $pageParams);

$stmt->execute();

$result = $stmt->get_result();

/* =========================
   GRAND TOTAL
========================= */
$grandTotalResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM beneficiaries
");

$grandTotalRow = $grandTotalResult->fetch_assoc();
$grandTotal = (int)($grandTotalRow['total'] ?? 0);

/* =========================
   BARANGAY LIST (fetched once into an array so it can be reused for the
   filter dropdown AND the Add/Edit beneficiary modals below)
========================= */
$allBarangays = [];
$barangayLookup = [];
$brgyRes = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
while($brgyRow = $brgyRes->fetch_assoc()){
    $allBarangays[] = $brgyRow;
    $barangayLookup[$brgyRow['barangay_id']] = $brgyRow['barangay_name'];
}

/* =========================
   PAGINATION URL
========================= */
function pageUrl($overrides = [])
{
    $qs = array_merge($_GET, $overrides);
    return '?' . http_build_query($qs);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Farmer Beneficiary - PIGOBAO</title>
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
    --pg-clay:#c1543f;
    --shadow-soft:0 1px 2px rgba(18,60,22,0.04),0 8px 24px -12px rgba(18,60,22,0.16);
    --shadow-lift:0 14px 30px -14px rgba(18,60,22,0.30);
}

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
    font-family:'Poppins',sans-serif;
}

body{
    min-height:100vh;
    display:flex;
    background:var(--pg-canvas);
    background-image:
        radial-gradient(1100px 480px at 100% -10%,rgba(46,125,50,0.07),transparent 60%),
        radial-gradient(700px 380px at -5% 100%,rgba(27,94,32,0.05),transparent 55%);
    color:var(--pg-ink);
}

/* =========================
   HEADER
========================= */
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
    box-shadow:0 2px 14px rgba(10,40,12,0.25);
}

header .brand{
    display:flex;
    align-items:center;
    gap:12px;
}

header .brand .mark{
    width:34px;
    height:34px;
    border-radius:9px;
    background:rgba(255,255,255,0.14);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    color:#eafbea;
    border:1px solid rgba(255,255,255,0.22);
}

header h1{
    font-size:17px;
    font-weight:600;
    letter-spacing:.2px;
}

header .brand small{
    display:block;
    font-size:11px;
    font-weight:400;
    color:rgba(255,255,255,0.65);
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
    box-shadow:0 2px 8px rgba(0,0,0,0.12);
}

/* =========================
   SIDEBAR
========================= */
.sidebar{
    position:fixed;
    top:65px;
    left:0;
    width:240px;
    height:calc(100% - 65px);
    background:linear-gradient(195deg,var(--pg-forest-800),var(--pg-forest-600) 120%);
    padding-top:16px;
    box-shadow:inset -1px 0 0 rgba(255,255,255,0.06);
}

.sidebar a{
    display:flex;
    align-items:center;
    gap:12px;
    color:rgba(255,255,255,0.88);
    padding:11px 22px;
    text-decoration:none;
    margin:2px 12px 3px;
    border-radius:9px;
    border-left:3px solid transparent;
    font-size:14px;
    font-weight:500;
}

.sidebar a i{
    width:18px;
    text-align:center;
    font-size:15px;
    opacity:.9;
}

.sidebar a:hover{
    background:rgba(255,255,255,0.12);
}

.sidebar a.active{
    background:var(--pg-forest-300);
    color:var(--pg-forest-900);
    border-left:3px solid var(--pg-forest-900);
    font-weight:600;
    box-shadow:0 4px 12px rgba(0,0,0,0.18);
}

/* =========================
   MAIN CONTENT
========================= */
.main-content{
    margin-left:240px;
    padding:88px 32px 25px;
    flex:1;
    width:auto;
}

.page-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:12px;
    margin-bottom:14px;
}

.page-head h2{
    font-family:'Space Grotesk',sans-serif;
    font-weight:700;
    font-size:22px;
    color:var(--pg-forest-900);
    margin-bottom:2px;
}

.page-head p{
    font-size:13px;
    color:var(--pg-ink-soft);
    margin-bottom:8px;
}

/* =========================
   TOTAL
========================= */
.total-badge{
    display:inline-flex;
    align-items:center;
    gap:10px;
    background:#fff;
    border:1px solid var(--pg-line);
    border-radius:12px;
    padding:7px 14px;
    box-shadow:var(--shadow-soft);
}

.total-badge .num{
    font-family:'Space Grotesk',sans-serif;
    font-weight:700;
    font-size:20px;
    color:var(--pg-forest-800);
}

.total-badge .lbl{
    font-size:11px;
    color:var(--pg-ink-soft);
    text-transform:uppercase;
    letter-spacing:.4px;
}

.total-badge i{
    color:var(--pg-forest-600);
    font-size:17px;
}

/* =========================
   ALERT
========================= */
.pg-alert{
    background:var(--pg-forest-100);
    color:var(--pg-forest-900);
    border:1px solid var(--pg-forest-300);
    border-left:4px solid var(--pg-forest-600);
    border-radius:10px;
    padding:13px 18px;
    margin-bottom:16px;
    font-size:14px;
    font-weight:500;
    display:flex;
    align-items:center;
    gap:10px;
}

/* =========================
   HEAD ACTIONS (Add button + search box)
========================= */
.head-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.add-btn{
    background:linear-gradient(120deg,var(--pg-forest-800),var(--pg-forest-600));
    color:#fff;
    border:none;
    padding:9px 18px;
    height:38px;
    border-radius:9px;
    font-weight:600;
    font-size:13.5px;
    letter-spacing:.2px;
    display:inline-flex;
    align-items:center;
    gap:8px;
    box-shadow:0 6px 16px -6px rgba(18,60,22,0.5);
    transition:0.2s ease;
    cursor:pointer;
    white-space:nowrap;
}

.add-btn:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 20px -6px rgba(18,60,22,0.55);
    color:#fff;
}

/* =========================
   SEARCH / FILTER
========================= */
.search-box{
    display:flex;
    gap:7px;
    align-items:center;
}

.search-box input,
.search-box select{
    border:1px solid var(--pg-line);
    border-radius:9px;
    padding:8px 12px;
    font-size:13.5px;
    outline:none;
    background:#fff;
    color:var(--pg-ink);
    height:38px;
}

.search-box input{
    min-width:210px;
}

.search-box input:focus,
.search-box select:focus{
    border-color:var(--pg-forest-600);
    box-shadow:0 0 0 2px rgba(46,125,50,.08);
}

.search-box button{
    height:38px;
    background:var(--pg-forest-800);
    color:#fff;
    border:none;
    border-radius:9px;
    padding:0 15px;
    font-weight:600;
    font-size:13px;
    cursor:pointer;
}

.search-box button:hover{
    background:var(--pg-forest-900);
}

/* =========================
   RESULTS
========================= */
.results-count{
    font-size:12px;
    color:var(--pg-ink-soft);
    margin-bottom:10px;
}

/* =========================
   CARDS
========================= */
.beneficiary-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
    gap:14px;
}

.b-card{
    background:#fff;
    border:1px solid var(--pg-line);
    border-radius:12px;
    padding:15px;
    box-shadow:var(--shadow-soft);
    text-align:center;
    transition:.2s ease;
}

.b-card:hover{
    transform:translateY(-3px);
    box-shadow:var(--shadow-lift);
}

.b-card img{
    width:72px;
    height:72px;
    border-radius:50%;
    object-fit:cover;
    margin-bottom:8px;
    border:3px solid var(--pg-forest-100);
}

.b-card h4{
    font-family:'Space Grotesk',sans-serif;
    font-size:15px;
    color:var(--pg-forest-900);
    margin-bottom:3px;
}

.b-card .addr{
    font-size:12px;
    color:var(--pg-ink-soft);
    margin-bottom:7px;
    min-height:18px;
}

.status-badge{
    display:inline-block;
    padding:3px 10px;
    border-radius:999px;
    font-size:10.5px;
    font-weight:600;
    letter-spacing:.3px;
    margin-bottom:9px;
    text-transform:capitalize;
}

.status-badge.active{
    background:var(--pg-forest-100);
    color:var(--pg-forest-800);
}

.status-badge.inactive{
    background:#fdeceb;
    color:var(--pg-clay);
}

.b-card a.view-btn{
    display:inline-block;
    background:var(--pg-forest-800);
    color:#fff;
    text-decoration:none;
    font-size:12px;
    font-weight:600;
    padding:7px 15px;
    border-radius:18px;
}

.b-card a.view-btn:hover{
    background:var(--pg-forest-900);
}

/* =========================
   CARD EDIT / DELETE ACTIONS
========================= */
.card-actions{
    display:flex;
    justify-content:center;
    gap:6px;
    margin-top:8px;
    flex-wrap:wrap;
}

.action-btn{
    border:none;
    border-radius:8px;
    padding:6px 12px;
    font-size:11.5px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    gap:5px;
    text-decoration:none;
    transition:0.2s ease;
    cursor:pointer;
}
.action-btn.edit{background:var(--pg-forest-100);color:var(--pg-forest-800);}
.action-btn.edit:hover{background:var(--pg-forest-300);color:var(--pg-forest-900);}
.action-btn.delete{background:#fbeceb;color:var(--pg-clay);}
.action-btn.delete:hover{background:var(--pg-clay);color:#fff;}

/* =========================
   MODALS (Add / Edit Beneficiary — merged in from add-beneficiaries.php)
========================= */
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
.modal-body{padding:24px;background:var(--pg-canvas);max-height:70vh;overflow-y:auto;}
.modal-body .form-control, .modal-body select.form-control{
    border:1px solid var(--pg-line);border-radius:9px;padding:10px 14px;
    font-size:14px;margin-bottom:12px;
}
.modal-body .form-control:focus{
    border-color:var(--pg-forest-600);
    box-shadow:0 0 0 3px rgba(46,125,50,0.14);
}
.modal-body label.field-label{
    display:block;font-size:11.5px;font-weight:600;text-transform:uppercase;
    letter-spacing:.3px;color:var(--pg-ink-soft);margin-bottom:5px;
}
.required-mark{
    color:#dc3545;
    font-weight:700;
    margin-left:2px;
}
.form-control.is-required-invalid,
select.is-required-invalid,
input[type="file"].is-required-invalid{
    border-color:#dc3545 !important;
    box-shadow:0 0 0 2px rgba(220,53,69,.10) !important;
}
.modal-photo-row{display:flex;align-items:center;gap:14px;margin-bottom:14px;}
.modal-photo-preview{
    width:64px;height:64px;border-radius:50%;object-fit:cover;flex:none;
    border:3px solid var(--pg-forest-100);
}
.modal-photo-preview.placeholder{
    display:flex;align-items:center;justify-content:center;
    background:var(--pg-forest-100);color:var(--pg-forest-600);font-size:20px;
}
.modal-footer{border:none;padding:16px 24px 22px;background:var(--pg-canvas);}
.modal-footer .btn-success{
    background:linear-gradient(120deg, var(--pg-forest-800), var(--pg-forest-600));
    border:none;border-radius:9px;padding:10px 20px;font-weight:600;font-size:14px;
    box-shadow:0 6px 16px -6px rgba(18,60,22,0.5);
}
.modal-footer .btn-success:hover{transform:translateY(-1px);}

/* =========================
   EMPTY
========================= */
.empty-state{
    grid-column:1/-1;
    background:#fff;
    border:1px dashed var(--pg-line);
    border-radius:12px;
    padding:35px 20px;
    text-align:center;
    color:var(--pg-ink-soft);
}

.empty-state i{
    font-size:30px;
    margin-bottom:8px;
    display:block;
}

/* =========================
   PAGINATION
========================= */
.pagination{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:5px;
    margin-top:18px;
    flex-wrap:wrap;
}

.pagination a,
.pagination span{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:34px;
    height:34px;
    padding:0 9px;
    border-radius:8px;
    text-decoration:none;
    font-size:12.5px;
    font-weight:600;
    border:1px solid var(--pg-line);
    color:var(--pg-forest-900);
    background:#fff;
}

.pagination a:hover{
    background:var(--pg-forest-100);
}

.pagination .current{
    background:var(--pg-forest-800);
    color:#fff;
    border-color:var(--pg-forest-800);
}

.pagination .disabled{
    opacity:.4;
    pointer-events:none;
}

/* =========================
   MOBILE
========================= */
@media(max-width:900px){
    .sidebar{ width:200px; }
    .main-content{ margin-left:200px; padding:88px 20px 25px; }
}

@media(max-width:768px){

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

    .main-content{
        margin-left:0;
        padding:20px 15px 25px;
    }

    header{
        padding:0 15px;
    }

    .page-head{
        align-items:flex-start;
    }

    .search-box{
        width:100%;
        flex-wrap:wrap;
    }

    .head-actions{
        width:100%;
        flex-direction:column;
        align-items:stretch;
    }

    .add-btn{
        justify-content:center;
    }

    .search-box select,
    .search-box input{
        flex:1;
        min-width:0;
    }

    .beneficiary-grid{
        grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
        gap:10px;
    }
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
            <small>Farmer Beneficiary &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../adminlog_out.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<div class="sidebar">
    <div class="menu-title">Main Menu</div>

    <a href="../admin_dashboard.php">
        <i class="fas fa-home"></i>
        Dashboard
    </a>

    <a href="./add-technician.php">
        <i class="fas fa-user-cog"></i>
    Technicians
    </a>

    <a href="./farmer-beneficiary.php" class="active">
        <i class="fas fa-id-card"></i>
        Farmer Beneficiary
    </a>

    <a href="./add-livestock.php">
        <i class="fas fa-drumstick-bite"></i>
        Livestock
    </a>

    <a href="./tracking.php">
        <i class="fas fa-eye"></i>
        Livestock Tracking
    </a>

    <a href="./dispersal.php">
        <i class="fas fa-people-carry-box"></i>
        Dispersal
    </a>

    <a href="./farmer-success-rate.php">
        <i class="fas fa-chart-pie"></i>
        Farmer Success Rate
    </a>

    <a href="./reports.php">
        <i class="fas fa-chart-line"></i>
        Reports
    </a>

    <a href="./generate-report.php">
        <i class="fas fa-file-alt"></i>
        Generate Report
    </a>

</div>

<div class="main-content">

    <div class="page-head page-card">

        <div class="page-icon"><i class="fas fa-id-card"></i></div>

        <div class="page-info">

            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>

            <h2>Farmer Beneficiary</h2>

            <p>
                Click a farmer to view their full profile and livestock distribution history.
            </p>

            <div class="total-badge">

                <i class="fas fa-users"></i>

                <div>
                    <div class="num">
                        <?= number_format($grandTotal) ?>
                    </div>

                    <div class="lbl">
                        Total Beneficiaries
                    </div>
                </div>

            </div>

        </div>

        <div class="head-actions">
            <button type="button" class="add-btn" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fas fa-plus"></i> Add Beneficiary
            </button>

            <form class="search-box" method="GET" action="" onsubmit="this.page.value='1';">

            <input type="hidden" name="page" value="<?= $page ?>">

            <select name="barangay_id" onchange="this.form.page.value='1'; this.form.submit();">

                <option value="">
                    All Barangays
                </option>

                <?php foreach ($allBarangays as $brRow): ?>

                    <option
                        value="<?= (int)$brRow['barangay_id'] ?>"
                        <?= ($barangayId === (int)$brRow['barangay_id']) ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($brRow['barangay_name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <input
                type="text"
                name="q"
                placeholder="Search by name..."
                value="<?= htmlspecialchars($search) ?>"
            >

            <button type="submit">
                <i class="fas fa-search"></i>
            </button>

            </form>
        </div>

    </div>

    <?php if($message): ?>
        <div class="pg-alert"><i class="fas fa-circle-info"></i> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($totalCount > 0): ?>

        <div class="results-count">

            Showing
            <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalCount) ?>
            of <?= $totalCount ?> beneficiaries

            <?php if ($totalPages > 1): ?>

                &middot;
                Page <?= $page ?> of <?= $totalPages ?>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <div class="beneficiary-grid">

        <?php $farmerRows = []; ?>
        <?php if ($result && $result->num_rows > 0): ?>

            <?php while ($row = $result->fetch_assoc()): $farmerRows[] = $row; ?>

                <?php

                $middleInitial = '';

                if (!empty($row['middlename'])) {
                    $middleInitial = ' ' . strtoupper(substr(trim($row['middlename']), 0, 1)) . '.';
                }

                $extension = '';

                if (!empty($row['extension_name'])) {
                    $extension = ' ' . trim($row['extension_name']);
                }

                $fullName = trim(
                    $row['firstname'] .
                    $middleInitial .
                    ' ' .
                    $row['lastname'] .
                    $extension
                );

                if (!empty($row['photo'])) {

                    $photo = '../uploads/beneficiaries/' .
                             rawurlencode($row['photo']);

                } else {

                    $photo = 'https://via.placeholder.com/150?text=No+Photo';

                }

                $status = strtolower(trim($row['status'] ?? ''));

                $statusClass = ($status === 'active')
                    ? 'active'
                    : 'inactive';

                ?>

                <div class="b-card">

                    <img
                        src="<?= htmlspecialchars($photo) ?>"
                        alt="Farmer photo"
                        onerror="this.src='https://via.placeholder.com/150?text=No+Photo';"
                    >

                    <h4>
                        <?= htmlspecialchars($fullName) ?>
                    </h4>

                    <div class="addr">

                        <i class="fas fa-map-marker-alt"></i>

                        <?= htmlspecialchars($row['barangay_name'] ?? 'No Barangay') ?>

                    </div>

                    <span class="status-badge <?= $statusClass ?>">
                        <?= htmlspecialchars($row['status'] ?? 'Unknown') ?>
                    </span>

                    <br>

                    <a
                        class="view-btn"
                        href="farmer-profile.php?id=<?= urlencode($row['beneficiary_id']) ?>"
                    >
                        <i class="fas fa-eye"></i>
                        View Profile
                    </a>

                    <div class="card-actions">
                        <button type="button" class="action-btn edit" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['beneficiary_id'] ?>">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <a href="farmer-beneficiary.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['delete' => $row['beneficiary_id']]))) ?>"
                           class="action-btn delete"
                           onclick="return confirm('Delete <?= htmlspecialchars($fullName) ?>?')">
                           <i class="fas fa-trash"></i> Delete
                        </a>
                    </div>

                </div>

            <?php endwhile; ?>

        <?php else: ?>

            <div class="empty-state">

                <i class="fas fa-user-friends"></i>

                No farmer beneficiaries found
                <?php if ($barangayId > 0): ?>
                    in the selected barangay.
                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

    <?php if ($totalPages > 1): ?>

        <div class="pagination">

            <?php if ($page > 1): ?>

                <a href="<?= pageUrl(['page' => $page - 1]) ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>

            <?php else: ?>

                <span class="disabled">
                    <i class="fas fa-chevron-left"></i>
                </span>

            <?php endif; ?>

            <?php

            $windowStart = max(1, $page - 2);
            $windowEnd = min($totalPages, $page + 2);

            if ($windowStart > 1) {

                echo '<a href="' . htmlspecialchars(pageUrl(['page' => 1])) . '">1</a>';

                if ($windowStart > 2) {
                    echo '<span>&hellip;</span>';
                }
            }

            for ($p = $windowStart; $p <= $windowEnd; $p++) {

                if ($p === $page) {

                    echo '<span class="current">' . $p . '</span>';

                } else {

                    echo '<a href="' .
                         htmlspecialchars(pageUrl(['page' => $p])) .
                         '">' . $p . '</a>';
                }
            }

            if ($windowEnd < $totalPages) {

                if ($windowEnd < $totalPages - 1) {
                    echo '<span>&hellip;</span>';
                }

                echo '<a href="' .
                     htmlspecialchars(pageUrl(['page' => $totalPages])) .
                     '">' .
                     $totalPages .
                     '</a>';
            }

            ?>

            <?php if ($page < $totalPages): ?>

                <a href="<?= pageUrl(['page' => $page + 1]) ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>

            <?php else: ?>

                <span class="disabled">
                    <i class="fas fa-chevron-right"></i>
                </span>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</div>

<!-- Edit Beneficiary Modals: rendered OUTSIDE the grid on purpose, same
     reasoning as any other Bootstrap modal set — keeping them inside a
     repeating grid item can mess with stacking/backdrop behavior. -->
<?php foreach($farmerRows as $b): ?>
<div class="modal fade" id="editModal<?= $b['beneficiary_id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="farmer-beneficiary.php" class="modal-content" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Beneficiary</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="beneficiary_id" value="<?= $b['beneficiary_id'] ?>">
        <input type="hidden" name="existing_photo" value="<?= htmlspecialchars($b['photo'] ?? '') ?>">

        <label class="field-label">Photo</label>
        <div class="modal-photo-row">
            <?php if(!empty($b['photo'])): ?>
                <img class="modal-photo-preview" src="../uploads/beneficiaries/<?= htmlspecialchars($b['photo']) ?>" alt="Current photo">
            <?php else: ?>
                <div class="modal-photo-preview placeholder"><i class="fas fa-user"></i></div>
            <?php endif; ?>
            <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
        </div>

        <label class="field-label">First Name <span class="required-mark">*</span></label>
        <input type="text" name="firstname" class="form-control required-field" value="<?= htmlspecialchars($b['firstname']) ?>" required>

        <label class="field-label">Middle Name</label>
        <input type="text" name="middlename" class="form-control" value="<?= htmlspecialchars($b['middlename']) ?>">

        <label class="field-label">Last Name <span class="required-mark">*</span></label>
        <input type="text" name="lastname" class="form-control required-field" value="<?= htmlspecialchars($b['lastname']) ?>" required>

        <label class="field-label">Extension Name</label>
        <input type="text" name="extension_name" class="form-control" value="<?= htmlspecialchars($b['extension_name']) ?>">

        <label class="field-label">Gender <span class="required-mark">*</span></label>
        <select name="gender" class="form-control required-field" required>
            <option value="">Select Gender</option>
            <option value="Male" <?= $b['gender']=='Male'?'selected':'' ?>>Male</option>
            <option value="Female" <?= $b['gender']=='Female'?'selected':'' ?>>Female</option>
        </select>

        <label class="field-label">Birthdate <span class="required-mark">*</span></label>
        <input type="date" name="birthdate" class="form-control required-field" max="<?= date('Y-m-d') ?>" value="<?= !empty($b['birthdate']) ? htmlspecialchars(date('Y-m-d', strtotime($b['birthdate']))) : '' ?>" required>

        <label class="field-label">Barangay <span class="required-mark">*</span></label>
        <select name="barangay_id" class="form-control required-field" required>
            <?php foreach($allBarangays as $brgy_opt): ?>
              <option value="<?= $brgy_opt['barangay_id'] ?>" <?= $brgy_opt['barangay_id']==$b['barangay_id']?'selected':'' ?>>
                <?= htmlspecialchars($brgy_opt['barangay_name']) ?>
              </option>
            <?php endforeach; ?>
        </select>

        <label class="field-label">Contact Number <span class="required-mark">*</span></label>
        <input type="text" name="contact" class="form-control required-field" value="<?= htmlspecialchars($b['contact']) ?>" required>

        <label class="field-label">Civil Status <span class="required-mark">*</span></label>
        <select name="civil_status" class="form-control required-field" required>
            <option value="Single" <?= $b['civil_status']=='Single'?'selected':'' ?>>Single</option>
            <option value="Married" <?= $b['civil_status']=='Married'?'selected':'' ?>>Married</option>
            <option value="Widowed" <?= $b['civil_status']=='Widowed'?'selected':'' ?>>Widowed</option>
            <option value="Separated" <?= $b['civil_status']=='Separated'?'selected':'' ?>>Separated</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="submit" name="update_beneficiary" class="btn btn-success">
            <i class="fas fa-save"></i> Update Beneficiary
        </button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- Add Beneficiary Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="farmer-beneficiary.php" class="modal-content" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add Beneficiary</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="field-label">Photo <span class="required-mark">*</span></label>
        <div class="modal-photo-row">
            <div class="modal-photo-preview placeholder"><i class="fas fa-user"></i></div>
            <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
        </div>

        <label class="field-label">First Name <span class="required-mark">*</span></label>
        <input type="text" name="firstname" class="form-control required-field" placeholder="First Name" required>

        <label class="field-label">Middle Name</label>
        <input type="text" name="middlename" class="form-control" placeholder="Middle Name">

        <label class="field-label">Last Name <span class="required-mark">*</span></label>
        <input type="text" name="lastname" class="form-control required-field" placeholder="Last Name" required>

        <label class="field-label">Extension Name</label>
        <input type="text" name="extension_name" class="form-control" placeholder="Extension Name (e.g. Jr., Sr.)">

        <label class="field-label">Gender <span class="required-mark">*</span></label>
        <select name="gender" class="form-control required-field" required>
          <option value="">Select Gender</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>

        <label class="field-label">Birthdate <span class="required-mark">*</span></label>
        <input type="date" name="birthdate" class="form-control required-field" max="<?= date('Y-m-d') ?>" required>

        <label class="field-label">Barangay <span class="required-mark">*</span></label>
        <select name="barangay_id" class="form-control required-field" required>
          <option value="">Select Barangay</option>
          <?php foreach($allBarangays as $brgy_opt): ?>
            <option value="<?= $brgy_opt['barangay_id'] ?>"><?= htmlspecialchars($brgy_opt['barangay_name']) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="field-label">Contact Number <span class="required-mark">*</span></label>
        <input type="text" name="contact" class="form-control required-field" placeholder="Contact Number" required>

        <label class="field-label">Civil Status <span class="required-mark">*</span></label>
        <select name="civil_status" class="form-control required-field" required>
          <option value="">Select Civil Status</option>
          <option value="Single">Single</option>
          <option value="Married">Married</option>
          <option value="Widowed">Widowed</option>
          <option value="Separated">Separated</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="submit" name="add_beneficiary" class="btn btn-success">
          <i class="fas fa-save"></i> Save Beneficiary
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Validate every Add/Edit beneficiary form.
    document.querySelectorAll('form.modal-content').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            let valid = true;
            let firstInvalid = null;

            form.querySelectorAll('.required-field').forEach(function (field) {
                const value = field.value.trim();

                if (value === '') {
                    field.classList.add('is-required-invalid');
                    valid = false;
                    if (!firstInvalid) firstInvalid = field;
                } else {
                    field.classList.remove('is-required-invalid');
                }
            });

            // Add form photo is required. Edit photo is optional because
            // the existing photo can be retained.
            const photo = form.querySelector('input[name="photo"][required]');
            if (photo) {
                if (!photo.files || photo.files.length === 0) {
                    photo.classList.add('is-required-invalid');
                    valid = false;
                    if (!firstInvalid) firstInvalid = photo;
                } else {
                    photo.classList.remove('is-required-invalid');
                }
            }

            if (!valid) {
                e.preventDefault();
                if (firstInvalid) firstInvalid.focus();
            }
        });

        // Remove the red border as soon as the user starts/fixes the field.
        form.querySelectorAll('.required-field').forEach(function (field) {
            field.addEventListener('input', function () {
                if (this.value.trim() !== '') {
                    this.classList.remove('is-required-invalid');
                }
            });
            field.addEventListener('change', function () {
                if (this.value.trim() !== '') {
                    this.classList.remove('is-required-invalid');
                }
            });
        });

        const photo = form.querySelector('input[name="photo"][required]');
        if (photo) {
            photo.addEventListener('change', function () {
                if (this.files && this.files.length > 0) {
                    this.classList.remove('is-required-invalid');
                }
            });
        }
    });
});
</script>
</body>
</html>