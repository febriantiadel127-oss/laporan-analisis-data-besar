<?php
$pageTitle = "Laporan Analitik & Export Data";
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

$startDate = $_GET['start_date'] ?? '2026-01-01';
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$reportType = $_GET['report_type'] ?? 'sales_summary';

// 1. DATA RINGKASAN SESUAI FILTER TANGGAL
$salesReportQuery = "SELECT 
    s.*,
    COUNT(si.id) as total_items,
    SUM(si.quantity) as total_qty
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    WHERE s.sale_date >= ? AND s.sale_date <= ?
    GROUP BY s.id
    ORDER BY s.sale_date DESC";
$stmtSR = $pdo->prepare($salesReportQuery);
$stmtSR->execute([$startDate, $endDate]);
$reportSales = $stmtSR->fetchAll();

// Total kalkulasi
$grandTotalOmset = 0;
$grandTotalCost = 0;
$grandTotalProfit = 0;
$grandTotalPcs = 0;

foreach ($reportSales as $rs) {
    $grandTotalOmset += $rs['total_amount'];
    $grandTotalCost += $rs['total_cost'];
    $grandTotalProfit += $rs['total_profit'];
    $grandTotalPcs += $rs['total_qty'];
}

// 2. DATA PRODUKSI PADA PERIODE
$prodReportQuery = "SELECT 
    pr.*,
    p.name as product_name, p.motif,
    a.name as artisan_name
    FROM productions pr
    JOIN products p ON pr.product_id = p.id
    JOIN artisans a ON pr.artisan_id = a.id
    WHERE pr.start_date >= ? AND pr.start_date <= ?
    ORDER BY pr.start_date DESC";
$stmtPR = $pdo->prepare($prodReportQuery);
$stmtPR->execute([$startDate, $endDate]);
$reportProductions = $stmtPR->fetchAll();

$grandTotalProdCost = 0;
$grandTotalProdUnits = 0;
foreach ($reportProductions as $rp) {
    $grandTotalProdCost += $rp['total_cost'];
    $grandTotalProdUnits += ($rp['status'] === 'Selesai' ? $rp['actual_quantity'] : $rp['target_quantity']);
}

include __DIR__ . '/includes/header.php';
?>

<!-- Header Toolbar (Sembunyi saat dicetak) -->
<div class="no-print pb-6 border-b border-slate-200">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2">
                <span class="px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase rounded-full bg-slate-100 text-slate-700 border border-slate-300">
                    <i class="fas fa-file-invoice mr-1"></i> Pelaporan Bisnis
                </span>
                <span class="text-xs text-slate-500 font-medium">Export & Dokumen Resmi Usaha</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">
                Pusat Laporan & Ekspor Data
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
                Cetak dokumen format A4 laporan analitik produksi & penjualan untuk evaluasi pimpinan usaha.
            </p>
        </div>

        <!-- Tombol Aksi Cetak & CSV -->
        <div class="flex items-center space-x-2">
            <button onclick="window.print()" class="px-4 py-2 bg-bomba-600 hover:bg-bomba-700 text-white font-bold text-xs rounded-xl shadow-md transition flex items-center">
                <i class="fas fa-print mr-2"></i> Cetak Dokumen / Simpan PDF
            </button>
            <a href="api.php?action=export_csv&type=sales" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs rounded-xl shadow-sm transition flex items-center">
                <i class="fas fa-file-excel mr-1.5 text-emerald-600"></i> CSV Penjualan
            </a>
            <a href="api.php?action=export_csv&type=production" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs rounded-xl shadow-sm transition flex items-center">
                <i class="fas fa-file-excel mr-1.5 text-blue-600"></i> CSV Produksi
            </a>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="glass-card rounded-2xl p-4 mt-4 shadow-sm">
        <form action="reports.php" method="GET" class="flex flex-wrap items-center gap-4 text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">Dari Tanggal:</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-medium">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">Sampai Tanggal:</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 font-medium">
            </div>
            <div class="self-end">
                <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-lg shadow-sm transition">
                    <i class="fas fa-filter mr-1"></i> Terapkan Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- KOP SURAT RESMI (Tampil Bagus saat dicetak / PDF) -->
