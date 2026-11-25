<?php
/**
 * Backend API & Form Actions Handler
 * BOMBA ANALYTICS
 */

require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

$action = $_REQUEST['action'] ?? '';

// 1. TAMBAH TRANSAKSI PENJUALAN
if ($action === 'create_sale') {
    try {
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerType = $_POST['customer_type'] ?? 'Perorangan';
        $salesChannel = $_POST['sales_channel'] ?? 'Galeri Utama Palu';
        $paymentMethod = $_POST['payment_method'] ?? 'Transfer Bank';
        $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);

        if (empty($customerName) || $productId <= 0 || $quantity <= 0) {
            header("Location: sales.php?msg=" . urlencode("Gagal: Mohon lengkapi seluruh formulir transaksi.") . "&type=error");
            exit;
        }

        // Ambil info produk
        $stmtProd = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmtProd->execute([$productId]);
        $product = $stmtProd->fetch();

        if (!$product) {
            header("Location: sales.php?msg=" . urlencode("Gagal: Produk tidak ditemukan.") . "&type=error");
            exit;
        }

        if ($product['stock_quantity'] < $quantity) {
            header("Location: sales.php?msg=" . urlencode("Gagal: Stok tidak mencukupi. Sisa stok: " . $product['stock_quantity'] . " pcs.") . "&type=error");
            exit;
        }

        $unitPrice = (float)$product['selling_price'];
        $costPrice = (float)$product['production_cost'];
        $totalAmount = $unitPrice * $quantity;
        $totalCost = $costPrice * $quantity;
        $totalProfit = $totalAmount - $totalCost;

        $pdo->beginTransaction();

        // Generate Nomor Invoice Unik
        $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        // Insert Sales
        $stmtSale = $pdo->prepare("INSERT INTO sales (invoice_number, sale_date, customer_name, customer_type, sales_channel, total_amount, total_cost, total_profit, payment_method, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Lunas', ?)");
        $stmtSale->execute([$invoiceNumber, $saleDate, $customerName, $customerType, $salesChannel, $totalAmount, $totalCost, $totalProfit, $paymentMethod, $notes]);
        $saleId = $pdo->lastInsertId();

        // Insert Sale Item
        $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, cost_price, subtotal, profit) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtItem->execute([$saleId, $productId, $quantity, $unitPrice, $costPrice, $totalAmount, $totalProfit]);

        // Kurangi Stok Produk
        $stmtStock = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
        $stmtStock->execute([$quantity, $productId]);

        $pdo->commit();

        header("Location: sales.php?msg=" . urlencode("Transaksi $invoiceNumber berhasil dicatat! Stok otomatis disesuaikan.") . "&type=success");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: sales.php?msg=" . urlencode("Terjadi kesalahan: " . $e->getMessage()) . "&type=error");
        exit;
    }
}

