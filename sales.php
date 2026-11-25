<?php
$pageTitle = "Analisis & Manajemen Penjualan";
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

// Filter Channel atau Date
$channelFilter = $_GET['channel'] ?? 'all';
$queryWhere = "1=1";
if ($channelFilter !== 'all') {
    $queryWhere = "s.sales_channel = " . $pdo->quote($channelFilter);
}

// 1. STATISTIK PENJUALAN
$salesStats = $pdo->query("SELECT 
    COUNT(*) as total_transaksi,
    COALESCE(SUM(total_amount), 0) as total_omset,
    COALESCE(SUM(total_cost), 0) as total_hpp,
    COALESCE(SUM(total_profit), 0) as total_laba,
    COALESCE(AVG(total_amount), 0) as avg_order_value
    FROM sales s WHERE $queryWhere")->fetch();

// Total Volume Pcs
$totalPcsSold = (int)$pdo->query("SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE $queryWhere")->fetchColumn();

// 2. KONTRIBUSI PELANGGAN (Berdasarkan Tipe Pelanggan)
$custTypeData = $pdo->query("SELECT 
    customer_type, 
    COUNT(*) as transaksi_count, 
    SUM(total_amount) as omset 
    FROM sales s 
    WHERE $queryWhere
    GROUP BY customer_type 
    ORDER BY omset DESC")->fetchAll();

// 3. SEBARAN SALURAN PENJUALAN
$channelStats = $pdo->query("SELECT 
    sales_channel, 
    COUNT(*) as total_trans, 
    SUM(total_amount) as omset 
    FROM sales 
    GROUP BY sales_channel 
    ORDER BY omset DESC")->fetchAll();

// 4. METODE PEMBAYARAN
$paymentStats = $pdo->query("SELECT 
    payment_method, 
    COUNT(*) as count, 
    SUM(total_amount) as omset 
    FROM sales s
    WHERE $queryWhere
    GROUP BY payment_method")->fetchAll();

// 4b. TREN TRANSAKSI & AOV BULANAN (CHART 13)
$monthlyAOVQuery = "SELECT 
    strftime('%m', sale_date) as bulan,
    COUNT(*) as total_trans,
    SUM(total_amount) as total_omset,
    ROUND(AVG(total_amount), 0) as avg_order
    FROM sales
    WHERE sale_date LIKE '2026%'
    GROUP BY strftime('%m', sale_date)
    ORDER BY bulan ASC";
$monthlyAOVData = $pdo->query($monthlyAOVQuery)->fetchAll();

$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$salesTransMonthly = array_fill(0, 12, 0);
$salesAOVMonthly = array_fill(0, 12, 0);
foreach ($monthlyAOVData as $row) {
    $mIdx = (int)$row['bulan'] - 1;
    if ($mIdx >= 0 && $mIdx < 12) {
        $salesTransMonthly[$mIdx] = (int)$row['total_trans'];
        $salesAOVMonthly[$mIdx] = (float)$row['avg_order'];
    }
}

// 4c. POLA HARI TRANSAKSI (CHART 17)
$daySalesQuery = "SELECT 
    strftime('%w', sale_date) as day_num,
    COUNT(*) as trans_count,
    SUM(total_amount) as total_omset
    FROM sales
    GROUP BY day_num
    ORDER BY day_num ASC";
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

// 4d. RETENSI PELANGGAN BARU VS REPEAT (CHART 18)
$repeatQuery = "SELECT 
    customer_name, COUNT(*) as order_count
    FROM sales
    GROUP BY customer_name";
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

// 5. RIWAYAT TRANSAKSI PENJUALAN LENGKAP
$salesListQuery = "SELECT 
    s.*,
    GROUP_CONCAT(p.name || ' (' || si.quantity || ' pcs)', ', ') as items_summary,
    SUM(si.quantity) as total_items_qty
    FROM sales s
    JOIN sale_items si ON s.id = si.sale_id
    JOIN products p ON si.product_id = p.id
    WHERE $queryWhere
    GROUP BY s.id
    ORDER BY s.sale_date DESC, s.id DESC";
$salesList = $pdo->query($salesListQuery)->fetchAll();

// Ambil produk yang stoknya > 0 untuk modal penjualan
$availableProducts = $pdo->query("SELECT id, name, code, motif, selling_price, stock_quantity FROM products WHERE stock_quantity > 0 ORDER BY name ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Header & Action Button -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between pb-6 border-b border-slate-200 gap-4">
    <div>
        <div class="flex items-center space-x-2">
            <span class="px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase rounded-full bg-emerald-100 text-emerald-700 border border-emerald-300">
                <i class="fas fa-shopping-cart mr-1"></i> Modul Transaksi & Pendapatan
            </span>
            <span class="text-xs text-slate-500 font-medium">Monitoring Penjualan Kain Tenun & Batik Bomba</span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1">
            Analisis Kinerja Penjualan
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 mt-0.5">
            Analisis arus kas masuk, margin laba kotor per transaksi, dan performa saluran penjualan galeri & dinas.
        </p>
    </div>

    <!-- Tombol Catat Transaksi & Export -->
    <div class="flex items-center space-x-3">
        <a href="api.php?action=export_csv&type=sales" class="px-3.5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-xl shadow-sm transition flex items-center">
            <i class="fas fa-file-excel mr-2 text-emerald-600"></i> Export CSV
        </a>
        <button onclick="document.getElementById('modalAddSale').classList.remove('hidden')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-md transition flex items-center">
            <i class="fas fa-plus mr-2"></i> Catat Transaksi Baru
        </button>
    </div>
</div>

<!-- SECTION 1: METRIK RINGKASAN PENJUALAN -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 my-6">
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-emerald-500">
        <span class="text-xs text-slate-500 font-medium">Total Akumulasi Omset</span>
        <div class="mt-1 text-2xl font-extrabold text-slate-900"><?= formatRupiah($salesStats['total_omset']) ?></div>
        <div class="mt-2 text-[11px] text-emerald-600 font-semibold"><i class="fas fa-receipt mr-1"></i> <?= $salesStats['total_transaksi'] ?> Transaksi Berhasil</div>
    </div>
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-bomba-500">
        <span class="text-xs text-slate-500 font-medium">Laba Kotor Penjualan</span>
        <div class="mt-1 text-2xl font-extrabold text-slate-900"><?= formatRupiah($salesStats['total_laba']) ?></div>
        <div class="mt-2 text-[11px] text-bomba-600 font-semibold">
            Margin: <?= ($salesStats['total_omset'] > 0) ? round(($salesStats['total_laba'] / $salesStats['total_omset']) * 100, 1) : 0 ?>% dari Omset
        </div>
    </div>
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-blue-500">
        <span class="text-xs text-slate-500 font-medium">Total Volume Terjual</span>
        <div class="mt-1 text-2xl font-extrabold text-slate-900"><?= number_format($totalPcsSold, 0, ',', '.') ?> <span class="text-xs font-normal text-slate-500">Pcs</span></div>
        <div class="mt-2 text-[11px] text-blue-600 font-medium"><i class="fas fa-layer-group mr-1"></i> Tenun & Batik Terserap</div>
    </div>
    <div class="glass-card rounded-2xl p-4 shadow-sm border-l-4 border-l-purple-500">
        <span class="text-xs text-slate-500 font-medium">Rata-rata Nilai Transaksi (AOV)</span>
        <div class="mt-1 text-xl font-extrabold text-slate-900"><?= formatRupiah($salesStats['avg_order_value']) ?></div>
        <div class="mt-2 text-[11px] text-slate-500">Nilai belanja rata-rata per pelanggan</div>
    </div>
</div>

<!-- SECTION 3: TABEL RIWAYAT TRANSAKSI PENJUALAN -->
<div class="glass-card rounded-2xl p-5 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-slate-100 gap-3">
        <div>
            <h3 class="text-sm font-bold text-slate-900 flex items-center">
                <i class="fas fa-receipt mr-2 text-emerald-600"></i> Riwayat Transaksi Penjualan Lengkap
            </h3>
            <p class="text-xs text-slate-500">Data seluruh penjualan dan rincian item motif kain</p>
        </div>
        <!-- Filter Saluran Cepat -->
        <div class="flex items-center space-x-2 text-xs">
            <span class="text-slate-500">Saluran:</span>
            <select onchange="location.href='sales.php?channel=' + this.value" class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-700 font-medium">
                <option value="all" <?= $channelFilter === 'all' ? 'selected' : '' ?>>Semua Saluran</option>
                <option value="Galeri Utama Palu" <?= $channelFilter === 'Galeri Utama Palu' ? 'selected' : '' ?>>Galeri Utama Palu</option>
                <option value="Pameran Dekranasda" <?= $channelFilter === 'Pameran Dekranasda' ? 'selected' : '' ?>>Pameran Dekranasda</option>
                <option value="Online Store / Medsos" <?= $channelFilter === 'Online Store / Medsos' ? 'selected' : '' ?>>Online Instagram (@batikbomba_khaskotapalu)</option>
                <option value="Pesanan Khusus Pemda" <?= $channelFilter === 'Pesanan Khusus Pemda' ? 'selected' : '' ?>>Pesanan Khusus Pemda</option>
            </select>
        </div>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-600">
            <thead class="text-slate-400 bg-slate-50 uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="py-3 px-3">Invoice & Tanggal</th>
                    <th class="py-3 px-3">Pelanggan</th>
                    <th class="py-3 px-3">Rincian Produk Terjual</th>
                    <th class="py-3 px-3">Saluran Penjualan</th>
                    <th class="py-3 px-3 text-right">Nilai Total</th>
                    <th class="py-3 px-3 text-right">Laba Kotor</th>
                    <th class="py-3 px-3 text-center">Metode Bayar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($salesList as $sale): ?>
                <tr class="hover:bg-slate-50/70 transition">
                    <td class="py-3 px-3">
                        <span class="font-mono font-bold text-slate-900 block"><?= htmlspecialchars($sale['invoice_number']) ?></span>
                        <span class="text-[10px] text-slate-400"><?= formatTanggalIndo($sale['sale_date']) ?></span>
                    </td>
                    <td class="py-3 px-3">
                        <div class="font-bold text-slate-800"><?= htmlspecialchars($sale['customer_name']) ?></div>
                        <span class="text-[10px] text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded"><?= htmlspecialchars($sale['customer_type']) ?></span>
                    </td>
                    <td class="py-3 px-3 max-w-xs truncate text-slate-700" title="<?= htmlspecialchars($sale['items_summary']) ?>">
                        <?= htmlspecialchars($sale['items_summary']) ?>
                    </td>
                    <td class="py-3 px-3">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700">
                            <?= htmlspecialchars($sale['sales_channel']) ?>
                        </span>
                    </td>
                    <td class="py-3 px-3 text-right font-extrabold text-slate-900">
                        <?= formatRupiah($sale['total_amount']) ?>
                    </td>
                    <td class="py-3 px-3 text-right font-bold text-emerald-600">
                        +<?= formatRupiah($sale['total_profit']) ?>
                    </td>
                    <td class="py-3 px-3 text-center">
                        <span class="text-[10px] font-medium text-slate-600 bg-slate-100 px-2 py-0.5 rounded-lg">
                            <?= htmlspecialchars($sale['payment_method']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: CATAT TRANSAKSI PENJUALAN BARU -->
<div id="modalAddSale" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl relative max-h-[90vh] overflow-y-auto">
        <button onclick="document.getElementById('modalAddSale').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">
            <i class="fas fa-times text-lg"></i>
        </button>
        <h3 class="text-base font-extrabold text-slate-900 flex items-center mb-1">
            <i class="fas fa-cart-plus mr-2 text-emerald-600"></i> Catat Transaksi Penjualan Baru
        </h3>
        <p class="text-xs text-slate-500 mb-4">Input penjualan kain tenun / batik. Sistem otomatis memotong stok produk.</p>

        <form action="api.php" method="POST" class="space-y-3.5 text-xs">
            <input type="hidden" name="action" value="create_sale">

            <div>
                <label class="block font-bold text-slate-700 mb-1">Pilih Produk Kain *</label>
                <select name="product_id" id="saleProductId" required onchange="updateProductStockInfo(this)" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                    <option value="">-- Pilih Produk Ready Stok --</option>
                    <?php foreach ($availableProducts as $prod): ?>
                        <option value="<?= $prod['id'] ?>" data-price="<?= $prod['selling_price'] ?>" data-stock="<?= $prod['stock_quantity'] ?>">
                            <?= htmlspecialchars($prod['name']) ?> (Stok: <?= $prod['stock_quantity'] ?> pcs - <?= formatRupiah($prod['selling_price']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="stockBadge" class="text-[11px] text-slate-500 mt-1"></div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Jumlah Pembelian (Pcs) *</label>
                    <input type="number" name="quantity" id="saleQty" min="1" value="1" required oninput="calcSubtotal()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Estimasi Total Bayar</label>
                    <input type="text" id="saleTotalPreview" readonly value="Rp 0" class="w-full px-3 py-2 bg-slate-100 border border-slate-200 rounded-xl text-slate-800 font-bold text-xs">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Nama Pembeli / Instansi *</label>
                    <input type="text" name="customer_name" required placeholder="Contoh: Ibu Hj. Rahmi / Dinas Pariwisata" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Kategori Pembeli *</label>
                    <select name="customer_type" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                        <option value="Perorangan">Perorangan / Wisatawan</option>
                        <option value="Instansi Pemerintah">Instansi Pemerintah / BUMN</option>
                        <option value="Kolektor Budaya">Kolektor Wastra Nusantara</option>
                        <option value="Toko Reseller">Toko Busana / Reseller</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Saluran Penjualan *</label>
                    <select name="sales_channel" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                        <option value="Galeri Utama Palu">Galeri Utama Palu</option>
                        <option value="Pameran Dekranasda">Pameran Dekranasda / Event</option>
                        <option value="Online Store / Medsos">Online Store & Instagram (@batikbomba_khaskotapalu)</option>
                        <option value="Pesanan Khusus Pemda">Pesanan Khusus Pemda / Dinas</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Metode Pembayaran</label>
                    <select name="payment_method" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                        <option value="Transfer Bank">Transfer Bank (BCA/Mandiri/BSI)</option>
                        <option value="QRIS / E-Wallet">QRIS / E-Wallet</option>
                        <option value="Tunai (Galeri)">Tunai di Galeri</option>
                        <option value="Termin / SPK Dinas">Termin / SPK Kedinasan</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Tanggal Transaksi</label>
                    <input type="date" name="sale_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Keterangan / Catatan</label>
                    <input type="text" name="notes" placeholder="Misal: Termasuk kotak hampers tenun..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-emerald-500 text-xs">
                </div>
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button type="button" onclick="document.getElementById('modalAddSale').classList.add('hidden')" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50">Batal</button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-md">Simpan & Kurangi Stok</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentPrice = 0;
let maxStock = 0;

function updateProductStockInfo(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    if (selectedOption.value) {
        currentPrice = parseFloat(selectedOption.getAttribute('data-price') || 0);
        maxStock = parseInt(selectedOption.getAttribute('data-stock') || 0);
        document.getElementById('saleQty').max = maxStock;
        document.getElementById('stockBadge').innerHTML = `<span class="text-emerald-600 font-semibold"><i class="fas fa-check-circle mr-1"></i> Sisa Stok Galeri: ${maxStock} pcs</span> (Harga Satuan: Rp ${currentPrice.toLocaleString('id-ID')})`;
        calcSubtotal();
    } else {
        currentPrice = 0;
        maxStock = 0;
        document.getElementById('stockBadge').innerText = '';
        document.getElementById('saleTotalPreview').value = 'Rp 0';
    }
}

function calcSubtotal() {
    const qty = parseInt(document.getElementById('saleQty').value || 1);
    const total = currentPrice * qty;
    document.getElementById('saleTotalPreview').value = "Rp " + total.toLocaleString('id-ID');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
