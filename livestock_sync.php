<?php
/*
 * livestock_sync.php
 * Pamalit sa 4 ka TRIGGERS (indi allowed sa InfinityFree).
 * Gamita: require_once __DIR__ . '/livestock_sync.php';   (ayusa ang path kon kinahanglan)
 */

/* Pamalit sa trg_distribution_insert_status + trg_distribution_update_status
   Tawga PAGKATAPOS sang INSERT ukon UPDATE sa distributions. */
function syncLivestockStatus(mysqli $conn, int $livestock_id, string $distStatus): void {
    $map = ['active' => 'distributed', 'returned' => 'available', 'death' => 'dead'];
    if (!isset($map[$distStatus])) return;
    $new = $map[$distStatus];
    $st = $conn->prepare("UPDATE livestock SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE livestock_id = ?");
    $st->bind_param("si", $new, $livestock_id);
    $st->execute();
    $st->close();
}

/* Pamalit sa trg_monitoring_death + trg_monitoring_return
   Tawga PAGKATAPOS sang INSERT sa monitoring.
   $m = ['distribution_id','health_status','action','date_recorded',
         'cause_of_death','death_type','notes','created_by']  */
function applyMonitoringEffects(mysqli $conn, array $m): void {
    $distId = (int)$m['distribution_id'];
    $date   = $m['date_recorded'] ?? date('Y-m-d');
    $by     = (int)($m['created_by'] ?? 0);

    $isDeath  = (($m['health_status'] ?? '') === 'Dead') || (($m['action'] ?? '') === 'death');
    $isReturn = (($m['action'] ?? '') === 'return');

    if ($isDeath) {
        $cause = $m['cause_of_death'] ?? null;
        $type  = $m['death_type'] ?? null;
        $notes = $m['notes'] ?? null;
        $st = $conn->prepare("UPDATE distributions SET status='death', death_date=?, death_cause=?, death_type=?,
                              death_remarks=?, closed_at=CURRENT_TIMESTAMP, closed_by=?
                              WHERE distribution_id=? AND status='active'");
        $st->bind_param("ssssii", $date, $cause, $type, $notes, $by, $distId);
        $st->execute(); $st->close();
    } elseif ($isReturn) {
        $notes = $m['notes'] ?? null;
        $st = $conn->prepare("UPDATE distributions SET status='returned', return_date=?, return_reason=?,
                              closed_at=CURRENT_TIMESTAMP, closed_by=?
                              WHERE distribution_id=? AND status='active'");
        $st->bind_param("ssii", $date, $notes, $by, $distId);
        $st->execute(); $st->close();
    } else {
        return;
    }

    // ipasa ang bag-o nga distribution status sa livestock
    $r = $conn->prepare("SELECT livestock_id, status FROM distributions WHERE distribution_id=?");
    $r->bind_param("i", $distId);
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    $r->close();
    if ($row) syncLivestockStatus($conn, (int)$row['livestock_id'], $row['status']);
}
