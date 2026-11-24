<?php
// Assigning barangays now happens right from the technicians table in
// add-technician.php (see the "Assign" button per row), so this page is
// no longer needed on its own. Kept as a redirect only so any old
// bookmarks or links to assign-technician.php still land somewhere useful.
session_start();
header("Location: add-technician.php");
exit();