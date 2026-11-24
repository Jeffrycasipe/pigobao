<?php
session_start();
require '../db.php';

if (!isset($_SESSION['technician_id'])) {
    header("Location: ../technicianindex.php");
    exit();
}

$tech_id = (int)$_SESSION['technician_id'];

/* All the SQL + aggregation that builds the chart data now lives in
   inc_report_data.php, shared with tech_reports_data.php (the JSON
   endpoint the page polls so charts stay current when a death or
   return gets recorded elsewhere, without needing a page reload). */
require 'inc_report_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Technician Reports - PIGOBAO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root{
    --forest-900:#123c16;
    --forest-800:#1b5e20;
    --forest-600:#2e7d32;
    --forest-300:#a5d6a7;
    --forest-100:#e8f5e9;
    --canvas:#f3f6f4;
    --ink:#233126;
    --muted:#66736a;
    --line:#e1e9e2;
    --amber:#c98a1f;
    --blue:#3f7cac;
    --red:#c1543f;
    --shadow:0 8px 24px -12px rgba(18,60,22,.18);
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

.topbar{
    position:fixed;
    top:0;
    left:0;
    right:0;
    height:65px;
    background:linear-gradient(120deg,var(--forest-900),var(--forest-800) 55%,var(--forest-600));
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 28px;
    z-index:1000;
    box-shadow:0 3px 15px rgba(0,0,0,.18);
}

.brand{
    display:flex;
    align-items:center;
    gap:11px;
}

.brand-icon{
    width:35px;
    height:35px;
    border-radius:9px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.2);
    display:flex;
    align-items:center;
    justify-content:center;
}

.brand h1{
    font-size:17px;
    margin:0;
    font-weight:700;
}

.brand small{
    display:block;
    font-size:10px;
    color:rgba(255,255,255,.7);
}

.logout{
    color:var(--forest-800);
    background:#fff;
    padding:8px 18px;
    border-radius:20px;
    text-decoration:none;
    font-size:12px;
    font-weight:600;
}

.sidebar{
    position:fixed;
    top:65px;
    left:0;
    bottom:0;
    width:230px;
    background:linear-gradient(195deg,var(--forest-800),var(--forest-600));
    padding-top:22px;
    z-index:999;
    overflow-y:auto;
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
    background:var(--forest-300);
    color:var(--forest-900);
    font-weight:600;
    box-shadow:0 4px 12px rgba(0,0,0,.18);
}

.main{
    margin-left:235px;
    padding:90px 32px 35px;
    max-width:1500px;
}

.page-header{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px;
    padding:20px 23px;
    display:flex;
    align-items:center;
    gap:14px;
    box-shadow:var(--shadow);
    margin-bottom:18px;
}

.page-icon{
    width:47px;
    height:47px;
    border-radius:12px;
    background:linear-gradient(145deg,var(--forest-600),var(--forest-900));
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
}

.page-header h2{
    font-family:'Space Grotesk',sans-serif;
    color:var(--forest-900);
    font-size:21px;
    margin:0 0 3px;
    font-weight:700;
}

.page-header p{
    color:var(--muted);
    font-size:11px;
    margin:0;
}

.stats{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
    margin-bottom:18px;
}

.stat-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:14px;
    padding:17px;
    box-shadow:var(--shadow);
    display:flex;
    align-items:center;
    gap:13px;
}

.stat-icon{
    width:42px;
    height:42px;
    border-radius:11px;
    background:var(--forest-100);
    color:var(--forest-800);
    display:flex;
    align-items:center;
    justify-content:center;
}

.stat-card strong{
    display:block;
    color:var(--forest-900);
    font-family:'Space Grotesk',sans-serif;
    font-size:21px;
}

.stat-card span{
    font-size:10px;
    color:var(--muted);
}

.section-title{
    font-family:'Space Grotesk',sans-serif;
    color:var(--forest-900);
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.5px;
    font-weight:700;
    margin:0 0 8px 2px;
}

.global-year-bar{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px;
    box-shadow:var(--shadow);
    padding:13px 17px;
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.global-year-bar select{
    background:var(--forest-800);
    color:#fff;
    border-color:var(--forest-800);
}

.global-year-bar small{
    color:var(--muted);
    font-size:10.5px;
}

.chart-grid{
    display:grid;
    grid-template-columns:.85fr 1.55fr;
    gap:17px;
    margin-bottom:18px;
}

.chart-full-width{
    grid-column: 1 / -1;
}

.chart-full-width .chart-box{
    height:340px;
}

.card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:15px;
    box-shadow:var(--shadow);
    overflow:hidden;
}

