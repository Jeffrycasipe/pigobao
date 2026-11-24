<?php
// Adding, editing, and deleting beneficiaries now happens right from
// farmer-beneficiary.php (see the "Add Beneficiary" button, plus the
// Edit/Delete buttons on each farmer card), so this page is no longer
// needed on its own. Kept as a redirect only so any old bookmarks or
// links to add-beneficiaries.php still land somewhere useful.
session_start();
header("Location: farmer-beneficiary.php");
exit();