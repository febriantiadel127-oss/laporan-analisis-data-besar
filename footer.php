    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 mt-12 py-6 text-slate-500 text-xs no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center space-x-2">
                <span class="w-2.5 h-2.5 rounded-full bg-bomba-600 inline-block"></span>
                <span class="font-semibold text-slate-700">Dashboard Analitik Batik Bomba & Tenun Donggala</span>
                <span>&copy; <?= date('Y') ?> Sentra Industri Kreatif Tenun Palu</span>
            </div>
            <div class="flex items-center space-x-4 text-slate-400">
                <a href="https://www.instagram.com/batikbomba_khaskotapalu" target="_blank" rel="noopener noreferrer" class="text-slate-600 hover:text-pink-600 font-medium transition flex items-center">
                    <i class="fab fa-instagram mr-1.5 text-pink-500 text-sm"></i> @batikbomba_khaskotapalu
                </a>
                <span>&bull;</span>
                <span>Versi 2.4.0 (Enterprise Analytics)</span>
                <span>&bull;</span>
                <a href="reports.php" class="hover:text-bomba-600 text-slate-600 font-medium"><i class="fas fa-print mr-1"></i> Cetak Laporan</a>
            </div>
        </div>
    </footer>

    <!-- Global Notification Toast Container -->
    <div id="toastContainer" class="fixed bottom-5 right-5 z-50 flex flex-col space-y-2 pointer-events-none"></div>

    <!-- Script Notification Helper -->
    <script>
        function showNotification(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            
            const bgColors = {
                success: 'bg-emerald-600 text-white',
                error: 'bg-rose-600 text-white',
                warning: 'bg-amber-500 text-white',
                info: 'bg-blue-600 text-white'
            };

            const icons = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                info: 'fas fa-info-circle'
            };

            toast.className = `${bgColors[type] || bgColors.success} px-4 py-3 rounded-xl shadow-xl flex items-center space-x-3 pointer-events-auto transform transition-all duration-300 ease-out translate-y-4 opacity-0 text-sm font-medium`;
            toast.innerHTML = `<i class="${icons[type] || icons.success} text-base"></i><span>${message}</span>`;
            
            container.appendChild(toast);
            
            // Animate in
            setTimeout(() => {
                toast.classList.remove('translate-y-4', 'opacity-0');
            }, 10);

            // Animate out
            setTimeout(() => {
                toast.classList.add('translate-y-4', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Cek URL params untuk notifikasi sukses
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('msg')) {
            showNotification(decodeURIComponent(urlParams.get('msg')), urlParams.get('type') || 'success');
        }
    </script>
</body>
</html>
