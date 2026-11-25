<?php
$pageTitle = "Analisis Produk & Motif Terpopuler";
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

// 1. RANKING PRODUK TERLARIS (BERDASARKAN KUANTITAS & OMSET)
$rankedProductsQuery = "SELECT 
    p.*,
    c.name as category_name,
    COALESCE(SUM(si.quantity), 0) as total_sold_qty,
    COALESCE(SUM(si.subtotal), 0) as total_revenue,
    COALESCE(SUM(si.profit), 0) as total_profit_earned,
    ROUND(((p.selling_price - p.production_cost) / p.selling_price) * 100, 1) as profit_margin_pct
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN sale_items si ON p.id = si.product_id
    GROUP BY p.id
    ORDER BY total_sold_qty DESC";
$rankedProducts = $pdo->query($rankedProductsQuery)->fetchAll();

// 2. ANALISIS POPULARITAS MOTIF TRADISIONAL
$motifQuery = "SELECT 
    p.motif,
    COUNT(DISTINCT p.id) as varian_count,
    COALESCE(SUM(si.quantity), 0) as total_terjual,
    COALESCE(SUM(si.subtotal), 0) as total_omset
    FROM products p
    LEFT JOIN sale_items si ON p.id = si.product_id
    GROUP BY p.motif
    ORDER BY total_terjual DESC";
$motifStats = $pdo->query($motifQuery)->fetchAll();

// 2b. MARGIN KEUNTUNGAN RATA-RATA PER MOTIF (CHART 20)
$motifMarginQuery = "SELECT 
    p.motif,
    ROUND(AVG(((p.selling_price - p.production_cost) / p.selling_price) * 100), 1) as avg_margin
    FROM products p
    GROUP BY p.motif
    ORDER BY avg_margin DESC";
$motifMarginData = $pdo->query($motifMarginQuery)->fetchAll();

// 2c. VALUASI ASET STOK PER KATEGORI (CHART 22)
$stockValuationQuery = "SELECT 
    c.name as category_name,
    SUM(p.stock_quantity * p.production_cost) as total_hpp_valuation,
    SUM(p.stock_quantity * p.selling_price) as total_retail_valuation
    FROM products p
    JOIN categories c ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY total_retail_valuation DESC";
$stockValuationData = $pdo->query($stockValuationQuery)->fetchAll();

// 3. AMBIL LIST KATEGORI UNTUK FORM MODAL
$categoriesList = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Header & Action Button -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between pb-6 border-b border-slate-200 gap-4">
    <div>
        <div class="flex items-center space-x-2">
            <span class="px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase rounded-full bg-amber-100 text-bomba-700 border border-amber-300">
                <i class="fas fa-gem mr-1"></i> Modul Katalog & Motif Khas
            </span>
            <span class="text-xs text-slate-500 font-medium">Koleksi Wastra Sulawesi Tengah</span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">
            Produk & Motif Paling Diminati
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
            Analisis preferensi konsumen terhadap motif Bomba (Sambulagana, Taiganja, Souraja) dan manajemen stok.
        </p>
    </div>

    <!-- Tombol Tambah Produk Baru -->
    <button onclick="openProductModal()" class="px-4 py-2 bg-bomba-600 hover:bg-bomba-700 text-white text-xs font-bold rounded-xl shadow-md transition flex items-center self-start md:self-auto">
        <i class="fas fa-plus mr-2"></i> Tambah Produk Baru
    </button>
</div>

