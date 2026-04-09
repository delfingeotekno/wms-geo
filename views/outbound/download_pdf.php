<?php
session_start();
include '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
    die("ID transaksi tidak valid.");
}

// Ambil data utama transaksi
$transaction_query = "SELECT 
    t.*,
    u.name AS creator_name
FROM outbound_transactions t
JOIN users u ON t.created_by = u.id
WHERE t.id = ? AND t.is_deleted = 0";
$stmt_main = $conn->prepare($transaction_query);
$stmt_main->bind_param("i", $transaction_id);
$stmt_main->execute();
$transaction = $stmt_main->get_result()->fetch_assoc();
$stmt_main->close();

if (!$transaction) {
    die("Transaksi tidak ditemukan.");
}

// Ambil data detail item
$details_query = "SELECT 
    td.*,
    p.product_code,
    p.product_name,
    p.has_serial,
    p.unit
FROM outbound_transaction_details td
JOIN products p ON td.product_id = p.id
WHERE td.transaction_id = ?";
$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();


// --- LOGIKA PENGELOMPOKAN ITEM (Grouping) ---
$grouped_details = [];
foreach ($details as $row) {
    $key = $row['product_id'];
    
    // Inisialisasi jika produk baru
    if (!isset($grouped_details[$key])) {
        $grouped_details[$key] = [
            'product_name' => $row['product_name'],
            'product_code' => $row['product_code'],
            'unit' => $row['unit'],
            'quantity' => 0,
            'serial_numbers' => [],
            'has_serial' => $row['has_serial'],
        ];
    }
    
    // Tambahkan kuantitas
    $grouped_details[$key]['quantity'] += $row['quantity'];
    
    // Kumpulkan serial numbers
    if ($row['has_serial'] == 1 && !empty($row['serial_number'])) {
        $grouped_details[$key]['serial_numbers'][] = $row['serial_number'];
    }
}
// Konversi array asosiatif menjadi array terindeks untuk FPDF
$final_details = array_values($grouped_details);
// --- AKHIR LOGIKA PENGELOMPOKAN ITEM ---


class PDF extends FPDF {
    private $transaction;
    private $details;

    function setTransactionData($transaction, $details) {
        $this->transaction = $transaction;
        $this->details = $details;
    }