.card-head{
    padding:14px 17px;
    border-bottom:1px solid var(--line);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.card-head h3{
    font-family:'Space Grotesk',sans-serif;
    font-size:13px;
    color:var(--forest-900);
    margin:0;
    font-weight:700;
}

.card-head small{
    color:var(--muted);
    font-size:9.5px;
}

.filter-group{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.year-filter{
    display:flex;
    align-items:center;
    gap:6px;
}

.year-filter label{
    font-size:9.5px;
    color:var(--muted);
    font-weight:600;
    display:flex;
    align-items:center;
    gap:4px;
    white-space:nowrap;
}

.year-filter select{
    border:1px solid var(--line);
    border-radius:8px;
    padding:6px 9px;
    font-family:'Poppins',sans-serif;
    font-size:11px;
    font-weight:600;
    color:var(--forest-900);
    background:var(--forest-100);
    cursor:pointer;
}

.chart-box{
    height:300px;
    padding:15px;
}

.highest{
    padding:10px 15px;
    background:var(--forest-100);
    border-top:1px solid var(--line);
    display:flex;
    justify-content:space-between;
    align-items:center;
    font-size:10px;
    gap:10px;
    flex-wrap:wrap;
}

.highest strong{
    color:var(--forest-900);
}

.highest.multi-stat{
    flex-wrap:wrap;
    gap:16px 22px;
}

.highest.multi-stat .stat-pair{
    display:flex;
    flex-direction:column;
    gap:4px;
    flex:1 1 220px;
    min-width:190px;
}

.highest.multi-stat .stat-pair-top{
    display:flex;
    align-items:center;
    gap:8px;
}

.highest.multi-stat .stat-pair strong{
    margin-left:auto;
    white-space:nowrap;
}

.highest.multi-stat .stat-desc{
    font-size:9px;
    color:var(--muted);
    font-weight:400;
    line-height:1.35;
}

@media(max-width:1100px){
    .chart-grid{
        grid-template-columns:1fr;
    }
    .stats{
        grid-template-columns:1fr;
    }
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
    .sidebar a{
        margin:2px;
        padding:9px 10px;
        font-size:11px;
    }

    .main{
        margin-left:0;
        padding:20px 15px 25px;
    }

    .topbar{
        padding:0 15px;
    }

    .brand h1{
        font-size:14px;
    }

    .page-header{
        padding:16px;
    }
}

@media print{
    @page{
        size:A4 portrait;
        margin:10mm;
    }

    body{
        background:#fff !important;
    }

    .topbar,
    .sidebar{
        display:none !important;
    }

    .main{
        margin:0 !important;
        padding:0 !important;
        max-width:none !important;
    }
}

/* =========================================================
   GOVERNMENT PORTAL THEME  (matches admin / technician dashboards)
   Screen only - the printable report layout above is untouched.
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
header,.topbar{
top:var(--strip-h);height:var(--header-h);padding:0 30px;z-index:1002;
background:linear-gradient(120deg,#123c16,#1b5e20 60%,#2e7d32);
box-shadow:0 4px 16px rgba(10,40,12,.28);
}
header .brand,.topbar .brand{display:flex;align-items:center;gap:14px;}
.seal{width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none;box-shadow:0 0 0 2px rgba(255,255,255,.35);}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:#1b5e20;}
.brand .titles{padding-left:14px;border-left:1px solid rgba(255,255,255,.28);}
.brand .titles h1{font-family:'Merriweather',serif;font-size:19px;font-weight:700;letter-spacing:1.2px;line-height:1.15;margin:0;color:#fff;}
.brand .titles small{display:block;font-size:12px;font-weight:400;color:rgba(255,255,255,.78);letter-spacing:.5px;text-transform:uppercase;margin-top:2px;}
header a.logout,.topbar a.logout{
color:#123c16;background:#fff;padding:9px 22px;border-radius:8px;font-weight:600;font-size:14px;
text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.14);transition:.2s ease;
}
header a.logout:hover,.topbar a.logout:hover{background:#e8f5e9;transform:translateY(-1px);}

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
.main-content,.main{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
max-width:none;width:auto;min-height:100vh;
display:flex;flex-direction:column;
}

/* page banner */
.page-card,.page-header{
background:#fff;border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;
padding:22px 26px;gap:18px;box-shadow:var(--gv-shadow);margin-bottom:26px;position:relative;overflow:hidden;
}
.page-card::before{display:none;}
.page-card::after,.page-header::after{
content:"\f200";font-family:"Font Awesome 6 Free";font-weight:900;position:absolute;right:-8px;bottom:-30px;
font-size:120px;color:#e8f5e9;z-index:0;pointer-events:none;
}
.page-card > *,.page-header > *{position:relative;z-index:1;}
.page-icon{width:60px;height:60px;border-radius:50%;font-size:22px;box-shadow:0 0 0 4px #e8f5e9;background:linear-gradient(145deg,#2e7d32,#123c16);}
.page-date{font-size:12.5px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--gov-navy);margin-bottom:2px;display:flex;align-items:center;gap:7px;}
.page-info h2,.page-header h2{font-size:23px;margin:0 0 3px;color:#123c16;}
.page-info p,.page-header p{font-size:14px;color:var(--gv-soft);max-width:680px;}
.role-pill{
margin-left:auto;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#123c16;color:#fff;
display:inline-flex;align-items:center;gap:8px;letter-spacing:.3px;
}
.role-pill i{color:var(--gov-gold);}

/* section headings */
.section-label,.section-title{
font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
color:#123c16;display:flex;align-items:center;gap:10px;margin:0 0 14px;
}
.section-label::before,.section-title::before{content:"";width:5px;height:20px;border-radius:3px;background:linear-gradient(180deg,var(--gov-gold),#2e7d32);}
.section-label-row{margin-bottom:14px;}
.section-label-row .section-label{margin:0 !important;}

/* cards */
.chart-card,.card{
border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;
box-shadow:var(--gv-shadow);background:#fff;
}
.chart-card{padding:22px 24px;}
.chart-card::before{display:none;}
.chart-card:hover,.card:hover{box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.chart-grid{gap:18px;margin-bottom:26px;}
.chart-title-icon{width:38px;height:38px;border-radius:10px;font-size:16px;background:#e6eef8;color:var(--gov-navy);}
.chart-header h3,.card-head h3{font-size:15px;letter-spacing:.2px;color:#123c16;}
.chart-header span,.card-head small{font-size:12.5px;color:var(--gv-soft);}
.chart-header{padding-bottom:12px;margin-bottom:14px;border-bottom:1px dashed var(--gv-line);}
.chart-desc{font-size:13px;border-radius:8px;border-left:4px solid var(--gov-gold);background:#f7faf7;}
.peak-badge{font-size:12.5px;border-radius:8px;background:#e6eef8;color:var(--gov-navy);border:1px solid #cfdcee;}
.pie-total-box{border-radius:10px;background:#f7faf7;}
.pie-total-label{font-size:11.5px;}
.pie-total-sub,.pie-total-row{font-size:12.5px;}
.trend-legend-item{border-radius:8px;}
.trend-legend-item .t-name{font-size:13.5px;}
.trend-legend-item .t-peak,.trend-hint{font-size:11.5px;}

/* filters */
.chart-year-filter label,.year-filter label{font-size:12.5px;color:var(--gv-soft);}
.chart-year-filter select,.year-filter select{font-size:14px;padding:7px 12px;border-radius:7px;border:1px solid var(--gv-line);color:#123c16;}
.global-year-bar{
border:1px solid var(--gv-line);border-radius:12px;box-shadow:var(--gv-shadow);padding:12px 18px;margin-bottom:26px;
}
.global-year-bar select{background:#f3f6f4;color:#123c16;border:1px solid var(--gv-line);}
.global-year-bar small{font-size:13px;}

/* tech stat cards */
.stats{gap:18px;margin-bottom:26px;}
.stat-card{
--accent:#1b5e20;--accent-soft:#e8f5e9;
border:1px solid var(--gv-line);border-top:5px solid var(--accent);border-radius:12px;padding:22px 24px;gap:16px;
box-shadow:var(--gv-shadow);transition:transform .2s ease,box-shadow .2s ease;
}
.stat-card:nth-child(2){--accent:#0b2f5b;--accent-soft:#e6eef8;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 16px 30px -16px rgba(18,60,22,.38);}
.stat-icon{width:52px;height:52px;border-radius:12px;font-size:22px;background:var(--accent-soft);color:var(--accent);flex:none;}
.stat-card strong{font-size:38px;line-height:1.05;color:var(--accent);}
.stat-card span{font-size:13px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--gv-soft);}

/* tech card internals */
.card-head{padding:16px 22px;background:#fbfdfb;border-bottom:1px dashed var(--gv-line);}
.highest{font-size:13px;padding:14px 22px;background:#f7faf7;}
.highest.multi-stat .stat-desc{font-size:11.5px;}

/* toolbar + table (reports.php) */
.report-toolbar{border:1px solid var(--gv-line);border-left:6px solid #1b5e20;border-radius:12px;box-shadow:var(--gv-shadow);padding:16px 22px;}
.toolbar-icon{width:42px;height:42px;border-radius:10px;font-size:17px;}
.toolbar-info strong{font-size:15px;}
.toolbar-info small{font-size:12.5px;}
.pg-btn{font-size:13px;padding:10px 18px;border-radius:8px;}
.report-card{border:1px solid var(--gv-line);border-top:5px solid var(--gov-navy);border-radius:12px;box-shadow:var(--gv-shadow);}
.report-card-header{padding:16px 22px;background:#fbfdfb;border-bottom:1px dashed var(--gv-line);}
.report-card-header h3{font-size:15px;}
.report-card-header p{font-size:12.5px;}
.report-table{font-size:13px;}
.report-table thead th{font-size:11.5px;letter-spacing:.6px;padding:13px 16px;background:#123c16;}
.report-table tbody td{padding:12px 16px;}
.report-table tbody tr:nth-child(even){background:#f8fbf8;}
.report-table tbody tr:hover{background:#e8f5e9;}
.id-badge{font-size:11.5px;padding:3px 8px;}
.kind-badge{font-size:11.5px;padding:3px 10px;}

/* footer */
.gov-footer{display:block;margin-top:auto;padding-top:30px;}
.gov-footer .inner{border-top:1px solid var(--gv-line);padding-top:14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:12.5px;color:var(--gv-soft);}

/* responsive */
@media(max-width:900px){
.sidebar{width:215px;}
.main-content,.main{margin-left:215px;padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media(max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header,.topbar{padding:0 14px;}
.brand .titles h1{font-size:15px;letter-spacing:.8px;}
.brand .titles small{display:block;font-size:10px;}
.seal{width:38px;height:38px;}
.brand .titles{padding-left:10px;}
header a.logout,.topbar a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{position:static;width:100%;height:auto;margin-top:var(--top-offset);padding:8px;display:flex;flex-wrap:wrap;gap:3px;}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
.sidebar a:hover{transform:none;}
.main-content,.main{margin-left:0;padding:18px 15px;min-height:auto;}
.page-card,.page-header{padding:18px;margin-bottom:20px;}
.page-info h2,.page-header h2{font-size:19px;}
.role-pill{margin-left:0;}
.chart-card{padding:16px;}
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

<header class="topbar">
    <div class="brand">
        <div class="seal">
            <img src="../img/CABATUAN.png" alt="Municipality of Cabatuan seal" onerror="this.parentNode.classList.add('fallback');this.outerHTML='<i class=&quot;fas fa-paw&quot;></i>'">
        </div>
        <div class="seal">
            <img src="../img/DA.png" alt="Department of Agriculture seal" onerror="this.parentNode.style.display='none'">
        </div>
        <div class="titles">
            <h1>PIGOBAO</h1>
            <small>Technician Reports &bull; Livestock Distribution Program</small>
        </div>
    </div>
    <a href="../tech_logout.php" class="logout"><i class="fas fa-right-from-bracket"></i> Logout</a>
</header>

<aside class="sidebar">
    <div class="menu-title">Main Menu</div>
    <a href="../technician_dashboard.php">
        <i class="fas fa-home"></i> Dashboard
    </a>

    <a href="assign_beneficiaries.php">
        <i class="fas fa-user-check"></i> Assign Beneficiaries
    </a>

    <a href="monitoring.php">
        <i class="fas fa-stethoscope"></i> Monitoring
    </a>

    <a href="monitoring_history.php">
        <i class="fas fa-history"></i> Monitoring History
    </a>

    <a href="farmer_success_rate.php" >
        <i class="fas fa-chart-pie"></i>
        Farmer Success Rate
    </a>

    <a href="mortality_cases.php">
        <i class="fas fa-skull-crossbones"></i> Mortality Cases
    </a>

    <a href="livestock_return.php">
        <i class="fas fa-undo"></i> Livestock Return
    </a>

    <a href="tech_reports.php" class="active">
        <i class="fas fa-file-alt"></i> Reports
    </a>

    <a href="generate_report.php">
        <i class="fas fa-file-pdf"></i> Generate Report
    </a>
    
</aside>

<main class="main">

    <div class="page-header">
        <div class="page-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="page-info">
            <div class="page-date"><i class="fas fa-calendar-day"></i> <?= date('l, F j, Y') ?></div>
            <h2>Technician Reports & Analytics</h2>
            <p>Review livestock distribution performance, barangay success rates, and complete beneficiary records.</p>
        </div>
        <span class="role-pill"><i class="fas fa-user-gear"></i> Livestock Technician</span>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-cow"></i>
            </div>
            <div>
                <strong id="totalLivestockStat"><?= number_format($totalLivestock) ?></strong>
                <span>Total Livestock</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <strong id="totalBeneficiariesStat"><?= number_format($totalBeneficiaries) ?></strong>
                <span>Beneficiaries Served</span>
            </div>
        </div>
    </div>

    

    <div class="global-year-bar">
        <div class="year-filter">
            <label for="globalYearFilter"><i class="fas fa-calendar"></i> Year</label>
            <select id="globalYearFilter">
                <option value="__ALL__">All Years</option>
                <?php foreach ($availableYears as $yr): ?>
                    <option value="<?= htmlspecialchars($yr) ?>"><?= htmlspecialchars($yr) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <small>Changing the year updates every chart below</small>
    </div>

    <div class="chart-grid">

        <div class="card">
            <div class="card-head">
                <div>
                    <h3><i class="fas fa-chart-pie"></i> Total Livestock</h3>
                    <small>Number of livestock distributed by type</small>
                </div>
            </div>

            <div class="chart-box">
                <canvas id="pieChart"></canvas>
            </div>

            <div class="highest multi-stat">
                <div class="stat-pair">
                    <div class="stat-pair-top">
                        <span>Total livestock distributed</span>
                        <strong id="pieTotalValue"><?= number_format($totalLivestock) ?></strong>
                    </div>
                    <span class="stat-desc">Sum of goats, pigs, and carabaos distributed for the selected year.</span>
                </div>
                <div class="stat-pair">
                    <div class="stat-pair-top">
                        <span>
                            <i class="fas fa-trophy"></i>
                            Most distributed type
                        </span>
                        <strong id="pieHighestType"><?= htmlspecialchars($mostDistributedLabel) ?><?= $mostDistributedCount > 0 ? ' (' . number_format($mostDistributedCount) . ')' : '' ?></strong>
                    </div>
                    <span class="stat-desc">The livestock type with the highest total distributed count for the selected year.</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h3><i class="fas fa-chart-line"></i> Barangay Success Rate</h3>
                    <small>Active (green) vs. died (red), by month</small>
                </div>

                <div class="filter-group">
                    <div class="year-filter">
                        <label for="barangayFilter"><i class="fas fa-map-marker-alt"></i> Barangay</label>
                        <select id="barangayFilter">
                            <option value="__ALL__">All Barangays</option>
                            <?php foreach ($assignedBarangays as $brgy): ?>
                                <option value="<?= htmlspecialchars($brgy) ?>"><?= htmlspecialchars($brgy) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="year-filter">
                        <label for="animalFilter"><i class="fas fa-paw"></i> Animal</label>
                        <select id="animalFilter">
                            <option value="__ALL__" selected>All Kinds</option>
                            <option value="goat">Goat</option>
                            <option value="pig">Pig</option>
                            <option value="carabao">Carabao</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="chart-box">
                <canvas id="lineChart"></canvas>
            </div>

            <div class="highest multi-stat">
                <div class="stat-pair">
                    <div class="stat-pair-top">
                        <span>
                            <i class="fas fa-trophy"></i>
                            <span id="successLabel">Success rate</span>
                        </span>
                        <strong id="successValue">
                            Loading...
                        </strong>
                    </div>
                    <span class="stat-desc">Share of livestock returned (still active) vs. died, for the selected barangay, animal, and year.</span>
                </div>
            </div>
        </div>

        <div class="card chart-full-width">
            <div class="card-head">
                <div>
                    <h3><i class="fas fa-map-marked-alt"></i> Barangay Performance Comparison</h3>
                    <small>All assigned barangays, side by side — returned (green) vs. died (red)</small>
                </div>

                <div class="filter-group">
                    <div class="year-filter">
                        <label for="brgyCompareAnimalFilter"><i class="fas fa-paw"></i> Animal</label>
                        <select id="brgyCompareAnimalFilter">
                            <option value="__ALL__">All Kinds</option>
                            <option value="goat">Goat</option>
                            <option value="pig">Pig</option>
                            <option value="carabao">Carabao</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="chart-box">
                <canvas id="barangayCompareChart"></canvas>
            </div>

            <div class="highest multi-stat">
                <div class="stat-pair">
                    <div class="stat-pair-top">
                        <span>
                            <i class="fas fa-chart-bar"></i>
                            <span id="brgyVolumeLabel">Highest volume barangay</span>
                        </span>
                        <strong id="brgyVolumeValue">
                            Loading...
                        </strong>
                    </div>
                    <span class="stat-desc">Barangay with the most total cases (returned + died) recorded, regardless of outcome — shows where the most data was presented.</span>
                </div>
                <div class="stat-pair">
                    <div class="stat-pair-top">
                        <span>
                            <i class="fas fa-trophy"></i>
                            <span id="brgyCompareLabel">Best performing barangay</span>
                        </span>
                        <strong id="brgyCompareValue">
                            Loading...
                        </strong>
                    </div>
                    <span class="stat-desc">Barangay with the highest success rate: returned livestock ÷ total cases.</span>
                </div>
                <div class="stat-pair">
                    <div class="stat-pair-top">
                        <span>
                            <i class="fas fa-percentage"></i>
                            Overall success rate
                        </span>
                        <strong id="brgyOverallRate">
                            Loading...
                        </strong>
                    </div>
                    <span class="stat-desc">Total returned across all barangays ÷ total cases across all barangays.</span>
                </div>
                <div class="stat-pair">
                    <div class="stat-pair-top">
                        <span>
                            <i class="fas fa-chart-line"></i>
                            Average per barangay
                        </span>
                        <strong id="brgyAverageRate">
                            Loading...
                        </strong>
                    </div>
                    <span class="stat-desc">Average of each barangay's own success rate, treating every barangay equally.</span>
                </div>
            </div>
        </div>

    </div>


    <div class="gov-footer">
        <div class="inner">
            <span>PIGOBAO &bull; Municipal Agriculture Office &bull; Cabatuan, Iloilo</span>
            <span>&copy; <?=date('Y')?> Municipality of Cabatuan. All rights reserved.</span>
        </div>
    </div>

</main>

<script>
/* =========================================================
   GLOBAL YEAR FILTER (declared first — every chart below reads
   its current value, either directly or via currentSelectionAndRender)
========================================================= */
const globalYearFilter = document.getElementById('globalYearFilter');

/* =========================================================
   PIE CHART
   pieDataByYear holds one totals object per year plus an
   "__ALL__" key for all-time totals. renderPieChart() swaps
   the chart's data (and the total shown below it) when the
   Year dropdown changes — no page reload needed.
========================================================= */
let pieDataByYear = <?= json_encode($pieDataByYear) ?>;
const pieCanvas = document.getElementById('pieChart');
const pieTotalValueEl = document.getElementById('pieTotalValue');
const pieHighestTypeEl = document.getElementById('pieHighestType');
const pieTypeLabels = { goat: 'Goat', pig: 'Pig', carabao: 'Carabao' };
let pieChartInstance = null;

const pieCenterTextPlugin = {
    id: 'pieCenterText',
    afterDraw(chart) {
        const { ctx, chartArea: { left, right, top, bottom } } = chart;
        const centerX = (left + right) / 2;
        const centerY = (top + bottom) / 2;
        const total = chart.data.datasets[0].data.reduce(function (a, b) { return a + b; }, 0);

        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.font = "700 24px 'Space Grotesk', sans-serif";
        ctx.fillStyle = '#233126';
        ctx.fillText(total, centerX, centerY - 9);

        ctx.font = "600 10px 'Poppins', sans-serif";
        ctx.fillStyle = '#66736a';
        ctx.fillText('TOTAL', centerX, centerY + 13);

        ctx.restore();
    }
};

/* Draws the "xx%" label on top of each slice, skipping any
   slice that's empty (0 count / 0%) so the chart doesn't get
   cluttered with "0%" text. */
const pieSliceLabelsPlugin = {
    id: 'pieSliceLabels',
    afterDraw(chart) {
        const { ctx } = chart;
        const meta = chart.getDatasetMeta(0);
        const dataArr = chart.data.datasets[0].data;
        const total = dataArr.reduce(function (a, b) { return a + b; }, 0);

        if (total <= 0) return;

        ctx.save();
        ctx.font = "700 11px 'Poppins', sans-serif";
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        meta.data.forEach(function (arc, i) {
            const value = dataArr[i];
            if (!value) return;
            const percentage = Math.round((value / total) * 100);
            if (percentage <= 0) return;
            const point = arc.getCenterPoint();
            ctx.fillText(percentage + '%', point.x, point.y);
        });

        ctx.restore();
    }
};

function renderPieChart(year) {
    const yearData = pieDataByYear[year] || { goat: 0, pig: 0, carabao: 0 };
    const chartData = [yearData.goat || 0, yearData.pig || 0, yearData.carabao || 0];
    const total = chartData.reduce(function (a, b) { return a + b; }, 0);

    if (pieTotalValueEl) {
        pieTotalValueEl.textContent = total.toLocaleString();
    }

    if (pieHighestTypeEl) {
        let bestKey = null;
        let bestCount = -1;
        ['goat', 'pig', 'carabao'].forEach(function (key) {
            const count = yearData[key] || 0;
            if (count > bestCount) {
                bestCount = count;
                bestKey = key;
            }
        });

        pieHighestTypeEl.textContent = (bestCount > 0)
            ? pieTypeLabels[bestKey] + ' (' + bestCount.toLocaleString() + ')'
            : 'No data yet';
    }

    if (pieChartInstance) {
        pieChartInstance.data.datasets[0].data = chartData;
        pieChartInstance.update();
        return;
    }

    if (!pieCanvas) return;

    pieChartInstance = new Chart(pieCanvas, {
        type: 'doughnut',
        data: {
            labels: ['Goat', 'Pig', 'Carabao'],
            datasets: [{
                data: chartData,
                backgroundColor: [
                    '#2e7d32',
                    '#c98a1f',
                    '#3f7cac'
                ],
                borderColor: '#ffffff',
                borderWidth: 4,
                hoverOffset: 8
            }]
        },
        plugins: [pieCenterTextPlugin, pieSliceLabelsPlugin],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 15,
                        font: {
                            family: 'Poppins',
                            size: 10
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const arr = context.dataset.data;
                            const sum = arr.reduce(function (a, b) { return a + b; }, 0);
                            const pct = sum > 0 ? Math.round((context.raw / sum) * 100) : 0;
                            return ' ' + context.label + ': ' + context.raw + ' livestock (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
}

renderPieChart('__ALL__');

/* =========================================================
   LINE CHART
   X-axis (bottom) = months, Jan..Dec.
   Two dropdowns drive it: Barangay (or "All Barangays") and
   Animal type (goat/pig/carabao). Two lines are drawn:
   green = Active (based on returned livestock)
   red   = Died (mortality)
========================================================= */
const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
let monthlyReturnsByYear = <?= json_encode($monthlyReturnsByYear) ?>;
let monthlyDeathsByYear = <?= json_encode($monthlyDeathsByYear) ?>;

function seriesFor(byYear, year, barangay, kind) {
    const yearData = byYear[year] || {};
    const brgyData = yearData[barangay] || {};
    /* "All Kinds" = goat + pig + carabao added together, month by month */
    const kindsToSum = (kind === '__ALL__') ? ['goat', 'pig', 'carabao'] : [kind];
    return monthNames.map(function (_, idx) {
        return kindsToSum.reduce(function (sum, k) {
            const kindData = brgyData[k] || {};
            return sum + (kindData[idx + 1] || 0);
        }, 0);
    });
}

const lineCanvas = document.getElementById('lineChart');
let lineChartInstance = null;

function updateSuccessRate(barangay, kind, returned, died) {
    const successLabel = document.getElementById('successLabel');
    const successValue = document.getElementById('successValue');

    if (!successLabel || !successValue) return;

    const barangayText = barangay === '__ALL__' ? 'All Barangays' : barangay;
    const kindText = kind === '__ALL__' ? 'All Kinds' : kind.charAt(0).toUpperCase() + kind.slice(1);

    successLabel.textContent = 'Success rate — ' + barangayText + ' (' + kindText + ')';

    const totalReturned = returned.reduce(function (a, b) { return a + b; }, 0);
    const totalDied = died.reduce(function (a, b) { return a + b; }, 0);
    const totalCases = totalReturned + totalDied;

    successValue.textContent = totalCases === 0
        ? 'No data'
        : Math.round((totalReturned / totalCases) * 100) + '% (' + totalReturned + ' active / ' + totalDied + ' died)';
}

function renderLineChart(year, barangay, kind) {
    const returned = seriesFor(monthlyReturnsByYear, year, barangay, kind);
    const died = seriesFor(monthlyDeathsByYear, year, barangay, kind);

    if (lineChartInstance) {
        lineChartInstance.data.datasets[0].data = returned;
        lineChartInstance.data.datasets[1].data = died;
        lineChartInstance.update();
    } else if (lineCanvas) {
        lineChartInstance = new Chart(lineCanvas, {
            type: 'line',
            data: {
                labels: monthNames,
                datasets: [
                    {
                        label: 'Active',
                        data: returned,
                        borderColor: '#2e7d32',
                        backgroundColor: '#2e7d3214',
                        borderWidth: 2.5,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'Died',
                        data: died,
                        borderColor: '#c1543f',
                        backgroundColor: '#c1543f14',
                        borderWidth: 2.5,
                        tension: 0.35,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 12,
                            font: {
                                family: 'Poppins',
                                size: 9
                            }
                        }
                    },
                    tooltip: {
                        padding: 10,
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.dataset.label + ': ' + context.raw;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'Poppins',
                                size: 9
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            stepSize: 1,
                            font: {
                                family: 'Poppins',
                                size: 9
                            }
                        },
                        grid: {
                            color: '#e1e9e2'
                        }
                    }
                }
            }
        });
    }

    updateSuccessRate(barangay, kind, returned, died);
}

const barangayFilter = document.getElementById('barangayFilter');
const animalFilter = document.getElementById('animalFilter');

function currentSelectionAndRender() {
    const year = globalYearFilter ? globalYearFilter.value : '__ALL__';
    const barangay = barangayFilter ? barangayFilter.value : '__ALL__';
    const kind = animalFilter ? animalFilter.value : '__ALL__';
    renderLineChart(year, barangay, kind);
}

if (barangayFilter) {
    barangayFilter.addEventListener('change', currentSelectionAndRender);
}

if (animalFilter) {
    animalFilter.addEventListener('change', currentSelectionAndRender);
}

renderLineChart('__ALL__', '__ALL__', '__ALL__');

/* =========================================================
   BARANGAY PERFORMANCE COMPARISON CHART
   X-axis = every barangay assigned to this technician.
   Two lines: green = returned (success), red = died.
   Totals are summed across all months for the selected
   animal kind (or all kinds combined).
========================================================= */
let barangayList = <?= json_encode($assignedBarangays) ?>;
const livestockKindsList = ['goat', 'pig', 'carabao'];

function totalsForKind(byYear, year, brgy, kind) {
    const kindsToSum = (kind === '__ALL__') ? livestockKindsList : [kind];
    const yearData = byYear[year] || {};
    let sum = 0;

    kindsToSum.forEach(function (k) {
        const kindData = (yearData[brgy] || {})[k] || {};
        Object.keys(kindData).forEach(function (mn) {
            sum += kindData[mn] || 0;
        });
    });

    return sum;
}

const barangayCompareCanvas = document.getElementById('barangayCompareChart');
let barangayCompareChartInstance = null;

function updateBarangayCompareHighest(labels, returned, died) {
    const volumeLabel = document.getElementById('brgyVolumeLabel');
    const volumeValue = document.getElementById('brgyVolumeValue');
    const label = document.getElementById('brgyCompareLabel');
    const value = document.getElementById('brgyCompareValue');
    const overallRateEl = document.getElementById('brgyOverallRate');
    const averageRateEl = document.getElementById('brgyAverageRate');

    // --- Barangay with the highest volume (most livestock/cases presented,
    //     regardless of outcome) — descriptive, not performance-based ---
    let topVolumeIdx = -1;
    let topVolume = -1;

    labels.forEach(function (brgy, idx) {
        const totalCases = returned[idx] + died[idx];
        if (totalCases > topVolume) {
            topVolume = totalCases;
            topVolumeIdx = idx;
        }
    });

    if (volumeLabel && volumeValue) {
        if (topVolumeIdx === -1 || topVolume <= 0) {
            volumeLabel.textContent = 'Highest volume barangay';
            volumeValue.textContent = 'No data yet';
        } else {
            volumeLabel.textContent = 'Highest volume barangay — ' + labels[topVolumeIdx];
            volumeValue.textContent = topVolume.toLocaleString() + ' total cases (' + returned[topVolumeIdx] + ' returned / ' + died[topVolumeIdx] + ' died)';
        }
    }

    // --- Best performing barangay ---
    let bestIdx = -1;
    let bestRate = -1;
    let bestCases = 0;

    labels.forEach(function (brgy, idx) {
        const totalCases = returned[idx] + died[idx];
        if (totalCases === 0) return;
        const rate = returned[idx] / totalCases;
        if (rate > bestRate || (rate === bestRate && totalCases > bestCases)) {
            bestRate = rate;
            bestIdx = idx;
            bestCases = totalCases;
        }
    });

    if (label && value) {
        if (bestIdx === -1) {
            label.textContent = 'Best performing barangay';
            value.textContent = 'No data yet';
        } else {
            label.textContent = 'Best performing barangay — ' + labels[bestIdx];
            value.textContent = Math.round(bestRate * 100) + '% (' + returned[bestIdx] + ' returned / ' + died[bestIdx] + ' died)';
        }
    }

    // --- Overall success rate (all barangays combined) ---
    const totalReturned = returned.reduce(function (a, b) { return a + b; }, 0);
    const totalDied = died.reduce(function (a, b) { return a + b; }, 0);
    const totalCasesAll = totalReturned + totalDied;

    if (overallRateEl) {
        overallRateEl.textContent = totalCasesAll === 0
            ? 'No data'
            : Math.round((totalReturned / totalCasesAll) * 100) + '% (' + totalReturned + ' / ' + totalCasesAll + ')';
    }

    // --- Average success rate per barangay (mean of each barangay's own rate) ---
    let rateSum = 0;
    let rateCount = 0;

    labels.forEach(function (brgy, idx) {
        const totalCases = returned[idx] + died[idx];
        if (totalCases === 0) return;
        rateSum += (returned[idx] / totalCases);
        rateCount++;
    });

    if (averageRateEl) {
        averageRateEl.textContent = rateCount === 0
            ? 'No data'
            : Math.round((rateSum / rateCount) * 100) + '% avg (' + rateCount + ' barangay' + (rateCount === 1 ? '' : 's') + ')';
    }
}

function renderBarangayCompareChart(year, kind) {
    const returnedData = barangayList.map(function (b) { return totalsForKind(monthlyReturnsByYear, year, b, kind); });
    const diedData = barangayList.map(function (b) { return totalsForKind(monthlyDeathsByYear, year, b, kind); });

    if (barangayCompareChartInstance) {
        barangayCompareChartInstance.data.labels = barangayList;
        barangayCompareChartInstance.data.datasets[0].data = returnedData;
        barangayCompareChartInstance.data.datasets[1].data = diedData;
        barangayCompareChartInstance.update();
    } else if (barangayCompareCanvas) {
        barangayCompareChartInstance = new Chart(barangayCompareCanvas, {
            type: 'line',
            data: {
                labels: barangayList,
                datasets: [
                    {
                        label: 'Returned',
                        data: returnedData,
                        borderColor: '#2e7d32',
                        backgroundColor: '#2e7d3214',
                        borderWidth: 2.5,
                        tension: 0.3,
                        fill: false,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Died',
                        data: diedData,
                        borderColor: '#c1543f',
                        backgroundColor: '#c1543f14',
                        borderWidth: 2.5,
                        tension: 0.3,
                        fill: false,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 12,
                            font: {
                                family: 'Poppins',
                                size: 9
                            }
                        }
                    },
                    tooltip: {
                        padding: 10,
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.dataset.label + ': ' + context.raw;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                family: 'Poppins',
                                size: 9
                            },
                            maxRotation: 40,
                            minRotation: 0
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            stepSize: 1,
                            font: {
                                family: 'Poppins',
                                size: 9
                            }
                        },
                        grid: {
                            color: '#e1e9e2'
                        }
                    }
                }
            }
        });
    }

    updateBarangayCompareHighest(barangayList, returnedData, diedData);
}

const brgyCompareAnimalFilter = document.getElementById('brgyCompareAnimalFilter');
if (brgyCompareAnimalFilter) {
    brgyCompareAnimalFilter.addEventListener('change', function () {
        const year = globalYearFilter ? globalYearFilter.value : '__ALL__';
        renderBarangayCompareChart(year, brgyCompareAnimalFilter.value);
    });
}

renderBarangayCompareChart('__ALL__', '__ALL__');

/* =========================================================
   GLOBAL YEAR FILTER
   One dropdown, shared by all three charts above. Changing it
   re-renders the pie chart, the monthly line chart (keeping
   whatever barangay/animal are currently selected), and the
   barangay comparison chart (keeping whatever animal kind is
   currently selected) — all for the newly picked year.
========================================================= */
if (globalYearFilter) {
    globalYearFilter.addEventListener('change', function () {
        const year = globalYearFilter.value;

        renderPieChart(year);
        currentSelectionAndRender();

        const compareKind = brgyCompareAnimalFilter ? brgyCompareAnimalFilter.value : '__ALL__';
        renderBarangayCompareChart(year, compareKind);
    });
}

/* =========================================================
   AUTO-REFRESH
   Polls tech_reports_data.php every few seconds and swaps in
   fresh data for all three charts (plus the two summary stat
   cards) — so a death or return recorded elsewhere (e.g. from
   Monitoring) shows up here without a page reload. Whatever
   year / barangay / animal filters are currently selected are
   kept as-is; only the underlying data changes.
========================================================= */
const totalLivestockStatEl = document.getElementById('totalLivestockStat');
const totalBeneficiariesStatEl = document.getElementById('totalBeneficiariesStat');

const REPORT_REFRESH_INTERVAL_MS = 20000; // 20 seconds
let reportRefreshTimer = null;

/* Rebuilds a <select>'s options from a fresh list of values, keeping
   the technician's current selection if it's still valid (falls back
   to "All ..." if the selected year/barangay disappeared). */
function rebuildSelectOptions(selectEl, values, allLabel) {
    if (!selectEl) return;

    const current = selectEl.value;

    selectEl.innerHTML = '';

    const allOption = document.createElement('option');
    allOption.value = '__ALL__';
    allOption.textContent = allLabel;
    selectEl.appendChild(allOption);

    values.forEach(function (val) {
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = val;
        selectEl.appendChild(opt);
    });

    selectEl.value = (current === '__ALL__' || values.indexOf(current) !== -1) ? current : '__ALL__';
}

function refreshReportData() {
    fetch('tech_reports_data.php', { credentials: 'same-origin' })
        .then(function (res) {
            if (!res.ok) throw new Error('Refresh request failed with status ' + res.status);
            return res.json();
        })
        .then(function (data) {
            if (data.error) throw new Error(data.error);

            pieDataByYear = data.pieDataByYear || pieDataByYear;
            monthlyReturnsByYear = data.monthlyReturnsByYear || monthlyReturnsByYear;
            monthlyDeathsByYear = data.monthlyDeathsByYear || monthlyDeathsByYear;
            barangayList = data.assignedBarangays || barangayList;

            rebuildSelectOptions(globalYearFilter, data.availableYears || [], 'All Years');
            rebuildSelectOptions(barangayFilter, data.assignedBarangays || [], 'All Barangays');

            if (totalLivestockStatEl && typeof data.totalLivestock === 'number') {
                totalLivestockStatEl.textContent = data.totalLivestock.toLocaleString();
            }
            if (totalBeneficiariesStatEl && typeof data.totalBeneficiaries === 'number') {
                totalBeneficiariesStatEl.textContent = data.totalBeneficiaries.toLocaleString();
            }

            const year = globalYearFilter ? globalYearFilter.value : '__ALL__';

            renderPieChart(year);
            currentSelectionAndRender();

            const compareKind = brgyCompareAnimalFilter ? brgyCompareAnimalFilter.value : '__ALL__';
            renderBarangayCompareChart(year, compareKind);
        })
        .catch(function (err) {
            // Keep showing the last good data instead of breaking the charts.
            console.error('Auto-refresh failed:', err);
        });
}

function startReportAutoRefresh() {
    if (reportRefreshTimer) return;
    reportRefreshTimer = setInterval(refreshReportData, REPORT_REFRESH_INTERVAL_MS);
}

function stopReportAutoRefresh() {
    if (!reportRefreshTimer) return;
    clearInterval(reportRefreshTimer);
    reportRefreshTimer = null;
}

// Pause polling while the tab is in the background, refresh immediately
// (and resume polling) once the technician comes back to it.
document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
        stopReportAutoRefresh();
    } else {
        refreshReportData();
        startReportAutoRefresh();
    }
});

startReportAutoRefresh();
</script>

</body>
</html>