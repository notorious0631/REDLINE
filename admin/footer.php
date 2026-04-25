</main><!-- /admin-main -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
// Mobile sidebar toggle
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (toggle) {
        toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }
});
</script>
</body>
</html>
