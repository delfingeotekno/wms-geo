<?php
// File: generate_pdf_bast.php
session_start();
include '../../includes/db_connect.php';
require('../../includes/fpdf/fpdf.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Gunakan ID Transaksi yang sama untuk menarik data
$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transaction_id == 0) {
    die("ID transaksi tidak valid.");
}

// Fungsi untuk konversi angka bulan ke Romawi
function romanNumerals($num) 
{
    $n = intval($num);
    $res = '';

    // Array Romawi
    $roman_array = array(
        'M'  => 1000,
        'CM' => 900,
        'D'  => 500,
        'CD' => 400,
        'C'  => 100,
        'XC' => 90,
        'L'  => 50,
        'XL' => 40,
        'X'  => 10,
        'IX' => 9,
        'V'  => 5,
        'IV' => 4,
        'I'  => 1
    );

    foreach ($roman_array as $roman => $number) 
    {
        // Loop selama angka lebih besar atau sama dengan nilai Romawi
        while ($n >= $number) {
            $n -= $number;
            $res .= $roman;
        }
    }
    return $res;
}

// Ambil data utama transaksi, termasuk kolom BAST yang baru
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

// --- LOGIKA DATA BAST (Fallback jika kolom BAST_NUMBER kosong) ---
$current_date = new DateTime($transaction['transaction_date']);
$current_month_roman = romanNumerals($current_date->format('n')); // Bulan (1-12)
$current_year = $current_date->format('Y');

// Base prefix
$bast_prefix = "BAST/GTG-JKT/";

// FALLBACK: Jika bast_number KOSONG di database, generate format baru
if (empty($transaction['bast_number'])) {
    // PENTING: ID Transaksi digunakan sebagai nomor urut sementara.
    // Dalam implementasi nyata, nomor urut (385) HARUS diambil dari tabel khusus 
    // atau kolom urutan terakhir di database untuk mencegah duplikasi.
    $sequence_number = str_pad($transaction_id, 3, '0', STR_PAD_LEFT); // Menggunakan ID Transaksi sebagai urutan 3 digit
    $generated_bast_number = $sequence_number . '/' . $bast_prefix . $current_month_roman . '/' . $current_year;
    
    $bast_number = $generated_bast_number;
    $bast_date = $transaction['transaction_date']; 
} else {
    // Jika sudah ada di database, gunakan yang ada
    $bast_number = $transaction['bast_number'];
    $bast_date = $transaction['bast_date'] ?: $transaction['transaction_date'];
}

$location = $transaction['location'] ?: 'Jakarta'; 
// --- AKHIR LOGIKA DATA BAST ---


// Ambil data detail item (sama seperti Surat Jalan)
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
    
    $grouped_details[$key]['quantity'] += $row['quantity'];
    
    if ($row['has_serial'] == 1 && !empty($row['serial_number'])) {
        $grouped_details[$key]['serial_numbers'][] = $row['serial_number'];
    }
}
$final_details = array_values($grouped_details);
// --- AKHIR LOGIKA PENGELOMPOKAN ITEM ---


class PDF extends FPDF {
    private $transaction;
    private $details;
    private $bast_data; // Untuk menyimpan data BAST yang sudah diproses
    private $logo_path = '../../assets/img/logogeo.png'; // Ganti dengan path logo Anda

    function setTransactionData($transaction, $details, $bast_data) {
        $this->transaction = $transaction;
        $this->details = $details;
        $this->bast_data = $bast_data;
    }

