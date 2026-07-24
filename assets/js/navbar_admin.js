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
                </div>
                <button onclick="confirmLogout()" class="text-white/30 hover:text-red-400 transition-all">
                    <i class="fa-solid fa-right-from-bracket text-sm"></i>
                </button>
            </div>
        </div>
    </aside>`;
    
    document.getElementById('sidebar-container').innerHTML = sidebarHTML;
}