<?php
$pageTitle = "Sistem Pendukung Keputusan (DSS) Pemilik Usaha";
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

// 1. ANALISIS KELAYAKAN STOK & VELOCITY PENJUALAN (30 HARI TERAKHIR)
$velocityQuery = "SELECT 
    p.id, p.code, p.name, p.motif, p.material, p.stock_quantity, p.min_stock_threshold, p.lead_time_days,
    p.production_cost, p.selling_price,
    c.name as category_name,
    COALESCE(SUM(si.quantity), 0) as sold_qty_all,
    ROUND(((p.selling_price - p.production_cost) / p.selling_price) * 100, 1) as profit_margin_pct
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN sale_items si ON p.id = si.product_id
    GROUP BY p.id
    ORDER BY sold_qty_all DESC";
$productsVelocity = $pdo->query($velocityQuery)->fetchAll();

// Klasifikasi Produk Berdasarkan Algoritma Decision Support
$urgentRestock = [];
$slowMoving = [];
$starProducts = [];
$cashCows = [];

foreach ($productsVelocity as $prod) {
    // Estimasi penjualan bulanan
    $monthlySalesEst = max(1, round($prod['sold_qty_all'] / 11)); // Estimasi rata-rata per bulan
    $stockDaysCover = ($monthlySalesEst > 0) ? round(($prod['stock_quantity'] / $monthlySalesEst) * 30) : 999;

    $prod['monthly_sales_est'] = $monthlySalesEst;
    $prod['stock_days_cover'] = $stockDaysCover;

    // Prioritas Restock Mendesak: Stok kurang dari atau sama dengan threshold, atau cover < lead time
    if ($prod['stock_quantity'] <= $prod['min_stock_threshold'] || $stockDaysCover <= $prod['lead_time_days']) {
        $neededQty = max(5, ($monthlySalesEst * 2) - $prod['stock_quantity']);
        $prod['recommended_production_qty'] = $neededQty;
        $urgentRestock[] = $prod;
    }

    // Slow-Moving: Stok melimpah (> 3x threshold) dan penjualan bulanan rendah (<= 2 pcs)
    if ($prod['stock_quantity'] > ($prod['min_stock_threshold'] * 2) && $monthlySalesEst <= 2) {
        $slowMoving[] = $prod;
    }

    // Star: Permintaan tinggi (> 20 pcs) dan Margin tinggi (> 45%)
    if ($prod['sold_qty_all'] >= 20 && $prod['profit_margin_pct'] >= 45) {
        $starProducts[] = $prod;
    }

    // Cash Cow: Permintaan tinggi (> 25 pcs)
    if ($prod['sold_qty_all'] >= 25 && $prod['profit_margin_pct'] < 45) {
        $cashCows[] = $prod;
    }
}

// 2. DATA BAHAN BAKU
$rawMaterials = $pdo->query("SELECT * FROM raw_materials ORDER BY (stock_quantity / min_threshold) ASC")->fetchAll();

// 2b. DATA CHART 24: STOK KRITIS VS LEAD TIME
$urgentNames = [];
$urgentCoverDays = [];
$urgentLeadDays = [];
foreach ($urgentRestock as $ur) {
    $urgentNames[] = $ur['name'];
    $urgentCoverDays[] = (int)$ur['stock_days_cover'];
    $urgentLeadDays[] = (int)$ur['lead_time_days'];
}

