// Shared weak-password nudge, used by all 4 role dashboards.
// Uses a shared stacking container (see getOrCreateAlertsContainer) so this can
// sit alongside other reminder banners (e.g. phone verification) without overlapping.
(function () {
    function getOrCreateAlertsContainer() {
        let container = document.getElementById('topAlertsStack');
        if (!container) {
            container = document.createElement('div');
            container.id = 'topAlertsStack';
            container.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; z-index: 9999; display: flex; flex-direction: column;';
            document.body.prepend(container);
        }
        return container;
    }

    function injectStyles() {
        if (document.getElementById('weakPasswordAlertStyles')) return;
        const style = document.createElement('style');
        style.id = 'weakPasswordAlertStyles';
        style.textContent = `
            @keyframes weakPwSlideDown { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
            #weakPasswordAlert { animation: weakPwSlideDown 0.35s ease-out; }
        `;
        document.head.appendChild(style);
    }

    function showAlert() {
        if (document.getElementById('weakPasswordAlert')) return;

        injectStyles();
        const container = getOrCreateAlertsContainer();

        const bar = document.createElement('div');
        bar.id = 'weakPasswordAlert';
        bar.className = 'bg-amber-500 text-white px-4 py-3 flex items-center justify-center gap-3 shadow-lg text-sm font-bold';
        bar.innerHTML = `
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Your password doesn't meet our security standards (8+ characters, including a symbol). Please update it.</span>
            <a href="/views/profile/profile.html" class="underline hover:no-underline ml-2">Change Password</a>
            <button id="weakPasswordAlertClose" class="ml-4 w-6 h-6 flex items-center justify-center rounded-full hover:bg-white/20 transition-colors" aria-label="Dismiss">
                <i class="fa-solid fa-xmark"></i>
            </button>
        `;
        container.appendChild(bar);

        document.getElementById('weakPasswordAlertClose').addEventListener('click', () => {
            fetch('/api/auth/dismiss_weak_password_alert.php', { method: 'POST' }).catch(() => {});
            bar.remove();
        });
    }

    fetch('/api/auth/session_check.php')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'active' && data.weak_password === true) {
                showAlert();
            }
        })
        .catch(() => {});
})();