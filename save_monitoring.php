<?php
session_start();
require '../db.php';

if (!isset($_SESSION['technician_id'])) {
    header("Location: ../technicianindex.php");
    exit();
}

$tech_id = (int)$_SESSION['technician_id'];

/* =========================================================
   ONLY ACCEPT POST REQUEST
========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_monitoring'])) {
    header("Location: monitoring.php");
    exit();
}

/* =========================================================
   REQUIRED FIELDS
========================================================= */
if (
    !isset($_POST['distribution_id']) ||
    !isset($_POST['health_status']) ||
    !isset($_POST['monitor_date'])
) {
    $_SESSION['error'] = "Missing required monitoring fields.";
    header("Location: monitoring.php");
    exit();
}

$distribution_id = (int)$_POST['distribution_id'];
$health_status = trim($_POST['health_status']);
$monitor_date = trim($_POST['monitor_date']);
$remarks = trim($_POST['remarks'] ?? '');

/* =========================================================
   BASIC VALIDATION
========================================================= */
if ($distribution_id <= 0) {
    $_SESSION['error'] = "Invalid distribution record.";
    header("Location: monitoring.php");
    exit();
}

if ($health_status === '') {
    $_SESSION['error'] = "Please select the livestock health status.";
    header("Location: monitoring.php");
    exit();
}

if ($monitor_date === '') {
    $_SESSION['error'] = "Please select the monitoring date.";
    header("Location: monitoring.php");
    exit();
}

/* =========================================================
   ALL FIELDS ARE REQUIRED
   (checked before any photo is uploaded)
========================================================= */
function fail_required($message) {
    $_SESSION['error'] = $message;
    header("Location: monitoring.php");
    exit();
}

// Monitoring date must be a real date (YYYY-MM-DD)
$dateCheck = DateTime::createFromFormat('Y-m-d', $monitor_date);
if (!$dateCheck || $dateCheck->format('Y-m-d') !== $monitor_date) {
    fail_required("Please enter a valid monitoring date.");
}

// Mortality case selection
$posted_livestock_status = strtolower(trim($_POST['livestock_status'] ?? ''));
if (!in_array($posted_livestock_status, ['alive', 'death'], true)) {
    fail_required("Please select the mortality case (alive or death).");
}

// Health status must be one of the allowed values
if (!in_array($health_status, ['Healthy', 'Sick', 'Critical'], true)) {
    fail_required("Please select a valid health status.");
}

// Weight
if (!isset($_POST['weight']) || trim($_POST['weight']) === '' || !is_numeric($_POST['weight'])) {
    fail_required("Please enter the livestock weight.");
}

// Pregnancy status
if (
    !isset($_POST['pregnancy_status']) ||
    !in_array($_POST['pregnancy_status'], ['pregnant', 'not_pregnant', 'gave_birth'], true)
) {
    fail_required("Please select the pregnancy status.");
}

// Offspring alive / dead (total is computed automatically)
if (!isset($_POST['offspring_alive']) || trim($_POST['offspring_alive']) === '' || !ctype_digit(trim($_POST['offspring_alive']))) {
    fail_required("Please enter the number of offspring alive (0 or more).");
}
if (!isset($_POST['offspring_dead']) || trim($_POST['offspring_dead']) === '' || !ctype_digit(trim($_POST['offspring_dead']))) {
    fail_required("Please enter the number of offspring dead (0 or more).");
}

// Remarks / notes
if ($remarks === '') {
    fail_required("Please enter remarks / notes.");
}

// Photo
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    fail_required("Please upload a photo.");
}

// Death details
if ($posted_livestock_status === 'death') {
    if (trim($_POST['cause_of_death'] ?? '') === '') {
        fail_required("Please provide the cause of death.");
    }
    if (!in_array(strtolower(trim($_POST['death_type'] ?? '')), ['natural', 'disease', 'accident', 'unknown', 'other'], true)) {
        fail_required("Please select a valid death type.");
    }
}

