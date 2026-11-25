<?php
/**
 * Database Initializer & Rich Sample Data Seeder
 * Usaha Pengrajin Kain Tenun & Batik Lokal "Batik Bomba" (Palu & Donggala, Sulawesi Tengah)
 */

function initializeDatabase(PDO $pdo) {
    // 1. Skema Tabel
    $schema = <<<SQL
    CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        description TEXT,
        icon TEXT DEFAULT 'fas fa-palette'
    );

    CREATE TABLE IF NOT EXISTS artisans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        phone TEXT,
        address TEXT,
        specialty TEXT NOT NULL,
        experience_years INTEGER DEFAULT 5,
        daily_capacity_units REAL DEFAULT 1.0,
        status TEXT DEFAULT 'Aktif'
    );

    CREATE TABLE IF NOT EXISTS raw_materials (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        unit TEXT NOT NULL,
        stock_quantity REAL NOT NULL DEFAULT 0,
        min_threshold REAL NOT NULL DEFAULT 10,
        unit_price REAL NOT NULL DEFAULT 0,
        category TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        motif TEXT NOT NULL,
        material TEXT NOT NULL,
        production_cost REAL NOT NULL,
        selling_price REAL NOT NULL,
        stock_quantity INTEGER NOT NULL DEFAULT 0,
        min_stock_threshold INTEGER NOT NULL DEFAULT 5,
        lead_time_days INTEGER NOT NULL DEFAULT 7,
        status TEXT DEFAULT 'Tersedia',
        description TEXT,
        image_url TEXT,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
    );

    CREATE TABLE IF NOT EXISTS productions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_code TEXT NOT NULL UNIQUE,
        product_id INTEGER NOT NULL,
        artisan_id INTEGER NOT NULL,
        target_quantity INTEGER NOT NULL,
        actual_quantity INTEGER DEFAULT 0,
        start_date TEXT NOT NULL,
        estimated_end_date TEXT NOT NULL,
        actual_end_date TEXT,
        status TEXT NOT NULL, -- 'Persiapan Bahan', 'Pengerjaan (Tenun/Batik)', 'Pewarnaan & Fiksasi', 'Quality Control', 'Selesai'
        material_cost REAL NOT NULL DEFAULT 0,
        labor_cost REAL NOT NULL DEFAULT 0,
        total_cost REAL NOT NULL DEFAULT 0,
        reject_quantity INTEGER DEFAULT 0,
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (artisan_id) REFERENCES artisans(id) ON DELETE RESTRICT
    );

    CREATE TABLE IF NOT EXISTS sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL UNIQUE,
        sale_date TEXT NOT NULL,
        customer_name TEXT NOT NULL,
        customer_type TEXT NOT NULL, -- 'Perorangan', 'Instansi Pemerintah', 'Kolektor Budaya', 'Toko Reseller'
        sales_channel TEXT NOT NULL, -- 'Galeri Utama Palu', 'Pameran Dekranasda', 'Online Store / Medsos', 'Pesanan Khusus Pemda'
        total_amount REAL NOT NULL,
        total_cost REAL NOT NULL DEFAULT 0,
        total_profit REAL NOT NULL DEFAULT 0,
        payment_method TEXT DEFAULT 'Transfer Bank',
        status TEXT DEFAULT 'Lunas',
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS sale_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        cost_price REAL NOT NULL,
        subtotal REAL NOT NULL,
        profit REAL NOT NULL,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    );

    CREATE TABLE IF NOT EXISTS decision_recommendations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL, -- 'REKOMENDASI_PRODUKSI', 'STOK_KRITIS', 'OPTIMASI_HARGA', 'SLOW_MOVING'
        priority TEXT NOT NULL, -- 'High', 'Medium', 'Low'
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        suggested_action TEXT NOT NULL,
        product_id INTEGER,
        status TEXT DEFAULT 'Aktif',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
    );
