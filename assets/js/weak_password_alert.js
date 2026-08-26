// Shared weak-password nudge, used by all 5 role dashboards.
// Shows a dismissible sliding banner if session_check.php reports the account's
// current password doesn't meet the 8-character + special-symbol rule.
// Dismissing hides it for the rest of THIS login (sessionStorage) - it comes back
// fresh on the next login, since login.php re-checks the real password every time.
(function () {
    const DISMISS_KEY = 'weakPasswordAlertDismissed';

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
        if (sessionStorage.getItem(DISMISS_KEY) === '1') return;
        if (document.getElementById('weakPasswordAlert')) return;

        injectStyles();

        const bar = document.createElement('div');
        bar.id = 'weakPasswordAlert';
        bar.className = 'fixed top-0 left-0 right-0 z-[9999] bg-amber-500 text-white px-4 py-3 flex items-center justify-center gap-3 shadow-lg text-sm font-bold';
        bar.innerHTML = `
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Your password doesn't meet our security standards (8+ characters, including a symbol). Please update it.</span>
            <a href="/views/profile/profile.html" class="underline hover:no-underline ml-2">Change Password</a>
            <button id="weakPasswordAlertClose" class="ml-4 w-6 h-6 flex items-center justify-center rounded-full hover:bg-white/20 transition-colors" aria-label="Dismiss">
                <i class="fa-solid fa-xmark"></i>
            </button>
        `;
        document.body.prepend(bar);

        document.getElementById('weakPasswordAlertClose').addEventListener('click', () => {
            sessionStorage.setItem(DISMISS_KEY, '1');
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