/* =========================================================
   CHECK IF DISTRIBUTION BELONGS TO TECHNICIAN ASSIGNMENT
========================================================= */
$check = $conn->prepare("
    SELECT
        d.distribution_id,
        d.status,
        d.beneficiary_id
    FROM distributions d
    INNER JOIN beneficiaries b
        ON d.beneficiary_id = b.beneficiary_id
    INNER JOIN technician_barangay tb
        ON b.barangay_id = tb.barangay_id
    WHERE d.distribution_id = ?
      AND tb.technician_id = ?
    LIMIT 1
");

$check->bind_param(
    "ii",
    $distribution_id,
    $tech_id
);

$check->execute();

$checkResult = $check->get_result();

if ($checkResult->num_rows === 0) {
    $_SESSION['error'] = "You are not assigned to this beneficiary's barangay.";
    header("Location: monitoring.php");
    exit();
}

$distribution = $checkResult->fetch_assoc();

/* =========================================================
   LIVESTOCK STATUS
   alive = normal monitoring
   death = mortality case
========================================================= */
$livestock_status = strtolower(trim($_POST['livestock_status'] ?? 'alive'));

if ($livestock_status !== 'death') {
    $livestock_status = 'alive';
}

/* =========================================================
   DEATH INFORMATION
========================================================= */
$cause_of_death = null;
$death_type = null;

if ($livestock_status === 'death') {

    $cause_of_death = trim($_POST['cause_of_death'] ?? '');

    if ($cause_of_death === '') {
        $_SESSION['error'] = "Please provide the cause of death.";
        header("Location: monitoring.php");
        exit();
    }

    $allowed_death_types = [
        'natural',
        'disease',
        'accident',
        'unknown',
        'other'
    ];

    $posted_death_type = strtolower(
        trim($_POST['death_type'] ?? '')
    );

    if (!in_array($posted_death_type, $allowed_death_types, true)) {
        $_SESSION['error'] = "Please select a valid death type.";
        header("Location: monitoring.php");
        exit();
    }

    $death_type = $posted_death_type;
}

/* =========================================================
   WEIGHT
========================================================= */
$weight = null;

if (
    isset($_POST['weight']) &&
    $_POST['weight'] !== ''
) {
    $weight = (float)$_POST['weight'];

    if ($weight < 0) {
        $_SESSION['error'] = "Weight cannot be negative.";
        header("Location: monitoring.php");
        exit();
    }
}

/* =========================================================
   PREGNANCY STATUS
========================================================= */
$pregnancy_status = null;

$allowed_pregnancy = [
    'pregnant',
    'not_pregnant',
    'gave_birth'
];

if (
    isset($_POST['pregnancy_status']) &&
    in_array(
        $_POST['pregnancy_status'],
        $allowed_pregnancy,
        true
    )
) {
    $pregnancy_status = $_POST['pregnancy_status'];
}

/* =========================================================
   OFFSPRING (alive + dead = total)
   Offspring only applies when the animal gave birth.
========================================================= */
$offspring_alive = (int)trim($_POST['offspring_alive']);
$offspring_dead  = (int)trim($_POST['offspring_dead']);

if ($pregnancy_status !== 'gave_birth') {
    $offspring_alive = 0;
    $offspring_dead  = 0;
}

$offspring = $offspring_alive + $offspring_dead;   // total offspring

/* =========================================================
   PHOTO UPLOAD
   Death requires a photo.
========================================================= */
$photoFile = null;

if (
    isset($_FILES['photo']) &&
    $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE
) {

    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "There was a problem uploading the photo.";
        header("Location: monitoring.php");
        exit();
    }

    /* Maximum 5 MB */
    if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
        $_SESSION['error'] = "Photo is too large. Maximum size is 5MB.";
        header("Location: monitoring.php");
        exit();
    }

    /* Check actual image */
    $imgInfo = @getimagesize(
        $_FILES['photo']['tmp_name']
    );

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];

    if (
        $imgInfo === false ||
        !isset($allowedMime[$imgInfo['mime']])
    ) {
        $_SESSION['error'] = "Invalid photo. Only JPG and PNG images are allowed.";
        header("Location: monitoring.php");
        exit();
    }

    /* Create uploads folder */
    $uploadDir = "../uploads/";

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            $_SESSION['error'] = "Unable to create upload directory.";
            header("Location: monitoring.php");
            exit();
        }
    }

    /* Generate safe filename */
    $extension = $allowedMime[$imgInfo['mime']];

    try {
        $randomNumber = random_int(1000, 9999);
    } catch (Exception $e) {
        $randomNumber = mt_rand(1000, 9999);
    }

    $photoFile =
        "monitor_" .
        date("YmdHis") .
        "_" .
        $randomNumber .
        "." .
        $extension;

    $destination = $uploadDir . $photoFile;

    if (
        !move_uploaded_file(
            $_FILES['photo']['tmp_name'],
            $destination
        )
    ) {
        $_SESSION['error'] = "Failed to save the uploaded photo.";
        header("Location: monitoring.php");
        exit();
    }
}

