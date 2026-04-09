</div> <!-- Penutup div.container-fluid -->
    </div> <!-- Penutup div#page-content-wrapper -->
</div> <!-- Penutup div#wrapper -->

<!-- JavaScript Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<!-- Custom JavaScript -->
<script src="/wms-geo/assets/js/script.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var sidebarToggle = document.getElementById("sidebarToggle");
        var wrapper = document.getElementById("wrapper");

        if (sidebarToggle) {
            sidebarToggle.addEventListener("click", function() {
                wrapper.classList.toggle("toggled");
            });
        }
        
        // Kode JavaScript untuk menyembunyikan notifikasi saat modal terbuka
        var dangerStockModal = document.getElementById('dangerStockModal');
        dangerStockModal.addEventListener('shown.bs.modal', function () {
            const badge = document.getElementById('danger-badge');
            if (badge) {
                badge.style.display = 'none';
            }
        });
    });
</script>

</body>
</html>