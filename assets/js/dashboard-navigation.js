/**
 * GRC Portal - Dashboard Navigation System (Reusable)
 * -----------------------------------------------------------------------------
 * - Discovers sections dynamically from .sidebar-nav .nav-link[data-section]
 * - Works on Student / Admin / Faculty / Finance dashboards
 * - Preserves browser Back/Forward via hashchange
 * - Supports "overview mode" via data-overview="true" (shows all sections)
 * - Never interferes with Bootstrap, AJAX, chat, notifications, or forms
 */
document.addEventListener('DOMContentLoaded', () => {
    const sidebar     = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const headerTitle = document.querySelector('.top-header .header-title');

    /* ---------------------------------------------------------------------
       1. Discover nav links + their matching section elements dynamically
       --------------------------------------------------------------------- */
    const navLinks = Array.from(
        document.querySelectorAll('.sidebar-nav .nav-link[data-section]')
    );

    // Map: { "dashboard": <element>, "ledger": <element>, ... }
    // Only includes sections that actually exist in the DOM.
    const sectionMap = new Map();
    navLinks.forEach(link => {
        const id = link.dataset.section;
        if (!id) return;
        const el = document.getElementById(id);
        if (el) sectionMap.set(id, el);
    });

    // If the page has no recognised sections, do nothing (don't break the page).
    if (sectionMap.size === 0) return;

    /* ---------------------------------------------------------------------
       2. Mobile backdrop (unchanged behaviour)
       --------------------------------------------------------------------- */
    let backdrop = document.querySelector('.sidebar-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        document.body.appendChild(backdrop);
    }

    /* ---------------------------------------------------------------------
       3. Sidebar toggle (logo click) — desktop collapse + mobile slide-in
       --------------------------------------------------------------------- */
    function toggleSidebar() {
        const isMobile = window.innerWidth < 992;
        if (isMobile) {
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
            document.body.classList.toggle('sidebar-open');
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }
    }
    document.getElementById('sidebarLogoToggle')?.addEventListener('click', toggleSidebar);
    backdrop.addEventListener('click', () => {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
        document.body.classList.remove('sidebar-open');
    });
    if (window.innerWidth >= 992 && localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }

    /* ---------------------------------------------------------------------
       4. Section switching
       --------------------------------------------------------------------- */
    function closeMobileSidebar() {
        if (window.innerWidth < 992) {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.classList.remove('sidebar-open');
        }
    }

    function showSection(sectionId, { updateHash = true, scrollTop = true } = {}) {
        const target = sectionMap.get(sectionId);
        if (!target) return false;

        // Determine if this link is an "overview" link (shows ALL sections).
        const clickedLink = navLinks.find(l => l.dataset.section === sectionId);
        const isOverview  = clickedLink && clickedLink.dataset.overview === 'true';

        if (isOverview) {
            // Show every section at once.
            sectionMap.forEach(el => el.classList.add('active'));
        } else {
            // Single-section mode.
            sectionMap.forEach(el => el.classList.remove('active'));
            target.classList.add('active');
        }

        // Update active/aria on the sidebar links.
        navLinks.forEach(link => {
            const isActive = link.dataset.section === sectionId;
            link.classList.toggle('active', isActive);
            if (isActive) link.setAttribute('aria-current', 'page');
            else          link.removeAttribute('aria-current');
        });

        // Header title — read text from the link itself.
        if (headerTitle) {
            const labelEl = clickedLink ? clickedLink.querySelector('span') : null;
            if (labelEl) headerTitle.textContent = labelEl.textContent.trim();
        }

        // URL hash (guard against loops).
        if (updateHash) {
            const newHash = '#' + sectionId;
            if (window.location.hash !== newHash) {
                history.pushState(null, '', newHash);
            }
        }

        if (scrollTop && mainContent) mainContent.scrollTop = 0;
        closeMobileSidebar();
        return true;
    }

    /* ---------------------------------------------------------------------
       5. Click handlers
       --------------------------------------------------------------------- */
    navLinks.forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const id = link.dataset.section;
            if (id) showSection(id, { updateHash: true });
        });
    });

    /* ---------------------------------------------------------------------
       6. Default section resolution (used on first load + hash change)
       --------------------------------------------------------------------- */
    function getDefaultSectionId() {
        // Prefer "#dashboard" if it exists.
        if (sectionMap.has('dashboard')) return 'dashboard';
        // Otherwise, the first nav link that has a matching section.
        for (const link of navLinks) {
            if (sectionMap.has(link.dataset.section)) return link.dataset.section;
        }
        return null;
    }

    function resolveHash() {
        const hash = window.location.hash.replace(/^#/, '');
        if (hash && sectionMap.has(hash)) return hash;
        return getDefaultSectionId();
    }

    /* ---------------------------------------------------------------------
       7. Initial load + history navigation
       --------------------------------------------------------------------- */
    function applyFromHash(scrollTop = true) {
        const id = resolveHash();
        if (!id) return;
        showSection(id, { updateHash: false, scrollTop });
    }

    window.addEventListener('hashchange', () => applyFromHash(true));
    // Support back/forward when we used pushState (which doesn't fire hashchange reliably).
    window.addEventListener('popstate', () => applyFromHash(true));

    // First paint: install hash if missing so URLs are shareable.
    const initialId = resolveHash();
    if (initialId) {
        const wantHash = '#' + initialId;
        if (window.location.hash !== wantHash) {
            history.replaceState(null, '', wantHash);
        }
        showSection(initialId, { updateHash: false, scrollTop: false });
    }
});