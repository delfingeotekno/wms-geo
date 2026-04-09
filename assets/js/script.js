window.addEventListener('DOMContentLoaded', event => {
    // Toggle the side navigation
    const sidebarToggle = document.body.querySelector('#sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', event => {
            event.preventDefault();
            document.body.classList.toggle('toggled');
            // Menyesuaikan margin page-content-wrapper saat sidebar di-toggle
            const pageContentWrapper = document.getElementById('page-content-wrapper');
            if (document.body.classList.contains('toggled')) {
                pageContentWrapper.classList.add('toggled');
            } else {
                pageContentWrapper.classList.remove('toggled');
            }
        });
    }
});