<div class="print-full bg-white p-6 sm:p-8 rounded-2xl shadow-sm border border-slate-200 mt-6">
    <!-- Header Kop Surat -->
    <div class="border-b-2 border-slate-900 pb-4 mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-14 h-14 rounded-xl bg-bomba-700 text-gold-400 flex items-center justify-center text-2xl font-bold shadow">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <h2 class="text-xl sm:text-2xl font-serif font-black tracking-wider text-slate-900">BATIK BOMBA & TENUN DONGGALA</h2>
                    <p class="text-xs text-slate-600 font-medium">Sentra Kerajinan Wastra Tradisional Sulawesi Tengah &bull; Palu - Banawa Donggala</p>
                    <p class="text-[10px] text-slate-400">Kota Palu, Sulawesi Tengah | IG: @batikbomba_khaskotapalu | Telp/WA Sentra: (0451) 481-229</p>
                </div>
            </div>
            <div class="text-right text-xs text-slate-500">
                <span class="font-bold text-slate-900 block text-sm uppercase tracking-wider">Laporan Resmi Analitik</span>
                <span>Periode: <?= date('d M Y', strtotime($startDate)) ?> s.d. <?= date('d M Y', strtotime($endDate)) ?></span>
                <span class="block text-[10px] text-slate-400 mt-0.5">Dicetak: <?= date('d/m/Y H:i') ?> WITA</span>
            </div>
        </div>
    </div>

    <!-- Ringkasan Eksekutif Angka -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 p-4 bg-slate-50 rounded-xl border border-slate-200">
        <div>
            <span class="text-[10px] text-slate-400 uppercase font-bold block">Total Omset Penjualan</span>
            <div class="text-base sm:text-lg font-extrabold text-emerald-600 mt-0.5"><?= formatRupiah($grandTotalOmset) ?></div>
            <span class="text-[10px] text-slate-500"><?= count($reportSales) ?> Transaksi Terdata</span>
        </div>
        <div>
            <span class="text-[10px] text-slate-400 uppercase font-bold block">Total Laba Kotor</span>
            <div class="text-base sm:text-lg font-extrabold text-bomba-700 mt-0.5"><?= formatRupiah($grandTotalProfit) ?></div>
            <span class="text-[10px] text-bomba-600 font-semibold">Margin: <?= ($grandTotalOmset > 0) ? round(($grandTotalProfit / $grandTotalOmset) * 100, 1) : 0 ?>%</span>
        </div>
        <div>
            <span class="text-[10px] text-slate-400 uppercase font-bold block">Volume Produk Terjual</span>
            <div class="text-base sm:text-lg font-extrabold text-blue-600 mt-0.5"><?= number_format($grandTotalPcs, 0, ',', '.') ?> Pcs</div>
            <span class="text-[10px] text-slate-500">Kain Tenun & Batik</span>
        </div>
        <div>
            <span class="text-[10px] text-slate-400 uppercase font-bold block">Realisasi Biaya Produksi</span>
            <div class="text-base sm:text-lg font-extrabold text-slate-900 mt-0.5"><?= formatRupiah($grandTotalProdCost) ?></div>
            <span class="text-[10px] text-slate-500"><?= count($reportProductions) ?> Batch Dikerjakan</span>
        </div>
    </div>

    <!-- Rincian Transaksi Penjualan -->
    <h3 class="text-sm font-extrabold text-slate-900 mb-2 flex items-center">
        <i class="fas fa-list mr-2 text-emerald-600"></i> Rincian Realisasi Penjualan
    </h3>
    <div class="overflow-x-auto mb-8">
        <table class="w-full text-left text-xs text-slate-700 border border-slate-200">
            <thead class="bg-slate-100 text-slate-700 text-[10px] font-bold uppercase">
                <tr>
                    <th class="py-2 px-2.5 border-b">No Invoice</th>
                    <th class="py-2 px-2.5 border-b">Tanggal</th>
                    <th class="py-2 px-2.5 border-b">Pelanggan & Kategori</th>
                    <th class="py-2 px-2.5 border-b">Saluran</th>
                    <th class="py-2 px-2.5 border-b text-center">Unit</th>
                    <th class="py-2 px-2.5 border-b text-right">Nilai Total</th>
                    <th class="py-2 px-2.5 border-b text-right">Laba Kotor</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php foreach (array_slice($reportSales, 0, 20) as $rs): ?>
                <tr>
                    <td class="py-2 px-2.5 font-mono font-bold text-slate-900"><?= htmlspecialchars($rs['invoice_number']) ?></td>
                    <td class="py-2 px-2.5 text-slate-600"><?= date('d/m/Y', strtotime($rs['sale_date'])) ?></td>
                    <td class="py-2 px-2.5 font-medium"><?= htmlspecialchars($rs['customer_name']) ?> <span class="text-[10px] text-slate-400">(<?= htmlspecialchars($rs['customer_type']) ?>)</span></td>
                    <td class="py-2 px-2.5"><?= htmlspecialchars($rs['sales_channel']) ?></td>
                    <td class="py-2 px-2.5 text-center"><?= $rs['total_qty'] ?> pcs</td>
                    <td class="py-2 px-2.5 text-right font-bold"><?= formatRupiah($rs['total_amount']) ?></td>
                    <td class="py-2 px-2.5 text-right font-semibold text-emerald-700"><?= formatRupiah($rs['total_profit']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-slate-50 font-bold text-slate-900 border-t-2 border-slate-300">
                <tr>
                    <td colspan="4" class="py-2.5 px-2.5 text-right uppercase text-[10px]">Grand Total (Periode Terpilih):</td>
                    <td class="py-2.5 px-2.5 text-center"><?= number_format($grandTotalPcs, 0, ',', '.') ?> pcs</td>
                    <td class="py-2.5 px-2.5 text-right text-emerald-700"><?= formatRupiah($grandTotalOmset) ?></td>
                    <td class="py-2.5 px-2.5 text-right text-bomba-700"><?= formatRupiah($grandTotalProfit) ?></td>
                </tr>
            </tfoot>
        </table>
        <?php if (count($reportSales) > 20): ?>
            <p class="text-[10px] text-slate-400 italic mt-1">* Menampilkan 20 transaksi pertama pada pratinjau dokumen. Untuk data lengkap, gunakan tombol Export CSV di atas.</p>
        <?php endif; ?>
    </div>

    <!-- Tanda Tangan Pengesahan Manajemen Usaha -->
    <div class="grid grid-cols-2 mt-12 pt-6 border-t border-slate-200 text-xs">
        <div>
            <p class="text-slate-500">Mengetahui & Menyetujui,</p>
            <p class="font-bold text-slate-900 mt-0.5">Pimpinan Usaha Kerajinan</p>
            <div class="h-16"></div>
            <p class="font-bold text-slate-900 underline">H. Moh. Rizal, S.E., M.Si.</p>
            <p class="text-[10px] text-slate-500">Batik & Tenun Bomba Palu</p>
        </div>
        <div class="text-right">
            <p class="text-slate-500">Palu, <?= date('d F Y') ?></p>
            <p class="font-bold text-slate-900 mt-0.5">Penanggung Jawab Produksi & Keuangan</p>
            <div class="h-16"></div>
            <p class="font-bold text-slate-900 underline">Nurul Fadilah, S.Ak.</p>
            <p class="text-[10px] text-slate-500">Manajer Operasional Sentra</p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
