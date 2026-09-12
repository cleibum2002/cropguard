// Fires when Chrome restores a page from bfcache (Back/Forward button)
window.addEventListener('pageshow', function(e) {
    if (e.persisted) {
        fetch('../../api/auth/session_check.php', {cache: 'no-store'})
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'active') {
                    window.location.replace('../../auth.html');
                }
            })
            .catch(() => window.location.replace('../../auth.html'));
    }
});

function loadAdminNavbar(currentPage) {
    const sidebarHTML = `
    <aside class="bg-[#11542e] w-64 min-h-screen flex flex-col shadow-2xl z-30 flex-shrink-0">
        <div class="p-6 border-b border-white/10 flex flex-col items-center gap-3">
            <div class="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center overflow-hidden border-2 border-white/20">
                <i class="fa-solid fa-seedling text-3xl text-[#f1c40f]"></i>
            </div>
            <div class="text-center">
                <h1 class="text-white text-lg font-black tracking-tight">CropGuard</h1>
                <p class="text-white/40 text-[9px] uppercase tracking-[0.25em]">Crop Damage System</p>
                <p class="text-white/40 text-[9px] uppercase tracking-[0.15em]">DA Region X</p>
            </div>
        </div>

        <nav class="flex-1 px-3 py-6 space-y-1 overflow-y-auto">
            <a href="../profile/profile.html" class="sidebar-link ${currentPage === 'profile' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm transition-all">
                <i class="fa-solid fa-user-circle mr-3 w-4"></i> My Profile
            </a>
            <a href="dashboard.html" class="sidebar-link ${currentPage === 'dashboard' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-table-columns mr-3 w-4"></i> Dashboard
            </a>

            <div class="pt-4 pb-1"><p class="px-4 text-[10px] font-black text-white/40 uppercase tracking-widest">Reports & Analytics</p></div>
            <a href="damage_reports.html" class="sidebar-link ${currentPage === 'reports' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-triangle-exclamation mr-3 w-4"></i> Damage Reports
            </a>
            <a href="system_reports.html" class="sidebar-link ${currentPage === 'sys_reports' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-chart-line mr-3 w-4"></i> System Reports
            </a>
            <a href="analytics.html" class="sidebar-link ${currentPage === 'analytics' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-chart-bar mr-3 w-4"></i> Analytics & Trends
            </a>

            <div class="pt-4 pb-1"><p class="px-4 text-[10px] font-black text-white/40 uppercase tracking-widest">Operations</p></div>
            <a href="item_management.html" class="sidebar-link ${currentPage === 'items' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-database mr-3 w-4"></i> Item Management
            </a>
            <a href="crops.html" class="sidebar-link ${currentPage === 'crops' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-seedling mr-3 w-4"></i> Crops Masterlist
            </a>
            <a href="../communication/index.html" class="sidebar-link ${currentPage === 'comm' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-message mr-3 w-4"></i> Communication
                <span id="commRedDot" class="hidden ml-auto w-2 h-2 bg-red-500 rounded-full"></span>
            </a>
            <a href="../communication/index.html?to=mao" class="sidebar-link flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-headset mr-3 w-4"></i> Message MAO
            </a>
            <a href="admin_seed_requests.html" class="sidebar-link ${currentPage === 'seed_requests' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-leaf mr-3 w-4"></i> Seed Requests
            </a>

            <div class="pt-4 pb-1"><p class="px-4 text-[10px] font-black text-white/40 uppercase tracking-widest">Master File Records</p></div>
            <a href="farmer_management.html" class="sidebar-link ${currentPage === 'farmers' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-person mr-3 w-4"></i> Farmer Management
            </a>
            <a href="audit_logs.html" class="sidebar-link ${currentPage === 'logs' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-shield-halved mr-3 w-4"></i> Audit Logs
            </a>
            <a href="user_management.html" class="sidebar-link ${currentPage === 'staff' ? 'active' : ''} flex items-center px-4 py-3 text-white/60 hover:text-white rounded-xl text-sm">
                <i class="fa-solid fa-users-gear mr-3 w-4"></i> User Management
            </a>
        </nav>

        <div class="p-4 border-t border-white/10">
            <div class="flex items-center gap-3 bg-white/5 px-3 py-3 rounded-2xl">
                <div class="w-9 h-9 bg-[#f1c40f]/20 text-[#f1c40f] rounded-full flex items-center justify-center font-black text-sm">A</div>
                <div class="overflow-hidden flex-1">
                    <p id="adminNameSidebar" class="text-white text-xs font-bold truncate">Loading...</p>
                    <p class="text-white/40 text-[10px] uppercase font-black">Administrator</p>
                </div>
                <button onclick="confirmLogout()" class="text-white/30 hover:text-red-400 transition-all">
                    <i class="fa-solid fa-right-from-bracket text-sm"></i>
                </button>
            </div>
        </div>
    </aside>`;
    
    document.getElementById('sidebar-container').innerHTML = sidebarHTML;
    fetch('../../api/messages/get_unread.php')
        .then(res => res.json())
        .then(data => {
            const dot = document.getElementById('commRedDot');
            if (dot) dot.classList.toggle('hidden', !(data.status === 'success' && data.unread > 0));
        })
        .catch(() => {});

    initCropGuardMobileShell();
}

