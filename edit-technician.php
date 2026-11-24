<?php
session_start();
require '../db.php';

if(!isset($_SESSION['admin_id'])) {
    header("Location: ../adminindex.php");
    exit();
}

$message = "";

// Get technician ID
if(!isset($_GET['id'])) {
    header("Location: add-technician.php");
    exit();
}
$id = intval($_GET['id']);

// Fetch technician
$stmt = $conn->prepare("SELECT * FROM technicians WHERE technician_id=?");
$stmt->bind_param("i",$id);
$stmt->execute();
$tech = $stmt->get_result()->fetch_assoc();

if(!$tech){
    header("Location: add-technician.php");
    exit();
}

// Update technician
if(isset($_POST['update_technician'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $contact = trim($_POST['contact']);
    $status = $_POST['status'];

    // If password field is filled, update it
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
        // Refresh data
        $stmt = $conn->prepare("SELECT * FROM technicians WHERE technician_id=?");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        $tech = $stmt->get_result()->fetch_assoc();
    } else {
        $message = "Error: ".$stmt->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Technician - PIGOBAO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{min-height:100vh;display:flex;background:#f4f6f9;font-family:'Poppins',sans-serif;color:#333;}
header{position:fixed;top:0;left:0;width:100%;height:65px;background:#1b5e20;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:0 30px;z-index:1001;border-bottom:4px solid #145a32;}
header h1{font-size:18px;font-weight:600;}
header a.logout{color:#1b5e20;background:#fff;padding:8px 18px;border-radius:20px;font-weight:600;text-decoration:none;}
header a.logout:hover{background:#c8e6c9;}
.sidebar{position:fixed;top:65px;left:0;width:230px;height:calc(100% - 65px);background:#2e7d32;padding-top:25px;}
.sidebar a{display:flex;align-items:center;gap:10px;color:#fff;padding:14px 22px;text-decoration:none;margin-bottom:8px;}
.sidebar a:hover{background:rgba(255,255,255,0.15);}
.sidebar a.active{background:#a5d6a7;color:#1b5e20;font-weight:600;}
.main-content{margin-left:230px;padding:100px 35px 30px;flex:1;background:#fff;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,0.1);}

/* RESPONSIVE */
@media(max-width:900px){
    body{display:block;}
    .sidebar{width:200px;}
    .main-content{margin-left:200px;padding:100px 24px 30px;}
}
@media(max-width:768px){
    body{display:block;}
    header{padding:0 16px;}
    header h1{font-size:15px;}
    header a.logout{padding:7px 14px;font-size:13px;}

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
    .sidebar a{margin:2px;padding:9px 12px;gap:8px;font-size:12px;}

    .main-content{margin-left:0;padding:20px 16px 30px;border-radius:0;box-shadow:none;}
}
</style>
</head>
<body>

<header>
    <h1>PIGOBAO Admin Dashboard</h1>
    <a href="../adminlog_out.php" class="logout">Logout</a>
</header>

<div class="sidebar">
    <a href="../admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="add-technician.php" class="active"><i class="fas fa-user-cog"></i> Technicians</a>
    <a href="add-beneficiaries.php"><i class="fas fa-user-friends"></i> Beneficiaries</a>
    <a href="add-livestock.php"><i class="fas fa-drumstick-bite"></i> Livestock</a>
    <a href="distribution.php"><i class="fas fa-exchange-alt"></i> Distribution</a>
    <a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a>
</div>

<div class="main-content">
    <h2>Edit Technician</h2>
    <?php if($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST" class="mt-3">
        <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($tech['name']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($tech['email']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($tech['username']) ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Password (leave blank to keep current)</label>
            <input type="password" name="password" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label">Contact Number</label>
            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($tech['contact']) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="active" <?= $tech['status']=='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $tech['status']=='inactive'?'selected':'' ?>>Inactive</option>
            </select>
        </div>
        <button type="submit" name="update_technician" class="btn btn-success"><i class="fas fa-save"></i> Update Technician</button>
        <a href="add-technician.php" class="btn btn-secondary">Back</a>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>