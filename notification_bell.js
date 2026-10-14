(function () {
    'use strict';

    function initBell(wrapId, btnId, menuId) {
        var wrap = document.getElementById(wrapId);
        var btn  = document.getElementById(btnId);
        var menu = document.getElementById(menuId);
        if (!wrap || !btn || !menu) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var willOpen = menu.hidden;
            closeAllBells();
            if (willOpen) {
                menu.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        });

        menu.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    }

    function closeAllBells() {
        document.querySelectorAll('.nb-dropdown').forEach(function (m) { m.hidden = true; });
        document.querySelectorAll('.nb-btn').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    }

    function refreshNoticeBadge() {
        var btn = document.getElementById('nbNoticesBtn');
        if (!btn) return;

        fetch('notice_unread_count.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data) return;
            var count = parseInt(data.count, 10) || 0;
            var badge = btn.querySelector('.nb-count');
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'nb-count';
                    btn.appendChild(badge);
                }
                badge.textContent = count > 99 ? '99+' : count;
            } else if (badge) {
                badge.remove();
            }
        })
        .catch(function () {});
    }

    document.addEventListener('click', closeAllBells);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllBells();
    });

    window.addEventListener('focus', refreshNoticeBadge);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refreshNoticeBadge();
    });

    function boot() {
        initBell('nbNoticesWrap', 'nbNoticesBtn', 'nbNoticesMenu');
        initBell('nbFeeWrap', 'nbFeeBtn', 'nbFeeMenu');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();