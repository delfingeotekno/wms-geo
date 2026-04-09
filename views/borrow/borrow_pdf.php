<?php
session_start();
// Pastikan path sudah benar
include '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Mengambil ID transaksi dari URL
$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
    die("ID transaksi peminjaman tidak valid.");
}

// 1. Ambil data utama transaksi pinjaman
$transaction_query = "SELECT 
    t.transaction_number,
    t.borrow_date,
    t.due_date,
    t.borrower_name,
    t.subject,
    t.notes,
    u.name AS creator_name
FROM borrowed_transactions t
JOIN users u ON t.user_id = u.id 
WHERE t.id = ? AND t.is_deleted = 0";
$stmt_main = $conn->prepare($transaction_query);
$stmt_main->bind_param("i", $transaction_id);
$stmt_main->execute();
$transaction = $stmt_main->get_result()->fetch_assoc();
$stmt_main->close();

if (!$transaction) {
    die("Transaksi pinjaman tidak ditemukan.");
}

// 2. Ambil data detail item pinjaman, termasuk tanggal kembali (return_date) dari detail
$details_query = "SELECT 
    td.*,
    p.product_code,
    p.product_name,
    p.has_serial,
    p.unit,
    td.return_date AS detail_return_date -- Ambil return_date dari detail
FROM borrowed_transactions_detail td
JOIN products p ON td.product_id = p.id
WHERE td.transaction_id = ?";
$stmt_details = $conn->prepare($details_query);
$stmt_details->bind_param("i", $transaction_id);
$stmt_details->execute();
$details = $stmt_details->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_details->close();


// --- LOGIKA PENCARIAN TANGGAL KEMBALI AKTUAL DARI DETAIL ---
$actual_return_date = null;
$unreturned_count = 0;
$latest_return_date = null;

foreach ($details as $row) {
    // 1. Hitung item yang belum dikembalikan
    if (empty($row['detail_return_date']) || $row['detail_return_date'] == '0000-00-00') {
        $unreturned_count++;
    }
    
    // 2. Cari tanggal kembali paling akhir (jika ada)
    if (!empty($row['detail_return_date']) && $row['detail_return_date'] != '0000-00-00') {
        if ($latest_return_date === null || $row['detail_return_date'] > $latest_return_date) {
            $latest_return_date = $row['detail_return_date'];
        }
    }
}

// Jika semua item telah dikembalikan, gunakan tanggal kembali terakhir sebagai tanggal aktual
if ($unreturned_count === 0 && $latest_return_date !== null) {
    $actual_return_date = $latest_return_date;
}

