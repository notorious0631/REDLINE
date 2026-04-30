<?php
// seller_dashboard/footer.php
?>
        </div> <!-- End seller-content -->
    </main>
</div> <!-- End seller-layout -->

<script>
    function openSdSidebar() {
        document.getElementById('sidebar').classList.add('mobile-active');
        document.getElementById('sdOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeSdSidebar() {
        document.getElementById('sidebar').classList.remove('mobile-active');
        document.getElementById('sdOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
</script>

</body>
</html>
