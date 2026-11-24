<?php
session_start();
require '../db.php';

if(!isset($_SESSION['admin_id'])){
    header("Location: ../adminindex.php");
    exit();
}

/* =====================================================
   PROCESS ENDPOINT: dispersal.php?process=LIVESTOCK_ID  (JSON)
   Nagabalik sang tanan nga step/distribution sang isa ka sapat
   ===================================================== */
if(isset($_GET['process'])){
    header('Content-Type: application/json; charset=utf-8');
    $lid=(int)$_GET['process'];
    $steps=[];
    try{
        $st=$conn->prepare("
            SELECT d.distribution_id, d.beneficiary_id, d.status, d.batch_no,
                   d.distribution_date, d.return_date, d.returned_to_beneficiary_id,
                   CONCAT_WS(' ', b.firstname, b.lastname, b.extension_name) AS b_name,
                   br.barangay_name AS b_brgy,
                   CONCAT(UPPER(SUBSTRING(l.kind,1,1)),SUBSTRING(l.kind,2),
                          IF(l.breed<>'',CONCAT(' - ',l.breed),'')) AS l_type
            FROM distributions d
            JOIN beneficiaries b ON b.beneficiary_id=d.beneficiary_id
            LEFT JOIN barangays br ON br.barangay_id=b.barangay_id
            LEFT JOIN livestock l ON l.livestock_id=d.livestock_id
            WHERE d.livestock_id=?
            ORDER BY d.distribution_id ASC");
        $st->bind_param('i',$lid);
        $st->execute();
        $res=$st->get_result();
        $rowsP=[];
        while($r=$res->fetch_assoc()){ $rowsP[]=$r; }

        foreach($rowsP as $i=>$r){
            if($i===0){
                $src='da';
            }else{
                $p=$rowsP[$i-1];
                $src=($p['returned_to_beneficiary_id']==$r['beneficiary_id'])?'transfer':'return';
            }
            $steps[]=[
                'name'   =>$r['b_name'],
                'brgy'   =>$r['b_brgy']??'-',
                'type'   =>$r['l_type']??'-',
                'batch'  =>$r['batch_no']??'-',
                'src'    =>$src,
                'status' =>$r['status'],
                'date'   =>$r['distribution_date']?date('M d, Y',strtotime($r['distribution_date'])):'-',
                'rdate'  =>$r['return_date']?date('M d, Y',strtotime($r['return_date'])):null,
            ];
        }
        echo json_encode(['ok'=>true,'steps'=>$steps]);
    }catch(Throwable $e){
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit();
}

/* =====================================================
   SOURCE DETECTION
   - Return   : ang sapat ginbalik anay (status=returned), dayon gin-distribute liwat sa lain nga beneficiary
   - Transfer : pareho sa ibabaw, pero ang nakabaton amo ang returned_to_beneficiary_id sang una nga record
   - DA       : tanan nga indi sa ibabaw = sapat nga halin sa inventory nga gin-add sang admin
   ===================================================== */

/* base query: isa ka row kada distribution, may computed nga "src" */
$base="
    SELECT d.distribution_id, d.livestock_id, d.quantity, d.distribution_date, d.batch_no, d.status,
           d.distribution_location,
           CONCAT_WS(' ', b.firstname, b.lastname, b.extension_name) AS b_name,
           br.barangay_name AS b_brgy,
           CONCAT(UPPER(SUBSTRING(l.kind,1,1)),SUBSTRING(l.kind,2),
                  IF(l.breed<>'',CONCAT(' - ',l.breed),'')) AS l_type,
           CONCAT_WS(' ', pb.firstname, pb.lastname) AS from_name,
           pv.return_date AS from_date,
           CASE
             WHEN pv.distribution_id IS NOT NULL THEN
                  IF(pv.returned_to_beneficiary_id=d.beneficiary_id,'transfer','return')
             ELSE 'da'
           END AS src
    FROM distributions d
    JOIN beneficiaries b ON b.beneficiary_id=d.beneficiary_id
    LEFT JOIN barangays br ON br.barangay_id=b.barangay_id
    LEFT JOIN livestock l ON l.livestock_id=d.livestock_id
    LEFT JOIN distributions pv ON pv.distribution_id=(
        SELECT p.distribution_id FROM distributions p
        WHERE p.livestock_id=d.livestock_id
          AND p.distribution_id<d.distribution_id
          AND p.status='returned'
          AND p.beneficiary_id<>d.beneficiary_id
        ORDER BY p.distribution_id DESC LIMIT 1)
    LEFT JOIN beneficiaries pb ON pb.beneficiary_id=pv.beneficiary_id
";

/* =========================
   FILTERS
   ========================= */
$allowedSources=['all','return','transfer','da'];
$source=$_GET['source']??'all';
if(!in_array($source,$allowedSources,true)){ $source='all'; }

/* Status sang sapat (distributions.status): active / death */
$allowedStates=['all','active','death'];
$dstatus=$_GET['dstatus']??'all';
if(!in_array($dstatus,$allowedStates,true)){ $dstatus='all'; }

$search=trim($_GET['q']??'');
$yearParam=$_GET['year']??'all';
$year=preg_match('/^\d{4}$/',$yearParam)?(int)$yearParam:'all';

$years=[];
try{
    $yr=$conn->query("SELECT DISTINCT YEAR(distribution_date) yr FROM distributions WHERE distribution_date IS NOT NULL ORDER BY yr DESC");
    if($yr){ while($r=$yr->fetch_assoc()){ $years[]=(int)$r['yr']; } }
}catch(Throwable $e){}

$where=" WHERE 1=1";
$types='';
$params=[];
if($year!=='all'){ $where.=" AND YEAR(t.distribution_date)=?"; $types.='i'; $params[]=$year; }
if($dstatus!=='all'){ $where.=" AND t.status=?"; $types.='s'; $params[]=$dstatus; }
if($search!==''){
    $where.=" AND (t.b_name LIKE ? OR t.b_brgy LIKE ? OR t.l_type LIKE ? OR t.batch_no LIKE ?)";
    $like='%'.$search.'%';
    $types.='ssss'; array_push($params,$like,$like,$like,$like);
}

function runQuery($conn,$sql,$types,$params){
    try{
        $st=$conn->prepare($sql);
        if($types!==''){ $st->bind_param($types,...$params); }
        $st->execute();
        return [$st->get_result(),null];
    }catch(Throwable $e){
        return [false,$e->getMessage()];
    }
}

/* COUNTS per source */
$counts=['all'=>0,'return'=>0,'transfer'=>0,'da'=>0];
[$cres,$cerr]=runQuery($conn,"SELECT t.src, COUNT(*) c FROM ($base) t $where GROUP BY t.src",$types,$params);
if($cres){
    while($r=$cres->fetch_assoc()){
        if(isset($counts[$r['src']])){ $counts[$r['src']]=(int)$r['c']; }
        $counts['all']+=(int)$r['c'];
    }
}

/* LIST */
$listWhere=$where; $listTypes=$types; $listParams=$params;
if($source!=='all'){ $listWhere.=" AND t.src=?"; $listTypes.='s'; $listParams[]=$source; }

[$rows,$qerr]=runQuery($conn,
    "SELECT t.* FROM ($base) t $listWhere ORDER BY t.distribution_date DESC, t.distribution_id DESC",
    $listTypes,$listParams);
if(!$qerr && $cerr){ $qerr=$cerr; }

/* STATS para sa Process of Dispersal flow (sumatotal sang tanan nga distribution) */
$stat=['active'=>0,'death'=>0,'returned'=>0];
$yw=''; $yt=''; $yp=[];
if($year!=='all'){ $yw=" WHERE YEAR(distribution_date)=?"; $yt='i'; $yp[]=$year; }
[$sres,$serr]=runQuery($conn,"SELECT status, COUNT(*) c FROM distributions $yw GROUP BY status",$yt,$yp);
if($sres){
    while($r=$sres->fetch_assoc()){
        if(isset($stat[$r['status']])){ $stat[$r['status']]=(int)$r['c']; }
    }
}
$statTotal=array_sum($stat);

$labels=[
    'return'  =>['Return','fa-rotate-left','return'],
    'transfer'=>['Transfer','fa-right-left','transfer'],
    'da'      =>['DA (Inventory)','fa-warehouse','da'],
];
function tabUrl($s,$year,$search,$dstatus='all'){
    return '?'.http_build_query(['source'=>$s,'year'=>$year,'q'=>$search,'dstatus'=>$dstatus]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dispersal - PIGOBAO</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<!-- KEEP SCROLL POSITION: i-restore dayon antes mag-paint para indi mag-lukso sa babaw -->
<script>
(function(){
  if('scrollRestoration' in history){ history.scrollRestoration='manual'; }
  window.SCROLL_KEY='dispersal_scroll';
  window.saveScroll=function(){
    try{ sessionStorage.setItem(window.SCROLL_KEY,String(window.scrollY||document.documentElement.scrollTop||0)); }catch(e){}
  };
})();
</script>

<style>
:root{
--pg-forest-900:#123c16;
--pg-forest-800:#1b5e20;
--pg-forest-600:#2e7d32;
--pg-forest-300:#a5d6a7;
--pg-forest-100:#e8f5e9;
--pg-canvas:#f3f6f4;
--pg-ink:#1f2b22;
--pg-ink-soft:#4f5d53;
--pg-line:#dce5de;
--gov-navy:#0b2f5b;
--gov-gold:#c9a227;
--gov-red:#b3262e;
--shadow-soft:0 1px 2px rgba(18,60,22,.05),0 10px 26px -14px rgba(18,60,22,.22);
--strip-h:32px;
--header-h:70px;
--top-offset:calc(var(--strip-h) + var(--header-h));
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
color:var(--pg-ink);
font-size:15px;
line-height:1.5;
-webkit-font-smoothing:antialiased;
}

body::before{
content:"";
position:fixed;
inset:0;
z-index:-2;
background-image:url('../img/rice field.jpg');
background-size:cover;
background-position:center 40%;
background-repeat:no-repeat;
background-attachment:fixed;
}
body::after{
content:"";
position:fixed;
inset:0;
z-index:-1;
background:linear-gradient(180deg,rgba(243,246,244,.95),rgba(236,242,238,.90));
}

/* =========================
   OFFICIAL GOVERNMENT STRIP
   ========================= */
.gov-strip{
position:fixed;
top:0;left:0;right:0;
height:var(--strip-h);
background:#0a1f3d;
color:#dbe5f3;
display:flex;
align-items:center;
justify-content:space-between;
padding:0 30px;
font-size:12px;
letter-spacing:.3px;
z-index:1003;
border-bottom:3px solid transparent;
border-image:linear-gradient(90deg,var(--gov-navy) 0 33.3%,var(--gov-gold) 33.3% 66.6%,var(--gov-red) 66.6% 100%) 1;
}
.gov-strip .left{display:flex;align-items:center;gap:8px;}
.gov-strip .left i{color:var(--gov-gold);}
.gov-strip .right{opacity:.85;}
.gov-strip strong{color:#fff;font-weight:600;}

/* =========================
   HEADER
   ========================= */
header{
position:fixed;
top:var(--strip-h);
left:0;
width:100%;
height:var(--header-h);
background:linear-gradient(120deg,var(--pg-forest-900),var(--pg-forest-800) 60%,var(--pg-forest-600));
color:#fff;
display:flex;
justify-content:space-between;
align-items:center;
padding:0 30px;
z-index:1002;
box-shadow:0 4px 16px rgba(10,40,12,.28);
}

header .brand{
display:flex;
align-items:center;
gap:14px;
}

.seal{
width:46px;height:46px;
border-radius:50%;
background:#fff;
display:flex;align-items:center;justify-content:center;
overflow:hidden;
flex:none;
box-shadow:0 0 0 2px rgba(255,255,255,.35);
}
.seal img{width:100%;height:100%;object-fit:contain;padding:3px;}
.seal.fallback{font-size:18px;color:var(--pg-forest-800);}

header .brand .titles{
padding-left:14px;
border-left:1px solid rgba(255,255,255,.28);
}
header h1{
font-family:'Merriweather',serif;
font-size:19px;
font-weight:700;
letter-spacing:1.2px;
line-height:1.15;
}
header .brand small{
display:block;
font-size:12px;
font-weight:400;
color:rgba(255,255,255,.78);
letter-spacing:.5px;
text-transform:uppercase;
margin-top:2px;
}

header a.logout{
color:var(--pg-forest-900);
background:#fff;
padding:9px 22px;
border-radius:8px;
font-weight:600;
text-decoration:none;
font-size:14px;
display:inline-flex;align-items:center;gap:8px;
box-shadow:0 2px 8px rgba(0,0,0,.14);
transition:.2s ease;
}
header a.logout:hover{
background:var(--pg-forest-100);
transform:translateY(-1px);
}

/* =========================
   SIDEBAR
   ========================= */
.sidebar{
position:fixed;
top:var(--top-offset);
left:0;
width:250px;
height:calc(100% - var(--top-offset));
background:linear-gradient(195deg,#164f1b,var(--pg-forest-800) 45%,var(--pg-forest-600) 130%);
padding-top:14px;
box-shadow:inset -1px 0 0 rgba(255,255,255,.06);
overflow-y:auto;
z-index:1000;
}

.sidebar .menu-title{
font-size:11.5px;
font-weight:600;
letter-spacing:1.4px;
text-transform:uppercase;
color:rgba(255,255,255,.55);
padding:6px 26px 8px;
}

.sidebar a{
display:flex;
align-items:center;
gap:13px;
color:rgba(255,255,255,.92);
padding:12px 20px;
text-decoration:none;
margin:2px 12px 3px;
border-radius:8px;
border-left:4px solid transparent;
font-size:15px;
font-weight:500;
transition:.2s ease;
position:relative;
}
.sidebar a i{
width:20px;
text-align:center;
font-size:15.5px;
opacity:.92;
}
.sidebar a:hover{
background:rgba(255,255,255,.12);
transform:translateX(2px);
}
.sidebar a.active{
background:#fff;
color:var(--pg-forest-900);
border-left:4px solid var(--gov-gold);
font-weight:600;
box-shadow:0 6px 16px rgba(0,0,0,.2);
}

/* =========================
   MAIN CONTENT
   ========================= */
.main-content{
margin-left:250px;
padding:calc(var(--top-offset) + 26px) 34px 20px;
flex:1;
width:calc(100% - 250px);
min-width:0;
display:flex;
flex-direction:column;
min-height:100vh;
}

/* =========================
   SECTION HEADINGS
   ========================= */
.section-label{
font-family:'Space Grotesk',sans-serif;
font-size:14px;
font-weight:700;
letter-spacing:.9px;
text-transform:uppercase;
color:var(--pg-forest-900);
display:flex;
align-items:center;
gap:10px;
}
.section-label::before{
content:"";
width:5px;height:20px;
border-radius:3px;
background:linear-gradient(180deg,var(--gov-gold),var(--pg-forest-600));
}

.overview-bar{
display:flex;
align-items:flex-end;
justify-content:space-between;
flex-wrap:wrap;
gap:10px;
margin:0 0 14px;
}
.overview-bar .period{
font-size:13px;
color:var(--pg-ink-soft);
margin-top:3px;
padding-left:15px;
}
.overview-bar .period strong{color:var(--pg-forest-900);}

.year-filter{
display:flex;
align-items:center;
gap:10px;
background:#fff;
border:1px solid var(--pg-line);
border-radius:10px;
padding:6px 8px 6px 14px;
box-shadow:var(--shadow-soft);
}
.year-filter label{
font-size:13px;
font-weight:600;
color:var(--pg-ink-soft);
display:flex;align-items:center;gap:7px;
}
.year-filter select{
font-family:'Poppins',sans-serif;
font-size:14px;
font-weight:600;
color:var(--pg-forest-900);
background:var(--pg-canvas);
border:1px solid var(--pg-line);
border-radius:7px;
padding:7px 12px;
cursor:pointer;
}
.year-filter select:focus{outline:2px solid var(--pg-forest-300);}

/* =========================
   KPI CARDS
   ========================= */
.dashboard-cards{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:18px;
}

.card{
--accent:#1b5e20;
--accent-soft:#e8f5e9;
position:relative;
padding:22px 24px 18px;
border:1px solid var(--pg-line);
border-top:5px solid var(--accent);
border-radius:12px;
background:#fff;
box-shadow:var(--shadow-soft);
display:flex;
flex-direction:column;
gap:6px;
transition:transform .2s ease,box-shadow .2s ease;
}
.card:hover{
transform:translateY(-3px);
box-shadow:0 16px 30px -16px rgba(18,60,22,.38);
}

.card-top{
display:flex;
align-items:center;
justify-content:space-between;
gap:12px;
}
.card-label{
font-family:'Space Grotesk',sans-serif;
font-size:13.5px;
font-weight:700;
letter-spacing:.7px;
text-transform:uppercase;
color:var(--pg-ink-soft);
}
.card-icon{
width:48px;height:48px;
border-radius:12px;
display:flex;align-items:center;justify-content:center;
font-size:21px;
flex:none;
background:var(--accent-soft);
color:var(--accent);
}
.card .number{
font-family:'Space Grotesk',sans-serif;
font-size:52px;
line-height:1.05;
font-weight:700;
color:var(--accent);
margin-top:4px;
}
.card .description{
font-size:14px;
font-weight:500;
color:var(--pg-ink-soft);
}

/* =========================
   FOOTER
   ========================= */
.gov-footer{
margin-top:auto;
padding-top:30px;
}
.gov-footer .inner{
border-top:1px solid var(--pg-line);
padding-top:14px;
display:flex;
justify-content:space-between;
flex-wrap:wrap;
gap:8px;
font-size:12.5px;
color:var(--pg-ink-soft);
}

/* =========================
   RESPONSIVE
   ========================= */
@media(max-width:1000px){
.dashboard-cards{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:900px){
.sidebar{width:215px;}
.main-content{margin-left:215px;width:calc(100% - 215px);padding:calc(var(--top-offset) + 20px) 20px 18px;}
}
@media(max-width:768px){
:root{--strip-h:30px;--header-h:62px;}
.gov-strip{padding:0 14px;font-size:11px;}
.gov-strip .right{display:none;}
header{padding:0 14px;}
header h1{font-size:15px;letter-spacing:.8px;}
header .brand small{font-size:10px;}
.seal{width:38px;height:38px;}
header .brand .titles{padding-left:10px;}
header a.logout{padding:8px 14px;font-size:12.5px;}
.sidebar{
position:static;
width:100%;
height:auto;
margin-top:var(--top-offset);
padding:8px;
display:flex;
flex-wrap:wrap;
gap:3px;
}
.sidebar .menu-title{display:none;}
.sidebar a{margin:1px;padding:9px 11px;font-size:12.5px;border-left-width:3px;}
body{flex-direction:column;}
.main-content{margin-left:0;width:100%;padding:18px 15px;min-height:auto;}
.dashboard-cards{gap:12px;}
.card{padding:16px 16px 14px;}
.card .number{font-size:38px;}
.card-label{font-size:12px;}
.card-icon{width:40px;height:40px;font-size:17px;}
}
@media(max-width:520px){
.dashboard-cards{grid-template-columns:1fr;}
.overview-bar{align-items:flex-start;}
}

/* =========================
   DISPERSAL PAGE
   ========================= */
.dashboard-cards.-three{grid-template-columns:repeat(3,1fr);margin-bottom:26px;}
a.card{text-decoration:none;color:inherit;}
.card.-active{box-shadow:0 0 0 3px var(--accent-soft),var(--shadow-soft);}
.card.-return{--accent:#00707a;--accent-soft:#dff3f4;}
.card.-transfer{--accent:#b7791f;--accent-soft:#fbf1dd;}
.card.-da{--accent:#0b2f5b;--accent-soft:#e6eef8;}
.card .number{font-size:42px;}

.panel{
background:#fff;
border:1px solid var(--pg-line);
border-radius:12px;
box-shadow:var(--shadow-soft);
padding:20px 22px;
}
.tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.tab{
display:inline-flex;align-items:center;gap:8px;
padding:8px 15px;border-radius:8px;
border:1px solid var(--pg-line);background:#fff;
color:var(--pg-ink);text-decoration:none;
font-size:13.5px;font-weight:600;transition:.2s ease;
}
.tab:hover{border-color:var(--pg-forest-600);}
.tab .n{background:var(--pg-canvas);border-radius:999px;padding:0 9px;font-size:12px;}
.tab.active{background:var(--pg-forest-900);border-color:var(--pg-forest-900);color:#fff;}
.tab.active .n{background:rgba(255,255,255,.2);color:#fff;}

.tools{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;}
.tools input[type=text]{
flex:1;min-width:220px;
font-size:14px;padding:9px 14px;
border:1px solid var(--pg-line);border-radius:8px;
background:var(--pg-canvas);color:var(--pg-ink);
}
.tools input[type=text]:focus{outline:2px solid var(--pg-forest-300);}
.tools button{
font-size:14px;font-weight:600;padding:9px 20px;
border:0;border-radius:8px;cursor:pointer;
background:var(--pg-forest-800);color:#fff;transition:.2s ease;
}
.tools button:hover{background:var(--pg-forest-900);}
.tools a.reset{padding:9px 12px;color:var(--pg-ink-soft);text-decoration:none;font-size:14px;}

.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{
text-align:left;background:var(--pg-forest-100);color:var(--pg-forest-900);
padding:11px 12px;font-family:'Space Grotesk',sans-serif;
font-size:12.5px;text-transform:uppercase;letter-spacing:.6px;white-space:nowrap;
}
td{padding:11px 12px;border-bottom:1px solid var(--pg-line);vertical-align:middle;}
tr:hover td{background:#fafcfb;}
.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;}
.badge.return{background:#dff3f4;color:#00707a;}
.badge.transfer{background:#fbf1dd;color:#9a6200;}
.badge.da{background:#e6eef8;color:#0b2f5b;}
.badge.st-active{background:#e3f4e5;color:#1b5e20;}
.badge.st-death{background:#f3e3e4;color:#8f1d24;}
.badge.st-returned{background:#eceff1;color:#4f5d53;}
.state-filter{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:14px;}
.state-filter .lbl{font-size:13px;font-weight:600;color:var(--pg-ink-soft);margin-right:2px;}
.state-filter .pill{
display:inline-flex;align-items:center;gap:7px;
padding:6px 14px;border-radius:999px;
border:1px solid var(--pg-line);background:#fff;
color:var(--pg-ink);text-decoration:none;
font-size:13px;font-weight:600;transition:.2s ease;
}
.state-filter .pill:hover{border-color:var(--pg-forest-600);}
.state-filter .pill.on{background:var(--pg-forest-800);border-color:var(--pg-forest-800);color:#fff;}
.state-filter .pill.on.death{background:var(--gov-red);border-color:var(--gov-red);}
.status{font-weight:500;color:var(--pg-ink-soft);}
.from{display:block;font-size:11.5px;color:var(--pg-ink-soft);margin-top:3px;}
.empty{text-align:center;color:var(--pg-ink-soft);padding:36px 0;}
.err{background:#f9e6e6;color:#9b2c2c;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;}
@media(max-width:1000px){.dashboard-cards.-three{grid-template-columns:repeat(2,1fr);}}
@media(max-width:520px){.dashboard-cards.-three{grid-template-columns:1fr;}}

/* =========================
   PROCESS BUTTON + MODAL
   ========================= */
.status-actions{
display:flex;
align-items:center;
gap:8px;
flex-wrap:wrap;
}
.btn-proc{
display:inline-flex;align-items:center;gap:6px;
padding:5px 12px;border:1px solid var(--pg-forest-600);border-radius:8px;
background:#fff;color:var(--pg-forest-800);font-size:12.5px;font-weight:600;cursor:pointer;transition:.2s ease;
}
.btn-proc:hover{background:var(--pg-forest-800);color:#fff;}

.modal-bg{position:fixed;inset:0;background:rgba(10,31,61,.55);display:none;align-items:center;justify-content:center;z-index:2000;padding:16px;}
.modal-bg.show{display:flex;}
.modal{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:88vh;overflow-y:auto;box-shadow:0 24px 60px -20px rgba(0,0,0,.5);}
.modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 22px;border-bottom:1px solid var(--pg-line);position:sticky;top:0;background:#fff;}
.modal-head h3{font-family:'Space Grotesk',sans-serif;font-size:17px;color:var(--pg-forest-900);}
.modal-head button{border:0;background:none;font-size:20px;cursor:pointer;color:var(--pg-ink-soft);}
.modal-body{padding:22px;}

.flow{position:relative;margin-left:14px;padding-left:28px;border-left:3px solid var(--pg-line);}
.flow .step{position:relative;margin-bottom:22px;}
.flow .step:last-child{margin-bottom:0;}
.flow .dot{
position:absolute;left:-44px;top:0;width:30px;height:30px;border-radius:50%;
display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;
box-shadow:0 0 0 4px #fff;
}
.dot.da{background:#0b2f5b;}
.dot.return{background:#00707a;}
.dot.transfer{background:#b7791f;}
.flow .card-s{border:1px solid var(--pg-line);border-radius:10px;padding:12px 14px;background:var(--pg-canvas);}
.flow .card-s .top{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;}
.flow .card-s .nm{font-weight:600;color:var(--pg-forest-900);}
.flow .card-s .meta{font-size:12.5px;color:var(--pg-ink-soft);margin-top:4px;}
.flow .out{margin-top:8px;font-size:12.5px;font-weight:600;color:#4f5d53;display:flex;align-items:center;gap:6px;}
.flow .out.death{color:var(--gov-red);}
.flow .out.active{color:#1b5e20;}

/* =========================
   PROCESS OF DISPERSAL FLOWCHART
   ========================= */
.flow-panel{margin-bottom:26px;}
.flow-note{font-size:13px;color:var(--pg-ink-soft);margin:8px 0 16px;padding-left:15px;}
.pflow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pstep{
--c:#1b5e20;--cs:#e8f5e9;
flex:1;min-width:150px;max-width:210px;
border:1px solid var(--pg-line);border-top:4px solid var(--c);
border-radius:12px;padding:14px;background:#fff;
display:flex;flex-direction:column;gap:4px;
box-shadow:var(--shadow-soft);
}
.pstep .pico{
width:38px;height:38px;border-radius:10px;
display:flex;align-items:center;justify-content:center;
background:var(--cs);color:var(--c);font-size:16px;margin-bottom:4px;
}
.pstep b{font-family:'Space Grotesk',sans-serif;font-size:14px;color:var(--pg-forest-900);}
.pstep small{font-size:12px;color:var(--pg-ink-soft);line-height:1.35;}
.pstep .pcount{
margin-top:6px;font-size:12.5px;font-weight:700;color:var(--c);
background:var(--cs);border-radius:999px;padding:3px 10px;align-self:flex-start;
}
.pstep.s-da{--c:#0b2f5b;--cs:#e6eef8;}
.pstep.s-dist{--c:#4a3f8f;--cs:#ece9f8;}
.pstep.s-active{--c:#1b5e20;--cs:#e8f5e9;}
.pstep.s-death{--c:#b3262e;--cs:#f9e6e6;}
.pstep.s-ret{--c:#4f5d53;--cs:#eceff1;}
.pstep.s-re{--c:#00707a;--cs:#dff3f4;}
.pstep.s-tr{--c:#b7791f;--cs:#fbf1dd;}
.parrow{color:var(--pg-forest-600);font-size:18px;flex:none;}
.pbranch{display:flex;flex-direction:column;gap:10px;flex:1;min-width:150px;max-width:210px;}
.pbranch .pstep{max-width:none;}
@media(max-width:1200px){
.pflow{flex-direction:column;align-items:stretch;}
.pstep,.pbranch{max-width:none;}
.parrow{align-self:center;transform:rotate(90deg);}
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
<small>Dispersal &bull; Livestock Distribution Program</small>
</div>
</div>

<a href="../adminlog_out.php" class="logout">
<i class="fas fa-right-from-bracket"></i>
Logout
</a>
</header>

<div class="sidebar">
<div class="menu-title">Main Menu</div>

<a href="../admin_dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
<a href="add-technician.php"><i class="fas fa-user-cog"></i>Technicians</a>
<a href="farmer-beneficiary.php"><i class="fas fa-id-card"></i>Farmer Beneficiary</a>
<a href="add-livestock.php"><i class="fas fa-drumstick-bite"></i>Livestock</a>
<a href="tracking.php"><i class="fas fa-eye"></i>Livestock Tracking</a>
<a href="dispersal.php" class="active"><i class="fas fa-people-carry-box"></i>Dispersal</a>
<a href="farmer-success-rate.php"><i class="fas fa-chart-pie"></i>Farmer Success Rate</a>
<a href="reports.php"><i class="fas fa-chart-line"></i>Reports</a>
<a href="generate-report.php"><i class="fas fa-file-alt"></i>Generate Report</a>
</div>

<div class="main-content">

<div class="overview-bar">
<div>
<p class="section-label">Livestock Dispersal</p>
<p class="period">
Source of distributed livestock:
<strong>Return, Transfer &amp; DA (Inventory)</strong>
&bull; Period: <strong><?=$year==='all'?'All Time':(int)$year?></strong>
</p>
</div>

<form class="year-filter" method="GET">
<input type="hidden" name="source" value="<?=htmlspecialchars($source)?>">
<input type="hidden" name="dstatus" value="<?=htmlspecialchars($dstatus)?>">
<input type="hidden" name="q" value="<?=htmlspecialchars($search)?>">
<label for="year"><i class="fas fa-calendar-days"></i> Year</label>
<select name="year" id="year" onchange="saveScroll();this.form.submit()">
<option value="all" <?=$year==='all'?'selected':''?>>All Time</option>
<?php foreach($years as $y): ?>
<option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option>
<?php endforeach; ?>
</select>
</form>
</div>

<div class="panel flow-panel">
<p class="section-label">Process of Dispersal</p>
<p class="flow-note">Amo ini ang dalan sang sapat halin sa DA tubtob sa beneficiary. Ang numero amo ang kabilugan nga record sa napili nga period.</p>

<div class="pflow">
  <div class="pstep s-da">
    <div class="pico"><i class="fas fa-warehouse"></i></div>
    <b>1. DA Inventory</b>
    <small>Ginadugang sang admin ang sapat sa inventory</small>
    <span class="pcount"><?=number_format($counts['da'])?> halin sa DA</span>
  </div>

  <div class="parrow"><i class="fas fa-arrow-right"></i></div>

  <div class="pstep s-dist">
    <div class="pico"><i class="fas fa-hand-holding-heart"></i></div>
    <b>2. Distribution</b>
    <small>Ginahatag sa beneficiary (may batch kag petsa)</small>
    <span class="pcount"><?=number_format($statTotal)?> ka distribution</span>
  </div>

  <div class="parrow"><i class="fas fa-arrow-right"></i></div>

  <div class="pstep s-active">
    <div class="pico"><i class="fas fa-circle-check"></i></div>
    <b>3. Active</b>
    <small>Ang nabaton kag ginabantayan sang beneficiary</small>
    <span class="pcount"><?=number_format($stat['active'])?> active</span>
  </div>

  <div class="parrow"><i class="fas fa-arrow-right"></i></div>

  <div class="pbranch">
    <div class="pstep s-death">
      <div class="pico"><i class="fas fa-cross"></i></div>
      <b>4a. Death</b>
      <small>Napatay ang sapat, natapos ang process</small>
      <span class="pcount"><?=number_format($stat['death'])?> death</span>
    </div>
    <div class="pstep s-ret">
      <div class="pico"><i class="fas fa-rotate-left"></i></div>
      <b>4b. Returned</b>
      <small>Gin-return sang beneficiary sa DA</small>
      <span class="pcount"><?=number_format($stat['returned'])?> returned</span>
    </div>
  </div>

  <div class="parrow"><i class="fas fa-arrow-right"></i></div>

  <div class="pbranch">
    <div class="pstep s-re">
      <div class="pico"><i class="fas fa-rotate-left"></i></div>
      <b>5a. Return</b>
      <small>Gin-distribute liwat sa lain nga beneficiary</small>
      <span class="pcount"><?=number_format($counts['return'])?> re-distributed</span>
    </div>
    <div class="pstep s-tr">
      <div class="pico"><i class="fas fa-right-left"></i></div>
      <b>5b. Transfer</b>
      <small>Gin-hatag sa napili nga beneficiary</small>
      <span class="pcount"><?=number_format($counts['transfer'])?> transferred</span>
    </div>
  </div>
</div>

<p class="flow-note" style="margin:14px 0 0">
<i class="fas fa-circle-info"></i> Para makita ang dalan sang isa ka sapat, pislita ang <strong>Process</strong> button sa Status sang table sa ubos.
</p>
</div>

<div class="dashboard-cards -three">
<a class="card -return <?=$source==='return'?'-active':''?>" href="<?=htmlspecialchars(tabUrl('return',$yearParam,$search,$dstatus))?>">
<div class="card-top">
<span class="card-label">From Return</span>
<div class="card-icon"><i class="fas fa-rotate-left"></i></div>
</div>
<div class="number"><?=number_format($counts['return'])?></div>
<div class="description">Livestock returned then re-distributed</div>
</a>

<a class="card -transfer <?=$source==='transfer'?'-active':''?>" href="<?=htmlspecialchars(tabUrl('transfer',$yearParam,$search,$dstatus))?>">
<div class="card-top">
<span class="card-label">From Transfer</span>
<div class="card-icon"><i class="fas fa-right-left"></i></div>
</div>
<div class="number"><?=number_format($counts['transfer'])?></div>
<div class="description">Livestock transferred between farmers</div>
</a>

<a class="card -da <?=$source==='da'?'-active':''?>" href="<?=htmlspecialchars(tabUrl('da',$yearParam,$search,$dstatus))?>">
<div class="card-top">
<span class="card-label">From DA</span>
<div class="card-icon"><i class="fas fa-warehouse"></i></div>
</div>
<div class="number"><?=number_format($counts['da'])?></div>
<div class="description">Livestock from admin inventory</div>
</a>
</div>

<div class="panel">
<?php if($qerr): ?>
<div class="err"><i class="fas fa-triangle-exclamation"></i> Query error: <?=htmlspecialchars($qerr)?></div>
<?php endif; ?>

<div class="state-filter">
<span class="lbl"><i class="fas fa-filter"></i> Status:</span>
<a class="pill <?=$dstatus==='all'?'on':''?>" href="<?=htmlspecialchars(tabUrl($source,$yearParam,$search,'all'))?>"><i class="fas fa-list"></i> All</a>
<a class="pill <?=$dstatus==='active'?'on':''?>" href="<?=htmlspecialchars(tabUrl($source,$yearParam,$search,'active'))?>"><i class="fas fa-circle-check"></i> Active</a>
<a class="pill death <?=$dstatus==='death'?'on':''?>" href="<?=htmlspecialchars(tabUrl($source,$yearParam,$search,'death'))?>"><i class="fas fa-cross"></i> Death</a>
</div>

<div class="tabs">
<?php
$tabDefs=['all'=>['All','fa-list']]+array_map(fn($l)=>[$l[0],$l[1]],$labels);
foreach($tabDefs as $key=>$def): ?>
<a class="tab <?=$source===$key?'active':''?>" href="<?=htmlspecialchars(tabUrl($key,$yearParam,$search,$dstatus))?>">
<i class="fas <?=$def[1]?>"></i> <?=$def[0]?> <span class="n"><?=$counts[$key]?></span>
</a>
<?php endforeach; ?>
</div>

<form class="tools" method="get">
<input type="hidden" name="source" value="<?=htmlspecialchars($source)?>">
<input type="hidden" name="year" value="<?=htmlspecialchars($yearParam)?>">
<input type="hidden" name="dstatus" value="<?=htmlspecialchars($dstatus)?>">
<input type="text" name="q" placeholder="Search beneficiary, barangay, livestock, or batch..." value="<?=htmlspecialchars($search)?>">
<button type="submit"><i class="fas fa-magnifying-glass"></i> Search</button>
<a class="reset" href="dispersal.php">Reset</a>
</form>

<div class="table-wrap">
<table>
<thead>
<tr><th>Beneficiary</th><th>Barangay</th><th>Livestock</th><th>Batch</th><th>Date Distributed</th><th>Source</th><th>Status</th></tr>
</thead>
<tbody>
<?php
$i=0;
if($rows && $rows->num_rows>0):
while($r=$rows->fetch_assoc()):
    $i++;
    $src=isset($labels[$r['src']])?$r['src']:'da';
    [$lbl,$ico,$cls]=$labels[$src];
?>
<tr>
<td><?=htmlspecialchars($r['b_name'])?></td>
<td><?=htmlspecialchars($r['b_brgy']??'-')?></td>
<td><?=htmlspecialchars($r['l_type']??'-')?></td>
<td><?=htmlspecialchars($r['batch_no']??'-')?></td>
<td><?=!empty($r['distribution_date'])?date('M d, Y',strtotime($r['distribution_date'])):'-'?></td>
<td>
<span class="badge <?=$cls?>"><i class="fas <?=$ico?>"></i> <?=$lbl?></span>
<?php if(in_array($src,['return','transfer'],true) && !empty($r['from_name'])): ?>
<span class="from">from <?=htmlspecialchars($r['from_name'])?></span>
<?php endif; ?>
</td>
<td>
<?php $st=in_array($r['status'],['active','death','returned'],true)?$r['status']:'returned'; ?>
<div class="status-actions">
<span class="badge st-<?=$st?>"><?=htmlspecialchars(ucfirst($r['status']))?></span>
<button type="button" class="btn-proc" data-id="<?=(int)$r['livestock_id']?>" title="Tan-awa ang process sang dispersal">
<i class="fas fa-diagram-project"></i> Process
</button>
</div>
</td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="7" class="empty">Wala sing nakita nga record.</td></tr>
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

<!-- PROCESS POP-UP -->
<div class="modal-bg" id="procModal">
  <div class="modal">
    <div class="modal-head">
      <h3><i class="fas fa-diagram-project"></i> Dispersal Process</h3>
      <button type="button" id="procClose">&times;</button>
    </div>
    <div class="modal-body" id="procBody"></div>
  </div>
</div>

<script>
/* ===== KEEP SCROLL POSITION (indi mag-balik sa babaw) ===== */
(function restoreScroll(){
  let y=null;
  try{ y=sessionStorage.getItem(window.SCROLL_KEY); }catch(e){}
  if(y===null) return;
  try{ sessionStorage.removeItem(window.SCROLL_KEY); }catch(e){}
  const pos=parseInt(y,10)||0;
  window.scrollTo(0,pos);
  // para sure kung may late nga layout (fonts/images)
  window.addEventListener('load',()=>window.scrollTo(0,pos));
})();

// Tanan nga filter links (tabs, pills, cards, reset) kag forms (search, year)
document.querySelectorAll(
  '.main-content a.tab, .main-content a.pill, .main-content a.card, .main-content a.reset'
).forEach(a=>a.addEventListener('click',window.saveScroll));

document.querySelectorAll('.main-content form').forEach(f=>{
  f.addEventListener('submit',window.saveScroll);
});

/* ===== PROCESS MODAL ===== */
const modal=document.getElementById('procModal');
const body=document.getElementById('procBody');
const srcMap={
  da:{lbl:'From DA (Inventory)',ico:'fa-warehouse'},
  return:{lbl:'From Return',ico:'fa-rotate-left'},
  transfer:{lbl:'From Transfer',ico:'fa-right-left'}
};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

document.querySelectorAll('.btn-proc').forEach(b=>{
  b.addEventListener('click',async()=>{
    body.innerHTML='<p class="empty">Loading...</p>';
    modal.classList.add('show');
    try{
      const res=await fetch('dispersal.php?process='+encodeURIComponent(b.dataset.id));
      const data=await res.json();
      if(!data.ok||!data.steps.length){
        body.innerHTML='<p class="empty">Wala sing process nga record.</p>';
        return;
      }

      let html='<p style="margin-bottom:16px;font-size:13.5px;color:#4f5d53">Livestock: <strong>'+esc(data.steps[0].type)+'</strong> &bull; '+data.steps.length+' ka step</p><div class="flow">';
      data.steps.forEach((s,i)=>{
        const m=srcMap[s.src]||srcMap.da;
        let out='';
        if(s.status==='returned'){
          out='<div class="out"><i class="fas fa-rotate-left"></i> Gin-return'+(s.rdate?' sang '+esc(s.rdate):'')+'</div>';
        }else if(s.status==='death'){
          out='<div class="out death"><i class="fas fa-cross"></i> Napatay / Death</div>';
        }else{
          out='<div class="out active"><i class="fas fa-circle-check"></i> Active (subong nga ara sa beneficiary)</div>';
        }
        html+=`
        <div class="step">
          <div class="dot ${s.src}"><i class="fas ${m.ico}"></i></div>
          <div class="card-s">
            <div class="top">
              <span class="nm">${i+1}. ${esc(s.name)}</span>
              <span class="badge ${s.src}"><i class="fas ${m.ico}"></i> ${m.lbl}</span>
            </div>
            <div class="meta">Brgy. ${esc(s.brgy)} &bull; Batch: ${esc(s.batch)} &bull; Gin-distribute: ${esc(s.date)}</div>
            ${out}
          </div>
        </div>`;
      });
      html+='</div>';
      body.innerHTML=html;
    }catch(e){
      body.innerHTML='<p class="empty">Error sa pag-load.</p>';
    }
  });
});

document.getElementById('procClose').onclick=()=>modal.classList.remove('show');
modal.addEventListener('click',e=>{ if(e.target===modal) modal.classList.remove('show'); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') modal.classList.remove('show'); });
</script>
</body>
</html>