// Tambahkan actual_return_date ke array transaction untuk digunakan di Header FPDF
$transaction['actual_return_date'] = $actual_return_date; 
// --- AKHIR LOGIKA PENCARIAN TANGGAL KEMBALI AKTUAL ---


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
        $this->Cell(80, 8, 'SURAT PEMINJAMAN BARANG', 0, 1, 'L');
        $this->Ln(3);
        
        // Informasi transaksi dalam kotak
        // BARIS 1: SP No. & Tanggal Pinjam
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(30, 6, 'SPB No:', 1, 0, 'L'); 
        $this->SetFont('Arial', '', 9);
        $this->Cell(65, 6, $this->transaction['transaction_number'], 1, 0, 'L');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(30, 6, 'Tanggal Pinjam:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(65, 6, date('d M Y', strtotime($this->transaction['borrow_date'])), 1, 1, 'L'); 
        
        // BARIS 2: Peminjam & Tanggal Kembali (Target/Aktual)
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(30, 6, 'Peminjam:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(65, 6, $this->transaction['borrower_name'], 1, 0, 'L');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(30, 6, 'Tanggal Kembali:', 1, 0, 'L'); 
        $this->SetFont('Arial', '', 9);
        
        // Cek TANGGAL KEMBALI AKTUAL (yang diambil dari detail)
        $actual_date = $this->transaction['actual_return_date'];
        
        if (!empty($actual_date)) {
             // Jika semua sudah kembali, gunakan tanggal aktual
             $display_return_date = date('d M Y', strtotime($actual_date)) . ' '; 
        } else {
             // Jika belum kembali (masih pinjam), gunakan due_date (target) atau '-'
             $due_date = $this->transaction['due_date'];
             $display_return_date = (!empty($due_date) && $due_date !== '0000-00-00') ? date('d M Y', strtotime($due_date)) . ' (Target)' : '-'; 
        }
        
        $this->Cell(65, 6, $display_return_date, 1, 1, 'L');

        // BARIS 3: Subject (Ref Pinjaman dihapus)
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(30, 6, 'Subject:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        // Subject mengambil seluruh sisa lebar baris (65 + 30 + 65 = 160)
        $this->Cell(160, 6, $this->transaction['subject'], 1, 1, 'L'); 

        $this->Ln(4);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 6, 'Barang-barang berikut dipinjam oleh ' . $this->transaction['borrower_name'] . ':', 0, 1);
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
    
    // Fungsi NbLines (TETAP SAMA)
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

    // Body tabel (TETAP SAMA)
    function ItemTableBody() {
        $this->SetFont('Arial','',9);
        $this->SetTextColor(0);
        
        // Lebar kolom
        $w = array(10, 85, 11.5, 13.5, 12, 12, 46);
        $cell_line_height = 5; 
        $i = 1;
        
        if (empty($this->details)) {
            $this->details = [];
        }

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
            $this->SetFont('Arial','',9); // Pastikan font kembali ke normal untuk perhitungan
            $nb_desc_lines = $this->NbLines($w[1], $description);
            $this->SetXY($start_x + array_sum(array_slice($w, 0, 6)), $start_y); 
            $nb_note_lines = $this->NbLines($w[6], $note);
            
            $max_lines = max($nb_desc_lines, $nb_note_lines);
            $row_height = max(6, $max_lines * $cell_line_height); 

            // Check for page break 
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
        
        // Tambahkan baris kosong hingga minimal 5 baris
        $count = count($this->details); 
        $min_rows = 5;
        if ($count < $min_rows) {
            $empty_row_height = 6;
            for ($j = $count; $j < $min_rows; $j++) {
                $this->Cell($w[0], $empty_row_height, '', 1, 0, 'C');
                $this->Cell($w[1], $empty_row_height, '', 1, 0, 'L');
                $this->Cell($w[2], $empty_row_height, '', 1, 0, 'C');
                $this->Cell($w[3], $empty_row_height, '', 1, 0, 'C');
                $this->Cell($w[4], $empty_row_height, '', 1, 0, 'C');
                $this->Cell($w[5], $empty_row_height, '', 1, 0, 'C');
                $this->Cell($w[6], $empty_row_height, '', 1, 1, 'L');
            }
        }
    }

    // Penyesuaian Signature
    function SignatureAndNotes() {
        $this->Ln(0);
        
        // Tanda tangan
        $this->SetFont('Arial', 'B', 9);
        $this->SetLineWidth(0.2);
        $w_sig = 47.5; 
        
        // Label disesuaikan
        $this->Cell($w_sig, 6, 'Pengeluar Barang', 1, 0, 'C'); 
        $this->Cell($w_sig, 6, 'Mengetahui', 1, 0, 'C');
        $this->Cell($w_sig + 1.5, 6, 'Diperiksa', 1, 0, 'C');
        $this->Cell($w_sig - 1.5, 6, 'Penerima Barang', 1, 1, 'C'); 
        $this->Cell($w_sig, 12, '', 'LBR', 0, 'C');
        $this->Cell($w_sig, 12, '', 'LBR', 0, 'C');
        $this->Cell($w_sig + 1.5, 12, '', 'LBR', 0, 'C');
        $this->Cell($w_sig - 1.5, 12, '', 'LBR', 1, 'C');
        
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_sig, 6, $this->transaction['creator_name'], 'LBR', 0, 'C'); 
        $this->Cell($w_sig, 6, 'Windah', 'LBR', 0, 'C'); 
        $this->Cell($w_sig + 1.5, 6, 'Meri Mentari', 'LBR', 0, 'C'); 
        $this->Cell($w_sig - 1.5, 6, $this->transaction['borrower_name'], 'LBR', 1, 'C'); 
        $this->Ln(2);

        // Bagian catatan dan disclaimer
        $x = $this->GetX();
        $y = $this->GetY();
        $width = 190;
        
        // Kotak catatan
        $this->Rect($x, $y, $width, 18); 
        
        // Baris pertama (Notes)
        $this->SetFont('Arial', 'B', 9);
        $this->SetXY($x, $y); 
        $this->Cell(13, 6, 'Note(s):', 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(100, 6, $this->transaction['notes'], 0, 1, 'L');
        
        // Gambar garis tengah horizontal 1
        $this->Line($x, $y + 6, $x + $width, $y + 6);
        
        // Baris kedua (Disclaimer)
        $this->SetFont('Arial', 'B', 9);
        $this->SetX($x);
        $this->Cell(130, 6, 'Disclaimer:', 0, 1, 'L');

        // Gambar garis tengah horizontal 2
        $this->Line($x, $y + 12, $x + $width, $y + 12);
        
        // Baris ketiga (Detail Disclaimer) - FIX: Semua teks digabung di satu MultiCell
        $disclaimer_text = 'Barang yang dipinjam wajib dikembalikan dalam kondisi dan waktu yang telah disepakati. Kerusakan atau kehilangan barang menjadi tanggung jawab Penerima Barang (Peminjam).';

        $this->SetFont('Arial', '', 8);
        $this->SetX($x);
        // Menggunakan MultiCell sekali untuk seluruh teks disclaimer
        $this->MultiCell($width, 3, $disclaimer_text, 0, 'L'); // Menggunakan height 4 agar pas 3 baris
        // Catatan: FPDF MultiCell akan otomatis mengatur posisi Y baru setelah selesai.

        $this->Ln(1);
        
        // Keterangan warna (Tetap Sama)
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 5, '*Bila ada perubahan dalam surat pinjaman ini wajib di Paraf', 0, 0);
        $this->Cell(0, 5, '**Putih/Merah = Kantor; Kuning = Peminjam; Hijau = Gudang', 0, 0, 'R');
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

// Output nama file disesuaikan dengan Pinjaman
$pdf->Output('I', 'surat_pinjaman_' . $transaction['transaction_number'] . '.pdf');