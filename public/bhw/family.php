<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['bhw']);
$user = current_user();

$rows = db()->prepare('SELECT fm.full_name, fm.relationship, r.last_name, r.first_name FROM family_members fm JOIN residents r ON r.id=fm.resident_id WHERE r.purok_id=(SELECT purok_id FROM users WHERE id=?) ORDER BY r.last_name, fm.full_name');
$rows->execute([$user['id']]);
$families = $rows->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Family Members · BHW</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    'sans': ['Inter', 'system-ui', 'sans-serif'],
                }
            }
        }
    }
</script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50">
<div class="min-h-screen flex">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name','MediTrack'); if ($logo): ?>
                <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" class="h-8 w-8 rounded-lg" alt="Logo" />
            <?php else: ?>
                <div class="h-8 w-8 bg-white/20 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                </div>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($brand ?: 'MediTrack'); ?></span>
        </div>
        <nav class="sidebar-nav">
            <a href="<?php echo htmlspecialchars(base_url('bhw/dashboard.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"/></svg>
                Dashboard
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/requests.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Medicine Requests
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"/></svg>
                Residents
            </a>
            <a class="active" href="#">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Family Members
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/allocations.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                Allocations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/pending_residents.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Pending Registrations
            </a>
            <a href="<?php echo htmlspecialchars(base_url('bhw/stats.php')); ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Statistics
            </a>
            <a href="<?php echo htmlspecialchars(base_url('logout.php')); ?>" class="text-red-600 hover:text-red-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Logout
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <!-- Header -->
        <div class="content-header">
            <div class="flex items-center justify-between">
                <div class="animate-fade-in-up">
                    <div class="flex items-center space-x-3 mb-2">
                        <h1 class="text-4xl font-bold bg-gradient-to-r from-gray-900 via-blue-800 to-purple-800 bg-clip-text text-transparent">
                            Family Members
                        </h1>
                        <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                    </div>
                    <p class="text-gray-600 text-lg">Residents' listed family members in your assigned area</p>
                    <div class="flex items-center space-x-2 mt-2">
                        <div class="w-1 h-1 bg-blue-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-purple-400 rounded-full"></div>
                        <div class="w-1 h-1 bg-cyan-400 rounded-full"></div>
                        <span class="text-sm text-gray-500 ml-2">Live directory</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4 animate-slide-in-right">
                    <div class="text-right glass-effect px-4 py-2 rounded-xl">
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Total members</div>
                        <div class="text-sm font-semibold text-gray-900" id="family-count"><?php echo count($families); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-body">
            <?php if (!empty($families)): ?>
                <!-- Search and Filter -->
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="flex-1">
                            <div class="relative">
                                <input type="text" id="searchInput" placeholder="Search family members..." 
                                       class="w-full px-4 py-3 pl-12 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 bg-white/50 backdrop-blur-sm">
                                <svg class="absolute left-4 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button class="filter-chip active px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="all">
                                All
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="spouse">
                                Spouse
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="child">
                                Children
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="parent">
                                Parents
                            </button>
                            <button class="filter-chip px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200" data-filter="sibling">
                                Siblings
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Family Members Grid -->
                <div id="familyGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <?php foreach ($families as $index => $f): ?>
                        <div class="family-card hover-lift animate-fade-in-up p-6 rounded-2xl shadow-lg" 
                             data-name="<?php echo strtolower(htmlspecialchars($f['full_name'])); ?>"
                             data-resident="<?php echo strtolower(htmlspecialchars($f['last_name'] . ' ' . $f['first_name'])); ?>"
                             data-relationship="<?php echo strtolower(htmlspecialchars($f['relationship'])); ?>"
                             style="animation-delay: <?php echo $index * 0.1; ?>s">
                            
                            <!-- Family Member Header -->
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($f['full_name']); ?></h3>
                                        <p class="text-sm text-gray-500">Family Member</p>
                                    </div>
                                </div>
                                
                                <!-- Relationship Badge -->
                                <div class="relationship-badge">
                                    <?php 
                                    $relationship = strtolower($f['relationship']);
                                    $badgeClass = 'bg-gray-100 text-gray-800';
                                    if (strpos($relationship, 'spouse') !== false || strpos($relationship, 'wife') !== false || strpos($relationship, 'husband') !== false) {
                                        $badgeClass = 'bg-pink-100 text-pink-800';
                                    } elseif (strpos($relationship, 'child') !== false || strpos($relationship, 'son') !== false || strpos($relationship, 'daughter') !== false) {
                                        $badgeClass = 'bg-blue-100 text-blue-800';
                                    } elseif (strpos($relationship, 'parent') !== false || strpos($relationship, 'mother') !== false || strpos($relationship, 'father') !== false) {
                                        $badgeClass = 'bg-green-100 text-green-800';
                                    } elseif (strpos($relationship, 'sibling') !== false || strpos($relationship, 'brother') !== false || strpos($relationship, 'sister') !== false) {
                                        $badgeClass = 'bg-purple-100 text-purple-800';
                                    }
                                    ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars($f['relationship']); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Family Member Details -->
                            <div class="space-y-3">
                                <!-- Resident Info -->
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center space-x-2">
                                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-gray-700">Resident</span>
                                    </div>
                                    <span class="text-sm text-gray-600"><?php echo htmlspecialchars($f['last_name'] . ', ' . $f['first_name']); ?></span>
                                </div>

                                <!-- Relationship Info -->
                                <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                    <div class="flex items-center space-x-2">
                                        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        <span class="text-sm font-medium text-blue-700">Relationship</span>
                                    </div>
                                    <span class="text-sm text-blue-600"><?php echo htmlspecialchars($f['relationship']); ?></span>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <div class="mt-6 flex justify-end">
                                <button class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-medium rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- No Results Message -->
                <div id="noResults" class="hidden text-center py-12">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No family members found</h3>
                    <p class="text-gray-600 mb-4">Try adjusting your search or filter criteria.</p>
                    <button onclick="clearFilters()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Clear Filters
                    </button>
                </div>
            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state-card hover-lift animate-fade-in-up p-12 text-center rounded-2xl shadow-lg">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">No Family Members Found</h3>
                    <p class="text-gray-600 mb-6 text-lg">No family members have been registered for residents in your assigned area.</p>
                    
                    <div class="bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-200 rounded-xl p-6 max-w-2xl mx-auto">
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">What you can do:</h4>
                        <div class="space-y-2 text-left">
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Encourage residents to register their family members</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Check if residents have completed their profiles</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                <span class="text-sm text-gray-700">Verify your assigned area coverage</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const filterChips = document.querySelectorAll('.filter-chip');
        const familyCards = document.querySelectorAll('.family-card');
        const familyGrid = document.getElementById('familyGrid');
        const noResults = document.getElementById('noResults');
        const familyCount = document.getElementById('family-count');

        let currentFilter = 'all';
        let currentSearch = '';

        // Search functionality
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.toLowerCase();
                filterFamilyMembers();
            });
        }

        // Filter functionality
        filterChips.forEach(chip => {
            chip.addEventListener('click', function() {
                // Update active state
                filterChips.forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                
                currentFilter = this.dataset.filter;
                filterFamilyMembers();
            });
        });

        function filterFamilyMembers() {
            let visibleCount = 0;
            
            familyCards.forEach(card => {
                const name = card.dataset.name;
                const resident = card.dataset.resident;
                const relationship = card.dataset.relationship;
                
                let matchesSearch = true;
                let matchesFilter = true;
                
                // Check search match
                if (currentSearch) {
                    matchesSearch = name.includes(currentSearch) || resident.includes(currentSearch) || relationship.includes(currentSearch);
                }
                
                // Check filter match
                if (currentFilter !== 'all') {
                    matchesFilter = relationship.includes(currentFilter);
                }
                
                if (matchesSearch && matchesFilter) {
                    card.style.display = 'block';
                    card.classList.add('animate-fade-in');
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                    card.classList.remove('animate-fade-in');
                }
            });
            
            // Update count
            if (familyCount) {
                familyCount.textContent = visibleCount;
            }
            
            // Show/hide no results message
            if (visibleCount === 0) {
                if (noResults) noResults.classList.remove('hidden');
                if (familyGrid) familyGrid.classList.add('hidden');
            } else {
                if (noResults) noResults.classList.add('hidden');
                if (familyGrid) familyGrid.classList.remove('hidden');
            }
        }

        // Clear filters function
        window.clearFilters = function() {
            if (searchInput) searchInput.value = '';
            currentSearch = '';
            currentFilter = 'all';
            
            filterChips.forEach(c => c.classList.remove('active'));
            filterChips[0].classList.add('active');
            
            filterFamilyMembers();
        };

        // Add intersection observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all animated elements
        document.querySelectorAll('.animate-fade-in-up, .animate-fade-in, .animate-slide-in-right').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
            observer.observe(el);
        });

        // Add hover effects to cards
        document.querySelectorAll('.hover-lift').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Add ripple effect to buttons (excluding sidebar links)
        document.querySelectorAll('a:not(.sidebar-nav a), button').forEach(element => {
            element.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add keyboard navigation for search
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    currentSearch = '';
                    filterFamilyMembers();
                }
            });
        }
    });
    </script>
</div>
</body>
</html>