/* =========================================================
   REQUIRE PHOTO FOR DEATH
========================================================= */
if (
    $livestock_status === 'death' &&
    $photoFile === null
) {
    $_SESSION['error'] =
        "A photo is required when reporting a death.";

    header("Location: monitoring.php");
    exit();
}

/* =========================================================
   MAP FORM VALUES TO THE ACTUAL SQL SCHEMA
========================================================= */
if ($livestock_status === 'death') {
    $health_status = 'Dead';
    $action = 'death';
} else {
    $allowed_health = ['Healthy', 'Sick', 'Critical'];

    if (!in_array($health_status, $allowed_health, true)) {
        $_SESSION['error'] = "Please select a valid health status.";
        header("Location: monitoring.php");
        exit();
    }

    $action = 'visit';
}

$purpose = 'Routine Monitoring';

/* =========================================================
   INSERT MONITORING RECORD
   Exact pigo.sql columns:
   health_status, offspring_count, offspring_alive, offspring_dead, notes, action
========================================================= */
$stmt = $conn->prepare("
    INSERT INTO monitoring (
        distribution_id,
        health_status,
        weight,
        pregnancy_status,
        offspring_count,
        offspring_alive,
        offspring_dead,
        photo,
        cause_of_death,
        death_type,
        purpose,
        date_recorded,
        action,
        notes,
        created_by
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    $_SESSION['error'] = "Unable to prepare monitoring record: " . $conn->error;
    header("Location: monitoring.php");
    exit();
}

$stmt->bind_param(
    "isdsiiisssssssi",
    $distribution_id,
    $health_status,
    $weight,
    $pregnancy_status,
    $offspring,
    $offspring_alive,
    $offspring_dead,
    $photoFile,
    $cause_of_death,
    $death_type,
    $purpose,
    $monitor_date,
    $action,
    $remarks,
    $tech_id
);

if (!$stmt->execute()) {
    if ($photoFile !== null && file_exists("../uploads/" . $photoFile)) {
        unlink("../uploads/" . $photoFile);
    }

    $_SESSION['error'] = "Failed to save monitoring record: " . $stmt->error;
    header("Location: monitoring.php");
    exit();
}

$monitor_id = $stmt->insert_id;
$stmt->close();

/*
 * pigo.sql already has trg_monitoring_death, which updates
 * distributions and livestock automatically for a death record.
 */
if ($livestock_status === 'death') {
    $_SESSION['message'] = "Mortality case recorded successfully.";
    header("Location: mortality_cases.php?saved=1&monitor_id=" . $monitor_id);
    exit();
}

$_SESSION['message'] = "Monitoring record saved successfully.";
header("Location: monitoring_history.php?saved=1&monitor_id=" . $monitor_id);
exit();
?>