    // Header BAST
    function Header() {
        $this->Image($this->logo_path, 140, 3, 60); // Logo lebih kecil di kanan atas
        
        // Judul utama BAST
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(80, 8, 'BERITA ACARA SERAH TERIMA', 0, 1, 'L');
        $this->Ln(3);
        
        // Informasi transaksi dalam kotak (Mengikuti format BAST)
        $this->SetFont('Arial', '', 9);
        // Baris 1: BAST No & Date
        $this->Cell(25, 6, 'BAST No:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->bast_data['bast_number'], 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(25, 6, 'Date:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $bast_date_formatted = date('d M Y', strtotime($this->bast_data['bast_date']));
        $this->Cell(70, 6, $bast_date_formatted, 1, 1, 'L');
        
        // Baris 2: SJ No & To
        $this->SetFont('Arial', '', 9);
        $this->Cell(25, 6, 'SJ No:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['transaction_number'] ?: '-', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(25, 6, 'To:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['recipient'], 1, 1, 'L');

        // Baris 3: Subject & Ref
        $this->SetFont('Arial', '', 9);
        $this->Cell(25, 6, 'Subject:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['subject'] ?: '-', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(25, 6, 'Ref:', 1, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 6, $this->transaction['reference'] ?: '-', 1, 1, 'L');

        $this->Ln(4);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 6, 'Dengan ini kami lampirkan berita acara serah terima dengan informasi sebagai berikut:', 0, 1);
        $this->Ln(1);
        
        // Header tabel item BAST (tanpa Check dan Sign*)
        $this->SetFont('Arial','',9);
        $this->SetFillColor(255,255,255);
        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(.2);

        // Lebar kolom (Total 190mm)
        // Kolom yang tersisa: No, Description, Qty, UOM, Note
        $w = array(10, 110, 15, 15, 40); // Total: 10 + 110 + 15 + 15 + 40 = 190

        $this->Cell($w[0], 6, 'No.', 1, 0, 'C', true);
        $this->Cell($w[1], 6, 'Description', 1, 0, 'C', true);
        $this->Cell($w[2], 6, 'Qty', 1, 0, 'C', true);
        $this->Cell($w[3], 6, 'UOM', 1, 0, 'C', true);
        $this->Cell($w[4], 6, 'Note', 1, 1, 'C', true);
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

    // Body tabel BAST
    function ItemTableBody() {
        $this->SetFont('Arial','',9);
        $this->SetTextColor(0);
        
        // Lebar kolom BAST
        $w = array(10, 110, 15, 15, 40); 
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
                // Tampilkan S/N di Note
                $note = "S/N:\n" . implode("\n", $sn_list); 
            }
            
            $start_x = $this->GetX();
            $start_y = $this->GetY();

            // 1. Tentukan tinggi baris
            $this->SetXY($start_x + $w[0], $start_y); 
            $nb_desc_lines = $this->NbLines($w[1], $description);
            
            $this->SetXY($start_x + $w[0] + $w[1] + $w[2] + $w[3], $start_y); 
            $nb_note_lines = $this->NbLines($w[4], $note);
            
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

            // Kolom Description (w[1] = 110)
            $this->Rect($current_x, $start_y, $w[1], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height - $nb_desc_lines * $cell_line_height) / 2);
            $this->MultiCell($w[1], $cell_line_height, $description, 0, 'L');
            $current_x += $w[1];

            // Kolom Qty (w[2] = 15)
            $this->Rect($current_x, $start_y, $w[2], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height / 2) - ($cell_line_height / 2));
            $this->Cell($w[2], $cell_line_height, $row['quantity'], 0, 0, 'C'); 
            $current_x += $w[2];