// 3. DAFTAR REKOMENDASI AKTIF DARI DATABASE
$dbRecommendations = $pdo->query("SELECT dr.*, p.name as product_name, p.code as product_code 
    FROM decision_recommendations dr 
    LEFT JOIN products p ON dr.product_id = p.id 
    WHERE dr.status = 'Aktif' 
    ORDER BY CASE dr.priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Header Title -->
<div class="pb-6 border-b border-slate-200">
    <div class="flex items-center space-x-2">
        <span class="px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase rounded-full bg-purple-100 text-purple-700 border border-purple-300">
            <i class="fas fa-brain mr-1"></i> Decision Support System (DSS)
        </span>
        <span class="text-xs text-slate-500 font-medium">Bantuan Pengambilan Keputusan Pemilik Usaha</span>
    </div>
    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">
        Rekomendasi Strategis Berbasis Data
    </h1>
    <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
        Membantu pemilik usaha Batik Bomba menentukan prioritas produksi kain, mitigasi kehabisan stok, alokasi bahan baku, dan strategi harga.
    </p>
</div>

<!-- SECTION 1: ALERT KRITIS & REKOMENDASI PRIORITAS PRODUKSI -->
<div class="my-6">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-bold text-slate-900 flex items-center" id="stok-kritis">
            <i class="fas fa-exclamation-triangle mr-2 text-rose-500"></i> Prioritas Produksi Mendesak (Mencegah Kehabisan Stok / Stockout)
        </h3>
        <span class="text-xs text-rose-600 font-semibold"><?= count($urgentRestock) ?> Produk Butuh Perhatian Segera</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($urgentRestock as $ur): ?>
        <div class="glass-card rounded-2xl p-5 border-l-4 border-l-rose-500 shadow-sm relative overflow-hidden">
            <div class="flex justify-between items-start mb-2">
                <span class="font-mono text-[10px] font-bold bg-rose-100 text-rose-700 px-2 py-0.5 rounded"><?= htmlspecialchars($ur['code']) ?></span>
                <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-full bg-rose-500 text-white animate-pulse">Sisa: <?= $ur['stock_quantity'] ?> pcs</span>
            </div>
            <h4 class="font-bold text-sm text-slate-900"><?= htmlspecialchars($ur['name']) ?></h4>
            <p class="text-xs text-slate-500 mt-0.5">Motif: <strong class="text-bomba-700"><?= htmlspecialchars($ur['motif']) ?></strong> &bull; <?= htmlspecialchars($ur['category_name']) ?></p>

            <div class="mt-3 bg-rose-50/70 p-3 rounded-xl border border-rose-100 text-xs text-slate-700 space-y-1">
                <div class="flex justify-between">
                    <span>Kecepatan Jual Rata-rata:</span>
                    <span class="font-bold text-slate-900"><?= $ur['monthly_sales_est'] ?> pcs / bulan</span>
                </div>
                <div class="flex justify-between">
                    <span>Ketahanan Sisa Stok:</span>
                    <span class="font-bold text-rose-600">&plusmn; <?= $ur['stock_days_cover'] ?> hari ke depan</span>
                </div>
                <div class="flex justify-between">
                    <span>Lead Time Pengerjaan:</span>
                    <span class="font-bold text-slate-800"><?= $ur['lead_time_days'] ?> hari penenunan</span>
                </div>
            </div>

            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                <div>
                    <span class="text-[10px] text-slate-400 block">Saran Batch Produksi:</span>
                    <span class="text-xs font-extrabold text-bomba-700">+<?= $ur['recommended_production_qty'] ?> Lembar Segera</span>
                </div>
                <a href="production.php" class="px-3 py-1.5 bg-bomba-600 hover:bg-bomba-700 text-white font-bold rounded-lg text-xs shadow-sm transition">
                    <i class="fas fa-hammer mr-1"></i> Buka Batch
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- SECTION 2: INSIGHT BISNIS & REKOMENDASI SISTEM -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 my-6">
    <!-- Kolom Kiri: Rekomendasi Resmi Sistem DSS -->
    <div class="glass-card rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-bold text-slate-900 flex items-center">
                    <i class="fas fa-lightbulb mr-2 text-amber-500"></i> Rangkuman Keputusan Manajerial
                </h3>
                <p class="text-xs text-slate-500">Saran tindakan terverifikasi berdasarkan analisis data</p>
            </div>
        </div>

        <div class="mt-4 space-y-3.5">
            <?php foreach ($dbRecommendations as $rec): 
                $priorityBadge = 'bg-amber-100 text-amber-800 border-amber-300';
                if ($rec['priority'] === 'High') $priorityBadge = 'bg-rose-100 text-rose-800 border-rose-300';
                if ($rec['priority'] === 'Low') $priorityBadge = 'bg-blue-100 text-blue-800 border-blue-300';
            ?>
            <div class="p-3.5 rounded-xl border border-slate-200 bg-white hover:border-bomba-400 transition">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border <?= $priorityBadge ?>">
                        Prioritas <?= $rec['priority'] ?>
                    </span>
                    <span class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($rec['type']) ?></span>
                </div>
                <h4 class="font-bold text-xs text-slate-900"><?= htmlspecialchars($rec['title']) ?></h4>
                <p class="text-xs text-slate-600 mt-1 leading-relaxed"><?= htmlspecialchars($rec['description']) ?></p>
                <div class="mt-2.5 bg-slate-50 p-2.5 rounded-lg border border-slate-100 text-xs text-slate-800">
                    <span class="font-bold text-bomba-700 block text-[11px]"><i class="fas fa-check-circle mr-1"></i> Tindakan yang Disarankan:</span>
                    <span class="text-slate-600"><?= htmlspecialchars($rec['suggested_action']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Kolom Kanan: Evaluasi Produk Slow-Moving & Strategi Cuci Gudang -->
    <div class="glass-card rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-bold text-slate-900 flex items-center">
                    <i class="fas fa-hourglass-end mr-2 text-blue-500"></i> Evaluasi Produk Slow-Moving (Perputaran Lambat)
                </h3>
                <p class="text-xs text-slate-500">Stok mengendap di galeri yang perlu stimulus penjualan</p>
            </div>
            <span class="text-xs font-semibold text-slate-500"><?= count($slowMoving) ?> Produk Terdeteksi</span>
        </div>

        <div class="mt-4 space-y-3">
            <?php if (empty($slowMoving)): ?>
                <div class="text-center py-8 text-slate-400 text-xs">
                    <i class="fas fa-check-circle text-emerald-500 text-2xl mb-1"></i>
                    <p>Semua produk memiliki perputaran stok yang sehat!</p>
                </div>
            <?php else: ?>
                <?php foreach ($slowMoving as $sm): ?>
                <div class="p-3.5 rounded-xl border border-slate-200 bg-white">
                    <div class="flex justify-between items-start">
                        <div>
                            <span class="font-mono text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded font-bold"><?= htmlspecialchars($sm['code']) ?></span>
                            <h4 class="font-bold text-xs text-slate-900 mt-1"><?= htmlspecialchars($sm['name']) ?></h4>
                            <div class="text-[10px] text-slate-500">Stok Galeri: <strong class="text-slate-900"><?= $sm['stock_quantity'] ?> pcs</strong> (Modal Tertahan: <?= formatRupiah($sm['stock_quantity'] * $sm['production_cost']) ?>)</div>
                        </div>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-blue-100 text-blue-800">Perputaran Rendah</span>
                    </div>
                    <div class="mt-2.5 p-2 bg-blue-50/50 rounded-lg border border-blue-100 text-xs text-slate-700">
                        <span class="font-semibold text-blue-900 text-[11px] block"><i class="fas fa-lightbulb mr-1 text-amber-500"></i> Solusi Rekomendasi:</span>
                        <span>Bundling paket dengan selendang sutra saat pameran Dekranasda, atau tawarkan promo diskon 10% untuk seragam kantor.</span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- SECTION 3: KALKULATOR PROYEKSI KEBUTUHAN BAHAN BAKU -->
<div class="glass-card rounded-2xl p-5 shadow-sm my-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-100 gap-2">
        <div>
            <h3 class="text-sm font-bold text-slate-900 flex items-center">
                <i class="fas fa-cubes mr-2 text-emerald-600"></i> Proyeksi Kebutuhan Bahan Baku Sentra Tenun & Batik
            </h3>
            <p class="text-xs text-slate-500">Memastikan ketersediaan benang sutra Donggala, mori primissima, dan pewarna alam untuk kelancaran penenunan</p>
        </div>
        <div class="flex items-center space-x-2 text-xs">
            <span class="text-slate-500 font-medium">Proyeksi Target:</span>
            <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 font-bold rounded-lg border border-emerald-200">2 Bulan ke Depan</span>
        </div>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-600">
            <thead class="text-slate-400 bg-slate-50 uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="py-3 px-3">Nama Bahan Baku</th>
                    <th class="py-3 px-3">Kategori</th>
                    <th class="py-3 px-3 text-right">Stok Gudang</th>
                    <th class="py-3 px-3 text-right">Batas Aman Min</th>
                    <th class="py-3 px-3 text-right">Estimasi Kebutuhan (2 Bln)</th>
                    <th class="py-3 px-3 text-center">Status Bahan</th>
                    <th class="py-3 px-3 text-right">Saran Pembelian</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($rawMaterials as $mat): 
                    $estNeed2Mo = round($mat['min_threshold'] * 2.5, 1);
                    $deficit = max(0, round($estNeed2Mo - $mat['stock_quantity'], 1));
                    $isMatCritical = $mat['stock_quantity'] <= $mat['min_threshold'];
                ?>
                <tr class="hover:bg-slate-50/70 transition">
                    <td class="py-3 px-3 font-bold text-slate-900">
                        <?= htmlspecialchars($mat['name']) ?>
                        <div class="text-[10px] font-normal text-slate-400">Harga Satuan: <?= formatRupiah($mat['unit_price']) ?> / <?= htmlspecialchars($mat['unit']) ?></div>
                    </td>
                    <td class="py-3 px-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-700">
                            <?= htmlspecialchars($mat['category']) ?>
                        </span>
                    </td>
                    <td class="py-3 px-3 text-right font-bold text-slate-900">
                        <?= $mat['stock_quantity'] ?> <span class="text-[10px] font-normal text-slate-500"><?= htmlspecialchars($mat['unit']) ?></span>
                    </td>
                    <td class="py-3 px-3 text-right text-slate-600">
                        <?= $mat['min_threshold'] ?> <?= htmlspecialchars($mat['unit']) ?>
                    </td>
                    <td class="py-3 px-3 text-right font-bold text-slate-800">
                        &plusmn; <?= $estNeed2Mo ?> <?= htmlspecialchars($mat['unit']) ?>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <?php if ($isMatCritical): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-700 animate-pulse">
                                Kritis / Menipis
                            </span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">
                                Cukup
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 text-right font-bold <?= $deficit > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">
                        <?php if ($deficit > 0): ?>
                            Order +<?= $deficit ?> <?= htmlspecialchars($mat['unit']) ?>
                            <div class="text-[10px] text-slate-400 font-normal">Est: <?= formatRupiah($deficit * $mat['unit_price']) ?></div>
                        <?php else: ?>
                            <span class="text-slate-400 font-normal">Stok Memadai</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SECTION 4: SIMULATOR SKENARIO BISNIS (WHAT-IF CALCULATOR INTERAKTIF) -->
<div class="glass-card rounded-2xl p-5 shadow-sm my-6 bg-gradient-to-br from-white to-amber-50/40 border border-amber-200/60">
    <div class="flex items-center space-x-2 mb-1">
        <span class="px-2 py-0.5 rounded bg-amber-100 text-bomba-700 text-[10px] font-bold uppercase tracking-wider">
            Simulasi Strategis Interaktif
        </span>
    </div>
    <h3 class="text-base font-extrabold text-slate-900 flex items-center">
        <i class="fas fa-sliders mr-2 text-bomba-600"></i> Simulator Target Penjualan & Kebutuhan Kapasitas Tenun
    </h3>
    <p class="text-xs text-slate-500 mb-4">
        Geser target pertumbuhan omset untuk melihat proyeksi kuantitas kain yang harus diproduksi dan estimasi laba bersih.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
        <!-- Slider Input -->
        <div class="space-y-3">
            <label class="block text-xs font-bold text-slate-700">
                Target Pertumbuhan Omset: <span id="growthLabel" class="text-bomba-700 font-extrabold text-sm">+20%</span>
            </label>
            <input type="range" id="growthSlider" min="5" max="50" step="5" value="20" oninput="runSimulation(this.value)" class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-bomba-600">
            <div class="flex justify-between text-[10px] text-slate-400">
                <span>+5% Konservatif</span>
                <span>+25% Moderat</span>
                <span>+50% Agresif</span>
            </div>
        </div>

        <!-- Hasil Output Proyeksi -->
        <div class="md:col-span-2 grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div class="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm text-center">
                <span class="text-[10px] text-slate-400 uppercase font-bold block">Proyeksi Omset</span>
                <div id="simRevenue" class="text-sm sm:text-base font-extrabold text-emerald-600 mt-1">Rp 0</div>
            </div>
            <div class="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm text-center">
                <span class="text-[10px] text-slate-400 uppercase font-bold block">Target Produksi Kain</span>
                <div id="simProdUnits" class="text-sm sm:text-base font-extrabold text-blue-600 mt-1">0 Pcs</div>
            </div>
            <div class="bg-white p-3.5 rounded-xl border border-slate-200 shadow-sm text-center col-span-2 sm:col-span-1">
                <span class="text-[10px] text-slate-400 uppercase font-bold block">Estimasi Laba Bersih</span>
                <div id="simProfit" class="text-sm sm:text-base font-extrabold text-bomba-700 mt-1">Rp 0</div>
            </div>
        </div>
    </div>
</div>

<script>
// Data baseline tahun berjalan
const baseRevenue = 85000000; // rata-rata omset bulanan acuan
const baseUnits = 45; // rata-rata pcs per bulan
const profitMargin = 0.52; // margin rata-rata 52%

function runSimulation(growthPct) {
    document.getElementById('growthLabel').innerText = `+${growthPct}%`;
    const factor = 1 + (growthPct / 100);
    
    const projRev = baseRevenue * factor;
    const projUnits = Math.round(baseUnits * factor);
    const projProfit = projRev * profitMargin;

    document.getElementById('simRevenue').innerText = "Rp " + Math.round(projRev).toLocaleString('id-ID');
    document.getElementById('simProdUnits').innerText = projUnits + " Pcs Kain";
    document.getElementById('simProfit').innerText = "Rp " + Math.round(projProfit).toLocaleString('id-ID');
}

document.addEventListener("DOMContentLoaded", function() {
    runSimulation(20);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
