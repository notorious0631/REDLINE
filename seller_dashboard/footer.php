<?php
// seller_dashboard/footer.php
?>
        </div> <!-- End seller-content -->
    </main>
</div> <!-- End seller-layout -->

<script>
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.add('mobile-active');
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('mobile-active');
        });
    }

    // Close on click outside
    document.addEventListener('click', (e) => {
        if (sidebar && sidebar.classList.contains('mobile-active') && !sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
            sidebar.classList.remove('mobile-active');
        }
    });
</script>

</body>
</html>