            // Kolom UOM (w[3] = 15)
            $this->Rect($current_x, $start_y, $w[3], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height / 2) - ($cell_line_height / 2));
            $this->Cell($w[3], $cell_line_height, $row['unit'], 0, 0, 'C'); 
            $current_x += $w[3];

            // Kolom Note (w[4] = 40)
            $this->Rect($current_x, $start_y, $w[4], $row_height);
            $this->SetXY($current_x, $start_y + ($row_height - $nb_note_lines * $cell_line_height) / 2);
            $this->MultiCell($w[4], $cell_line_height, $note, 0, 'L');
            
            // 3. Atur kursor ke Y akhir baris
            $this->SetY($start_y + $row_height);
        }
        
        // Tambahkan baris kosong hingga minimal 3 baris (Logika pengisi minimum)
        $count = count($this->details); 
        $min_rows = 3;
        $empty_row_height = 6;
        for ($j = $count; $j < $min_rows; $j++) {
            // Check for page break for filler rows
            if ($this->GetY() + $empty_row_height > $this->PageBreakTrigger - 1) {
                $this->AddPage($this->CurOrientation);
            }
            $this->Cell($w[0], $empty_row_height, '', 1, 0, 'C');
            $this->Cell($w[1], $empty_row_height, '', 1, 0, 'L');
            $this->Cell($w[2], $empty_row_height, '', 1, 0, 'C');
            $this->Cell($w[3], $empty_row_height, '', 1, 0, 'C');
            $this->Cell($w[4], $empty_row_height, '', 1, 1, 'L');
        }

        // === LOGIKA TAMBAHAN 3 BARIS KOSONG (Sesuai Permintaan) ===
        $additional_rows = 3;
        for ($k = 0; $k < $additional_rows; $k++) {
            // Check for page break saat mencetak baris kosong tambahan
            if ($this->GetY() + $empty_row_height > $this->PageBreakTrigger - 1) {
                $this->AddPage($this->CurOrientation);
            }
            $this->Cell($w[0], $empty_row_height, '', 1, 0, 'C');
            $this->Cell($w[1], $empty_row_height, '', 1, 0, 'L');
            $this->Cell($w[2], $empty_row_height, '', 1, 0, 'C');
            $this->Cell($w[3], $empty_row_height, '', 1, 0, 'C');
            $this->Cell($w[4], $empty_row_height, '', 1, 1, 'L');
        }
        // === AKHIR LOGIKA TAMBAHAN 3 BARIS KOSONG ===
    }

    function SignatureAndNotes() {
        $this->Ln(3);
        
        // Paragraf penutup
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, 'Yang dimana sudah selesai dipasang dan beroperasi dengan baik di kapal.', 0, 1, 'L');

        $this->Ln(1);

        // Bagian tanggal dan lokasi
        $this->SetFont('Arial', '', 9);
        $location = $this->bast_data['location'];
        $bast_date_formatted = date('d F Y', strtotime($this->bast_data['bast_date']));
        $this->Cell(0, 5, $location . ', ' . $bast_date_formatted, 0, 1, 'L');
        $this->Ln(2);

        // Tanda tangan (Hanya Instalatur dan Penerima)
        $this->SetFont('Arial', 'B', 9);
        $this->SetLineWidth(0.2);
        $w_sig = 95; // (190 / 2)
        
        $this->Cell($w_sig, 6, 'Instalatur', 1, 0, 'C');
        $this->Cell($w_sig, 6, 'Penerima', 1, 1, 'C');
        
        // Baris untuk tanda tangan
        $this->Cell($w_sig, 15, '', 'LBR', 0, 'C');
        $this->Cell($w_sig, 15, '', 'LBR', 1, 'C');
        
        // Baris untuk nama (dianggap kosong karena ini adalah BAST)
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_sig, 6, '', 'LBR', 0, 'C'); 
        $this->Cell($w_sig, 6, '', 'LBR', 1, 'C'); 
        $this->Ln(3);

        // Bagian catatan yang baru
        $x = $this->GetX();
        $y = $this->GetY();
        $width = 190;
        
        // Kotak catatan
        $this->Rect($x, $y, $width, 12);

        // Baris pertama
        $this->SetFont('Arial', 'B', 9);
        $this->SetXY($x, $y); // Posisikan kursor kembali ke awal kotak
        $this->Cell(13, 6, 'Noted', 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $notes_db = $this->transaction['notes'] ?: '';
        $this->Cell(177, 6, $notes_db, 0, 1, 'L'); // Menggunakan 177mm lebar sisa (190 - 13)
        
        // Gambar garis tengah horizontal
        $this->Line($x, $y + 6, $x + $width, $y + 6);
        
        // Baris kedua
        $this->SetFont('Arial', '', 9);
        $this->SetX($x); // Posisikan kursor untuk baris berikutnya
        $this->Cell(130, 6, 'Barang telah diterima dalam keadaan baik dan lengkap.', 0, 1, 'L');
        $this->SetY($y + 12); // Pindah kursor ke bawah kotak
    }
}

// Inisialisasi dan Hasilkan PDF
$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->setTransactionData($transaction, $final_details, [
    'bast_number' => $bast_number,
    'bast_date' => $bast_date,
    'location' => $location
]); 
$pdf->AddPage();
$pdf->SetMargins(10, 10, 10);
$pdf->ItemTableBody();
$pdf->SignatureAndNotes();

$pdf->Output('I', 'berita_acara_' . str_replace('/', '_', $bast_number) . '.pdf');

?>