<!-- SECTION 1: KARTU ANALISIS MOTIF KHAS SULAWESI TENGAH -->
<div class="my-6">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-bold text-slate-900 flex items-center">
            <i class="fas fa-palette mr-2 text-gold-500"></i> Popularitas Motif Khas Batik & Tenun Palu / Donggala
        </h3>
        <span class="text-xs text-slate-500">Berdasarkan Total Unit Terserap</span>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <?php 
        $motifColors = ['border-t-amber-500', 'border-t-blue-500', 'border-t-emerald-500', 'border-t-purple-500', 'border-t-rose-500', 'border-t-cyan-500'];
        $i = 0;
        foreach ($motifStats as $m): 
            $topClass = $motifColors[$i % count($motifColors)];
            $i++;
        ?>
        <div class="glass-card rounded-xl p-3 border-t-4 <?= $topClass ?> shadow-sm hover:shadow-md transition">
            <span class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider block">Motif Khas</span>
            <h4 class="font-bold text-xs text-slate-900 mt-0.5 truncate" title="<?= htmlspecialchars($m['motif']) ?>"><?= htmlspecialchars($m['motif']) ?></h4>
            <div class="mt-2 text-base font-extrabold text-slate-900"><?= number_format($m['total_terjual'], 0, ',', '.') ?> <span class="text-[10px] font-normal text-slate-500">pcs</span></div>
            <div class="text-[10px] font-semibold text-emerald-600 truncate mt-0.5"><?= formatRupiah($m['total_omset']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- SECTION 3: TABEL KATALOG & STATUS STOK KAIN -->
<div class="glass-card rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-100 gap-3">
        <div>
            <h3 class="text-sm font-bold text-slate-900 flex items-center">
                <i class="fas fa-boxes mr-2 text-bomba-600"></i> Seluruh Katalog Produk & Posisi Stok Galeri
            </h3>
            <p class="text-xs text-slate-500">Informasi HPP, harga jual, tingkat perputaran, dan ambang batas minimum</p>
        </div>
        <div class="flex items-center space-x-2 text-xs">
            <span class="flex items-center"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 mr-1"></span> Stok Aman</span>
            <span class="flex items-center"><span class="w-2.5 h-2.5 rounded-full bg-rose-500 mr-1"></span> Menipis/Kritis</span>
        </div>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-600">
            <thead class="text-slate-400 bg-slate-50 uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="py-3 px-3">Kode & Nama Produk</th>
                    <th class="py-3 px-3">Kategori & Motif</th>
                    <th class="py-3 px-3 text-right">HPP (Modal)</th>
                    <th class="py-3 px-3 text-right">Harga Jual</th>
                    <th class="py-3 px-3 text-center">Margin %</th>
                    <th class="py-3 px-3 text-center">Total Terjual</th>
                    <th class="py-3 px-3 text-center">Sisa Stok</th>
                    <th class="py-3 px-3 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($rankedProducts as $prod): 
                    $isCritical = $prod['stock_quantity'] <= $prod['min_stock_threshold'];
                ?>
                <tr class="hover:bg-slate-50/70 transition <?= $isCritical ? 'bg-rose-50/20' : '' ?>">
                    <td class="py-3 px-3">
                        <span class="font-mono text-[10px] bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded font-bold"><?= htmlspecialchars($prod['code']) ?></span>
                        <div class="font-bold text-slate-900 mt-1"><?= htmlspecialchars($prod['name']) ?></div>
                        <div class="text-[10px] text-slate-400">Bahan: <?= htmlspecialchars($prod['material']) ?> &bull; Lead time: <?= $prod['lead_time_days'] ?> hari</div>
                    </td>
                    <td class="py-3 px-3">
                        <span class="font-semibold text-slate-800 block"><?= htmlspecialchars($prod['category_name']) ?></span>
                        <span class="text-[10px] text-bomba-700 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded font-medium">Motif: <?= htmlspecialchars($prod['motif']) ?></span>
                    </td>
                    <td class="py-3 px-3 text-right font-medium text-slate-600">
                        <?= formatRupiah($prod['production_cost']) ?>
                    </td>
                    <td class="py-3 px-3 text-right font-bold text-slate-900">
                        <?= formatRupiah($prod['selling_price']) ?>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700">
                            <?= $prod['profit_margin_pct'] ?>%
                        </span>
                    </td>
                    <td class="py-3 px-3 text-center font-bold text-slate-900">
                        <?= number_format($prod['total_sold_qty'], 0, ',', '.') ?> <span class="text-[10px] font-normal text-slate-400">pcs</span>
                        <div class="text-[10px] text-emerald-600 font-semibold"><?= formatRupiah($prod['total_revenue']) ?></div>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <?php if ($isCritical): ?>
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-100 text-rose-700 border border-rose-300 animate-pulse">
                                <?= $prod['stock_quantity'] ?> pcs (Kritis)
                            </span>
                            <div class="text-[9px] text-slate-400 mt-0.5">Min: <?= $prod['min_stock_threshold'] ?></div>
                        <?php else: ?>
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">
                                <?= $prod['stock_quantity'] ?> pcs (Aman)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <button onclick="editProductModal(<?= htmlspecialchars(json_encode($prod)) ?>)" class="px-2.5 py-1 bg-slate-100 hover:bg-bomba-50 text-slate-700 hover:text-bomba-700 border border-slate-200 rounded-lg text-xs font-semibold transition">
                            <i class="fas fa-edit mr-1"></i> Edit
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: TAMBAH / EDIT PRODUK -->
<div id="modalProduct" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto">
        <button onclick="document.getElementById('modalProduct').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">
            <i class="fas fa-times text-lg"></i>
        </button>
        <h3 class="text-base font-extrabold text-slate-900 flex items-center mb-1" id="modalProductTitle">
            <i class="fas fa-box mr-2 text-bomba-600"></i> Tambah Produk Baru
        </h3>
        <p class="text-xs text-slate-500 mb-4">Lengkapi data motif kain tenun atau batik Bomba untuk katalog.</p>

        <form action="api.php" method="POST" class="space-y-3.5 text-xs">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="id" id="prodId" value="0">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Kode Produk (SKU) *</label>
                    <input type="text" name="code" id="prodCode" required placeholder="Contoh: TNN-SBG-002" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs font-mono uppercase">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Kategori Produk *</label>
                    <select name="category_id" id="prodCategoryId" required class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                        <?php foreach ($categoriesList as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Nama Produk Kain *</label>
                <input type="text" name="name" id="prodName" required placeholder="Contoh: Kain Tenun Sutra Sambulagana Emas" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Motif Tradisional *</label>
                    <input type="text" name="motif" id="prodMotif" required placeholder="Contoh: Sambulagana / Taiganja" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Bahan Baku Kain *</label>
                    <input type="text" name="material" id="prodMaterial" required placeholder="Contoh: Sutra Alam Donggala / Mori Super" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">HPP Biaya Produksi (Rp) *</label>
                    <input type="number" name="production_cost" id="prodCost" required min="1000" step="5000" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Harga Jual Konsumen (Rp) *</label>
                    <input type="number" name="selling_price" id="prodPrice" required min="1000" step="5000" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Stok Awal</label>
                    <input type="number" name="stock_quantity" id="prodStock" min="0" value="10" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Batas Min Stok</label>
                    <input type="number" name="min_stock_threshold" id="prodMinStock" min="1" value="5" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Lead Time (Hari)</label>
                    <input type="number" name="lead_time_days" id="prodLeadTime" min="1" value="14" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Deskripsi & Filosofi Motif</label>
                <textarea name="description" id="prodDesc" rows="2" placeholder="Filosofi budaya motif tenun atau batik..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-bomba-500 text-xs"></textarea>
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('modalProduct').classList.add('hidden')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-5 py-2 bg-bomba-600 hover:bg-bomba-700 text-white font-bold rounded-xl shadow-md">Simpan Produk</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProductModal() {
    document.getElementById('modalProductTitle').innerHTML = '<i class="fas fa-box mr-2 text-bomba-600"></i> Tambah Produk Baru';
    document.getElementById('prodId').value = '0';
    document.getElementById('prodCode').value = '';
    document.getElementById('prodName').value = '';
    document.getElementById('prodMotif').value = '';
    document.getElementById('prodMaterial').value = '';
    document.getElementById('prodCost').value = '';
    document.getElementById('prodPrice').value = '';
    document.getElementById('prodStock').value = '10';
    document.getElementById('prodMinStock').value = '5';
    document.getElementById('prodLeadTime').value = '14';
    document.getElementById('prodDesc').value = '';
    document.getElementById('modalProduct').classList.remove('hidden');
}

function editProductModal(prod) {
    document.getElementById('modalProductTitle').innerHTML = '<i class="fas fa-edit mr-2 text-bomba-600"></i> Edit Produk: ' + prod.name;
    document.getElementById('prodId').value = prod.id;
    document.getElementById('prodCode').value = prod.code;
    document.getElementById('prodCategoryId').value = prod.category_id;
    document.getElementById('prodName').value = prod.name;
    document.getElementById('prodMotif').value = prod.motif;
    document.getElementById('prodMaterial').value = prod.material;
    document.getElementById('prodCost').value = prod.production_cost;
    document.getElementById('prodPrice').value = prod.selling_price;
    document.getElementById('prodStock').value = prod.stock_quantity;
    document.getElementById('prodMinStock').value = prod.min_stock_threshold;
    document.getElementById('prodLeadTime').value = prod.lead_time_days;
    document.getElementById('prodDesc').value = prod.description || '';
    document.getElementById('modalProduct').classList.remove('hidden');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
