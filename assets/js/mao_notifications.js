// File Location: assets/js/mao_notifications.js
// Shared notification bell + panel for the MAO side. Included on every MAO page
// (Dashboard, Report Management, Inventory & Aid, System Reports) — but NOT on
// My Profile, since that page doesn't use this header at all.
//
// Each page just needs the bell markup (button#maoNotifBellBtn + panel#maoNotifPanel
// + list#maoNotifList + badge#maoNotifBadge) somewhere in its header, and this file
// handles the rest: fetching, rendering, mark-as-read, and click-to-redirect.

async function loadMaoNotifications() {
    const badge = document.getElementById('maoNotifBadge');
    try {
        const res = await fetch('../../api/dashboard/mao_notifications.php?action=fetch');
        const result = await res.json();
        if (result.status === 'success') {
            renderMaoNotificationList(result.data);
            if (badge) {
                if (result.unread > 0) {
                    badge.textContent = result.unread > 9 ? '9+' : result.unread;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }
        }
    } catch (e) {
        console.error('Failed to load MAO notifications:', e);
    }
}

function renderMaoNotificationList(items) {
    const list = document.getElementById('maoNotifList');
    if (!list) return;

    if (!items || items.length === 0) {
        list.innerHTML = `<p class="text-sm text-gray-400 italic text-center py-10">${t('notif_none')}</p>`;
        return;
    }

    list.innerHTML = items.map(n => `
        <div onclick="goToMaoNotification('${(n.Redirect_URL || '').replace(/'/g, "\\'")}', ${n.Related_Report_ID ?? 'null'})"
             class="flex items-center gap-4 px-6 py-4 ${n.Is_Read == 0 ? 'bg-green-50/50' : ''} hover:bg-gray-50 cursor-pointer transition-colors border-b border-gray-50 last:border-0">
            <div class="w-10 h-10 rounded-full bg-green-100 text-[#11542e] flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-bell text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-700 leading-snug">${n.Message}</p>
                <p class="text-xs text-gray-400 font-bold mt-1">${n.DateStr}</p>
            </div>
            <i class="fa-solid fa-chevron-right text-gray-300 text-xs flex-shrink-0"></i>
        </div>
    `).join('');
}

// Called when a notification row is clicked. Every notification is expected to
// carry a Redirect_URL (a bare filename like "report_management.html", resolved
// relative to whichever MAO page the officer is currently on — they're all
// siblings in views/mao/, so a plain relative filename always works).
function goToMaoNotification(url, reportId) {
    if (!url) return;
    if (reportId) {
        const separator = url.includes('?') ? '&' : '?';
        url += `${separator}id=${reportId}`;
    }
    window.location.href = url;
}

function toggleMaoNotificationPanel() {
    const panel = document.getElementById('maoNotifPanel');
    if (!panel) return;
    const isHidden = panel.classList.contains('hidden');
    if (isHidden) {
        panel.classList.remove('hidden');
        fetch('../../api/dashboard/mao_notifications.php?action=read')
            .then(() => {
                const badge = document.getElementById('maoNotifBadge');
                if (badge) badge.classList.add('hidden');
            });
    } else {
        panel.classList.add('hidden');
    }
}

// Close the panel when clicking anywhere outside of it
document.addEventListener('click', (e) => {
    const panel = document.getElementById('maoNotifPanel');
    const btn = document.getElementById('maoNotifBellBtn');
    if (panel && btn && !panel.classList.contains('hidden') && !panel.contains(e.target) && !btn.contains(e.target)) {
        panel.classList.add('hidden');
    }
});

document.addEventListener('DOMContentLoaded', loadMaoNotifications);