// 2. TAMBAH BATCH PRODUKSI BARU
if ($action === 'create_production') {
    try {
        $productId = (int)($_POST['product_id'] ?? 0);
        $artisanId = (int)($_POST['artisan_id'] ?? 0);
        $targetQty = (int)($_POST['target_quantity'] ?? 0);
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $estEndDate = $_POST['estimated_end_date'] ?? date('Y-m-d', strtotime('+14 days'));
        $materialCost = (float)($_POST['material_cost'] ?? 0);
        $laborCost = (float)($_POST['labor_cost'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($productId <= 0 || $artisanId <= 0 || $targetQty <= 0) {
            header("Location: production.php?msg=" . urlencode("Gagal: Mohon lengkapi formulir batch produksi.") . "&type=error");
            exit;
        }

        $totalCost = $materialCost + $laborCost;
        $batchCode = 'PRD-' . date('ym') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $stmt = $pdo->prepare("INSERT INTO productions (batch_code, product_id, artisan_id, target_quantity, actual_quantity, start_date, estimated_end_date, status, material_cost, labor_cost, total_cost, reject_quantity, notes) VALUES (?, ?, ?, ?, 0, ?, ?, 'Persiapan Bahan', ?, ?, ?, 0, ?)");
        $stmt->execute([$batchCode, $productId, $artisanId, $targetQty, $startDate, $estEndDate, $materialCost, $laborCost, $totalCost, $notes]);

        header("Location: production.php?msg=" . urlencode("Batch Produksi $batchCode berhasil dibuat!") . "&type=success");
        exit;
    } catch (Exception $e) {
        header("Location: production.php?msg=" . urlencode("Gagal: " . $e->getMessage()) . "&type=error");
        exit;
    }
}

// 3. UPDATE STATUS BATCH PRODUKSI
if ($action === 'update_production_status') {
    try {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $actualQty = (int)($_POST['actual_quantity'] ?? 0);
        $rejectQty = (int)($_POST['reject_quantity'] ?? 0);

        $allowedStatuses = ['Persiapan Bahan', 'Pengerjaan (Tenun/Batik)', 'Pewarnaan & Fiksasi', 'Quality Control', 'Selesai'];
        if (!in_array($newStatus, $allowedStatuses) || $batchId <= 0) {
            header("Location: production.php?msg=" . urlencode("Status tidak valid.") . "&type=error");
            exit;
        }

        $pdo->beginTransaction();

        $stmtBatch = $pdo->prepare("SELECT * FROM productions WHERE id = ?");
        $stmtBatch->execute([$batchId]);
        $batch = $stmtBatch->fetch();

        if (!$batch) {
            header("Location: production.php?msg=" . urlencode("Batch tidak ditemukan.") . "&type=error");
            exit;
        }

        $actualEndDate = ($newStatus === 'Selesai') ? date('Y-m-d') : $batch['actual_end_date'];
        $qtySelesai = ($actualQty > 0) ? $actualQty : $batch['target_quantity'];

        $stmtUpdate = $pdo->prepare("UPDATE productions SET status = ?, actual_quantity = ?, reject_quantity = ?, actual_end_date = ? WHERE id = ?");
        $stmtUpdate->execute([$newStatus, $qtySelesai, $rejectQty, $actualEndDate, $batchId]);

        // Jika status baru adalah 'Selesai' dan sebelumnya belum selesai, tambahkan stok ke produk
        if ($newStatus === 'Selesai' && $batch['status'] !== 'Selesai') {
            $stmtAddStock = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
            $stmtAddStock->execute([$qtySelesai, $batch['product_id']]);
        }

        $pdo->commit();
        header("Location: production.php?msg=" . urlencode("Status Batch " . $batch['batch_code'] . " berhasil diperbarui menjadi $newStatus!") . "&type=success");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: production.php?msg=" . urlencode("Gagal update status: " . $e->getMessage()) . "&type=error");
        exit;
    }
}

// 4. TAMBAH ATAU EDIT PRODUK
if ($action === 'save_product') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 1);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $motif = trim($_POST['motif'] ?? '');
        $material = trim($_POST['material'] ?? '');
        $cost = (float)($_POST['production_cost'] ?? 0);
        $price = (float)($_POST['selling_price'] ?? 0);
        $stock = (int)($_POST['stock_quantity'] ?? 0);
        $minStock = (int)($_POST['min_stock_threshold'] ?? 5);
        $leadTime = (int)($_POST['lead_time_days'] ?? 7);
        $desc = trim($_POST['description'] ?? '');

        if (empty($code) || empty($name) || empty($motif) || $price <= 0) {
            header("Location: products.php?msg=" . urlencode("Gagal: Mohon lengkapi field wajib produk.") . "&type=error");
            exit;
        }

        if ($id > 0) {
            // Update
            $stmt = $pdo->prepare("UPDATE products SET category_id = ?, code = ?, name = ?, motif = ?, material = ?, production_cost = ?, selling_price = ?, stock_quantity = ?, min_stock_threshold = ?, lead_time_days = ?, description = ? WHERE id = ?");
            $stmt->execute([$categoryId, $code, $name, $motif, $material, $cost, $price, $stock, $minStock, $leadTime, $desc, $id]);
            $msg = "Produk $name berhasil diperbarui!";
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO products (category_id, code, name, motif, material, production_cost, selling_price, stock_quantity, min_stock_threshold, lead_time_days, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$categoryId, $code, $name, $motif, $material, $cost, $price, $stock, $minStock, $leadTime, $desc]);
            $msg = "Produk baru $name berhasil ditambahkan ke katalog!";
        }

        header("Location: products.php?msg=" . urlencode($msg) . "&type=success");
        exit;
    } catch (Exception $e) {
        header("Location: products.php?msg=" . urlencode("Gagal simpan produk: " . $e->getMessage()) . "&type=error");
        exit;
    }
}

// 5. EXPORT CSV (SALES ATAU PRODUCTION)
if ($action === 'export_csv') {
    $type = $_GET['type'] ?? 'sales';

    if ($type === 'sales') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Laporan_Penjualan_Batik_Bomba_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        // BOM untuk Excel UTF-8
        fputs($output, "\xEF\xBB\xBF");
        
        fputcsv($output, ['No Invoice', 'Tanggal', 'Nama Pelanggan', 'Tipe Pelanggan', 'Saluran Penjualan', 'Total Nilai (Rp)', 'Total HPP (Rp)', 'Laba Kotor (Rp)', 'Metode Bayar', 'Status']);

        $sales = $pdo->query("SELECT invoice_number, sale_date, customer_name, customer_type, sales_channel, total_amount, total_cost, total_profit, payment_method, status FROM sales ORDER BY sale_date DESC")->fetchAll();
        foreach ($sales as $s) {
            fputcsv($output, [
                $s['invoice_number'], $s['sale_date'], $s['customer_name'], $s['customer_type'],
                $s['sales_channel'], $s['total_amount'], $s['total_cost'], $s['total_profit'],
                $s['payment_method'], $s['status']
            ]);
        }
        fclose($output);
        exit;
    } elseif ($type === 'production') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Laporan_Produksi_Batik_Bomba_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF");

        fputcsv($output, ['Kode Batch', 'Produk', 'Motif', 'Pengrajin/Penenun', 'Target Qty', 'Qty Selesai', 'Cacat/Reject', 'Tgl Mulai', 'Tgl Estimasi Selesai', 'Tgl Aktual Selesai', 'Status', 'Total Biaya (Rp)']);

        $prods = $pdo->query("SELECT pr.batch_code, p.name as product_name, p.motif, a.name as artisan_name, pr.target_quantity, pr.actual_quantity, pr.reject_quantity, pr.start_date, pr.estimated_end_date, pr.actual_end_date, pr.status, pr.total_cost FROM productions pr JOIN products p ON pr.product_id = p.id JOIN artisans a ON pr.artisan_id = a.id ORDER BY pr.id DESC")->fetchAll();
        foreach ($prods as $p) {
            fputcsv($output, [
                $p['batch_code'], $p['product_name'], $p['motif'], $p['artisan_name'],
                $p['target_quantity'], $p['actual_quantity'], $p['reject_quantity'],
                $p['start_date'], $p['estimated_end_date'], $p['actual_end_date'] ?? '-',
                $p['status'], $p['total_cost']
            ]);
        }
        fclose($output);
        exit;
    }
}

// Default response jika invalid
echo json_encode(['status' => 'error', 'message' => 'Aksi tidak dikenali']);
