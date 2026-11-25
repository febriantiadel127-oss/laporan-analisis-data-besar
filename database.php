<?php
/**
 * Database Connection & Helper Functions
 * BOMBA ANALYTICS - Dashboard Analitik Produksi & Penjualan Batik Bomba & Tenun Donggala
 */

// Konfigurasi Database (Default: SQLite - portabel, siap pakai tanpa instalasi database server)
// Untuk beralih ke MySQL, ganti DB_DRIVER menjadi 'mysql' dan sesuaikan kredensialnya.
define('DB_DRIVER', 'sqlite'); // 'sqlite' atau 'mysql'

// Pengaturan MySQL (hanya jika DB_DRIVER = 'mysql')
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'db_batik_bomba');
define('DB_USER', 'root');
define('DB_PASS', '');

// Path file SQLite
define('DB_SQLITE_PATH', __DIR__ . '/../database/batik_bomba.sqlite');

function getDBConnection() {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        if (DB_DRIVER === 'sqlite') {
            $dbFile = DB_SQLITE_PATH;
            $dir = dirname($dbFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $isFirstRun = !file_exists($dbFile) || filesize($dbFile) === 0;

            $pdo = new PDO("sqlite:" . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec("PRAGMA foreign_keys = ON;");

            // Jika file baru, inisialisasi tabel dan data awal
            if ($isFirstRun) {
                require_once __DIR__ . '/init_db.php';
                initializeDatabase($pdo);
            }
        } else {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }

        return $pdo;
    } catch (PDOException $e) {
        die("Koneksi Database Gagal: " . $e->getMessage());
    }
}

// Helper format Rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Helper format Tanggal Indonesia
function formatTanggalIndo($tanggal) {
    if (!$tanggal) return '-';
    $bulanIndo = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $timestamp = strtotime($tanggal);
    $hari = date('d', $timestamp);
    $bulan = $bulanIndo[(int)date('m', $timestamp)];
    $tahun = date('Y', $timestamp);
    return "$hari $bulan $tahun";
}
