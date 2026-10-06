// File Location: assets/js/navbar_mao.js

// The bfcache (Back/Forward) guard that used to live here now sits in
// assets/js/session_guard.js, which every MAO page loads. Keeping a second
// copy here would only re-check the session without the role check or the
// hide-until-verified behaviour the guard adds.

function loadMaoNavbar(currentPage) {
    const sidebarHTML = `
    <aside class="bg-[#11542e] w-64 min-h-screen flex flex-col shadow-2xl z-30 flex-shrink-0 no-print">
        
        <div class="p-8 border-b border-white/10 flex flex-col items-center">
            <div class="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center border-2 border-white/20 mb-4 shadow-lg">
                <i class="fa-solid fa-wheat-awn text-4xl text-[#f1c40f]"></i>
            </div>
            <div class="text-center">
                <h1 class="text-white font-black text-lg tracking-tighter">CropGuard</h1>
                <p class="text-white/40 text-[9px] uppercase tracking-[0.2em]">MAO Center · Region X</p>
            </div>
        </div>
        
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto text-sm custom-scrollbar">
            <a href="../profile/profile.html" class="flex items-center px-4 py-3 rounded-xl transition-all ${currentPage === 'profile' ? 'bg-white/10 border-l-4 border-[#f1c40f] text-white font-bold' : 'text-white/60 hover:bg-white/5 hover:text-white border-l-4 border-transparent'}">
                <i class="fa-solid fa-user-circle mr-3 w-5 text-center ${currentPage === 'profile' ? 'text-[#f1c40f]' : ''}"></i> My Profile
            </a>
            
            <a href="index.html" class="flex items-center px-4 py-3 rounded-xl transition-all ${currentPage === 'dashboard' ? 'bg-white/10 border-l-4 border-[#f1c40f] text-white font-bold' : 'text-white/60 hover:bg-white/5 hover:text-white border-l-4 border-transparent'}">
                <i class="fa-solid fa-house mr-3 w-5 text-center ${currentPage === 'dashboard' ? 'text-[#f1c40f]' : ''}"></i> MAO Dashboard
            </a>
            
            <a href="report_management.html" class="flex items-center px-4 py-3 rounded-xl transition-all ${currentPage === 'reports' ? 'bg-white/10 border-l-4 border-[#f1c40f] text-white font-bold' : 'text-white/60 hover:bg-white/5 hover:text-white border-l-4 border-transparent'}">
                <i class="fa-solid fa-file-contract mr-3 w-5 text-center ${currentPage === 'reports' ? 'text-[#f1c40f]' : ''}"></i> Report Management
            </a>
            
            <a href="inventory_aid.html" class="flex items-center px-4 py-3 rounded-xl transition-all ${currentPage === 'inventory' ? 'bg-white/10 border-l-4 border-[#f1c40f] text-white font-bold' : 'text-white/60 hover:bg-white/5 hover:text-white border-l-4 border-transparent'}">
                <i class="fa-solid fa-box-open mr-3 w-5 text-center ${currentPage === 'inventory' ? 'text-[#f1c40f]' : ''}"></i> Inventory & Aid
            </a>
            
            <a href="mao_reports.html" class="flex items-center px-4 py-3 rounded-xl transition-all ${currentPage === 'analytics' ? 'bg-white/10 border-l-4 border-[#f1c40f] text-white font-bold' : 'text-white/60 hover:bg-white/5 hover:text-white border-l-4 border-transparent'}">
                <i class="fa-solid fa-chart-line mr-3 w-5 text-center ${currentPage === 'analytics' ? 'text-[#f1c40f]' : ''}"></i> Distribution Reports
            </a>
            
            <a href="../communication/index.html" class="flex items-center px-4 py-3 rounded-xl transition-all ${currentPage === 'communication' ? 'bg-white/10 border-l-4 border-[#f1c40f] text-white font-bold' : 'text-white/60 hover:bg-white/5 hover:text-white border-l-4 border-transparent'}">
                <i class="fa-solid fa-message mr-3 w-5 text-center ${currentPage === 'communication' ? 'text-[#f1c40f]' : ''}"></i> Communication
                <span id="commRedDot" class="hidden ml-auto w-2 h-2 bg-red-500 rounded-full"></span>
            </a>

           <a href="../communication/index.html?to=admin" class="flex items-center px-4 py-3 rounded-xl transition-all text-white/60 hover:bg-white/5 hover:text-white border-l-4 border-transparent">
               <i class="fa-solid fa-headset mr-3 w-5 text-center"></i> Message Admin
           </a>
            
        </nav>
        
        <div class="p-4 border-t border-white/10">
            <div class="flex items-center gap-3 bg-white/5 px-3 py-3 rounded-2xl">
                <div class="w-9 h-9 bg-[#f1c40f]/20 text-[#f1c40f] rounded-full flex items-center justify-center font-black text-sm flex-shrink-0">MA</div>
                <div class="overflow-hidden flex-1 text-white">
                    <p id="maoNameSidebar" class="text-xs font-bold truncate">Loading...</p>
                    <p class="text-white/40 text-[10px] truncate">MAO Officer</p>
                </div>
                <button onclick="confirmLogout()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-red-500/20 text-white/40 hover:text-red-400 transition-colors">
                    <i class="fa-solid fa-right-from-bracket text-sm"></i>
                </button>
            </div>
        </div>
    </aside>`;
    
    const container = document.getElementById('sidebar-container');
    if (container) {
        container.innerHTML = sidebarHTML;
        fetch('../../api/messages/get_unread.php')
            .then(res => res.json())
            .then(data => {
                const dot = document.getElementById('commRedDot');
                if (dot) dot.classList.toggle('hidden', !(data.status === 'success' && data.unread > 0));
            })
            .catch(() => {});
    }

    initCropGuardMobileShell();
}

/* ============================================================
   CropGuard mobile shell — phones only (max-width: 767px)
   Desktop layout is completely untouched.
   Identical copy of the version in navbar_admin.js — MAO pages
   never load that file, so this was calling an undefined function
   and the mobile drawer/FAB never activated on any MAO page.
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

        /* 6. Compact logo block in the drawer, so the whole menu fits on a
              short budget-phone screen instead of hiding the last items */
        body #sidebar-container > aside > div:first-child {
            padding-top: 1rem;
            padding-bottom: 1rem;
        }
        body #sidebar-container > aside > div:first-child > div:first-child {
            width: 3rem;
            height: 3rem;
            margin-bottom: 0.5rem;
        }
        body #sidebar-container > aside > div:first-child > div:first-child i {
            font-size: 1.25rem;
        }
        body #sidebar-container nav {
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
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