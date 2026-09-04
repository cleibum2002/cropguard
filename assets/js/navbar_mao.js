// File Location: assets/js/navbar_mao.js

function loadMaoNavbar(currentPage) {
    const sidebarHTML = `
    <div id="mobileSidebarBackdrop" class="hidden fixed inset-0 bg-black/50 z-30 md:hidden no-print" onclick="closeMobileSidebar()"></div>
    <button id="mobileSidebarToggle" onclick="toggleMobileSidebar()" class="md:hidden fixed bottom-5 right-5 z-50 w-14 h-14 bg-[#11542e] text-white rounded-full shadow-2xl flex items-center justify-center text-lg no-print">
        <i id="mobileSidebarToggleIcon" class="fa-solid fa-bars"></i>
    </button>
    <aside id="mobileSidebarAside" class="bg-[#11542e] w-64 min-h-screen flex flex-col shadow-2xl z-40 flex-shrink-0 no-print fixed inset-y-0 left-0 -translate-x-full transition-transform duration-300 ease-in-out md:static md:translate-x-0">
        
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
}

// Same pattern as Admin's navbar_admin.js: static sidebar on desktop,
// slide-in drawer via the floating button on mobile.
function toggleMobileSidebar() {
    const aside = document.getElementById('mobileSidebarAside');
    const backdrop = document.getElementById('mobileSidebarBackdrop');
    const icon = document.getElementById('mobileSidebarToggleIcon');
    const isOpen = aside.classList.contains('translate-x-0');
    if (isOpen) {
        closeMobileSidebar();
    } else {
        aside.classList.remove('-translate-x-full');
        aside.classList.add('translate-x-0');
        backdrop.classList.remove('hidden');
        icon.className = 'fa-solid fa-xmark';
    }
}

function closeMobileSidebar() {
    const aside = document.getElementById('mobileSidebarAside');
    const backdrop = document.getElementById('mobileSidebarBackdrop');
    const icon = document.getElementById('mobileSidebarToggleIcon');
    if (!aside) return;
    aside.classList.remove('translate-x-0');
    aside.classList.add('-translate-x-full');
    backdrop.classList.add('hidden');
    if (icon) icon.className = 'fa-solid fa-bars';
}