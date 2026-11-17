<?php
/**
 * shared/partials/footer.php
 * Closes </body></html>. Wires sidebar/theme + both view toggles.
 */
?>
<div id="sidebarOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script>
(function () {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('sidebarOverlay');
    const mt = document.getElementById('menuToggle');
    if (mt && sb && ov) {
        mt.addEventListener('click', () => {
            sb.classList.toggle('open');
            ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
        });
    }

    const tb = document.getElementById('themeToggle');
    const ti = document.getElementById('themeIcon');
    const h  = document.documentElement;
    if (tb && ti) {
        function st(t) {
            h.setAttribute('data-bs-theme', t);
            ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
            document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60 * 60 * 24 * 365);
        }
        (function () {
            const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);
            st(m ? decodeURIComponent(m[1]) : 'light');
        })();
        tb.addEventListener('click', () =>
            st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));
    }

    // ── 5-mode stat-grid toggle ──
    document.querySelectorAll('.view-toggle[data-view-key]').forEach(toggleEl => {
        const key     = toggleEl.dataset.viewKey;
        const buttons = toggleEl.querySelectorAll('.vt-btn');
        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.dataset.view;
                if (!mode) return;
                buttons.forEach(b => {
                    const active = b.dataset.view === mode;
                    b.classList.toggle('active', active);
                    b.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                document.querySelectorAll('.view-container').forEach(c => {
                    Array.from(c.classList).filter(cls => cls.startsWith('view-mode-')).forEach(cls => c.classList.remove(cls));
                    c.classList.add('view-mode-' + mode);
                });
                const safeKey = String(key).replace(/[^a-z0-9_]/gi, '_');
                document.cookie = 'view_' + safeKey + '=' + mode
                                + ';path=/;max-age=' + (60 * 60 * 24 * 365)
                                + ';samesite=lax';
            });
        });
    });

    // ── Table ⇄ Cards toggle (event-delegated; survives AJAX) ──
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.vt-btn[data-dir-view]');
        if (!btn) return;
        const mode = btn.dataset.dirView;
        if (mode !== 'table' && mode !== 'cards') return;

        const card = btn.closest('.card');
        let wrap = null;
        if (card) wrap = card.querySelector('.dir-view-wrap');
        if (!wrap) wrap = document.querySelector('.dir-view-wrap');

        if (wrap) {
            wrap.classList.remove('dir-view-table', 'dir-view-cards');
            wrap.classList.add('dir-view-' + mode);
            const key = wrap.dataset.pageKey || 'directory';
            document.cookie = 'view_' + key + '=' + mode
                            + ';path=/;max-age=' + (60 * 60 * 24 * 365)
                            + ';samesite=lax';
        }

        document.querySelectorAll('.vt-btn[data-dir-view]').forEach(b => {
            const active = b.dataset.dirView === mode;
            b.classList.toggle('active', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    });
})();
</script>
</body>
</html>