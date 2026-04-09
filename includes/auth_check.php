<?php
// Cek jika session belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkAccess($allowed_roles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
        // Jika tidak punya akses, lempar ke dashboard dengan pesan error
        header("Location: /wms-geo/index.php?error=access_denied");
        exit();
    }
}
?>