    // Header
    function Header() {
        // Logo
        $this->Image('../../assets/img/logogeo.png', 140, 3, 60); 
        
        // Judul utama 
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(80, 8, 'SURAT JALAN', 0, 1, 'L');
        $this->Ln(3);
        
        // Informasi transaksi dalam kotak
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 6, 'SJ No:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['transaction_number'], 1, 0, 'L');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 6, 'Date:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, date('d M Y', strtotime($this->transaction['transaction_date'])), 1, 1, 'L');
        
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 6, 'To:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['recipient'], 1, 0, 'L');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 6, 'PO No:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['po_number'] ?: '-', 1, 1, 'L');

        // Menambahkan baris untuk Subject dan Ref
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 6, 'Subject:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['subject'], 1, 0, 'L');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 6, 'Ref:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['reference'] ?: '-', 1, 1, 'L');

        $this->Ln(4);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 6, 'Dengan ini kami lampirkan surat jalan dengan informasi sebagai berikut:', 0, 1);
        $this->Ln(1);
        
        // Header tabel item
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(255,255,255);
        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(.2);

        // Lebar kolom (Total 190mm)
        $w = array(10, 85, 11.5, 13.5, 12, 12, 46);

        $this->Cell($w[0], 6, 'No.', 1, 0, 'C', true);
        $this->Cell($w[1], 6, 'Description', 1, 0, 'C', true);
        $this->Cell($w[2], 6, 'Qty', 1, 0, 'C', true);
        $this->Cell($w[3], 6, 'UOM', 1, 0, 'C', true);
        $this->Cell($w[4], 6, 'Check', 1, 0, 'C', true);
        $this->Cell($w[5], 6, 'Sign*', 1, 0, 'C', true);
        $this->Cell($w[6], 6, 'Note', 1, 1, 'C', true);
    }
    
    // Fungsi untuk menghitung jumlah baris yang dibutuhkan teks untuk wrap
    function NbLines($w, $txt) {
        $cw = $this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                continue;
            }
            if($c==' ') {
                $sep = $i;
                $ls = $l;
                $ns++;
            }
            $l += $cw[$c];
            if($l>$wmax) {
                if($sep==-1) {
                    if($i==$j)
                        $i++;
                } else {
                    $i = $sep+1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    // Body tabel
    function ItemTableBody() {
        $this->SetFont('Arial','',9);
        $this->SetTextColor(0);
        
        // Lebar kolom (Total 190mm)
        $w = array(10, 85, 11.5, 13.5, 12, 12, 46);
        $cell_line_height = 5; // Tinggi per baris teks dalam MultiCell
        $i = 1;
        
        if (empty($this->details)) {
            $this->details = [];
        }

        // Hitung total baris yang digunakan oleh item
        $used_rows_count = 0;
        
        foreach($this->details as $row) {
            $description = $row['product_name'];
            $sn_list = $row['serial_numbers'];
            $note = '';

            // Format Serial Numbers jika ada
            if (!empty($sn_list)) {
                $note = "S/N:\n" . implode("\n", $sn_list);
            }
            
            $start_x = $this->GetX();
            $start_y = $this->GetY();

            // 1. Tentukan tinggi baris
            $this->SetXY($start_x + $w[0], $start_y); 
            $nb_desc_lines = $this->NbLines($w[1], $description);
            $this->SetXY($start_x + array_sum(array_slice($w, 0, 6)), $start_y); 
            $nb_note_lines = $this->NbLines($w[6], $note);
            
            $max_lines = max($nb_desc_lines, $nb_note_lines);
            $row_height = max(6, $max_lines * $cell_line_height); 
            
            // CATATAN: Karena satu item dapat menggunakan lebih dari 1 baris (karena MultiCell), 
            // kita harus menghitung baris yang terpakai di sini.
            $current_item_rows = ceil($row_height / $cell_line_height);
            $used_rows_count += $current_item_rows;

            // Check for page break (dengan toleransi 1mm)
            if ($this->GetY() + $row_height > $this->PageBreakTrigger - 1) {
                $this->AddPage($this->CurOrientation);
                $start_y = $this->GetY(); 
            }
            
            $current_x = $start_x;

            // 2. Gambar border dan isi setiap kolom
            $this->SetLineWidth(0.2); 
            
            // Kolom NO (w[0] = 10)
            $this->Rect($current_x, $start_y, $w[0], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height / 2) - ($cell_line_height / 2));
            $this->Cell($w[0], $cell_line_height, $i++, 0, 0, 'C'); 
            $current_x += $w[0];

            // Kolom Description (w[1] = 85)
            $this->Rect($current_x, $start_y, $w[1], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height - $nb_desc_lines * $cell_line_height) / 2);
            $this->MultiCell($w[1], $cell_line_height, $description, 0, 'L');
            $current_x += $w[1];

            // Kembali ke Y awal untuk kolom lain
            $this->SetY($start_y);
            
            // Kolom Qty (w[2] = 11.5)
            $this->Rect($current_x, $start_y, $w[2], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height / 2) - ($cell_line_height / 2));
            $this->Cell($w[2], $cell_line_height, $row['quantity'], 0, 0, 'C'); 
            $current_x += $w[2];

            // Kolom UOM (w[3] = 13.5)
            $this->Rect($current_x, $start_y, $w[3], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height / 2) - ($cell_line_height / 2));
            $this->Cell($w[3], $cell_line_height, $row['unit'], 0, 0, 'C'); 
            $current_x += $w[3];

            // Kolom Check (w[4] = 12)
            $this->Rect($current_x, $start_y, $w[4], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height / 2) - ($cell_line_height / 2));
            $this->Cell($w[4], $cell_line_height, '', 0, 0, 'C'); 
            $current_x += $w[4];

            // Kolom Sign* (w[5] = 12)
            $this->Rect($current_x, $start_y, $w[5], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height / 2) - ($cell_line_height / 2));
            $this->Cell($w[5], $cell_line_height, '', 0, 0, 'C'); 
            $current_x += $w[5];

            // Kolom Note (w[6] = 46)
            $this->Rect($current_x, $start_y, $w[6], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height - $nb_note_lines * $cell_line_height) / 2);
            $this->MultiCell($w[6], $cell_line_height, $note, 0, 'L');
            
            // 3. Atur kursor ke Y akhir baris
            $this->SetY($start_y + $row_height);
        }
        
        // === LOGIKA BARU UNTUK 3 BARIS KOSONG TAMBAHAN ===
        $empty_row_height = 5; // Tinggi baris kosong (sama dengan sebelumnya)
        $additional_rows_to_add = 3;
        
        // 1. Tambahkan baris kosong pengisi hingga minimum 5 baris (agar total baris selalu 8 saat item sedikit)
        // Kita asumsikan baris minimum yang Anda inginkan adalah 5 (agar total 8 baris, karena permintaan sebelumnya)
        $min_rows_filler = 5; 
        
        // Jika total item (dihitung per baris MultiCell) kurang dari baris minimum pengisi
        if ($used_rows_count < $min_rows_filler) {
            $filler_rows_needed = $min_rows_filler - $used_rows_count;
            for ($j = 0; $j < $filler_rows_needed; $j++) {
                 // Check for page break saat mencetak baris kosong
                if ($this->GetY() + $empty_row_height > $this->PageBreakTrigger - 1) {
                    $this->AddPage($this->CurOrientation);
                }
                $this->Cell($w[0], $empty_row_height, '', 1, 0, 'C'); 
                $this->Cell($w[1], $empty_row_height, '', 1, 0, 'L'); 
                $this->Cell($w[2], $empty_row_height, '', 1, 0, 'C'); 
                $this->Cell($w[3], $empty_row_height, '', 1, 0, 'C'); 
                $this->Cell($w[4], $empty_row_height, '', 1, 0, 'C'); 
                $this->Cell($w[5], $empty_row_height, '', 1, 0, 'C'); 
                $this->Cell($w[6], $empty_row_height, '', 1, 1, 'C'); 
            }
        }
        
        // 2. Tambahkan 3 baris kosong TAMBAHAN sesuai permintaan
        for ($j = 0; $j < $additional_rows_to_add; $j++) {
             // Check for page break saat mencetak baris kosong tambahan
            if ($this->GetY() + $empty_row_height > $this->PageBreakTrigger - 1) {
                $this->AddPage($this->CurOrientation);
            }
            $this->Cell($w[0], $empty_row_height, '', 1, 0, 'C'); 
            $this->Cell($w[1], $empty_row_height, '', 1, 0, 'L'); 
            $this->Cell($w[2], $empty_row_height, '', 1, 0, 'C'); 
            $this->Cell($w[3], $empty_row_height, '', 1, 0, 'C'); 
            $this->Cell($w[4], $empty_row_height, '', 1, 0, 'C'); 
            $this->Cell($w[5], $empty_row_height, '', 1, 0, 'C'); 
            $this->Cell($w[6], $empty_row_height, '', 1, 1, 'C'); 
        }
    }

    function SignatureAndNotes() {
        $this->Ln(0);
        
        // Tanda tangan
        $this->SetFont('Arial', 'B', 9);
        $this->SetLineWidth(0.2);
        $w_sig = 47.5; // (190 / 4)
        $this->Cell($w_sig, 6, 'Dibuat Oleh', 1, 0, 'C');
        $this->Cell($w_sig, 6, 'Mengetahui', 1, 0, 'C');
        $this->Cell($w_sig + 1.5, 6, 'Instalatur', 1, 0, 'C');
        $this->Cell($w_sig - 1.5, 6, 'Penerima', 1, 1, 'C');
        $this->Cell($w_sig, 12, '', 'LBR', 0, 'C');
        $this->Cell($w_sig, 12, '', 'LBR', 0, 'C');
        $this->Cell($w_sig + 1.5, 12, '', 'LBR', 0, 'C');
        $this->Cell($w_sig - 1.5, 12, '', 'LBR', 1, 'C');
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_sig, 6, 'Windah', 'LBR', 0, 'C'); 
        $this->Cell($w_sig, 6, 'Meri Mentari', 'LBR', 0, 'C'); // Mengetahui
        $this->Cell($w_sig + 1.5, 6, '', 'LBR', 0, 'C'); // Instalatur
        $this->Cell($w_sig - 1.5, 6, '' ?? '', 'LBR', 1, 'C'); // Penerima
        $this->Ln(2);

        // Bagian catatan yang baru
        $x = $this->GetX();
        $y = $this->GetY();
        $width = 190;
        $notes = $this->transaction['notes'] ?? '';
        
        $h_notes = 6;
        $nb_notes = $this->NbLines($width - 13, $notes); 
        $total_height = max(12, 6 + $nb_notes * $h_notes);

        // Kotak catatan
        $this->Rect($x, $y, $width, $total_height);
        
        // Baris pertama (Note(s):)
        $this->SetFont('Arial', 'B', 9);
        $this->SetXY($x, $y); 
        $this->Cell(13, $h_notes, 'Note(s):', 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        
        // MultiCell untuk Notes
        $notes_x = $this->GetX();
        $this->SetXY($notes_x, $y); 
        $this->MultiCell($width - 13, $h_notes, $notes, 0, 'L');
        
        // Gambar garis tengah horizontal
        $line_y = $y + 6;
        if ($line_y < $y + $total_height) { 
            $this->Line($x, $line_y, $x + $width, $line_y);
        }
        
        // Baris kedua (Penerima)
        $this->SetFont('Arial', '', 9);
        $this->SetY(max($line_y, $y + $nb_notes * $h_notes)); 
        $this->SetX($x); 
        $this->Cell(130, 6, 'Barang telah diterima dalam keadaan baik dan lengkap.', 0, 1, 'L');

        $this->Ln(1);
        
        // Keterangan warna
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 4, '*Bila ada perubahan dalam surat jalan ini wajib di Paraf', 0, 0);
        $this->Cell(0, 4, '**Putih/Merah = Kantor; Kuning = Customer; Hijau = Gudang', 0, 0, 'R');
    }
}

// Inisialisasi dan Hasilkan PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setTransactionData($transaction, $final_details); 
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->ItemTableBody();
$pdf->SignatureAndNotes();

$pdf->Output('I', 'transaksi_keluar_' . $transaction['transaction_number'] . '.pdf');
?>