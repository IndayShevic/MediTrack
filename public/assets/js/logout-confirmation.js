/**
 * Centralized Logout Confirmation with Enhanced SweetAlert2
 * This file provides a reusable logout confirmation function
 * that can be used across all pages in the application.
 */

// Enhanced SweetAlert2 Configuration
const SwalConfig = {
    logout: {
        title: 'Logout?',
        text: 'Are you sure you want to logout? You will be redirected to the login page.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3b82f6',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<span style="display: inline-flex; align-items: center; gap: 0.5rem;"><svg style="width: 1rem; height: 1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Yes, logout</span>',
        cancelButtonText: '<span style="display: inline-flex; align-items: center; gap: 0.5rem;"><svg style="width: 1rem; height: 1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg> Cancel</span>',
        reverseButtons: true,
        customClass: {
            popup: 'swal2-popup-enhanced',
            title: 'swal2-title-enhanced',
            htmlContainer: 'swal2-html-container-enhanced',
            confirmButton: 'swal2-confirm-enhanced',
            cancelButton: 'swal2-cancel-enhanced',
            icon: 'swal2-icon-enhanced'
        },
        buttonsStyling: false,
        allowOutsideClick: false,
        allowEscapeKey: true,
        focusConfirm: false,
        focusCancel: true,
        showClass: {
            popup: 'swal2-show-enhanced',
            backdrop: 'swal2-backdrop-show-enhanced'
        },
        hideClass: {
            popup: 'swal2-hide-enhanced',
            backdrop: 'swal2-backdrop-hide-enhanced'
        }
    }
};

/**
 * Initialize logout confirmation for all logout links
 * This function should be called on DOMContentLoaded
 */
function initLogoutConfirmation() {
    // Get all logout links (sidebar footer and profile dropdown)
    const logoutLinks = document.querySelectorAll('a[href*="logout.php"]');
    
    if (logoutLinks.length === 0) {
        return; // No logout links found
    }
    
    logoutLinks.forEach(link => {
        // Remove any existing event listeners by cloning the node
        const newLink = link.cloneNode(true);
        link.parentNode.replaceChild(newLink, link);
        
        newLink.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const logoutUrl = newLink.getAttribute('href');
            
            // Show enhanced SweetAlert confirmation dialog
            Swal.fire(SwalConfig.logout).then((result) => {
                if (result.isConfirmed) {
                    // Add loading state
                    Swal.fire({
                        title: 'Logging out...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        willOpen: () => {
                            Swal.showLoading();
                        },
                        customClass: {
                            popup: 'swal2-popup-enhanced',
                            title: 'swal2-title-enhanced',
                            htmlContainer: 'swal2-html-container-enhanced'
                        },
                        buttonsStyling: false
                    });
                    
                    // Small delay for smooth transition, then redirect
                    setTimeout(() => {
                        window.location.href = logoutUrl;
                    }, 300);
                }
            });
        });
    });
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLogoutConfirmation);
} else {
    // DOM is already ready
    initLogoutConfirmation();
}

// Also initialize on page navigation (for SPAs or dynamic content)
document.addEventListener('DOMContentLoaded', function() {
    // Observe for dynamically added logout links
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                const newLogoutLinks = document.querySelectorAll('a[href*="logout.php"]:not([data-logout-initialized])');
                if (newLogoutLinks.length > 0) {
                    newLogoutLinks.forEach(link => {
                        link.setAttribute('data-logout-initialized', 'true');
                    });
                    initLogoutConfirmation();
                }
            }
        });
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});

