<?php
$pageTitle = "Analisis & Manajemen Produksi";
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

// Filter Status jika ada
$statusFilter = $_GET['status'] ?? 'all';
$queryWhere = "1=1";
if ($statusFilter !== 'all') {
    $queryWhere = "pr.status = " . $pdo->quote($statusFilter);
}

// 1. METRIK PRODUKSI
$prodStats = $pdo->query("SELECT 
    COUNT(*) as total_batch,
    COALESCE(SUM(target_quantity), 0) as total_target_unit,
    COALESCE(SUM(actual_quantity), 0) as total_actual_unit,
    COALESCE(SUM(reject_quantity), 0) as total_reject_unit,
    COALESCE(SUM(total_cost), 0) as total_cost_all,
    COALESCE(SUM(material_cost), 0) as total_mat_cost,
    COALESCE(SUM(labor_cost), 0) as total_labor_cost,
    COALESCE(SUM(CASE WHEN status != 'Selesai' THEN target_quantity ELSE 0 END), 0) as ongoing_unit
    FROM productions pr WHERE $queryWhere")->fetch();

// 2. KANBAN / STATUS PIPELINE COUNTS
$pipelineCounts = [
    'Persiapan Bahan' => 0,
    'Pengerjaan (Tenun/Batik)' => 0,
    'Pewarnaan & Fiksasi' => 0,
    'Quality Control' => 0,
    'Selesai' => 0
];
$statusRows = $pdo->query("SELECT status, COUNT(*) as count FROM productions GROUP BY status")->fetchAll();
foreach ($statusRows as $sr) {
    if (isset($pipelineCounts[$sr['status']])) {
        $pipelineCounts[$sr['status']] = (int)$sr['count'];
    }
}

// 3. PRODUKTIVITAS PENGRAJIN (Top Artisans)
$artisanProdQuery = "SELECT 
    a.name, a.specialty,
    COUNT(pr.id) as batch_count,
    SUM(CASE WHEN pr.status = 'Selesai' THEN pr.actual_quantity ELSE pr.target_quantity END) as total_units
    FROM artisans a
    LEFT JOIN productions pr ON a.id = pr.artisan_id
    GROUP BY a.id
    ORDER BY total_units DESC";
$artisanProd = $pdo->query($artisanProdQuery)->fetchAll();

// 3b. DATA VOLUME PRODUKSI BULANAN PER KATEGORI (CHART 07)
$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$prodByCatQuery = "SELECT 
    strftime('%m', pr.start_date) as bulan,
    c.name as category_name,
    SUM(CASE WHEN pr.status = 'Selesai' THEN pr.actual_quantity ELSE pr.target_quantity END) as qty
    FROM productions pr
    JOIN products p ON pr.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    WHERE pr.start_date LIKE '2026%'
    GROUP BY bulan, c.name";
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

// 3c. LEAD TIME RATA-RATA PER KATEGORI (CHART 08)
$leadTimeQuery = "SELECT 
    c.name as category_name,
    ROUND(AVG(p.lead_time_days), 1) as avg_days
    FROM products p
    JOIN categories c ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY avg_days DESC";
$leadTimeData = $pdo->query($leadTimeQuery)->fetchAll();

// 3d. DEFECT / REJECT RATE PER KATEGORI (CHART 11)
$rejectQuery = "SELECT 
    c.name as category_name,
    COALESCE(SUM(pr.reject_quantity), 0) as total_reject,
    COALESCE(SUM(pr.target_quantity), 0) as total_target
    FROM productions pr
    JOIN products p ON pr.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    GROUP BY c.id";
$rejectRaw = $pdo->query($rejectQuery)->fetchAll();
$rejectCategories = [];
$rejectPercentages = [];
foreach ($rejectRaw as $r) {
    $rejectCategories[] = $r['category_name'];
    $pct = ($r['total_target'] > 0) ? round(($r['total_reject'] / $r['total_target']) * 100, 2) : 0;
    $rejectPercentages[] = $pct;
}

// 4. DAFTAR SELURUH BATCH PRODUKSI
$batchesQuery = "SELECT 
    pr.*, 
    p.name as product_name, p.code as product_code, p.motif, p.material,
    a.name as artisan_name, a.specialty as artisan_specialty
    FROM productions pr
    JOIN products p ON pr.product_id = p.id
    JOIN artisans a ON pr.artisan_id = a.id
    WHERE $queryWhere
    ORDER BY pr.id DESC";
$batches = $pdo->query($batchesQuery)->fetchAll();

// Ambil list produk & artisans untuk formulir modal
$productsList = $pdo->query("SELECT id, name, code, motif FROM products ORDER BY name ASC")->fetchAll();
$artisansList = $pdo->query("SELECT id, name, specialty FROM artisans WHERE status = 'Aktif' ORDER BY name ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Header & Action Button -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between pb-6 border-b border-slate-200 gap-4">
    <div>
        <div class="flex items-center space-x-2">
            <span class="px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase rounded-full bg-blue-100 text-blue-700 border border-blue-300">
                <i class="fas fa-industry mr-1"></i> Modul Produksi Tenun & Batik
            </span>
            <span class="text-xs text-slate-500 font-medium">Monitoring Sentra Pengrajin Palu & Donggala</span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">
            Analisis & Siklus Produksi
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
            Manajemen batch penenunan ATBM sutra, batik tulis canting, pencapan motif Bomba, dan alokasi pengrajin.
        </p>
    </div>

    <!-- Tombol Tambah Batch Produksi -->
    <div class="flex items-center space-x-3">
        <a href="api.php?action=export_csv&type=production" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-xl shadow-sm transition flex items-center">
            <i class="fas fa-file-csv mr-2 text-emerald-600"></i> Export CSV
        </a>
        <button onclick="document.getElementById('modalAddProduction').classList.remove('hidden')" class="px-4 py-2 bg-bomba-600 hover:bg-bomba-700 text-white text-xs font-bold rounded-xl shadow-md transition flex items-center">
            <i class="fas fa-plus mr-2"></i> Tambah Batch Produksi
        </button>
    </div>
</div>

<!-- SECTION 1: METRIK RINGKASAN PRODUKSI -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 my-6">
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-blue-500">
        <span class="text-xs text-slate-500 font-medium">Total Batch Produksi</span>
        <div class="mt-1 text-2xl font-extrabold text-slate-900"><?= $prodStats['total_batch'] ?> <span class="text-xs font-normal text-slate-500">Batch</span></div>
        <div class="mt-2 text-[11px] text-blue-600 font-medium"><i class="fas fa-tasks mr-1"></i> Seluruh Siklus Terpantau</div>
    </div>
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-emerald-500">
        <span class="text-xs text-slate-500 font-medium">Kain Selesai Masuk Stok</span>
        <div class="mt-1 text-2xl font-extrabold text-slate-900"><?= number_format($prodStats['total_actual_unit'], 0, ',', '.') ?> <span class="text-xs font-normal text-slate-500">Pcs</span></div>
        <div class="mt-2 text-[11px] text-emerald-600 font-medium"><i class="fas fa-check-circle mr-1"></i> Siap Distribusi & Galeri</div>
    </div>
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-amber-500">
        <span class="text-xs text-slate-500 font-medium">Unit Sedang Dikerjakan</span>
        <div class="mt-1 text-2xl font-extrabold text-slate-900"><?= number_format($prodStats['ongoing_unit'], 0, ',', '.') ?> <span class="text-xs font-normal text-slate-500">Pcs</span></div>
        <div class="mt-2 text-[11px] text-amber-600 font-medium"><i class="fas fa-hourglass-half mr-1"></i> Dalam Proses Pengrajin</div>
    </div>
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-purple-500">
        <span class="text-xs text-slate-500 font-medium">Total Akumulasi Biaya Produksi</span>
        <div class="mt-1 text-xl font-extrabold text-slate-900"><?= formatRupiah($prodStats['total_cost_all']) ?></div>
        <div class="mt-2 text-[11px] text-slate-500">Bahan: <?= formatRupiah($prodStats['total_mat_cost']) ?> &bull; Upah: <?= formatRupiah($prodStats['total_labor_cost']) ?></div>
    </div>
</div>

<!-- SECTION 2: PIPELINE TAHAPAN PRODUKSI KAIN (KANBAN STATUS) -->
<div class="mb-8">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-bold text-slate-900 flex items-center">
            <i class="fas fa-stream mr-2 text-bomba-600"></i> Pipeline Tahapan Pengerjaan Kain Tenun & Batik
        </h3>
        <span class="text-xs text-slate-500">Klik status untuk menyaring tabel</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <?php
        $pipelineSteps = [
            'Persiapan Bahan' => ['icon' => 'fa-box-open', 'color' => 'slate'],
            'Pengerjaan (Tenun/Batik)' => ['icon' => 'fa-hammer', 'color' => 'blue'],
            'Pewarnaan & Fiksasi' => ['icon' => 'fa-flask', 'color' => 'purple'],
            'Quality Control' => ['icon' => 'fa-clipboard-check', 'color' => 'amber'],
            'Selesai' => ['icon' => 'fa-check-double', 'color' => 'emerald']
        ];
        foreach ($pipelineSteps as $stepName => $stepInfo):
            $count = $pipelineCounts[$stepName] ?? 0;
            $isActive = ($statusFilter === $stepName);
        ?>
        <a href="production.php?status=<?= urlencode($stepName) ?>" class="p-3 rounded-xl border transition-all duration-200 <?= $isActive ? 'bg-bomba-50 border-bomba-500 ring-2 ring-bomba-400' : 'bg-white border-slate-200 hover:border-slate-300 hover:shadow-sm' ?>">
            <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                <i class="fas <?= $stepInfo['icon'] ?> text-<?= $stepInfo['color'] ?>-500 text-sm"></i>
                <span class="font-extrabold text-slate-900 text-sm"><?= $count ?></span>
            </div>
            <p class="text-xs font-semibold text-slate-800 truncate"><?= $stepName ?></p>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- SECTION 4: TABEL SELURUH BATCH PRODUKSI -->
<div class="glass-card rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-100 gap-3">
        <div>
            <h3 class="text-sm font-bold text-slate-900 flex items-center">
                <i class="fas fa-list-check mr-2 text-bomba-600"></i> Riwayat & Status Seluruh Batch Produksi
            </h3>
            <p class="text-xs text-slate-500">Total <?= count($batches) ?> batch tercatat di sistem</p>
        </div>
        <?php if ($statusFilter !== 'all'): ?>
            <a href="production.php" class="text-xs font-semibold text-bomba-600 hover:underline">
                <i class="fas fa-times-circle mr-1"></i> Hapus Filter (Tampilkan Semua)
            </a>
        <?php endif; ?>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-600">
            <thead class="text-slate-400 bg-slate-50 uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="py-3 px-3">Batch & Produk</th>
                    <th class="py-3 px-3">Pengrajin / Penenun</th>
                    <th class="py-3 px-3 text-center">Target / Selesai</th>
                    <th class="py-3 px-3">Jadwal Produksi</th>
                    <th class="py-3 px-3 text-center">Status</th>
                    <th class="py-3 px-3 text-right">Biaya Produksi</th>
                    <th class="py-3 px-3 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($batches as $b): ?>
                <tr class="hover:bg-slate-50/70 transition">
                    <td class="py-3 px-3">
                        <span class="font-mono text-[10px] bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded font-bold"><?= htmlspecialchars($b['batch_code']) ?></span>
                        <div class="font-bold text-slate-900 mt-1"><?= htmlspecialchars($b['product_name']) ?></div>
                        <div class="text-[11px] text-slate-500">Motif: <span class="font-medium text-bomba-700"><?= htmlspecialchars($b['motif']) ?></span> &bull; <?= htmlspecialchars($b['material']) ?></div>
                    </td>
                    <td class="py-3 px-3">
                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($b['artisan_name']) ?></div>
                        <div class="text-[10px] text-slate-400"><?= htmlspecialchars($b['artisan_specialty']) ?></div>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <span class="font-bold text-slate-900"><?= $b['actual_quantity'] > 0 ? $b['actual_quantity'] : '-' ?></span> / 
                        <span class="text-slate-500"><?= $b['target_quantity'] ?> pcs</span>
                        <?php if ($b['reject_quantity'] > 0): ?>
                            <div class="text-[10px] text-rose-500 font-semibold"><i class="fas fa-ban mr-0.5"></i> <?= $b['reject_quantity'] ?> reject</div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 text-slate-600">
                        <div>Mulai: <?= date('d M Y', strtotime($b['start_date'])) ?></div>
                        <div class="text-slate-400">Estimasi: <?= date('d M Y', strtotime($b['estimated_end_date'])) ?></div>
                        <?php if ($b['actual_end_date']): ?>
                            <div class="text-emerald-600 font-semibold text-[10px]">Selesai: <?= date('d M Y', strtotime($b['actual_end_date'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <?php
                            $badgeColor = 'bg-slate-100 text-slate-700';
                            if ($b['status'] === 'Pengerjaan (Tenun/Batik)') $badgeColor = 'bg-blue-100 text-blue-700';
                            if ($b['status'] === 'Pewarnaan & Fiksasi') $badgeColor = 'bg-purple-100 text-purple-700';
                            if ($b['status'] === 'Quality Control') $badgeColor = 'bg-amber-100 text-amber-700';
                            if ($b['status'] === 'Selesai') $badgeColor = 'bg-emerald-100 text-emerald-700';
                        ?>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= $badgeColor ?> whitespace-nowrap">
                            <?= htmlspecialchars($b['status']) ?>
                        </span>
                    </td>
                    <td class="py-3 px-3 text-right">
                        <span class="font-bold text-slate-900"><?= formatRupiah($b['total_cost']) ?></span>
                        <div class="text-[10px] text-slate-400">Upah: <?= formatRupiah($b['labor_cost']) ?></div>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <button onclick="openUpdateStatusModal(<?= htmlspecialchars(json_encode($b)) ?>)" class="px-2.5 py-1 bg-slate-100 hover:bg-bomba-50 text-slate-700 hover:text-bomba-700 border border-slate-200 rounded-lg text-xs font-semibold transition" title="Perbarui Status Pengerjaan">
                            <i class="fas fa-edit mr-1"></i> Update
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL 1: TAMBAH BATCH PRODUKSI -->
<div id="modalAddProduction" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto">
        <button onclick="document.getElementById('modalAddProduction').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">
            <i class="fas fa-times text-lg"></i>
        </button>
        <h3 class="text-base font-extrabold text-slate-900 flex items-center mb-1">
            <i class="fas fa-plus-circle mr-2 text-bomba-600"></i> Buat Batch Produksi Baru
        </h3>
        <p class="text-xs text-slate-500 mb-4">Input rencana penenunan kain atau pembatikan di sentra.</p>

        <form action="api.php" method="POST" class="space-y-3.5 text-xs">
            <input type="hidden" name="action" value="create_production">
            
            <div>
                <label class="block font-bold text-slate-700 mb-1">Pilih Produk Kain Tenun / Batik *</label>
                <select name="product_id" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 focus:bg-white text-xs">
                    <option value="">-- Pilih Produk --</option>
                    <?php foreach ($productsList as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Motif: <?= htmlspecialchars($p['motif']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Pilih Pengrajin / Penenun Utama *</label>
                <select name="artisan_id" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 focus:bg-white text-xs">
                    <option value="">-- Pilih Pengrajin --</option>
                    <?php foreach ($artisansList as $art): ?>
                        <option value="<?= $art['id'] ?>"><?= htmlspecialchars($art['name']) ?> (<?= htmlspecialchars($art['specialty']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Target Kuantitas (Pcs) *</label>
                    <input type="number" name="target_quantity" min="1" value="5" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Tgl Mulai Pengerjaan *</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Estimasi Tanggal Selesai *</label>
                <input type="date" name="estimated_end_date" value="<?= date('Y-m-d', strtotime('+18 days')) ?>" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Estimasi Biaya Bahan (Rp)</label>
                    <input type="number" name="material_cost" value="1500000" step="50000" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Estimasi Upah Pengrajin (Rp)</label>
                    <input type="number" name="labor_cost" value="1200000" step="50000" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Catatan Khusus Batch</label>
                <textarea name="notes" rows="2" placeholder="Contoh: Pesanan pameran, gunakan benang sutra grade super..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs"></textarea>
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('modalAddProduction').classList.add('hidden')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-5 py-2 bg-bomba-600 hover:bg-bomba-700 text-white font-bold rounded-xl shadow-md">Simpan Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: UPDATE STATUS BATCH PRODUKSI -->
<div id="modalUpdateStatus" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl relative">
        <button onclick="document.getElementById('modalUpdateStatus').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">
            <i class="fas fa-times text-lg"></i>
        </button>
        <h3 class="text-base font-extrabold text-slate-900 mb-1 flex items-center">
            <i class="fas fa-refresh mr-2 text-bomba-600"></i> Update Tahapan Produksi
        </h3>
        <p class="text-xs text-slate-500 mb-4" id="updateBatchTitle">-</p>

        <form action="api.php" method="POST" class="space-y-3.5 text-xs">
            <input type="hidden" name="action" value="update_production_status">
            <input type="hidden" name="batch_id" id="updateBatchId" value="">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Tahapan / Status Baru *</label>
                <select name="status" id="updateBatchStatus" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs font-semibold">
                    <option value="Persiapan Bahan">1. Persiapan Bahan</option>
                    <option value="Pengerjaan (Tenun/Batik)">2. Pengerjaan (Tenun / Batik)</option>
                    <option value="Pewarnaan & Fiksasi">3. Pewarnaan & Fiksasi</option>
                    <option value="Quality Control">4. Quality Control</option>
                    <option value="Selesai">5. Selesai (Otomatis Masuk Stok Galeri)</option>
                </select>
                <p class="text-[10px] text-emerald-600 mt-1"><i class="fas fa-info-circle mr-1"></i> Jika diubah ke "Selesai", kuantitas otomatis ditambahkan ke stok katalog.</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Kuantitas Selesai (Pcs)</label>
                    <input type="number" name="actual_quantity" id="updateActualQty" min="0" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Kuantitas Reject/Cacat</label>
                    <input type="number" name="reject_quantity" id="updateRejectQty" min="0" value="0" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('modalUpdateStatus').classList.add('hidden')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-5 py-2 bg-bomba-600 hover:bg-bomba-700 text-white font-bold rounded-xl shadow-md">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUpdateStatusModal(batch) {
    document.getElementById('updateBatchId').value = batch.id;
    document.getElementById('updateBatchTitle').innerText = `${batch.batch_code} - ${batch.product_name} (${batch.artisan_name})`;
    document.getElementById('updateBatchStatus').value = batch.status;
    document.getElementById('updateActualQty').value = batch.actual_quantity > 0 ? batch.actual_quantity : batch.target_quantity;
    document.getElementById('updateRejectQty').value = batch.reject_quantity || 0;
    document.getElementById('modalUpdateStatus').classList.remove('hidden');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
