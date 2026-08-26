// Shared weak-password nudge, used by all 5 role dashboards.
// Shows a dismissible sliding banner if session_check.php reports the account's
// current password doesn't meet the 8-character + special-symbol rule.
// Dismissal is tracked server-side in the PHP session (see dismiss_weak_password_alert.php),
// NOT in the browser - sessionStorage has no idea when a login actually happens, so it
// wrongly stayed dismissed across logouts/different accounts in the same tab. login.php
// resets the dismissal flag on every fresh login, so this now correctly reappears every
// login until the password actually changes.
(function () {
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