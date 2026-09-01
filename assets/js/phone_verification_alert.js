// Shared phone-verification reminder, used by all 4 role dashboards.
// Non-disruptive by design - dismissible, links to Profile, reappears next login
// until the phone is actually verified. Shares the same stacking container as
// weak_password_alert.js so both can show together without overlapping.
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
        if (document.getElementById('phoneAlertStyles')) return;
        const style = document.createElement('style');
        style.id = 'phoneAlertStyles';
        style.textContent = `
            @keyframes phoneAlertSlideDown { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
            #phoneVerifyAlert { animation: phoneAlertSlideDown 0.35s ease-out; }
        `;
        document.head.appendChild(style);
    }

    function showAlert() {
        if (document.getElementById('phoneVerifyAlert')) return;

        injectStyles();
        const container = getOrCreateAlertsContainer();

        const bar = document.createElement('div');
        bar.id = 'phoneVerifyAlert';
        bar.className = 'bg-blue-600 text-white px-4 py-3 flex items-center justify-center gap-3 shadow-lg text-sm font-bold';
        bar.innerHTML = `
            <i class="fa-solid fa-mobile-screen"></i>
            <span>Your phone number isn't verified yet.</span>
            <a href="/views/profile/profile.html" class="underline hover:no-underline ml-2">Verify Now</a>
            <button id="phoneVerifyAlertClose" class="ml-4 w-6 h-6 flex items-center justify-center rounded-full hover:bg-white/20 transition-colors" aria-label="Dismiss">
                <i class="fa-solid fa-xmark"></i>
            </button>
        `;
        container.appendChild(bar);

        document.getElementById('phoneVerifyAlertClose').addEventListener('click', () => {
            fetch('/api/auth/dismiss_phone_alert.php', { method: 'POST' }).catch(() => {});
            bar.remove();
        });
    }

    fetch('/api/auth/session_check.php')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'active' && data.phone_unverified === true) {
                showAlert();
            }
        })
        .catch(() => {});
})();