/* ============================================================
   CropGuard mobile shell — phones only (max-width: 767px)
   Desktop layout is completely untouched.
   ============================================================ */
function initCropGuardMobileShell() {
    if (document.getElementById('cgMobileShellStyles')) return;

    const css = `
    #cgMenuBtn, #cgDrawerOverlay { display: none; }

    @media (max-width: 767px) {

        /* 1. The 16rem sidebar no longer eats the screen.
              It becomes a drawer that slides in from the left. */
        body #sidebar-container {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: 16rem;
            max-width: 82vw;
            z-index: 60;
            transform: translateX(-100%);
            transition: transform .25s ease;
            overflow: hidden;
        }
        body #sidebar-container.cg-drawer-open { transform: translateX(0); }
        body #sidebar-container > aside {
            width: 100%;
            height: 100%;
            min-height: 100%;
        }

        /* 2. Dark backdrop behind the open drawer */
        #cgDrawerOverlay {
            display: block;
            position: fixed;
            top: 0; right: 0; bottom: 0; left: 0;
            background: rgba(0,0,0,.5);
            z-index: 55;
            opacity: 0;
            visibility: hidden;
            transition: opacity .25s ease, visibility .25s ease;
        }
        #cgDrawerOverlay.cg-drawer-open { opacity: 1; visibility: visible; }

        /* 3. Floating menu button, bottom-right, sitting above
              the phone's own back/home/menu bar */
        #cgMenuBtn {
            display: flex;
            align-items: center;
            justify-content: center;
            position: fixed;
            right: 1rem;
            bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 9999px;
            background: #11542e;
            color: #ffffff;
            border: 2px solid rgba(255,255,255,.25);
            box-shadow: 0 8px 22px rgba(0,0,0,.3);
            font-size: 1.15rem;
            z-index: 70;
            cursor: pointer;
            padding: 0;
            transition: transform .15s ease;
            -webkit-tap-highlight-color: transparent;
        }
        #cgMenuBtn:active { transform: scale(.93); }

        /* 4. Comfortable side padding + real clearance at the bottom
              so the phone's nav bar stops covering content */
        main.overflow-y-auto {
            padding-left: 1rem;
            padding-right: 1rem;
            padding-bottom: calc(7rem + env(safe-area-inset-bottom, 0px));
        }

        /* 5. Page headers were sized for wide screens */
        header.flex {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    }

    /* Never let the drawer button appear on printed reports */
    @media print {
        #cgMenuBtn, #cgDrawerOverlay { display: none !important; }
    }`;

    const style = document.createElement('style');
    style.id = 'cgMobileShellStyles';
    style.textContent = css;
    document.head.appendChild(style);

    const overlay = document.createElement('div');
    overlay.id = 'cgDrawerOverlay';
    document.body.appendChild(overlay);

    const btn = document.createElement('button');
    btn.id = 'cgMenuBtn';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Open menu');
    btn.innerHTML = '<i class="fa-solid fa-bars"></i>';
    document.body.appendChild(btn);

    const drawer = document.getElementById('sidebar-container');

    function setDrawer(open) {
        if (!drawer) return;
        drawer.classList.toggle('cg-drawer-open', open);
        overlay.classList.toggle('cg-drawer-open', open);
        btn.innerHTML = open
            ? '<i class="fa-solid fa-xmark"></i>'
            : '<i class="fa-solid fa-bars"></i>';
    }

    btn.addEventListener('click', function () {
        setDrawer(!(drawer && drawer.classList.contains('cg-drawer-open')));
    });
    overlay.addEventListener('click', function () { setDrawer(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setDrawer(false);
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 768) setDrawer(false);
    });
}