SQL;

    $pdo->exec($schema);

    // Cek apakah data sudah ada
    $check = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($check > 0) {
        return; // Sudah diinisialisasi
    }

    // 2. Data Kategori
    $categories = [
        ['Tenun Donggala Sutra', 'tenun-donggala', 'Kain tenun tradisional asli menggunakan ATBM dengan benang sutra alam khas Lembah Palu & Donggala.', 'fas fa-layer-group'],
        ['Batik Bomba Tulis', 'batik-bomba-tulis', 'Batik tulis eksklusif dengan canting tangan bermotif filosofi khas Kaili (Sambulagana, Taiganja).', 'fas fa-feather-alt'],
        ['Batik Bomba Cap', 'batik-bomba-cap', 'Batik cap berkualitas tinggi menggunakan bahan katun primissima dengan motif khas Sulawesi Tengah.', 'fas fa-stamp'],
        ['Busana Siap Pakai', 'busana-siap-pakai', 'Pakaian kemeja, tunik, dan blouse pria/wanita berbahan tenun dan batik Bomba berkualitas tinggi.', 'fas fa-tshirt'],
        ['Selendang & Aksesoris', 'selendang-aksesoris', 'Selendang tenun halus, syal sutra, dan aksesoris etnik Bomba untuk acara formal & budaya.', 'fas fa-scarf']
    ];

    $stmtCat = $pdo->prepare("INSERT INTO categories (name, slug, description, icon) VALUES (?, ?, ?, ?)");
    foreach ($categories as $cat) {
        $stmtCat->execute($cat);
    }

    // 3. Data Pengrajin / Penenun
    $artisans = [
        ['Ibu Maryam Lasahido', '0812-4210-9811', 'Banawa, Donggala', 'Penenun Utama Sutra ATBM', 18, 0.2, 'Aktif'],
        ['Ibu Nurhayati Kaili', '0852-9981-4432', 'Watusampu, Palu Barat', 'Pembatik Canting Tulis Halus', 14, 0.4, 'Aktif'],
        ['Pak Syamsuddin', '0821-8890-1200', 'Tondo, Palu Timur', 'Spesialis Batik Cap & Pewarna Alam', 11, 2.5, 'Aktif'],
        ['Ibu Salmawati Tande', '0813-5561-2345', 'Limboro, Donggala', 'Penenun Tenun Suba & Bomba Kota', 22, 0.15, 'Aktif'],
        ['Ibu Fatimah Donggala', '0853-4011-8902', 'Loli Oge, Donggala', 'Penenun Motif Taiganja Sutra', 12, 0.25, 'Aktif'],
        ['Pak Hendra Pratama', '0812-4500-6712', 'Birobuli, Palu Selatan', 'Pewarnaan & Fiksasi Kimia/Alam', 8, 4.0, 'Aktif'],
        ['Ibu Sitti Rahma', '0822-7100-3341', 'Palu Barat', 'Penjahit Busana & Modiste Etnik', 9, 1.5, 'Aktif'],
        ['Ibu Hasnah K.', '0852-3312-9908', 'Towale, Banawa Tengah', 'Penenun Motif Sambulagana', 15, 0.2, 'Aktif']
    ];

    $stmtArt = $pdo->prepare("INSERT INTO artisans (name, phone, address, specialty, experience_years, daily_capacity_units, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($artisans as $art) {
        $stmtArt->execute($art);
    }

    // 4. Data Bahan Baku
    $rawMaterials = [
        ['Benang Sutra Alam Donggala', 'Kilos', 45.5, 15.0, 480000, 'Benang Tenun'],
        ['Kain Mori Primissima Super (Gamelan Mas)', 'Meter', 240.0, 50.0, 38000, 'Kain Mori'],
        ['Malam / Lilin Batik Kualitas Ekspor', 'Kg', 85.0, 20.0, 45000, 'Lilin Batik'],
        ['Pewarna Alami Kayu Mahoni & Kulit Bakau', 'Kg', 32.0, 10.0, 75000, 'Pewarna Alami'],
        ['Pewarna Sintetis Remazol & Indigosol', 'Kaleng', 18.0, 8.0, 120000, 'Pewarna Kimia'],
        ['Kain Rayon Viscose Etnik', 'Meter', 110.0, 30.0, 42000, 'Kain Dasar']
    ];

    $stmtMat = $pdo->prepare("INSERT INTO raw_materials (name, unit, stock_quantity, min_threshold, unit_price, category) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($rawMaterials as $mat) {
        $stmtMat->execute($mat);
    }

    // 5. Data Produk (Kain Tenun & Batik Bomba)
    $products = [
        // id=1: Tenun Donggala Sutra Sambulagana
        [1, 'TNN-SBG-001', 'Kain Tenun Donggala Sutra Sambulagana', 'Sambulagana', 'Benang Sutra Alam', 650000, 1250000, 8, 5, 21, 'Tersedia', 'Kain tenun sutra asli ATBM dengan motif Sambulagana khas Kaili yang melambangkan kebersamaan dan kehormatan.', 'assets/images/motif-sambulagana.jpg'],
        // id=2: Tenun Donggala Sutra Motif Taiganja
        [1, 'TNN-TGJ-002', 'Kain Tenun Sutra Motif Taiganja Pusaka', 'Taiganja', 'Benang Sutra Emas & Katun', 720000, 1400000, 4, 6, 25, 'Tersedia', 'Kain tenun eksklusif dengan ornamen Taiganja sakral lambang kesuburan, kemakmuran, dan kehormatan tanah Kaili.', 'assets/images/motif-taiganja.jpg'],
        // id=3: Tenun Bomba Suba Kota Donggala
        [1, 'TNN-SUB-003', 'Kain Tenun Donggala Motif Suba Kota', 'Suba', 'Sutra Halus', 580000, 1100000, 11, 4, 18, 'Tersedia', 'Motif tenun klasik Donggala kombinasi corak kotak berhias ragam hias keemasan.', 'assets/images/motif-suba.jpg'],
        // id=4: Batik Bomba Tulis Halus Sambulagana
        [2, 'BTK-SBG-004', 'Kain Batik Bomba Tulis Halus Sambulagana', 'Sambulagana', 'Katun Primissima Super', 320000, 680000, 15, 6, 10, 'Tersedia', 'Batik tulis tangan manual dengan canting halus, motif daun talas dan bunga Sambulagana khas Palu.', 'assets/images/motif-sambulagana.jpg'],
        // id=5: Batik Bomba Tulis Souraja (Rumah Adat)
        [2, 'BTK-SRJ-005', 'Kain Batik Bomba Tulis Motif Souraja', 'Souraja', 'Katun Primissima Halus', 350000, 750000, 6, 5, 12, 'Tersedia', 'Batik tulis eksklusif menampilkan filosofi arsitektur Souraja (istana raja Kaili tempo dulu).', 'assets/images/motif-souraja.jpg'],
        // id=6: Batik Bomba Tulis Bunga Cengkeh Alam
        [2, 'BTK-CGK-006', 'Kain Batik Bomba Tulis Pewarna Alam Cengkeh', 'Bunga Cengkeh', 'Katun Mori Organik', 380000, 820000, 3, 5, 14, 'Tersedia', 'Batik tulis pewarna alami dari ekstrak kulit kayu mahoni dan tingi dengan motif kuncup cengkeh.', 'assets/images/motif-cengkeh.jpg'],
        // id=7: Batik Bomba Tulis Sero Salo
        [2, 'BTK-SRS-007', 'Kain Batik Bomba Tulis Sero Salo', 'Sero Salo', 'Katun Primissima', 310000, 650000, 9, 4, 10, 'Tersedia', 'Motif Sero Salo melambangkan keharmonisan masyarakat pesisir teluk Palu dan lembah sungai.', 'assets/images/motif-serosalo.jpg'],
        // id=8: Batik Bomba Cap Sambulagana Dinamis
        [3, 'CAP-SBG-008', 'Kain Batik Bomba Cap Sambulagana', 'Sambulagana', 'Katun Santung Halus', 120000, 260000, 38, 15, 4, 'Tersedia', 'Batik cap presisi cocok untuk seragam kantor, instansi, atau perkumpulan adat.', 'assets/images/motif-sambulagana.jpg'],
        // id=9: Batik Bomba Cap Motif Rumah Tambi
        [3, 'CAP-TMB-009', 'Kain Batik Bomba Cap Rumah Tambi', 'Rumah Adat Tambi', 'Katun Primissima', 135000, 285000, 24, 12, 4, 'Tersedia', 'Batik cap dengan motif ikonik Rumah Tambi khas suku Lore dan Kaili Sulawesi Tengah.', 'assets/images/motif-tambi.jpg'],
        // id=10: Batik Bomba Cap Motif Bunga Sero
        [3, 'CAP-SER-010', 'Kain Batik Bomba Cap Geometris Sero', 'Sero Salo', 'Katun Sanforized', 115000, 240000, 19, 10, 3, 'Tersedia', 'Batik cap geometris berdaya tahan tinggi, warna cerah dan tajam.', 'assets/images/motif-serosalo.jpg'],
        // id=11: Kemeja Pria Batik Bomba Tulis Lengan Panjang
        [4, 'BSN-KML-011', 'Kemeja Pria Batik Bomba Tulis Lengan Panjang', 'Sambulagana & Taiganja', 'Katun Lapis Furing Silk', 420000, 890000, 12, 6, 7, 'Tersedia', 'Kemeja formal pria jahitan tailor rapi, pola motif nyambung dan lapis furing lembut.', 'assets/images/kemeja-bomba.jpg'],
        // id=12: Kemeja Pria Batik Bomba Cap Lengan Pendek
        [4, 'BSN-KMP-012', 'Kemeja Pria Batik Bomba Cap Lengan Pendek', 'Sambulagana', 'Katun Halus', 160000, 350000, 28, 10, 5, 'Tersedia', 'Kemeja kasual dan formal santai untuk kerja harian dinas maupun swasta.', 'assets/images/kemeja-bomba.jpg'],
        // id=13: Blouse Wanita Asimetris Batik Bomba
        [4, 'BSN-BLW-013', 'Blouse Wanita Modern Batik Bomba', 'Souraja Modern', 'Katun Kombinasi Tenun', 220000, 480000, 14, 8, 6, 'Tersedia', 'Busana wanita kontemporer perpaduan motif etnik Bomba dengan potongan kekinian.', 'assets/images/blouse-bomba.jpg'],
        // id=14: Selendang Tenun Sutra Donggala Halus
        [5, 'SLD-STR-014', 'Selendang Tenun Sutra Donggala Rumbai', 'Taiganja Halus', 'Sutra Alam Murni', 280000, 620000, 7, 5, 10, 'Tersedia', 'Selendang pelengkap busana kebaya dan adat dengan kilau alami sutra Sulawesi.', 'assets/images/selendang-tenun.jpg'],
        // id=15: Sarung Tenun Bomba Tradisional Palu
        [1, 'TNN-SRG-015', 'Sarung Tenun Bomba Songket Palu', 'Bomba Kota & Bunga', 'Katun Sutra Kombinasi', 450000, 950000, 5, 5, 16, 'Tersedia', 'Sarung tenun tradisional untuk upacara adat Kaili, pernikahan, dan perayaan daerah.', 'assets/images/sarung-tenun.jpg']
    ];

    $stmtProd = $pdo->prepare("INSERT INTO products (category_id, code, name, motif, material, production_cost, selling_price, stock_quantity, min_stock_threshold, lead_time_days, status, description, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($products as $p) {
        $stmtProd->execute($p);
    }

    // 6. Data Produksi Historis & Sedang Berjalan (Tahun 2026 dan akhir 2025)
    $productionStatuses = [
        'Selesai', 'Selesai', 'Selesai', 'Selesai', 'Quality Control',
        'Pewarnaan & Fiksasi', 'Pengerjaan (Tenun/Batik)', 'Persiapan Bahan'
    ];

    $batchNum = 101;
    $productions = [];

    // Buat data batch produksi realistis
    $productionPlan = [
        // [prod_id, art_id, target, actual, start_date, est_end, act_end, status, mat_cost, labor_cost, reject, notes]
        [1, 1, 6, 6, '2026-01-05', '2026-01-26', '2026-01-25', 'Selesai', 2100000, 1800000, 0, 'Tenun sutra Sambulagana selesai tepat waktu, kualitas serat grade A'],
        [2, 5, 4, 4, '2026-01-10', '2026-02-04', '2026-02-03', 'Selesai', 1600000, 1280000, 0, 'Kain tenun Taiganja pesanan instansi Pemprov Sulteng'],
        [4, 2, 12, 12, '2026-01-15', '2026-01-25', '2026-01-24', 'Selesai', 2100000, 1740000, 0, 'Batik tulis Sambulagana batch pertama tahun 2026'],
        [8, 3, 40, 39, '2026-01-20', '2026-01-24', '2026-01-24', 'Selesai', 2600000, 2200000, 1, 'Batik cap seragam dinas, 1 pcs cacat cetak pada pinggiran'],
        [11, 7, 15, 15, '2026-02-01', '2026-02-08', '2026-02-07', 'Selesai', 3400000, 2900000, 0, 'Kemeja pria lengan panjang tenun & batik Bomba'],
        [3, 4, 8, 8, '2026-02-05', '2026-02-23', '2026-02-22', 'Selesai', 2600000, 2040000, 0, 'Tenun Suba Donggala pesanan pameran Jakarta Inacraft'],
        [5, 2, 8, 8, '2026-02-10', '2026-02-22', '2026-02-21', 'Selesai', 1500000, 1300000, 0, 'Batik tulis motif Souraja Kaili'],
        [9, 3, 30, 30, '2026-02-15', '2026-02-19', '2026-02-19', 'Selesai', 2200000, 1850000, 0, 'Batik cap Rumah Tambi permintaan galeri Palu'],
        [12, 7, 25, 25, '2026-03-01', '2026-03-06', '2026-03-05', 'Selesai', 2300000, 1700000, 0, 'Kemeja cap kasual edisi Ramadhan'],
        [6, 2, 6, 6, '2026-03-05', '2026-03-19', '2026-03-18', 'Selesai', 1300000, 980000, 0, 'Batik tulis pewarna alam cengkeh ramah lingkungan'],
        [1, 8, 5, 5, '2026-03-10', '2026-03-31', '2026-03-30', 'Selesai', 1800000, 1450000, 0, 'Penenunan tenun Sambulagana halus'],
        [8, 3, 50, 49, '2026-04-02', '2026-04-06', '2026-04-06', 'Selesai', 3200000, 2800000, 1, 'Peningkatan kuota cap menjelang HUT Kota Palu'],
        [14, 1, 10, 10, '2026-04-10', '2026-04-20', '2026-04-19', 'Selesai', 1500000, 1300000, 0, 'Selendang tenun sutra untuk souvenir VVIP'],
        [2, 5, 5, 5, '2026-05-02', '2026-05-27', '2026-05-26', 'Selesai', 2000000, 1600000, 0, 'Tenun Taiganja benang sutra emas'],
        [4, 2, 14, 14, '2026-05-12', '2026-05-22', '2026-05-22', 'Selesai', 2400000, 2080000, 0, 'Batik tulis Sambulagana warna navy & maroon'],
        [11, 7, 18, 18, '2026-06-01', '2026-06-08', '2026-06-08', 'Selesai', 4000000, 3560000, 0, 'Kemeja tenun pria formal'],
        [7, 2, 10, 10, '2026-06-15', '2026-06-25', '2026-06-25', 'Selesai', 1700000, 1400000, 0, 'Batik tulis Sero Salo'],
        [9, 3, 35, 34, '2026-07-05', '2026-07-09', '2026-07-09', 'Selesai', 2500000, 2225000, 1, 'Batik cap Tambi untuk seragam sekolah & instansi'],
        [1, 1, 6, 6, '2026-08-01', '2026-08-22', '2026-08-21', 'Selesai', 2100000, 1800000, 0, 'Tenun sutra edisi Kemerdekaan'],
        [13, 7, 20, 20, '2026-08-15', '2026-08-21', '2026-08-21', 'Selesai', 2400000, 2000000, 0, 'Blouse asimetris wanita'],
        [3, 4, 8, 8, '2026-09-02', '2026-09-20', '2026-09-19', 'Selesai', 2600000, 2040000, 0, 'Tenun Suba Donggala'],
        [8, 3, 45, 45, '2026-09-18', '2026-09-22', '2026-09-22', 'Selesai', 2900000, 2500000, 0, 'Batik cap Sambulagana untuk pameran regional'],
        [2, 5, 5, 5, '2026-10-01', '2026-10-26', '2026-10-25', 'Selesai', 2000000, 1600000, 0, 'Tenun Taiganja sutra berkualitas tinggi'],
        [4, 2, 12, 12, '2026-10-15', '2026-10-25', '2026-10-24', 'Selesai', 2100000, 1740000, 0, 'Batik tulis Sambulagana'],
        
        // Batch yang sedang berjalan di bulan November 2026 (aktif saat ini)
        [2, 5, 6, 0, '2026-11-01', '2026-11-26', null, 'Pengerjaan (Tenun/Batik)', 2400000, 1920000, 0, 'Proses penenunan ATBM kain tenun Taiganja (65% selesai)'],
        [6, 2, 8, 0, '2026-11-08', '2026-11-22', null, 'Pewarnaan & Fiksasi', 1600000, 1440000, 0, 'Proses fiksasi zat warna alam mahoni pada motif Cengkeh'],
        [1, 1, 5, 0, '2026-11-05', '2026-11-26', null, 'Quality Control', 1750000, 1500000, 0, 'Pemeriksaan kerapatan tenunan & kelembutan sutra'],
        [8, 3, 50, 0, '2026-11-14', '2026-11-18', null, 'Quality Control', 3200000, 2800000, 0, 'Finishing pencucian dan setrika uap batik cap'],
        [11, 7, 20, 0, '2026-11-15', '2026-11-22', null, 'Pengerjaan (Tenun/Batik)', 4400000, 4000000, 0, 'Pemotongan pola dan penjahitan kemeja motif Sambulagana'],
        [15, 8, 8, 0, '2026-11-18', '2026-12-04', null, 'Persiapan Bahan', 2000000, 1600000, 0, 'Pewarnaan benang lusi dan pemasangan pada ATBM']
    ];

    $stmtProduction = $pdo->prepare("INSERT INTO productions 
        (batch_code, product_id, artisan_id, target_quantity, actual_quantity, start_date, estimated_end_date, actual_end_date, status, material_cost, labor_cost, total_cost, reject_quantity, notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($productionPlan as $plan) {
        $batchCode = 'PRD-' . date('ym', strtotime($plan[4])) . '-' . sprintf('%03d', $batchNum++);
        $totalCost = $plan[8] + $plan[9];
        $stmtProduction->execute([
            $batchCode, $plan[0], $plan[1], $plan[2], $plan[3], $plan[4], $plan[5], $plan[6], $plan[7], $plan[8], $plan[9], $totalCost, $plan[10], $plan[11]
        ]);
    }

    // 7. Data Penjualan (12 Bulan Terakhir dari Des 2025 s.d. Nov 2026)
    $customerNames = [
        'Drs. H. Rusdi Mastura (Pemprov Sulteng)', 'Ibu Hj. Anita B. Wardhani (Dekranasda)', 
        'dr. Reny Lamadjido (Dinas Kesehatan)', 'Bpk. Ahmad Rifai (Kolektor Tenun)',
        'Ibu Christine Mandagi (Tamu Hotel Best Western)', 'Ibu Ratna Dewi (Toko Busana Palu)',
        'PT. Citra Palu Minerals (Souvenir Perusahaan)', 'Bank Indonesia Perwakilan Sulteng',
        'Bpk. Moh. Faisal (Pesanan Pesta Adat)', 'Ibu Dra. Syarifah M.', 'Bpk. Ir. Hendro Santoso',
        'Ibu Maya Indriyani (Pembeli Online Jakarta)', 'Ibu Dian Sastro (Kolektor Batik Wastra)',
        'Bpk. H. Basir (Dinas Pariwisata Sulteng)', 'Ibu Endang S. (Sanggar Seni Tradisional)'
    ];

    $channels = ['Galeri Utama Palu', 'Pameran Dekranasda', 'Online Store / Medsos', 'Pesanan Khusus Pemda'];
    $customerTypes = ['Perorangan', 'Instansi Pemerintah', 'Kolektor Budaya', 'Toko Reseller'];
    $paymentMethods = ['Transfer Bank', 'QRIS / E-Wallet', 'Tunai (Galeri)', 'Termin / SPK Dinas'];

    // Ambil data produk untuk hitung harga jual & HPP
    $prodList = $pdo->query("SELECT id, name, production_cost, selling_price, stock_quantity FROM products")->fetchAll();
    $prodMap = [];
    foreach ($prodList as $p) {
        $prodMap[$p['id']] = $p;
    }

    $stmtSale = $pdo->prepare("INSERT INTO sales 
        (invoice_number, sale_date, customer_name, customer_type, sales_channel, total_amount, total_cost, total_profit, payment_method, status, notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtItem = $pdo->prepare("INSERT INTO sale_items 
        (sale_id, product_id, quantity, unit_price, cost_price, subtotal, profit) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");

    $invCounter = 1001;

    // Pola tren penjualan per bulan (Desember 2025 sampai November 2026)
    $months = [
        '2025-12' => 12, '2026-01' => 10, '2026-02' => 11, '2026-03' => 14,
        '2026-04' => 18, '2026-05' => 15, '2026-06' => 13, '2026-07' => 16,
        '2026-08' => 20, '2026-09' => 17, '2026-10' => 19, '2026-11' => 15
    ];

    foreach ($months as $yearMonth => $transCount) {
        for ($i = 0; $i < $transCount; $i++) {
            $day = sprintf('%02d', mt_rand(1, 28));
            $saleDate = "$yearMonth-$day";
            $custName = $customerNames[array_rand($customerNames)];
            $channel = $channels[array_rand($channels)];
            $cType = $customerTypes[array_rand($customerTypes)];
            $payMethod = $paymentMethods[array_rand($paymentMethods)];
            $invNum = 'INV-' . str_replace('-', '', $yearMonth) . '-' . $invCounter++;

            // Tentukan item dalam transaksi (1-3 jenis barang)
            $itemCount = mt_rand(1, 3);
            $selectedProdIds = (array)array_rand($prodMap, $itemCount);

            $saleTotalAmount = 0;
            $saleTotalCost = 0;
            $saleTotalProfit = 0;
            $itemsToInsert = [];

            foreach ($selectedProdIds as $pId) {
                $pData = $prodMap[$pId];
                // Kuantitas: produk cap bisa lebih banyak (2-10), tenun/tulis halus (1-3)
                $qty = ($pData['selling_price'] < 400000) ? mt_rand(2, 8) : mt_rand(1, 3);
                $unitPrice = $pData['selling_price'];
                $costPrice = $pData['production_cost'];
                $subtotal = $unitPrice * $qty;
                $itemProfit = ($unitPrice - $costPrice) * $qty;
                $costSubtotal = $costPrice * $qty;

                $saleTotalAmount += $subtotal;
                $saleTotalCost += $costSubtotal;
                $saleTotalProfit += $itemProfit;

                $itemsToInsert[] = [
                    'product_id' => $pId,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'cost_price' => $costPrice,
                    'subtotal' => $subtotal,
                    'profit' => $itemProfit
                ];
            }

            // Insert Sale
            $stmtSale->execute([
                $invNum, $saleDate, $custName, $cType, $channel,
                $saleTotalAmount, $saleTotalCost, $saleTotalProfit,
                $payMethod, 'Lunas', 'Transaksi resmi Batik Bomba Palu'
            ]);
            $saleId = $pdo->lastInsertId();

            // Insert Sale Items
            foreach ($itemsToInsert as $item) {
                $stmtItem->execute([
                    $saleId, $item['product_id'], $item['quantity'],
                    $item['unit_price'], $item['cost_price'], $item['subtotal'], $item['profit']
                ]);
            }
        }
    }

    // 8. Data Rekomendasi Cerdas / Decision Support Awal
    $recommendations = [
        [
            'REKOMENDASI_PRODUKSI',
            'High',
            'Tingkatkan Kapasitas Tenun Sutra Sambulagana',
            'Permintaan Kain Tenun Sutra Sambulagana (TNN-SBG-001) melonjak 34% dalam 2 bulan terakhir, sementara sisa stok hanya 8 lembar dengan lead-time tenun 21 hari.',
            'Segera alokasikan minimal 2 penenun ATBM (Ibu Maryam & Ibu Hasnah) untuk memulai batch tenun baru sebanyak 10 lembar.',
            1
        ],
        [
            'STOK_KRITIS',
            'High',
            'Peringatan Stok Kritis: Tenun Taiganja Pusaka & Batik Cengkeh',
            'Stok Kain Tenun Sutra Taiganja (TNN-TGJ-002) sisa 4 lembar dan Batik Cengkeh (BTK-CGK-006) sisa 3 lembar, mendekati batas minimum stok.',
            'Lakukan pemesanan bahan benang sutra emas dan percepat proses fiksasi pewarna alam untuk batch berjalan.',
            2
        ],
        [
            'OPTIMASI_HARGA',
            'Medium',
            'Potensi Margin Tinggi pada Kemeja Batik Sutra Lengan Panjang',
            'Kemeja pria motif Sambulagana memiliki margin profit 52.8% dan rata-rata terjual habis dalam waktu 12 hari setelah masuk galeri.',
            'Pertahankan harga jual premium dan siapkan varian ukuran XL & XXL untuk memenuhi permintaan tamu kedinasan.',
            11
        ],
        [
            'SLOW_MOVING',
            'Low',
            'Evaluasi Perputaran Stok: Kain Tenun Suba Donggala',
            'Perputaran stok Tenun Suba melambat dalam 45 hari terakhir. Terdapat 11 lembar di galeri dengan perputaran 0.4x per bulan.',
            'Buat bundel promosi paket kain tenun + selendang untuk acara perkawinan adat, atau tawarkan ke pameran Dekranasda.',
            3
        ]
    ];

    $stmtRec = $pdo->prepare("INSERT INTO decision_recommendations (type, priority, title, description, suggested_action, product_id, status) VALUES (?, ?, ?, ?, ?, ?, 'Aktif')");
    foreach ($recommendations as $rec) {
        $stmtRec->execute($rec);
    }
}
