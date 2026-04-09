<?php
include 'includes/db_connect.php';
$conn->query("ALTER TABLE assembly_outbound_results ADD COLUMN received_qty INT NOT NULL DEFAULT 0 AFTER qty");
echo "Migration complete: received_qty added to assembly_outbound_results.";
?>
