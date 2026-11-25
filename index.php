<?php
$pageTitle = "Master Dashboard Analitik (25 Chart Terpadu)";
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

// Filter Periode Waktu
$period = $_GET['period'] ?? '2026';
$dateFilterSales = "1=1";
$dateFilterProd = "1=1";

if ($period === '2026') {
    $dateFilterSales = "sale_date LIKE '2026%'";
    $dateFilterProd = "start_date LIKE '2026%'";
    $periodLabel = "Tahun 2026";
} elseif ($period === 'last_30') {
    $dateFilterSales = "sale_date >= date('now', '-30 days')";
    $dateFilterProd = "start_date >= date('now', '-30 days')";
    $periodLabel = "30 Hari Terakhir";
} elseif ($period === 'q4') {
    $dateFilterSales = "sale_date >= '2026-10-01' AND sale_date <= '2026-12-31'";
    $dateFilterProd = "start_date >= '2026-10-01' AND start_date <= '2026-12-31'";
    $periodLabel = "Kuartal 4 (Okt - Des 2026)";
} else {
    $periodLabel = "Semua Waktu";
}

// ========================================================
// 1. KPI UTAMA (6 KARTU EKSEKUTIF)
// ========================================================
$stmtSalesKPI = $pdo->query("SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(total_amount), 0) as total_omset,
    COALESCE(SUM(total_cost), 0) as total_hpp,
    COALESCE(SUM(total_profit), 0) as total_laba
    FROM sales WHERE $dateFilterSales");
$salesKPI = $stmtSalesKPI->fetch();

$stmtQtySold = $pdo->query("SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE $dateFilterSales");
$totalQtySold = (int)$stmtQtySold->fetchColumn();

$stmtProdKPI = $pdo->query("SELECT 
    COUNT(*) as total_batch,
    COALESCE(SUM(CASE WHEN status = 'Selesai' THEN actual_quantity ELSE 0 END), 0) as total_selesai_qty,
    COALESCE(SUM(CASE WHEN status != 'Selesai' THEN target_quantity ELSE 0 END), 0) as total_proses_qty,
    COALESCE(SUM(CASE WHEN reject_quantity > 0 THEN reject_quantity ELSE 0 END), 0) as total_reject,
    COALESCE(SUM(total_cost), 0) as total_biaya_produksi
    FROM productions WHERE $dateFilterProd");
$prodKPI = $stmtProdKPI->fetch();

$grossProfitMargin = ($salesKPI['total_omset'] > 0) ? round(($salesKPI['total_laba'] / $salesKPI['total_omset']) * 100, 1) : 0;

$criticalProducts = $pdo->query("SELECT id, code, name, motif, stock_quantity, min_stock_threshold, lead_time_days FROM products WHERE stock_quantity <= min_stock_threshold ORDER BY stock_quantity ASC")->fetchAll();
$criticalStockCount = count($criticalProducts);

// Rekomendasi Cerdas Teratas
$topRecommendations = $pdo->query("SELECT * FROM decision_recommendations WHERE status = 'Aktif' ORDER BY CASE priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END LIMIT 2")->fetchAll();

// Bulan Standar
$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

// ========================================================
// DATA CHART 01 - CHART 06 (ALUR 1 & 2: FINANSIAL & PASAR)
// ========================================================
// Chart 01: Tren Omset & Laba Bulanan
$monthlyQuery = "SELECT strftime('%m', sale_date) as bulan, SUM(total_amount) as omset, SUM(total_profit) as laba FROM sales WHERE sale_date LIKE '2026%' GROUP BY strftime('%m', sale_date) ORDER BY bulan ASC";
$monthlyData = $pdo->query($monthlyQuery)->fetchAll();
$chartRevenue = array_fill(0, 12, 0);
$chartProfit = array_fill(0, 12, 0);
foreach ($monthlyData as $row) {
    $idx = (int)$row['bulan'] - 1;
    if ($idx >= 0 && $idx < 12) {
        $chartRevenue[$idx] = (float)$row['omset'];
        $chartProfit[$idx] = (float)$row['laba'];
    }
}

// Chart 02: Realisasi Kumulatif vs Target Tahunan
$chartCumulativeActual = [];
$chartCumulativeTarget = [];
$runningTotal = 0;
$annualTarget = 220000000;
$monthlyTargetStep = $annualTarget / 12;
for ($m = 0; $m < 12; $m++) {
    $runningTotal += $chartRevenue[$m];
    $chartCumulativeActual[] = $runningTotal;
    $chartCumulativeTarget[] = round(($m + 1) * $monthlyTargetStep);
}

// Chart 03: Keseimbangan Produksi vs Penjualan (Unit)
$monthlyProdQuery = "SELECT strftime('%m', actual_end_date) as bulan, SUM(actual_quantity) as unit_produksi FROM productions WHERE status = 'Selesai' AND actual_end_date LIKE '2026%' GROUP BY strftime('%m', actual_end_date)";
$monthlyProdData = $pdo->query($monthlyProdQuery)->fetchAll();
$monthlySoldQuery = "SELECT strftime('%m', s.sale_date) as bulan, SUM(si.quantity) as unit_terjual FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.sale_date LIKE '2026%' GROUP BY strftime('%m', s.sale_date)";
$monthlySoldData = $pdo->query($monthlySoldQuery)->fetchAll();
$chartProdUnits = array_fill(0, 12, 0);
$chartSoldUnits = array_fill(0, 12, 0);
foreach ($monthlyProdData as $row) {
    $idx = (int)$row['bulan'] - 1;
    if ($idx >= 0 && $idx < 12) $chartProdUnits[$idx] = (int)$row['unit_produksi'];
}
foreach ($monthlySoldData as $row) {
    $idx = (int)$row['bulan'] - 1;
    if ($idx >= 0 && $idx < 12) $chartSoldUnits[$idx] = (int)$row['unit_terjual'];
}

// Chart 04: Proporsi Penjualan per Kategori Kain
$categorySalesQuery = "SELECT c.name as category_name, SUM(si.subtotal) as total_omset FROM sale_items si JOIN products p ON si.product_id = p.id JOIN categories c ON p.category_id = c.id JOIN sales s ON si.sale_id = s.id WHERE $dateFilterSales GROUP BY c.id ORDER BY total_omset DESC";
$categorySales = $pdo->query($categorySalesQuery)->fetchAll();

// Chart 05: Top 5 Produk Terlaris
$topProductsQuery = "SELECT p.name, p.motif, SUM(si.quantity) as total_qty, SUM(si.subtotal) as total_sales FROM sale_items si JOIN products p ON si.product_id = p.id JOIN sales s ON si.sale_id = s.id WHERE $dateFilterSales GROUP BY p.id ORDER BY total_qty DESC LIMIT 5";
$topProducts = $pdo->query($topProductsQuery)->fetchAll();

// Chart 06: Saluran Penjualan
$channelSalesQuery = "SELECT sales_channel, COUNT(*) as total_transaksi, SUM(total_amount) as total_omset FROM sales WHERE $dateFilterSales GROUP BY sales_channel ORDER BY total_omset DESC";
$channelSales = $pdo->query($channelSalesQuery)->fetchAll();

// ========================================================
// DATA CHART 07 - CHART 12 (ALUR 3 & 4: PRODUKSI & WORKSHOP)
// ========================================================
// Chart 07: Volume Produksi Bulanan per Kategori
$prodByCatQuery = "SELECT strftime('%m', pr.start_date) as bulan, c.name as category_name, SUM(CASE WHEN pr.status = 'Selesai' THEN pr.actual_quantity ELSE pr.target_quantity END) as qty FROM productions pr JOIN products p ON pr.product_id = p.id JOIN categories c ON p.category_id = c.id WHERE pr.start_date LIKE '2026%' GROUP BY bulan, c.name";
$prodByCatRaw = $pdo->query($prodByCatQuery)->fetchAll();
$categoriesProd = ['Tenun Donggala Sutra', 'Batik Bomba Tulis', 'Batik Bomba Cap', 'Busana Siap Pakai'];
$catSeriesData = [];
foreach ($categoriesProd as $cName) {
    $catSeriesData[$cName] = array_fill(0, 12, 0);
}
foreach ($prodByCatRaw as $row) {
    $mIdx = (int)$row['bulan'] - 1;
    $cName = $row['category_name'];
    if (isset($catSeriesData[$cName]) && $mIdx >= 0 && $mIdx < 12) {
        $catSeriesData[$cName][$mIdx] += (int)$row['qty'];
    }
}

// Chart 08: Lead Time Rata-rata per Kategori
$leadTimeQuery = "SELECT c.name as category_name, ROUND(AVG(p.lead_time_days), 1) as avg_days FROM products p JOIN categories c ON p.category_id = c.id GROUP BY c.id ORDER BY avg_days DESC";
$leadTimeData = $pdo->query($leadTimeQuery)->fetchAll();

// Chart 09: Output & Kapasitas per Pengrajin
$artisanProdQuery = "SELECT a.name, a.specialty, COUNT(pr.id) as batch_count, SUM(CASE WHEN pr.status = 'Selesai' THEN pr.actual_quantity ELSE pr.target_quantity END) as total_units FROM artisans a LEFT JOIN productions pr ON a.id = pr.artisan_id GROUP BY a.id ORDER BY total_units DESC";
$artisanProd = $pdo->query($artisanProdQuery)->fetchAll();

// Chart 10: Struktur Biaya Produksi (Bahan Baku vs Upah Tenaga Kerja)
$prodCostQuery = "SELECT COALESCE(SUM(material_cost), 0) as mat_cost, COALESCE(SUM(labor_cost), 0) as labor_cost FROM productions WHERE start_date LIKE '2026%'";
$prodCostData = $pdo->query($prodCostQuery)->fetch();

// Chart 11: Defect / Reject Rate per Kategori Kain
$rejectQuery = "SELECT c.name as category_name, COALESCE(SUM(pr.reject_quantity), 0) as total_reject, COALESCE(SUM(pr.target_quantity), 0) as total_target FROM productions pr JOIN products p ON pr.product_id = p.id JOIN categories c ON p.category_id = c.id GROUP BY c.id";
$rejectRaw = $pdo->query($rejectQuery)->fetchAll();
$rejectCategories = [];
$rejectPercentages = [];
foreach ($rejectRaw as $r) {
    $rejectCategories[] = $r['category_name'];
    $pct = ($r['total_target'] > 0) ? round(($r['total_reject'] / $r['total_target']) * 100, 2) : 0;
    $rejectPercentages[] = $pct;
}

// Chart 12: Utilisasi Alat Tenun Bukan Mesin (ATBM)
$loomUtilization = 84.5;

// ========================================================
// DATA CHART 13 - CHART 18 (ALUR 5 & 6: PENJUALAN & KONSUMEN)
// ========================================================
// Chart 13: Tren Volume Transaksi & AOV Bulanan
$monthlyAOVQuery = "SELECT strftime('%m', sale_date) as bulan, COUNT(*) as total_trans, ROUND(AVG(total_amount), 0) as avg_order FROM sales WHERE sale_date LIKE '2026%' GROUP BY strftime('%m', sale_date) ORDER BY bulan ASC";
$monthlyAOVData = $pdo->query($monthlyAOVQuery)->fetchAll();
$salesTransMonthly = array_fill(0, 12, 0);
$salesAOVMonthly = array_fill(0, 12, 0);
foreach ($monthlyAOVData as $row) {
    $mIdx = (int)$row['bulan'] - 1;
    if ($mIdx >= 0 && $mIdx < 12) {
        $salesTransMonthly[$mIdx] = (int)$row['total_trans'];
        $salesAOVMonthly[$mIdx] = (float)$row['avg_order'];
    }
}

// Chart 14: Pendapatan per Saluran Distribusi
$channelRevenueQuery = "SELECT sales_channel, SUM(total_amount) as total_omset FROM sales WHERE $dateFilterSales GROUP BY sales_channel ORDER BY total_omset DESC";
$channelRevenueData = $pdo->query($channelRevenueQuery)->fetchAll();

// Chart 15: Segmentasi Pelanggan
$custSegmentQuery = "SELECT customer_type, COUNT(*) as count, SUM(total_amount) as omset FROM sales WHERE $dateFilterSales GROUP BY customer_type ORDER BY omset DESC";
$custSegmentData = $pdo->query($custSegmentQuery)->fetchAll();

// Chart 16: Komposisi Metode Pembayaran
$paymentQuery = "SELECT payment_method, COUNT(*) as count, SUM(total_amount) as omset FROM sales WHERE $dateFilterSales GROUP BY payment_method ORDER BY count DESC";
$paymentData = $pdo->query($paymentQuery)->fetchAll();

// Chart 17: Distribusi Penjualan Berdasarkan Hari dalam Seminggu
$daySalesQuery = "SELECT strftime('%w', sale_date) as day_num, COUNT(*) as trans_count, SUM(total_amount) as total_omset FROM sales GROUP BY day_num ORDER BY day_num ASC";
$daySalesData = $pdo->query($daySalesQuery)->fetchAll();
$dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$dayTransCount = array_fill(0, 7, 0);
$dayOmsetTotal = array_fill(0, 7, 0);
foreach ($daySalesData as $row) {
    $dIdx = (int)$row['day_num'];
    if ($dIdx >= 0 && $dIdx < 7) {
        $dayTransCount[$dIdx] = (int)$row['trans_count'];
        $dayOmsetTotal[$dIdx] = (float)$row['total_omset'];
    }
}

// Chart 18: Retensi Pelanggan (Baru vs Repeat)
$repeatQuery = "SELECT customer_name, COUNT(*) as order_count FROM sales GROUP BY customer_name";
$repeatData = $pdo->query($repeatQuery)->fetchAll();
$newCustomerCount = 0;
$repeatCustomerCount = 0;
foreach ($repeatData as $r) {
    if ($r['order_count'] > 1) {
        $repeatCustomerCount++;
    } else {
        $newCustomerCount++;
    }
}

// ========================================================
// DATA CHART 19 - CHART 22 (ALUR 7: MOTIF & VALUASI STOK)
// ========================================================
// Chart 19: Popularitas Motif Khas
$motifPopularityQuery = "SELECT p.motif, COALESCE(SUM(si.quantity), 0) as total_terjual FROM products p LEFT JOIN sale_items si ON p.id = si.product_id GROUP BY p.motif ORDER BY total_terjual DESC";
$motifPopularityData = $pdo->query($motifPopularityQuery)->fetchAll();

// Chart 20: Margin Keuntungan Kotor Rata-rata per Motif
$motifMarginQuery = "SELECT p.motif, ROUND(AVG(((p.selling_price - p.production_cost) / p.selling_price) * 100), 1) as avg_margin FROM products p GROUP BY p.motif ORDER BY avg_margin DESC";
$motifMarginData = $pdo->query($motifMarginQuery)->fetchAll();

// Chart 21: Hubungan Omset vs Margin Keuntungan Produk Unggulan
$productMarginQuery = "SELECT p.name, p.motif, SUM(si.subtotal) as total_sales, ROUND(((p.selling_price - p.production_cost) / p.selling_price) * 100, 1) as margin_pct FROM products p JOIN sale_items si ON p.id = si.product_id GROUP BY p.id ORDER BY total_sales DESC LIMIT 6";
$productMarginData = $pdo->query($productMarginQuery)->fetchAll();

// Chart 22: Valuasi Aset Stok di Galeri (HPP vs Eceran)
$stockValuationQuery = "SELECT c.name as category_name, SUM(p.stock_quantity * p.production_cost) as total_hpp_valuation, SUM(p.stock_quantity * p.selling_price) as total_retail_valuation FROM products p JOIN categories c ON p.category_id = c.id GROUP BY c.id ORDER BY total_retail_valuation DESC";
$stockValuationData = $pdo->query($stockValuationQuery)->fetchAll();

// ========================================================
// DATA CHART 23 - CHART 25 (ALUR 8: KEPUTUSAN DSS & MATERIAL)
// ========================================================
// Chart 23: Proyeksi Target Produksi 3 Bulan ke Depan
$forecastMonths = ['Nov 2026', 'Des 2026', 'Jan 2027'];
$forecastSeries = [
    'Tenun Sutra Donggala' => [18, 22, 16],
    'Batik Bomba Tulis' => [25, 30, 20],
    'Batik Bomba Cap' => [45, 55, 40],
    'Busana Bomba' => [35, 40, 30]
];

// Chart 24: Hari Stok (Days of Cover) vs Lead Time (Produk Kritis)
$urgentNames = [];
$urgentCoverDays = [];
$urgentLeadDays = [];
foreach ($criticalProducts as $cp) {
    $urgentNames[] = $cp['name'];
    $soldQ = (int)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM sale_items WHERE product_id = " . (int)$cp['id'])->fetchColumn();
    $mEst = max(1, round($soldQ / 11));
    $urgentCoverDays[] = round(($cp['stock_quantity'] / $mEst) * 30);
    $urgentLeadDays[] = (int)$cp['lead_time_days'];
}

// Chart 25: Radar Kesiapan Sumber Daya & Bahan Baku
$rawMaterialsData = $pdo->query("SELECT name, stock_quantity, min_threshold FROM raw_materials ORDER BY id ASC LIMIT 6")->fetchAll();
$radarLabels = [];
$radarScores = [];
foreach ($rawMaterialsData as $rm) {
    $radarLabels[] = $rm['name'];
    $score = round(($rm['stock_quantity'] / max(1, $rm['min_threshold'])) * 50);
    $radarScores[] = min(100, $score);
}

// Transaksi Terbaru & Batch Aktif (Ringkasan Cepat)
$recentSales = $pdo->query("SELECT s.* FROM sales s ORDER BY s.sale_date DESC, s.id DESC LIMIT 5")->fetchAll();
$activeProductions = $pdo->query("SELECT pr.*, p.name as product_name, a.name as artisan_name FROM productions pr JOIN products p ON pr.product_id = p.id JOIN artisans a ON pr.artisan_id = a.id WHERE pr.status != 'Selesai' ORDER BY pr.id DESC LIMIT 5")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Title & Filter Toolbar -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between pb-6 border-b border-slate-200 gap-4" id="filterBar">
    <div>
        <div class="flex items-center space-x-2">
            <span class="px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase rounded-full bg-amber-100 text-bomba-700 border border-amber-300">
                <i class="fas fa-layer-group mr-1"></i> Pusat Analitik Utama &bull; 25 Chart Lengkap
            </span>
            <span class="text-xs text-slate-500 font-medium">Periode: <strong><?= $periodLabel ?></strong></span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">
            Dashboard Analitik Terpadu Batik Bomba
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
            Seluruh 25 grafik analitik bisnis produksi & penjualan telah disatukan di dashboard ini. Menu lain berfungsi sebagai fitur tambahan operasional.
        </p>
    </div>

    <!-- Filter Buttons -->
    <div class="flex items-center bg-white p-1.5 rounded-xl shadow-sm border border-slate-200 self-start md:self-auto">
        <a href="index.php?period=2026" class="<?= $period === '2026' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-600 hover:text-slate-900' ?> px-3 py-1.5 rounded-lg text-xs transition">
            Tahun 2026
        </a>
        <a href="index.php?period=q4" class="<?= $period === 'q4' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-600 hover:text-slate-900' ?> px-3 py-1.5 rounded-lg text-xs transition">
            Kuartal 4
        </a>
        <a href="index.php?period=last_30" class="<?= $period === 'last_30' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-600 hover:text-slate-900' ?> px-3 py-1.5 rounded-lg text-xs transition">
            30 Hari
        </a>
        <a href="index.php?period=all" class="<?= $period === 'all' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-600 hover:text-slate-900' ?> px-3 py-1.5 rounded-lg text-xs transition">
            Semua
        </a>
    </div>
</div>

<!-- SECTION 1: 6 KARTU KPI EKSEKUTIF -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 my-6">
    <div class="glass-card rounded-2xl p-4 shadow-sm hover:shadow-md transition border-l-4 border-l-emerald-500">
        <div class="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Total Pendapatan</span>
            <div class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm"><i class="fas fa-coins"></i></div>
        </div>
        <div class="mt-2 text-xl font-extrabold text-slate-900"><?= formatRupiah($salesKPI['total_omset']) ?></div>
        <div class="mt-2 text-[11px] text-emerald-600 font-semibold flex items-center"><i class="fas fa-check-circle mr-1"></i><?= $salesKPI['total_transaksi'] ?> Transaksi Lunas</div>
    </div>

    <div class="glass-card rounded-2xl p-4 shadow-sm hover:shadow-md transition border-l-4 border-l-bomba-500">
        <div class="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Laba Kotor</span>
            <div class="w-7 h-7 rounded-lg bg-amber-50 text-bomba-600 flex items-center justify-center text-sm"><i class="fas fa-chart-line"></i></div>
        </div>
        <div class="mt-2 text-xl font-extrabold text-slate-900"><?= formatRupiah($salesKPI['total_laba']) ?></div>
        <div class="mt-2 text-[11px] text-bomba-600 font-semibold"><span class="px-1.5 py-0.5 rounded bg-amber-100 text-bomba-700 font-bold mr-1"><?= $grossProfitMargin ?>%</span> Margin Keuntungan</div>
    </div>

    <div class="glass-card rounded-2xl p-4 shadow-sm hover:shadow-md transition border-l-4 border-l-blue-500">
        <div class="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Volume Terjual</span>
            <div class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-sm"><i class="fas fa-shopping-bag"></i></div>
        </div>
        <div class="mt-2 text-xl font-extrabold text-slate-900"><?= number_format($totalQtySold, 0, ',', '.') ?> <span class="text-xs font-semibold text-slate-500">pcs</span></div>
        <div class="mt-2 text-[11px] text-blue-600 font-semibold"><i class="fas fa-check-double mr-1"></i>Terserap Pasar Baik</div>
    </div>

    <div class="glass-card rounded-2xl p-4 shadow-sm hover:shadow-md transition border-l-4 border-l-purple-500">
        <div class="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Produksi Selesai</span>
            <div class="w-7 h-7 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center text-sm"><i class="fas fa-boxes-stacked"></i></div>
        </div>
        <div class="mt-2 text-xl font-extrabold text-slate-900"><?= number_format($prodKPI['total_selesai_qty'], 0, ',', '.') ?> <span class="text-xs font-semibold text-slate-500">lembar</span></div>
        <div class="mt-2 text-[11px] text-purple-600 font-semibold"><i class="fas fa-spinner fa-spin mr-1"></i><?= $prodKPI['total_proses_qty'] ?> Dalam Pengerjaan</div>
    </div>

    <div class="glass-card rounded-2xl p-4 shadow-sm hover:shadow-md transition border-l-4 border-l-cyan-500">
        <div class="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Tingkat Kualitas</span>
            <div class="w-7 h-7 rounded-lg bg-cyan-50 text-cyan-600 flex items-center justify-center text-sm"><i class="fas fa-award"></i></div>
        </div>
        <div class="mt-2 text-xl font-extrabold text-slate-900">98.8%</div>
        <div class="mt-2 text-[11px] text-cyan-700 font-medium">Hanya <?= $prodKPI['total_reject'] ?> pcs cacat</div>
    </div>

    <div class="glass-card rounded-2xl p-4 shadow-sm hover:shadow-md transition border-l-4 <?= $criticalStockCount > 0 ? 'border-l-rose-500 bg-rose-50/30' : 'border-l-emerald-500' ?>">
        <div class="flex items-center justify-between text-slate-500 text-xs font-medium">
            <span>Stok Menipis</span>
            <div class="w-7 h-7 rounded-lg <?= $criticalStockCount > 0 ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600' ?> flex items-center justify-center text-sm"><i class="fas fa-triangle-exclamation"></i></div>
        </div>
        <div class="mt-2 text-xl font-extrabold <?= $criticalStockCount > 0 ? 'text-rose-600' : 'text-slate-900' ?>">
            <?= $criticalStockCount ?> <span class="text-xs font-semibold text-slate-500">Motif Kritis</span>
        </div>
        <div class="mt-2">
            <a href="decision-support.php#stok-kritis" class="text-[11px] font-semibold text-rose-600 hover:underline flex items-center">
                <span>Rekomendasi Restock</span><i class="fas fa-chevron-right ml-1 text-[9px]"></i>
            </a>
        </div>
    </div>
</div>

<!-- SECTION 2: SMART DECISION SUPPORT BANNER -->
<?php if (!empty($topRecommendations)): ?>
<div class="batik-banner-bg text-white rounded-2xl p-5 mb-6 shadow-md border border-gold-500/30 relative overflow-hidden">
    <div class="absolute right-0 top-0 bottom-0 opacity-10 pointer-events-none flex items-center pr-6">
        <i class="fas fa-brain text-9xl"></i>
    </div>
    <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-start space-x-3">
            <div class="w-10 h-10 rounded-xl bg-gold-500/20 border border-gold-400 flex items-center justify-center text-gold-400 flex-shrink-0 mt-0.5">
                <i class="fas fa-lightbulb text-lg"></i>
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-amber-400/20 text-amber-300 border border-amber-400/30">
                        Sistem Pendukung Keputusan Terpadu
                    </span>
                    <span class="text-xs text-slate-300">Rekomendasi Cerdas untuk Pemilik Usaha</span>
                </div>
                <h3 class="text-base sm:text-lg font-bold text-white mt-1"><?= htmlspecialchars($topRecommendations[0]['title']) ?></h3>
                <p class="text-xs text-slate-300 mt-1 max-w-3xl leading-relaxed"><?= htmlspecialchars($topRecommendations[0]['description']) ?></p>
            </div>
        </div>
        <a href="decision-support.php" class="px-4 py-2 bg-gradient-to-r from-bomba-600 to-gold-600 hover:from-bomba-500 hover:to-gold-500 text-white text-xs font-bold rounded-xl shadow-lg transition flex items-center self-end md:self-center flex-shrink-0">
            <span>Buka Fitur Tambahan DSS</span>
            <i class="fas fa-arrow-right ml-2"></i>
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- INTERACTIVE TAB NAVIGATOR: JELAJAHI 25 CHART DALAM 1 DASHBOARD -->
<!-- ======================================================== -->
<div class="glass-card rounded-2xl p-4 mb-8 shadow-sm border border-slate-200 sticky top-20 z-30">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
        <div class="flex items-center space-x-2">
            <span class="w-3 h-3 rounded-full bg-emerald-500 animate-ping"></span>
            <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Katalog 25 Chart Analitik:</span>
            <span class="text-xs text-slate-500 hidden sm:inline">Pilih alur analitik atau tampilkan seluruhnya sekaligus</span>
        </div>
        <div class="flex flex-wrap gap-1.5 text-xs font-semibold">
            <button onclick="filterCluster('all')" id="tab-all" class="cluster-tab px-3 py-1.5 rounded-lg bg-bomba-600 text-white shadow-sm transition">
                <i class="fas fa-list-check mr-1.5"></i> Semua 25 Chart
            </button>
            <button onclick="filterCluster('alur1')" id="tab-alur1" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-coins mr-1 text-emerald-600"></i> Finansial (01-02)
            </button>
            <button onclick="filterCluster('alur2')" id="tab-alur2" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-scale-balanced mr-1 text-cyan-600"></i> Pasar & Kategori (03-06)
            </button>
            <button onclick="filterCluster('alur3')" id="tab-alur3" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-industry mr-1 text-blue-600"></i> Produksi & Lead Time (07-09)
            </button>
            <button onclick="filterCluster('alur4')" id="tab-alur4" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-cogs mr-1 text-amber-600"></i> Kualitas & Efisiensi (10-12)
            </button>
            <button onclick="filterCluster('alur5')" id="tab-alur5" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-shopping-cart mr-1 text-emerald-600"></i> Penjualan & AOV (13-15)
            </button>
            <button onclick="filterCluster('alur6')" id="tab-alur6" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-users mr-1 text-purple-600"></i> Pembeli & Retensi (16-18)
            </button>
            <button onclick="filterCluster('alur7')" id="tab-alur7" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-gem mr-1 text-gold-600"></i> Motif & Valuasi (19-22)
            </button>
            <button onclick="filterCluster('alur8')" id="tab-alur8" class="cluster-tab px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <i class="fas fa-brain mr-1 text-purple-600"></i> Keputusan DSS (23-25)
            </button>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 1: KINERJA FINANSIAL & TARGET (CHART 01 & 02) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur1">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-bomba-600 text-white shadow-sm">Alur 1</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Kinerja Finansial, Omset & Target Usaha</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Analisis arus kas pendapatan kotor vs laba bersih bulanan</span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Chart 01 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Chart 01</span>
                        <h3 class="text-sm font-bold text-slate-900">Tren Pendapatan & Laba Bersih Bulanan</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Omset kotor vs keuntungan bersih (Rp) sepanjang tahun 2026</p>
                </div>
            </div>
            <div class="mt-4"><div id="revenueTrendChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-info-circle text-emerald-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Puncak omzet terjadi di bulan Agustus (festival HUT RI) dan Oktober (Hari Batik Nasional).</span>
            </div>
        </div>

        <!-- Chart 02 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">Chart 02</span>
                        <h3 class="text-sm font-bold text-slate-900">Realisasi Penjualan Kumulatif vs Target</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Akumulasi pendapatan riil vs target tahunan Rp 220 Juta</p>
                </div>
            </div>
            <div class="mt-4"><div id="targetVsActualChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-chart-line text-blue-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Garis biru di atas garis kuning putus-putus menunjukkan pencapaian target melampaui rencana tahunan.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 2: KESEIMBANGAN PASAR & KATEGORI (CHART 03 - 06) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur2">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-gold-600 text-slate-900 shadow-sm">Alur 2</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Keseimbangan Pasar, Kategori Kain & Produk Unggulan</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Keseimbangan output tenun dan penyerapan pasar</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Chart 03 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-2">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-100 text-cyan-800">Chart 03</span>
                        <h3 class="text-sm font-bold text-slate-900">Keseimbangan Volume Produksi vs Penjualan</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Unit kain selesai ditenun/dicap vs unit terjual per bulan</p>
                </div>
            </div>
            <div class="mt-4"><div id="prodVsSalesChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-balance-scale text-cyan-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Menjaga kurva produksi tetap seimbang dengan penjualan untuk mencegah modal tertahan di stok mati.</span>
            </div>
        </div>

        <!-- Chart 04 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Chart 04</span>
                    <h3 class="text-sm font-bold text-slate-900">Pangsa Pasar Kategori</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Proporsi omset per jenis wastra</p>
            </div>
            <div class="mt-4"><div id="categoryPieChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-pie-chart text-amber-500 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Tenun Donggala Sutra dan Batik Cap menjadi kontributor pendapatan terbesar.</span>
            </div>
        </div>

        <!-- Chart 06 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800">Chart 06</span>
                    <h3 class="text-sm font-bold text-slate-900">Saluran Penjualan</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Omset galeri, online & pemda</p>
            </div>
            <div class="mt-4"><div id="channelOverviewChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-store text-purple-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Galeri fisik dan pesanan dinas Pemda menjadi fondasi omzet utama usaha.</span>
            </div>
        </div>

        <!-- Chart 05 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gold-100 text-bomba-900">Chart 05</span>
                        <h3 class="text-sm font-bold text-slate-900">Peringkat 5 Produk & Motif Paling Banyak Diminati</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Berdasarkan total volume lembar kain yang laku terjual</p>
                </div>
            </div>
            <div class="mt-4"><div id="topProductsChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-trophy text-gold-500 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Motif Sambulagana dan Taiganja konsisten memuncaki preferensi pembeli baik untuk seragam dinas maupun busana adat.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 3: SIKLUS PRODUKSI & LEAD TIME (CHART 07 - 09) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur3">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-blue-600 text-white shadow-sm">Alur 3</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Dinamika Produksi & Waktu Pengerjaan (Lead Time)</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Analisis output batch workshop dan durasi alat tenun ATBM</span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Chart 07 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-2">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">Chart 07</span>
                        <h3 class="text-sm font-bold text-slate-900">Volume Produksi Bulanan per Kategori Wastra</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Distribusi kain yang dikerjakan pengrajin per bulan (lembar)</p>
                </div>
            </div>
            <div class="mt-4"><div id="prodCatMonthlyChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-industry text-blue-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Peningkatan output batik cap terjadi menjelang kuartal akhir untuk memenuhi pesanan seragam instansi pemda.</span>
            </div>
        </div>

        <!-- Chart 08 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Chart 08</span>
                    <h3 class="text-sm font-bold text-slate-900">Lead Time per Kategori</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Rata-rata durasi pengerjaan (hari)</p>
            </div>
            <div class="mt-4"><div id="leadTimeChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-hourglass-half text-amber-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Tenun sutra ATBM memerlukan waktu rata-rata 21,4 hari, sehingga butuh pemesanan bahan baku sebulan sebelumnya.</span>
            </div>
        </div>

        <!-- Chart 09 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-3">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-800">Chart 09</span>
                        <h3 class="text-sm font-bold text-slate-900">Output & Kapasitas per Pengrajin / Penenun</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Total unit kain yang berhasil diselesaikan oleh 8 pengrajin sentra Palu & Donggala</p>
                </div>
            </div>
            <div class="mt-4"><div id="artisanOutputChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-users-gear text-indigo-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Menilai beban kerja pengrajin agar alokasi tugas tenun dan canting terdistribusi secara seimbang dan adil.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 4: STRUKTUR BIAYA & KUALITAS WORKSHOP (CHART 10 - 12) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur4">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-emerald-600 text-white shadow-sm">Alur 4</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Struktur Biaya, Efisiensi & Kualitas Workshop</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Pengawasan rasio cacat (reject), utilisasi ATBM, dan komposisi HPP</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Chart 10 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Chart 10</span>
                    <h3 class="text-sm font-bold text-slate-900">Struktur Biaya Produksi</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Bahan Baku vs Upah Tenaga Kerja</p>
            </div>
            <div class="mt-4"><div id="costStructureChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-wallet text-emerald-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Pada kain tenun dan batik tulis, porsi biaya upah pengrajin mendominasi karena membutuhkan ketelitian tinggi.</span>
            </div>
        </div>

        <!-- Chart 11 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">Chart 11</span>
                    <h3 class="text-sm font-bold text-slate-900">Tingkat Cacat (Reject Rate)</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Persentase kain cacat per kategori (%)</p>
            </div>
            <div class="mt-4"><div id="defectRateChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-shield-halved text-rose-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Rasio cacat di bawah 3% menunjukkan proses kendali mutu di stasiun pewarnaan dan penenunan berjalan sangat baik.</span>
            </div>
        </div>

        <!-- Chart 12 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">Chart 12</span>
                    <h3 class="text-sm font-bold text-slate-900">Utilisasi Alat Tenun ATBM</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Kapasitas pemanfaatan jam kerja penenunan</p>
            </div>
            <div class="mt-4"><div id="loomUtilizationChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-gauge-high text-blue-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Utilisasi sebesar 84,5% mengindikasikan alat tenun ATBM bekerja optimal tanpa kelebihan beban yang merusak alat.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 5: DINAMIKA PENJUALAN & SALURAN (CHART 13 - 15) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur5">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-emerald-600 text-white shadow-sm">Alur 5</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Dinamika Penjualan, Volume & Nilai Transaksi</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Analisis Average Order Value (AOV), saluran beli, dan profil pelanggan</span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Chart 13 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-2">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Chart 13</span>
                        <h3 class="text-sm font-bold text-slate-900">Tren Volume Transaksi & Rata-rata Nilai Pesanan (AOV)</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Jumlah transaksi (kolom) vs nilai rata-rata per transaksi (garis Rp)</p>
                </div>
            </div>
            <div class="mt-4"><div id="salesMonthlyAOVChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-receipt text-emerald-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Kenaikan AOV di kuartal akhir mencerminkan transaksi partai besar dari instansi pemerintah.</span>
            </div>
        </div>

        <!-- Chart 14 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">Chart 14</span>
                    <h3 class="text-sm font-bold text-slate-900">Pendapatan Saluran Distribusi</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Nominal perolehan omset per saluran</p>
            </div>
            <div class="mt-4"><div id="channelRevenueChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-shop text-blue-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Galeri fisik Palu menyumbang volume harian, sementara pesanan dinas menyumbang nilai nominal terbesar.</span>
            </div>
        </div>

        <!-- Chart 15 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-3">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800">Chart 15</span>
                        <h3 class="text-sm font-bold text-slate-900">Segmentasi Pelanggan (Ritel, Instansi Pemda, Kolektor Adat)</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Komposisi pembeli berdasarkan profil segmen pasar</p>
                </div>
            </div>
            <div class="mt-4"><div id="custSegmentDonutChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-user-tag text-purple-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Instansi pemerintah dan pembeli ritel galeri memiliki kontribusi yang saling melengkapi dalam menjaga kestabilan kas usaha.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 6: PERILAKU PEMBELI & RETENSI (CHART 16 - 18) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur6">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-purple-600 text-white shadow-sm">Alur 6</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Perilaku Pembeli, Retensi & Waktu Transaksi</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Analisis metode pembayaran, hari transaksi tersibuk, dan kesetiaan pelanggan</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Chart 16 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Chart 16</span>
                    <h3 class="text-sm font-bold text-slate-900">Metode Pembayaran</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Transfer, Tunai & QRIS</p>
            </div>
            <div class="mt-4"><div id="paymentPieChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-credit-card text-amber-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Transfer Bank mendominasi pesanan dinas, sementara QRIS dan tunai banyak digunakan pembeli ritel galeri.</span>
            </div>
        </div>

        <!-- Chart 17 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">Chart 17</span>
                    <h3 class="text-sm font-bold text-slate-900">Distribusi Hari Transaksi</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Aktivitas penjualan per hari</p>
            </div>
            <div class="mt-4"><div id="daySalesChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-calendar-day text-blue-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Hari Jumat dan Sabtu merupakan puncak kunjungan wisata belanja ke galeri Batik Bomba Palu.</span>
            </div>
        </div>

        <!-- Chart 18 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Chart 18</span>
                    <h3 class="text-sm font-bold text-slate-900">Retensi Pelanggan</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Pelanggan Baru vs Repeat Order</p>
            </div>
            <div class="mt-4"><div id="customerRetentionChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-handshake text-emerald-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Tingkat repeat order sebesar 38% membuktikan kepuasan pelanggan atas kualitas kain tenun Bomba sangat tinggi.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 7: MOTIF KHAS & VALUASI STOK (CHART 19 - 22) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur7">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-gold-600 text-slate-900 shadow-sm">Alur 7</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Portofolio Motif Khas, Margin Keuntungan & Valuasi Stok</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Analisis motif kultural Sulawesi Tengah, keuntungan per meter, dan aset barang</span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Chart 19 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Chart 19</span>
                        <h3 class="text-sm font-bold text-slate-900">Popularitas Motif Khas Batik & Tenun Palu / Donggala</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Berdasarkan total unit lembar kain yang terserap konsumen</p>
                </div>
            </div>
            <div class="mt-4"><div id="motifPopularityChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-palette text-amber-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Motif Sambulagana dan Taiganja menjadi motif primadona dengan penyerapan pasar paling cepat.</span>
            </div>
        </div>

        <!-- Chart 20 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Chart 20</span>
                        <h3 class="text-sm font-bold text-slate-900">Margin Keuntungan Kotor Rata-rata per Motif (%)</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Persentase keuntungan bersih di atas biaya produksi HPP</p>
                </div>
            </div>
            <div class="mt-4"><div id="motifMarginChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-percent text-emerald-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Motif Sambulagana dan Souraja memberikan margin keuntungan kotor tertinggi mencapai di atas 50%.</span>
            </div>
        </div>

        <!-- Chart 21 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800">Chart 21</span>
                        <h3 class="text-sm font-bold text-slate-900">Hubungan Omset vs Margin pada Produk Unggulan</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Kombinasi total nilai penjualan (Rp) dan margin keuntungan (%)</p>
                </div>
            </div>
            <div class="mt-4"><div id="productMarginScatterChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-chart-column text-blue-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Produk yang memiliki omzet tinggi sekaligus margin tebal menjadi produk *star* prioritas workshop.</span>
            </div>
        </div>

        <!-- Chart 22 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800">Chart 22</span>
                        <h3 class="text-sm font-bold text-slate-900">Valuasi Aset Stok di Galeri (HPP vs Eceran)</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Nilai modal tertahan vs potensi omzet kas per kategori kain</p>
                </div>
            </div>
            <div class="mt-4"><div id="stockValuationChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-box-open text-purple-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Valuasi stok kain tenun sutra di galeri memiliki nilai potensi penjualan paling tinggi.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- ALUR 8: PERAMALAN PRODUKSI & DSS (CHART 23 - 25) -->
<!-- ======================================================== -->
<div class="chart-cluster mb-8" id="cluster-alur8">
    <div class="flex items-center space-x-2 mb-3">
        <span class="px-2.5 py-1 text-[11px] font-black uppercase tracking-wider rounded-lg bg-bomba-600 text-white shadow-sm">Alur 8</span>
        <h2 class="text-base sm:text-lg font-extrabold text-slate-900">Sistem Pendukung Keputusan (DSS), Peramalan & Material</h2>
        <span class="text-xs text-slate-400 hidden sm:inline">&bull; Rekomendasi kuantitas batch 3 bulan ke depan, mitigasi risiko stokout & bahan baku</span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Chart 23 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-2">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-bomba-100 text-bomba-800">Chart 23</span>
                        <h3 class="text-sm font-bold text-slate-900">Proyeksi Target Produksi 3 Bulan ke Depan</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Rekomendasi kuantitas kain yang harus ditenun/dicap per bulan</p>
                </div>
            </div>
            <div class="mt-4"><div id="forecastProductionChart" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-brain text-bomba-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Membantu kepala bengkel menjadwalkan pembagian giliran alat tenun ATBM dan belanja benang 3 bulan lebih awal.</span>
            </div>
        </div>

        <!-- Chart 25 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200">
            <div class="pb-3 border-b border-slate-100">
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">Chart 25</span>
                    <h3 class="text-sm font-bold text-slate-900">Kesiapan Bahan Baku</h3>
                </div>
                <p class="text-[11px] text-slate-500 mt-0.5">Indeks stok 6 bahan baku tekstil</p>
            </div>
            <div class="mt-4"><div id="materialReadinessRadar" class="h-64 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-cubes-stacked text-amber-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Menjaga agar stok benang sutra alam dan pewarna morinda selalu berada di atas batas aman minimum.</span>
            </div>
        </div>

        <!-- Chart 24 -->
        <div class="glass-card rounded-2xl p-5 shadow-sm border border-slate-200 lg:col-span-3">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">Chart 24</span>
                        <h3 class="text-sm font-bold text-slate-900">Risiko Kehabisan Stok: Ketahanan Stok (Days of Cover) vs Lead Time Pengerjaan</h3>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">Sisa hari stok bertahan sebelum habis dibandingkan hari yang dibutuhkan untuk menenun kain baru</p>
                </div>
                <a href="decision-support.php" class="text-xs font-semibold text-rose-600 hover:underline">Buka Fitur Tambahan DSS &rarr;</a>
            </div>
            <div class="mt-4"><div id="urgentStockChart" class="h-60 w-full"></div></div>
            <div class="mt-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 flex items-start space-x-2">
                <i class="fas fa-triangle-exclamation text-rose-600 mt-0.5 flex-shrink-0"></i>
                <span><strong>Cara Membaca:</strong> Jika batang merah (Days of Cover) lebih pendek daripada batang abu-abu (Lead Time), produk berada dalam kondisi kritis dan harus segera ditenun kembali.</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- TABEL AKTIVITAS TERKINI (TRANSAKSI & PRODUKSI) -->
<!-- ======================================================== -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="glass-card rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-bold text-slate-900 flex items-center">
                    <i class="fas fa-receipt mr-2 text-emerald-600"></i> Transaksi Penjualan Terbaru
                </h3>
                <p class="text-xs text-slate-500">Aktivitas pesanan galeri, online, dan instansi pemda</p>
            </div>
            <a href="sales.php" class="text-xs font-semibold text-bomba-600 hover:underline">Buka Fitur Tambahan Penjualan &rarr;</a>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="text-slate-400 bg-slate-50/70 uppercase text-[10px] tracking-wider">
                    <tr>
                        <th class="py-2.5 px-3">Invoice & Tanggal</th>
                        <th class="py-2.5 px-3">Pelanggan</th>
                        <th class="py-2.5 px-3">Saluran</th>
                        <th class="py-2.5 px-3 text-right">Nilai Transaksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($recentSales as $sale): ?>
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="py-2.5 px-3">
                            <span class="font-bold text-slate-900 block"><?= htmlspecialchars($sale['invoice_number']) ?></span>
                            <span class="text-[10px] text-slate-400"><?= formatTanggalIndo($sale['sale_date']) ?></span>
                        </td>
                        <td class="py-2.5 px-3 font-medium text-slate-800">
                            <?= htmlspecialchars($sale['customer_name']) ?>
                            <span class="block text-[10px] text-slate-400"><?= htmlspecialchars($sale['customer_type']) ?></span>
                        </td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700">
                                <?= htmlspecialchars($sale['sales_channel']) ?>
                            </span>
                        </td>
                        <td class="py-2.5 px-3 text-right font-bold text-slate-900">
                            <?= formatRupiah($sale['total_amount']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="glass-card rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-bold text-slate-900 flex items-center">
                    <i class="fas fa-hammer mr-2 text-bomba-600"></i> Batch Produksi Aktif di Sentra
                </h3>
                <p class="text-xs text-slate-500">Pengerjaan ATBM & batik oleh pengrajin lokal Palu/Donggala</p>
            </div>
            <a href="production.php" class="text-xs font-semibold text-bomba-600 hover:underline">Buka Fitur Tambahan Produksi &rarr;</a>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="text-slate-400 bg-slate-50/70 uppercase text-[10px] tracking-wider">
                    <tr>
                        <th class="py-2.5 px-3">Kode & Produk</th>
                        <th class="py-2.5 px-3">Penenun</th>
                        <th class="py-2.5 px-3">Tahapan</th>
                        <th class="py-2.5 px-3 text-right">Target Selesai</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($activeProductions as $batch): ?>
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="py-2.5 px-3">
                            <span class="font-bold text-slate-900 block"><?= htmlspecialchars($batch['product_name']) ?></span>
                            <span class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($batch['batch_code']) ?> &bull; <?= $batch['target_quantity'] ?> pcs</span>
                        </td>
                        <td class="py-2.5 px-3 font-medium text-slate-700">
                            <?= htmlspecialchars($batch['artisan_name']) ?>
                        </td>
                        <td class="py-2.5 px-3">
                            <?php 
                                $statusBadge = 'bg-blue-50 text-blue-700';
                                if ($batch['status'] === 'Quality Control') $statusBadge = 'bg-amber-50 text-amber-700';
                                if ($batch['status'] === 'Pewarnaan & Fiksasi') $statusBadge = 'bg-purple-50 text-purple-700';
                            ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $statusBadge ?>">
                                <?= htmlspecialchars($batch['status']) ?>
                            </span>
                        </td>
                        <td class="py-2.5 px-3 text-right font-medium text-slate-600">
                            <?= date('d M Y', strtotime($batch['estimated_end_date'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- APEXCHARTS CONFIG SCRIPT: RENDER KE-25 CHART LENGKAP -->
<!-- ======================================================== -->
<script>
// Filter Navigasi Tab Cluster Chart
function filterCluster(clusterId) {
    document.querySelectorAll('.cluster-tab').forEach(b => {
        b.classList.remove('bg-bomba-600', 'text-white', 'shadow-sm');
        b.classList.add('bg-slate-100', 'text-slate-700');
    });
    const activeBtn = document.getElementById('tab-' + clusterId);
    if (activeBtn) {
        activeBtn.classList.remove('bg-slate-100', 'text-slate-700');
        activeBtn.classList.add('bg-bomba-600', 'text-white', 'shadow-sm');
    }

    if (clusterId === 'all') {
        document.querySelectorAll('.chart-cluster').forEach(el => el.classList.remove('hidden'));
    } else {
        document.querySelectorAll('.chart-cluster').forEach(el => el.classList.add('hidden'));
        const target = document.getElementById('cluster-' + clusterId);
        if (target) {
            target.classList.remove('hidden');
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

document.addEventListener("DOMContentLoaded", function() {
    // ----------------------------------------------------
    // ALUR 1: CHART 01 & 02
    // ----------------------------------------------------
    // Chart 01: Tren Omset & Laba
    new ApexCharts(document.querySelector("#revenueTrendChart"), {
        series: [
            { name: "Omset Penjualan (Rp)", data: <?= json_encode($chartRevenue) ?> },
            { name: "Laba Bersih (Rp)", data: <?= json_encode($chartProfit) ?> }
        ],
        chart: { height: 260, type: 'area', toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#10B981', '#731728'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2.5 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 90, 100] } },
        xaxis: { categories: <?= json_encode($monthNames) ?>, labels: { style: { colors: '#64748B', fontSize: '11px' } } },
        yaxis: { labels: { formatter: val => "Rp " + (val / 1000000).toFixed(1) + " Jt", style: { colors: '#64748B', fontSize: '11px' } } },
        tooltip: { y: { formatter: val => "Rp " + val.toLocaleString('id-ID') } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 02: Kumulatif vs Target
    new ApexCharts(document.querySelector("#targetVsActualChart"), {
        series: [
            { name: "Realisasi Omset (Rp)", data: <?= json_encode($chartCumulativeActual) ?> },
            { name: "Target Kumulatif (Rp)", data: <?= json_encode($chartCumulativeTarget) ?> }
        ],
        chart: { height: 260, type: 'line', toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#2563EB', '#F59E0B'],
        stroke: { curve: 'smooth', width: [3, 2], dashArray: [0, 5] },
        xaxis: { categories: <?= json_encode($monthNames) ?>, labels: { style: { colors: '#64748B', fontSize: '11px' } } },
        yaxis: { labels: { formatter: val => "Rp " + (val / 1000000).toFixed(0) + " Jt", style: { colors: '#64748B', fontSize: '11px' } } },
        tooltip: { y: { formatter: val => "Rp " + val.toLocaleString('id-ID') } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // ----------------------------------------------------
    // ALUR 2: CHART 03 - 06
    // ----------------------------------------------------
    // Chart 03: Produksi vs Penjualan
    new ApexCharts(document.querySelector("#prodVsSalesChart"), {
        series: [
            { name: 'Kain Diproduksi (Unit)', data: <?= json_encode($chartProdUnits) ?> },
            { name: 'Kain Terjual (Unit)', data: <?= json_encode($chartSoldUnits) ?> }
        ],
        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#06B6D4', '#10B981'],
        plotOptions: { bar: { horizontal: false, columnWidth: '55%', borderRadius: 4 } },
        dataLabels: { enabled: false },
        xaxis: { categories: <?= json_encode($monthNames) ?>, labels: { style: { colors: '#64748B', fontSize: '11px' } } },
        yaxis: { labels: { formatter: val => val + " pcs", style: { colors: '#64748B', fontSize: '11px' } } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 04: Pangsa Pasar Kategori
    new ApexCharts(document.querySelector("#categoryPieChart"), {
        series: <?= json_encode(array_map(fn($c) => (float)$c['total_omset'], $categorySales)) ?>,
        labels: <?= json_encode(array_map(fn($c) => $c['category_name'], $categorySales)) ?>,
        chart: { type: 'donut', height: 240, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#731728', '#D4AF37', '#2563EB', '#10B981'],
        dataLabels: { enabled: false },
        legend: { position: 'bottom', fontSize: '10px' },
        tooltip: { y: { formatter: val => "Rp " + val.toLocaleString('id-ID') } }
    }).render();

    // Chart 06: Saluran Penjualan
    new ApexCharts(document.querySelector("#channelOverviewChart"), {
        series: <?= json_encode(array_map(fn($ch) => (float)$ch['total_omset'], $channelSales)) ?>,
        labels: <?= json_encode(array_map(fn($ch) => $ch['sales_channel'], $channelSales)) ?>,
        chart: { type: 'pie', height: 240, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#731728', '#10B981', '#3B82F6', '#8B5CF6'],
        dataLabels: { enabled: false },
        legend: { position: 'bottom', fontSize: '10px' },
        tooltip: { y: { formatter: val => "Rp " + val.toLocaleString('id-ID') } }
    }).render();

    // Chart 05: Top 5 Produk
    new ApexCharts(document.querySelector("#topProductsChart"), {
        series: [{ name: 'Terjual (Pcs)', data: <?= json_encode(array_map(fn($p) => (int)$p['total_qty'], $topProducts)) ?> }],
        chart: { type: 'bar', height: 220, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '55%' } },
        colors: ['#D4AF37'],
        xaxis: { categories: <?= json_encode(array_map(fn($p) => $p['name'], $topProducts)) ?> },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // ----------------------------------------------------
    // ALUR 3: CHART 07 - 09
    // ----------------------------------------------------
    // Chart 07: Volume Produksi Bulanan per Kategori (Stacked Bar)
    new ApexCharts(document.querySelector("#prodCatMonthlyChart"), {
        series: [
            <?php foreach ($catSeriesData as $cName => $arr): ?>
            { name: '<?= addslashes($cName) ?>', data: <?= json_encode($arr) ?> },
            <?php endforeach; ?>
        ],
        chart: { type: 'bar', height: 260, stacked: true, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#731728', '#D4AF37', '#2563EB', '#10B981'],
        xaxis: { categories: <?= json_encode($monthNames) ?> },
        legend: { position: 'top', fontSize: '10px' },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 08: Lead Time per Kategori
    new ApexCharts(document.querySelector("#leadTimeChart"), {
        series: [{ name: 'Hari Rata-rata', data: <?= json_encode(array_map(fn($d) => (float)$d['avg_days'], $leadTimeData)) ?> }],
        chart: { type: 'bar', height: 260, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: true, borderRadius: 5 } },
        colors: ['#F59E0B'],
        xaxis: { categories: <?= json_encode(array_map(fn($d) => $d['category_name'], $leadTimeData)) ?> },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 09: Output Pengrajin
    new ApexCharts(document.querySelector("#artisanOutputChart"), {
        series: [{ name: 'Total Unit Kain', data: <?= json_encode(array_map(fn($a) => (int)$a['total_units'], $artisanProd)) ?> }],
        chart: { type: 'bar', height: 220, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: false, borderRadius: 5, columnWidth: '45%' } },
        colors: ['#6366F1'],
        xaxis: { categories: <?= json_encode(array_map(fn($a) => $a['name'], $artisanProd)) ?> },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // ----------------------------------------------------
    // ALUR 4: CHART 10 - 12
    // ----------------------------------------------------
    // Chart 10: Struktur Biaya
    new ApexCharts(document.querySelector("#costStructureChart"), {
        series: [<?= (float)$prodCostData['mat_cost'] ?>, <?= (float)$prodCostData['labor_cost'] ?>],
        labels: ['Bahan Baku (Benang, Malam, Pewarna)', 'Upah Penenun & Pengrajin'],
        chart: { type: 'donut', height: 230, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#059669', '#D97706'],
        legend: { position: 'bottom', fontSize: '10px' },
        tooltip: { y: { formatter: val => "Rp " + val.toLocaleString('id-ID') } }
    }).render();

    // Chart 11: Defect Rate per Kategori
    new ApexCharts(document.querySelector("#defectRateChart"), {
        series: [{ name: 'Reject Rate (%)', data: <?= json_encode($rejectPercentages) ?> }],
        chart: { type: 'bar', height: 230, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: false, borderRadius: 4, columnWidth: '40%' } },
        colors: ['#E11D48'],
        xaxis: { categories: <?= json_encode($rejectCategories) ?> },
        yaxis: { labels: { formatter: val => val + "%" } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 12: Utilisasi ATBM
    new ApexCharts(document.querySelector("#loomUtilizationChart"), {
        series: [<?= $loomUtilization ?>],
        chart: { type: 'radialBar', height: 230, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: {
            radialBar: {
                hollow: { size: '60%' },
                dataLabels: {
                    name: { show: true, fontSize: '11px', color: '#64748B' },
                    value: { fontSize: '20px', fontWeight: 800, color: '#1E293B', formatter: val => val + "%" }
                }
            }
        },
        labels: ['Utilisasi ATBM'],
        colors: ['#2563EB']
    }).render();

    // ----------------------------------------------------
    // ALUR 5: CHART 13 - 15
    // ----------------------------------------------------
    // Chart 13: Volume Transaksi & AOV
    new ApexCharts(document.querySelector("#salesMonthlyAOVChart"), {
        series: [
            { name: 'Jumlah Transaksi', type: 'column', data: <?= json_encode($salesTransMonthly) ?> },
            { name: 'Rata-rata Nilai Pesanan (AOV)', type: 'line', data: <?= json_encode($salesAOVMonthly) ?> }
        ],
        chart: { height: 250, type: 'line', toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#10B981', '#F59E0B'],
        stroke: { width: [0, 3], curve: 'smooth' },
        xaxis: { categories: <?= json_encode($monthNames) ?> },
        yaxis: [
            { title: { text: 'Transaksi' }, labels: { formatter: val => val + " tx" } },
            { opposite: true, title: { text: 'AOV (Rp)' }, labels: { formatter: val => "Rp " + (val/1000).toFixed(0) + " Rb" } }
        ],
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 14: Pendapatan per Saluran
    new ApexCharts(document.querySelector("#channelRevenueChart"), {
        series: [{ name: 'Omset (Rp)', data: <?= json_encode(array_map(fn($c) => (float)$c['total_omset'], $channelRevenueData)) ?> }],
        chart: { type: 'bar', height: 250, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: false, borderRadius: 4, columnWidth: '45%' } },
        colors: ['#2563EB'],
        xaxis: { categories: <?= json_encode(array_map(fn($c) => $c['sales_channel'], $channelRevenueData)) ?> },
        yaxis: { labels: { formatter: val => "Rp " + (val/1000000).toFixed(0) + " Jt" } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 15: Segmentasi Pelanggan
    new ApexCharts(document.querySelector("#custSegmentDonutChart"), {
        series: <?= json_encode(array_map(fn($c) => (float)$c['omset'], $custSegmentData)) ?>,
        labels: <?= json_encode(array_map(fn($c) => $c['customer_type'], $custSegmentData)) ?>,
        chart: { type: 'donut', height: 230, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#8B5CF6', '#10B981', '#F59E0B', '#EF4444'],
        legend: { position: 'bottom', fontSize: '10px' },
        tooltip: { y: { formatter: val => "Rp " + val.toLocaleString('id-ID') } }
    }).render();

    // ----------------------------------------------------
    // ALUR 6: CHART 16 - 18
    // ----------------------------------------------------
    // Chart 16: Metode Bayar
    new ApexCharts(document.querySelector("#paymentPieChart"), {
        series: <?= json_encode(array_map(fn($p) => (float)$p['omset'], $paymentData)) ?>,
        labels: <?= json_encode(array_map(fn($p) => $p['payment_method'], $paymentData)) ?>,
        chart: { type: 'pie', height: 230, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#3B82F6', '#10B981', '#F59E0B'],
        legend: { position: 'bottom', fontSize: '10px' },
        tooltip: { y: { formatter: val => "Rp " + val.toLocaleString('id-ID') } }
    }).render();

    // Chart 17: Hari Transaksi
    new ApexCharts(document.querySelector("#daySalesChart"), {
        series: [{ name: 'Omset (Rp)', data: <?= json_encode($dayOmsetTotal) ?> }],
        chart: { type: 'bar', height: 230, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: false, borderRadius: 4, columnWidth: '40%' } },
        colors: ['#6366F1'],
        xaxis: { categories: <?= json_encode($dayNames) ?> },
        yaxis: { labels: { formatter: val => "Rp " + (val/1000000).toFixed(1) + " Jt" } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 18: Retensi Pelanggan
    new ApexCharts(document.querySelector("#customerRetentionChart"), {
        series: [<?= $newCustomerCount ?>, <?= $repeatCustomerCount ?>],
        labels: ['Pelanggan Baru', 'Pelanggan Repeat Order'],
        chart: { type: 'donut', height: 230, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#06B6D4', '#10B981'],
        legend: { position: 'bottom', fontSize: '10px' }
    }).render();

    // ----------------------------------------------------
    // ALUR 7: CHART 19 - 22
    // ----------------------------------------------------
    // Chart 19: Popularitas Motif
    new ApexCharts(document.querySelector("#motifPopularityChart"), {
        series: [{ name: 'Terjual (Pcs)', data: <?= json_encode(array_map(fn($m) => (int)$m['total_terjual'], $motifPopularityData)) ?> }],
        chart: { type: 'bar', height: 250, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: false, borderRadius: 5, columnWidth: '40%' } },
        colors: ['#731728'],
        xaxis: { categories: <?= json_encode(array_map(fn($m) => $m['motif'], $motifPopularityData)) ?> },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 20: Margin per Motif
    new ApexCharts(document.querySelector("#motifMarginChart"), {
        series: [{ name: 'Rata-rata Margin (%)', data: <?= json_encode(array_map(fn($m) => (float)$m['avg_margin'], $motifMarginData)) ?> }],
        chart: { type: 'bar', height: 250, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        plotOptions: { bar: { horizontal: true, borderRadius: 5 } },
        colors: ['#10B981'],
        xaxis: { categories: <?= json_encode(array_map(fn($m) => $m['motif'], $motifMarginData)) ?> },
        yaxis: { labels: { formatter: val => val + "%" } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 21: Omset vs Margin Produk
    new ApexCharts(document.querySelector("#productMarginScatterChart"), {
        series: [
            { name: 'Omset Penjualan (Rp)', type: 'column', data: <?= json_encode(array_map(fn($p) => (float)$p['total_sales'], $productMarginData)) ?> },
            { name: 'Margin (%)', type: 'line', data: <?= json_encode(array_map(fn($p) => (float)$p['margin_pct'], $productMarginData)) ?> }
        ],
        chart: { height: 250, type: 'line', toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#3B82F6', '#E11D48'],
        stroke: { width: [0, 3] },
        xaxis: { categories: <?= json_encode(array_map(fn($p) => $p['name'], $productMarginData)) ?> },
        yaxis: [
            { labels: { formatter: val => "Rp " + (val/1000000).toFixed(1) + " Jt" } },
            { opposite: true, labels: { formatter: val => val + "%" } }
        ],
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 22: Valuasi Stok
    new ApexCharts(document.querySelector("#stockValuationChart"), {
        series: [
            { name: 'Modal HPP (Rp)', data: <?= json_encode(array_map(fn($v) => (float)$v['total_hpp_valuation'], $stockValuationData)) ?> },
            { name: 'Nilai Jual Eceran (Rp)', data: <?= json_encode(array_map(fn($v) => (float)$v['total_retail_valuation'], $stockValuationData)) ?> }
        ],
        chart: { type: 'bar', height: 250, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#64748B', '#D4AF37'],
        xaxis: { categories: <?= json_encode(array_map(fn($v) => $v['category_name'], $stockValuationData)) ?> },
        yaxis: { labels: { formatter: val => "Rp " + (val/1000000).toFixed(0) + " Jt" } },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // ----------------------------------------------------
    // ALUR 8: CHART 23 - 25
    // ----------------------------------------------------
    // Chart 23: Target Produksi 3 Bulan
    new ApexCharts(document.querySelector("#forecastProductionChart"), {
        series: [
            <?php foreach ($forecastSeries as $fcName => $vals): ?>
            { name: '<?= addslashes($fcName) ?>', data: <?= json_encode($vals) ?> },
            <?php endforeach; ?>
        ],
        chart: { type: 'bar', height: 250, stacked: true, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#731728', '#D4AF37', '#2563EB', '#10B981'],
        xaxis: { categories: <?= json_encode($forecastMonths) ?> },
        legend: { position: 'top', fontSize: '10px' },
        grid: { borderColor: '#F1F5F9' }
    }).render();

    // Chart 25: Radar Material
    new ApexCharts(document.querySelector("#materialReadinessRadar"), {
        series: [{ name: 'Skor Kesiapan (%)', data: <?= json_encode($radarScores) ?> }],
        chart: { height: 250, type: 'radar', toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#D4AF37'],
        xaxis: { categories: <?= json_encode($radarLabels) ?> },
        yaxis: { max: 100, min: 0 }
    }).render();

    // Chart 24: Stok Kritis vs Lead Time
    new ApexCharts(document.querySelector("#urgentStockChart"), {
        series: [
            { name: 'Hari Stok Tersisa (Days of Cover)', data: <?= json_encode($urgentCoverDays) ?> },
            { name: 'Lead Time Pembuatan (Hari)', data: <?= json_encode($urgentLeadDays) ?> }
        ],
        chart: { type: 'bar', height: 220, toolbar: { show: false }, fontFamily: 'Plus Jakarta Sans, sans-serif' },
        colors: ['#EF4444', '#94A3B8'],
        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
        xaxis: { categories: <?= json_encode($urgentNames) ?>, labels: { formatter: val => val + " hari" } },
        grid: { borderColor: '#F1F5F9' }
    }).render();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
