<?php
// File: api.php

// 1. Set Header untuk API (CORS & JSON)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS request (Biasa terjadi pada Fetch API)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. Panggil file konfigurasi database
require_once 'config.local2.php';

// 3. Tangkap 'action' dari URL (?action=nama_aksi)
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 4. Tangkap data JSON yang dikirim dari Frontend (JavaScript fetch)
$input = json_decode(file_get_contents("php://input"), true);

// 5. Routing Logika Berdasarkan Action
switch ($action) {

    // ==========================================
    // ACTION: LOGIN PERAWAT
    // ==========================================
    case 'login':
        if (!empty($input['username']) && !empty($input['password'])) {
            try {
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = :user");
                $stmt->execute([':user' => $input['username']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Verifikasi password (asumsi menggunakan password_hash di DB)
                // Jika password di DB belum di hash (masih plain text), ganti bagian ini dengan: if ($user && $input['password'] === $user['password'])
                if ($user && password_verify($input['password'], $user['password'])) {
                    echo json_encode([
                        "success" => true, 
                        "namaPerawat" => $user['nama_lengkap'],
                        "fotoUrl" => $user['foto_url'],
                        "role" => $user['role']
                    ]);
                } else {
                    echo json_encode(["success" => false, "message" => "Username atau Password salah!"]);
                }
            } catch (Exception $e) {
                echo json_encode(["success" => false, "message" => "Sistem Error: " . $e->getMessage()]);
            }
        } else {
            echo json_encode(["success" => false, "message" => "Data login tidak lengkap."]);
        }
        break;

    // ==========================================
    // ACTION: AMBIL DATA PASIEN (DAFTAR TABEL)
    // ==========================================
    case 'get_patients':
        try {
            // Ambil semua data pasien, urutkan berdasarkan yang terbaru
            $stmt = $conn->prepare("
                SELECT no_mr, nama_pasien, diagnosa, tgl_lahir, 
                DATE_FORMAT(tgl_lahir, '%d/%m/%Y') as tgl_lahir_indo,
                IFNULL(created_at, 'Belum ada') as created_at
                FROM pasien 
                ORDER BY created_at DESC 
                LIMIT 500
            ");
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ==========================================
    // ACTION: TAMBAH DATA PASIEN BARU
    // ==========================================
    case 'add_patient':
        if (!empty($input['mr']) && !empty($input['nama'])) {
            try {
                $stmt = $conn->prepare("INSERT INTO pasien (no_mr, nama_pasien, tgl_lahir, diagnosa) VALUES (:mr, :nama, :tgl, :dx)");
                $stmt->execute([
                    ':mr' => $input['mr'],
                    ':nama' => $input['nama'],
                    ':tgl' => !empty($input['tgl_lahir']) ? $input['tgl_lahir'] : '1970-01-01',
                    ':dx' => $input['dx'] ?? ''
                ]);
                echo json_encode(["success" => true, "message" => "Pasien berhasil ditambahkan."]);
            } catch (PDOException $e) {
                // Cek jika error karena duplikat primary key (No MR sudah ada)
                if ($e->getCode() == 23000) {
                    echo json_encode(["success" => false, "message" => "No MR tersebut sudah terdaftar!"]);
                } else {
                    echo json_encode(["success" => false, "message" => "Gagal: " . $e->getMessage()]);
                }
            }
        } else {
            echo json_encode(["success" => false, "message" => "No MR dan Nama wajib diisi."]);
        }
        break;

    // ==========================================
    // ACTION: SIMPAN FORM EWS
    // ==========================================
    case 'save_ews':
        if (!empty($input['noMR']) && !empty($input['namaPasien'])) {
            try {
                $conn->beginTransaction(); // Mulai transaksi

                // 1. Simpan atau Update Data Pasien (Jika ada perubahan diagnosa)
                $stmtPasien = $conn->prepare("
                    INSERT INTO pasien (no_mr, nama_pasien, tgl_lahir, diagnosa) 
                    VALUES (:mr, :nama, :dob, :diag) 
                    ON DUPLICATE KEY UPDATE nama_pasien=:nama, diagnosa=:diag
                ");
                $stmtPasien->execute([
                    ':mr' => $input['noMR'], 
                    ':nama' => $input['namaPasien'], 
                    ':dob' => !empty($input['dob']) ? $input['dob'] : '1970-01-01', 
                    ':diag' => $input['diagnosa'] ?? ''
                ]);

                // 2. Simpan Rekam Medis EWS
                $sqlEWS = "INSERT INTO laporan_ews 
                    (no_mr, nama_pasien, nama_perawat, dinas, ruang, suhu, rr, spo2, fio2, hr, crt, bp, perilaku, total_ews) 
                    VALUES 
                    (:no_mr, :nama_pasien, :nama_perawat, :dinas, :ruang, :suhu, :rr, :spo2, :fio2, :hr, :crt, :bp, :perilaku, :total_ews)";
                
                $stmtEWS = $conn->prepare($sqlEWS);
                $stmtEWS->execute([
                    ':no_mr' => $input['noMR'],
                    ':nama_pasien' => $input['namaPasien'],
                    ':nama_perawat' => $input['perawat'] ?? 'Sistem',
                    ':dinas' => $input['dinas'] ?? '',
                    ':ruang' => $input['ruang'] ?? '',
                    ':suhu' => $input['suhu'] ?? 0,
                    ':rr' => $input['rr'] ?? 0,
                    ':spo2' => $input['spo2'] ?? 0,
                    ':fio2' => $input['fio2'] ?? '',
                    ':hr' => $input['hr'] ?? 0,
                    ':crt' => $input['crt'] ?? 0,
                    ':bp' => $input['bp'] ?? '',
                    ':perilaku' => $input['perilaku'] ?? 'Alert',
                    ':total_ews' => $input['totalEWS'] ?? 0
                ]);

                $conn->commit(); // Selesaikan transaksi
                echo json_encode(["success" => true, "message" => "Data EWS berhasil disimpan"]);
                
            } catch (Exception $e) {
                $conn->rollBack(); // Batalkan jika terjadi error
                echo json_encode(["success" => false, "message" => "Gagal menyimpan: " . $e->getMessage()]);
            }
        } else {
            echo json_encode(["success" => false, "message" => "Data Pasien Tidak Lengkap!"]);
        }
        break;

    // ==========================================
    // ACTION: CARI ICD-10 (AUTOCOMPLETE)
    // ==========================================
    case 'search_icd10':
        $keyword = isset($_GET['q']) ? $_GET['q'] : '';
        if (!empty($keyword)) {
            try {
                // Cari berdasarkan kode atau nama penyakit
                $stmt = $conn->prepare("
                    SELECT kode, nama_penyakit 
                    FROM icd10_indonesia 
                    WHERE kode LIKE :kw OR nama_penyakit LIKE :kw 
                    LIMIT 20
                ");
                $stmt->execute([':kw' => "%$keyword%"]);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                echo json_encode([]);
            }
        } else {
            echo json_encode([]);
        }
        break;

    // Default jika action tidak dikenali
    default:
        echo json_encode(["success" => false, "message" => "Endpoint API tidak ditemukan."]);
        break;
}
?>