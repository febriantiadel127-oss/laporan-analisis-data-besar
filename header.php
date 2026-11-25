<?php
// includes/header.php
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Bomba Analytics');
}
$currentPage = basename($_SERVER['PHP_SELF']);

// Ambil notifikasi peringatan stok kritis untuk badge header
require_once __DIR__ . '/../config/database.php';
$headerPdo = getDBConnection();
$criticalStockCount = (int)$headerPdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_stock_threshold")->fetchColumn();
$activeBatchesCount = (int)$headerPdo->query("SELECT COUNT(*) FROM productions WHERE status != 'Selesai'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : '' ?><?= APP_NAME ?> | Usaha Kain Tenun & Batik Bomba</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        bomba: {
                            50: '#FDF6F7',
                            100: '#FCEBEF',
                            200: '#F8D2DB',
                            300: '#F1A9BB',
                            400: '#D95878',
                            500: '#8A2033', // Rich Maroon Bomba
                            600: '#731728', // Deep Maroon
                            700: '#5C101E', // Dark Tenun Maroon
                            800: '#470B16',
                            900: '#34060E',
                        },
                        gold: {
                            400: '#F5CE42',
                            500: '#D4AF37', // Tenun Sutra Gold
                            600: '#AA8820',
                        },
                        kaili: {
                            800: '#470B16',
                            900: '#34060E', // Deep Maroon Kaili
                        }
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        serif: ['"Cinzel"', 'serif'],
                    }
                }
            }
        }
    </script>
    
    <!-- FontAwesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- ApexCharts -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <style>
        /* Pattern Etnik Asli Batik Bomba Marun pada Header */
        .batik-pattern-bg {
            background-color: #5C101E;
            background-image: linear-gradient(135deg, rgba(75, 12, 22, 0.88), rgba(45, 8, 14, 0.92)), url('assets/images/batik-bomba-bg.png');
            background-size: 260px;
            background-repeat: repeat;
        }

        .batik-banner-bg {
            background-color: #5C101E;
            background-image: linear-gradient(135deg, rgba(92, 16, 30, 0.90), rgba(52, 6, 14, 0.93)), url('assets/images/batik-bomba-bg.png');
            background-size: 260px;
            background-repeat: repeat;
        }

        /* Halaman Utama / Body Background dengan Motif Asli Batik Bomba */
        body.batik-page-bg {
            background-color: #FBF8F5;
            background-image: linear-gradient(to bottom, rgba(253, 250, 246, 0.93), rgba(250, 246, 240, 0.95)), url('assets/images/batik-bomba-bg.png');
            background-size: 320px;
            background-repeat: repeat;
            background-attachment: fixed;
        }

        .gold-gradient-text {
            background: linear-gradient(135deg, #FFE27A 0%, #D4AF37 50%, #E4A830 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Glassmorphism Cards */
        .glass-card {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(229, 231, 235, 0.9);
        }

        /* Print Styling */
        @media print {
            body { background: white !important; color: black !important; }
            .no-print, aside, header, .modal, .btn-action, #filterBar { display: none !important; }
            main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .print-full { width: 100% !important; box-shadow: none !important; border: 1px solid #ccc !important; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body class="batik-page-bg text-slate-800 font-sans antialiased min-h-screen flex flex-col">
    <!-- Top Bar Navigation -->
    <header class="batik-pattern-bg text-white sticky top-0 z-40 shadow-lg border-b border-gold-500/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand & Logo -->
                <div class="flex items-center space-x-3">
                    <a href="index.php" class="flex items-center space-x-3 group">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-bomba-600 to-gold-500 flex items-center justify-center text-white shadow-lg shadow-bomba-900/60 group-hover:scale-105 transition-transform duration-200 border border-gold-400/40">
                            <i class="fas fa-layer-group text-lg"></i>
                        </div>
                        <div>
                            <span class="font-serif text-lg tracking-wider font-bold gold-gradient-text">BATIK BOMBA</span>
                            <span class="block text-[10px] text-amber-200/90 uppercase tracking-widest -mt-1 font-semibold">Dashboard Analitik Usaha Tenun & Batik</span>
                        </div>
                    </a>
                </div>

                <!-- Right Quick Stats & Actions -->
                <div class="flex items-center space-x-3 sm:space-x-4">
                    <!-- Lokasi & Status -->
                    <div class="hidden md:flex items-center text-xs text-amber-100/90 bg-black/25 px-3 py-1.5 rounded-lg border border-white/10">
                        <i class="fas fa-map-marker-alt text-amber-400 mr-2"></i>
                        <span>Sentra Palu & Banawa Donggala</span>
                    </div>

                    <!-- Instagram Resmi -->
                    <a href="https://www.instagram.com/batikbomba_khaskotapalu" target="_blank" rel="noopener noreferrer" class="hidden lg:flex items-center text-xs text-pink-200 bg-pink-950/60 px-3 py-1.5 rounded-lg border border-pink-400/30 hover:bg-pink-900/70 hover:text-white transition" title="Kunjungi Akun Instagram Resmi">
                        <i class="fab fa-instagram text-pink-400 mr-2 text-sm"></i>
                        <span>@batikbomba_khaskotapalu</span>
                    </a>

                    <!-- Notifikasi Stok Kritis -->
                    <a href="decision-support.php#stok-kritis" class="relative p-2 text-amber-100 hover:text-white hover:bg-white/10 rounded-lg transition" title="<?= $criticalStockCount ?> Produk Perlu Restock">
                        <i class="fas fa-bell text-lg"></i>
                        <?php if ($criticalStockCount > 0): ?>
                            <span class="absolute top-1 right-1 w-5 h-5 bg-rose-500 text-[10px] font-bold text-white rounded-full flex items-center justify-center ring-2 ring-bomba-900 animate-pulse">
                                <?= $criticalStockCount ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <!-- Profile Ringkas -->
                    <div class="flex items-center pl-2 border-l border-white/15 space-x-2">
                        <div class="w-8 h-8 rounded-full bg-gold-500/20 border border-gold-400 flex items-center justify-center text-gold-300 text-xs font-bold">
                            BO
                        </div>
                        <div class="hidden sm:block text-left">
                            <p class="text-xs font-semibold leading-tight text-white">Owner / Manajemen</p>
                            <p class="text-[10px] text-emerald-400 flex items-center"><span class="w-1.5 h-1.5 bg-emerald-400 rounded-full mr-1 inline-block"></span>Live Data</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Secondary Nav Bar (Horizontal Menu for Quick Accessibility) -->
        <div class="bg-[#34060E]/95 border-t border-white/10 backdrop-blur-sm">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <nav class="flex space-x-1 sm:space-x-3 overflow-x-auto py-2 text-xs font-medium no-scrollbar">
                    <a href="index.php" class="<?= $currentPage === 'index.php' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> px-3 py-2 rounded-lg transition flex items-center whitespace-nowrap">
                        <i class="fas fa-layer-group mr-2 text-amber-400"></i> Dashboard Utama (25 Chart)
                    </a>
                    <a href="production.php" class="<?= $currentPage === 'production.php' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> px-3 py-2 rounded-lg transition flex items-center whitespace-nowrap" title="Fitur Tambahan Operasional Produksi">
                        <i class="fas fa-tools mr-2 text-blue-400"></i> Produksi & Pengrajin
                        <?php if ($activeBatchesCount > 0): ?>
                            <span class="ml-1.5 bg-blue-500/30 text-blue-300 px-1.5 py-0.5 rounded text-[10px]"><?= $activeBatchesCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="sales.php" class="<?= $currentPage === 'sales.php' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> px-3 py-2 rounded-lg transition flex items-center whitespace-nowrap" title="Fitur Tambahan Pencatatan Transaksi Penjualan">
                        <i class="fas fa-cash-register mr-2 text-emerald-400"></i> Kasir Penjualan
                    </a>
                    <a href="products.php" class="<?= $currentPage === 'products.php' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> px-3 py-2 rounded-lg transition flex items-center whitespace-nowrap" title="Fitur Tambahan Katalog Motif & Stok">
                        <i class="fas fa-gem mr-2 text-gold-400"></i> Katalog Produk
                    </a>
                    <a href="decision-support.php" class="<?= $currentPage === 'decision-support.php' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> px-3 py-2 rounded-lg transition flex items-center whitespace-nowrap" title="Fitur Tambahan Simulasi Keputusan DSS">
                        <i class="fas fa-brain mr-2 text-purple-400"></i> Rekomendasi DSS
                        <?php if ($criticalStockCount > 0): ?>
                            <span class="ml-1.5 bg-rose-500 text-white px-1.5 py-0.5 rounded-full text-[10px] animate-pulse">!</span>
                        <?php endif; ?>
                    </a>
                    <a href="reports.php" class="<?= $currentPage === 'reports.php' ? 'bg-bomba-600 text-white font-semibold shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?> px-3 py-2 rounded-lg transition flex items-center whitespace-nowrap" title="Fitur Tambahan Cetak Laporan Formal">
                        <i class="fas fa-file-invoice-dollar mr-2 text-amber-300"></i> Cetak Laporan
                    </a>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Content Wrapper -->
    <main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
