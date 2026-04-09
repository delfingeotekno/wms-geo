    <?php
    session_start();
    include '../../includes/db_connect.php';

    // Fungsi helper untuk mengecek keberadaan tabel (tetap ada)
    function tableExists($conn, $table_name) {
        $check = $conn->query("SHOW TABLES LIKE '{$table_name}'");
        return $check && $check->num_rows > 0;
    }

    // Pastikan hanya admin yang bisa memulihkan produk
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
        header("Location: ../../index.php");
        exit();
    }

    // Pastikan request menggunakan metode POST dan ID produk tersedia
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id'])) {
        $product_id = intval($_POST['id']);

        // --- DEBUGGING OUTPUT: Tampilkan Product ID yang diterima ---
        echo "<h2 style='color: blue;'>Debugging Restore Process:</h2>";
        echo "<p><strong>Product ID received:</strong> " . $product_id . "</p>";
        // --- END DEBUGGING OUTPUT ---

        // Mulai transaksi database
        $conn->begin_transaction();

        try {
            // Cek apakah produk memiliki serial number
            $stmt_check = $conn->prepare("SELECT has_serial FROM products WHERE id = ?");
            $stmt_check->bind_param("i", $product_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $product_data = $result_check->fetch_assoc();
            $stmt_check->close();
            
            if (!$product_data) {
                 throw new Exception("Produk tidak ditemukan atau akses ditolak.");
            }

            // --- DEBUGGING OUTPUT: Informasi Serial Number ---
            echo "<p><strong>Has Serial (from product data):</strong> " . ($product_data ? $product_data['has_serial'] : 'N/A') . "</p>";
            echo "<p><strong>Serial Numbers table exists:</strong> " . (tableExists($conn, 'serial_numbers') ? 'Yes' : 'No') . "</p>";
            // --- END DEBUGGING OUTPUT ---

            // Jika produk memiliki serial number, aktifkan kembali serial number terkait
            if ($product_data && $product_data['has_serial'] && tableExists($conn, 'serial_numbers')) {
                // Update serials strictly for this product (tenant check implicit via product ownership, but good to have)
                $stmt_serials = $conn->prepare("UPDATE serial_numbers SET is_deleted = 0 WHERE product_id = ? AND is_deleted = 1"); 
                // Note: serial_numbers tenant_id might be needed if shared product_id across tenants possible? No, product_id is unique.
                $stmt_serials->bind_param("i", $product_id);
                if (!$stmt_serials->execute()) {
                    throw new Exception("Gagal mengaktifkan serial numbers: " . $stmt_serials->error);
                }
                echo "<p><strong>Serial Numbers affected rows:</strong> " . $stmt_serials->affected_rows . "</p>"; // Debug
                $stmt_serials->close();
            }

            // Pulihkan produk (ubah is_deleted dari 1 menjadi 0)
            $stmt = $conn->prepare("UPDATE products SET is_deleted = 0 WHERE id = ? AND is_deleted = 1");
            $stmt->bind_param("i", $product_id);

            if ($stmt->execute()) {
                // --- DEBUGGING OUTPUT: Affected Rows Produk ---
                echo "<p><strong>Products affected rows:</strong> " . $stmt->affected_rows . "</p>";
                // --- END DEBUGGING OUTPUT ---

                if ($stmt->affected_rows === 0) {
                    throw new Exception("Produk tidak ditemukan atau tidak dalam status terhapus (is_deleted tidak 1). Affected rows: " . $stmt->affected_rows);
                }
                $conn->commit();
                $stmt->close();
                $_SESSION['success_message'] = "Produk berhasil dipulihkan!";
                header("Location: index.php?status=success_restore");
                exit();
            } else {
                throw new Exception("Gagal memulihkan produk: " . $stmt->error);
            }

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error restoring product ID {$product_id}: " . $e->getMessage());
            echo "<h2 style='color: red;'>Debugging Error:</h2>";
            echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
            echo "<p><strong>Product ID:</strong> " . $product_id . "</p>";
            echo "<p><strong>SQL Error:</strong> " . (isset($stmt) ? $stmt->error : $conn->error) . "</p>";
            echo "<p><a href='index.php'>Kembali ke Daftar Produk</a></p>";
            exit();
        }
    } else {
        header("Location: index.php");
        exit();
    }

    $conn->close();
    ?>
    