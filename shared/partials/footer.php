<?php
/**
 * shared/partials/footer.php
 *
 * Include at the bottom of every admin/registrar page.
 * Pages are responsible for closing their own </div><!-- .main-content -->.
 * Bootstrap JS is loaded by head.php — page scripts can safely use it.
 *
 * This partial only:
 *   - adds the mobile sidebar overlay
 *   - wires up the theme toggle and mobile menu button
 *   - closes </body></html>
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
})();
</script>
</body>
</html>