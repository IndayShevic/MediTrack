<?php
declare(strict_types=1);
// Root landing page for MediTrack
require_once __DIR__ . '/config/db.php';

$user = current_user();
if ($user) {
    if ($user['role'] === 'super_admin') { header('Location: public/super_admin/dashboard.php'); exit; }
    if ($user['role'] === 'bhw') { header('Location: public/bhw/dashboard.php'); exit; }
    if ($user['role'] === 'resident') { header('Location: public/resident/dashboard.php'); exit; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MediTrack - Medicine Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/design-system.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        .nav-link.active { color: #1d4ed8; font-weight: 600; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50">
    <!-- Navigation -->
    <nav class="fixed top-0 w-full z-50 glass-effect border-b border-white/20">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <?php $logo = get_setting('brand_logo_path'); $brand = get_setting('brand_name', 'MediTrack'); ?>
                    <?php if ($logo): ?>
                        <img src="<?php echo htmlspecialchars(base_url($logo)); ?>" alt="Logo" class="h-10 w-10 rounded-xl shadow-glow" />
                    <?php else: ?>
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                            </svg>
                        </div>
                    <?php endif; ?>
                    <span class="text-2xl font-bold text-gradient"><?php echo htmlspecialchars($brand); ?></span>
                </div>
                
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#home" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors duration-200">Home</a>
                    <a href="#features" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors duration-200">Features</a>
                    <a href="#about" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors duration-200">About</a>
                    <a href="#contact" class="nav-link text-gray-700 hover:text-blue-600 font-medium transition-colors duration-200">Contact</a>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="openLoginModal()" class="btn btn-primary shadow-glow hidden md:inline-flex">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                        Login
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="pt-32 pb-24 relative overflow-hidden">
        <div class="absolute -top-24 -right-32 w-96 h-96 rounded-full bg-blue-200 blur-3xl opacity-40"></div>
        <div class="absolute -bottom-24 -left-32 w-96 h-96 rounded-full bg-indigo-200 blur-3xl opacity-40"></div>
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="animate-fade-in-up">
                    <div class="inline-flex items-center px-4 py-2 rounded-full bg-blue-100 text-blue-800 text-sm font-medium mb-6 shadow-sm">
                        <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        Trusted by 100+ Barangays
                    </div>
                    <h1 class="text-5xl lg:text-6xl font-bold text-gray-900 mb-6 leading-tight">
                        Medicine Access
                        <span class="text-gradient block">Simplified</span>
                    </h1>
                    <p class="text-lg lg:text-xl text-gray-600 mb-8 leading-relaxed max-w-xl">
                        Streamline medicine requests, inventory management, and healthcare delivery with our comprehensive barangay health management platform.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <button onclick="openLoginModal()" class="btn btn-primary btn-lg shadow-glow">
                            Get Started
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </button>
                        <a href="#features" class="btn btn-secondary btn-lg">
                            Learn More
                        </a>
                    </div>
                </div>
                <div class="animate-fade-in">
                    <div class="relative">
                        <div class="absolute inset-0 bg-gradient-to-r from-blue-400 to-indigo-500 rounded-3xl transform rotate-3"></div>
                        <div class="relative bg-white rounded-3xl shadow-2xl p-8 overflow-hidden">
                            <!-- Healthcare Illustration (SVG) -->
                            <svg viewBox="0 0 400 260" class="w-full h-auto">
                                <defs>
                                    <linearGradient id="pill" x1="0" x2="1">
                                        <stop offset="0%" stop-color="#60a5fa"/>
                                        <stop offset="100%" stop-color="#1d4ed8"/>
                                    </linearGradient>
                                    <linearGradient id="card" x1="0" x2="1">
                                        <stop offset="0%" stop-color="#dbeafe"/>
                                        <stop offset="100%" stop-color="#bfdbfe"/>
                                    </linearGradient>
                                </defs>
                                <!-- Card -->
                                <rect x="24" y="24" rx="16" ry="16" width="352" height="212" fill="url(#card)" stroke="#93c5fd"/>
                                <!-- Cross -->
                                <rect x="176" y="64" width="48" height="128" rx="8" fill="#2563eb" opacity="0.9"/>
                                <rect x="144" y="96" width="112" height="48" rx="8" fill="#2563eb" opacity="0.9"/>
                                <!-- Pills -->
                                <g transform="translate(64,180) rotate(-15)">
                                    <rect x="0" y="0" rx="14" ry="14" width="110" height="28" fill="url(#pill)"/>
                                    <line x1="54" y1="0" x2="54" y2="28" stroke="#fff" stroke-width="2"/>
                                </g>
                                <g transform="translate(260,180) rotate(10)">
                                    <rect x="0" y="0" rx="14" ry="14" width="110" height="28" fill="#f59e0b"/>
                                    <line x1="54" y1="0" x2="54" y2="28" stroke="#fff" stroke-width="2"/>
                                </g>
                                <!-- Text lines -->
                                <rect x="56" y="40" width="96" height="10" rx="5" fill="#3b82f6"/>
                                <rect x="56" y="56" width="64" height="8" rx="4" fill="#60a5fa"/>
                            </svg>
                            <!-- Labels under illustration -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                                <div class="flex items-center space-x-3">
                                    <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                                    <span class="text-sm text-gray-700">Request Approved</span>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                                    <span class="text-sm text-gray-700">Inventory Updated</span>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <span class="w-3 h-3 bg-purple-500 rounded-full"></span>
                                    <span class="text-sm text-gray-700">Senior Allocation</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

        <!-- Features Section -->
        <section id="features" class="py-20 bg-white/60">
            <div class="max-w-7xl mx-auto px-6">
                <h2 class="text-3xl font-bold text-gray-900 mb-8">Powerful Features</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="card animate-fade-in">
                        <div class="card-body flex items-start space-x-4">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-900">Browse & Request</div>
                                <p class="text-sm text-gray-600">Residents can discover medicines and submit requests with proof and patient info.</p>
                            </div>
                        </div>
                    </div>
                    <div class="card animate-fade-in" style="animation-delay:0.05s">
                        <div class="card-body flex items-start space-x-4">
                            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-900">BHW Approval</div>
                                <p class="text-sm text-gray-600">BHWs verify and approve requests, managing residents and families by purok.</p>
                            </div>
                        </div>
                    </div>
                    <div class="card animate-fade-in" style="animation-delay:0.1s">
                        <div class="card-body flex items-start space-x-4">
                            <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-900">Admin Inventory</div>
                                <p class="text-sm text-gray-600">Super Admins manage medicines, batches, users, and senior allocations with FEFO.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- About Section -->
        <section id="about" class="py-20">
            <div class="max-w-7xl mx-auto px-6">
                <h2 class="text-3xl font-bold text-gray-900 mb-4">About MediTrack</h2>
                <p class="text-gray-600 max-w-3xl">A barangay-focused medicine inventory and request platform featuring FEFO batch handling, role-based dashboards (Super Admin, BHW, Resident), and a senior citizen maintenance allocation program. Built with PHP, MySQL, and TailwindCSS for reliability and speed.</p>
            </div>
        </section>

        <!-- Contact CTA -->
        <section id="contact" class="py-16 bg-gradient-to-r from-blue-50 to-indigo-50">
            <div class="max-w-7xl mx-auto px-6">
                <div class="card">
                    <div class="card-body flex items-center justify-between flex-col md:flex-row gap-4">
                        <div>
                            <h3 class="text-xl font-semibold text-gray-900">Need help getting started?</h3>
                            <p class="text-gray-600">Visit your barangay health center or sign in below.</p>
                        </div>
                        <button onclick="openLoginModal()" class="btn btn-primary btn-lg">Sign in</button>
                    </div>
                </div>
            </div>
        </section>


        <!-- Testimonials -->
        <section id="testimonials" class="py-20 bg-white/60">
            <div class="max-w-7xl mx-auto px-6">
                <h2 class="text-3xl font-bold text-gray-900 mb-8">What users say</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="card animate-fade-in">
                        <div class="card-body">
                            <p class="text-gray-700">"MediTrack made it easy for our seniors to get their monthly maintenance meds on time."</p>
                            <div class="mt-4 flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center font-semibold text-blue-700">JL</div>
                                <div>
                                    <div class="font-semibold text-gray-900">Jose L.</div>
                                    <div class="text-xs text-gray-500">Barangay Captain</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card animate-fade-in" style="animation-delay:0.05s">
                        <div class="card-body">
                            <p class="text-gray-700">"Approving requests is straightforward, and stock deduction follows FEFO automatically."</p>
                            <div class="mt-4 flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center font-semibold text-green-700">MA</div>
                                <div>
                                    <div class="font-semibold text-gray-900">Maria A.</div>
                                    <div class="text-xs text-gray-500">BHW</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card animate-fade-in" style="animation-delay:0.1s">
                        <div class="card-body">
                            <p class="text-gray-700">"Inventory, batches, and email notifications work seamlessly for our team."</p>
                            <div class="mt-4 flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center font-semibold text-purple-700">AN</div>
                                <div>
                                    <div class="font-semibold text-gray-900">Ana N.</div>
                                    <div class="text-xs text-gray-500">Super Admin</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="py-20">
            <div class="max-w-5xl mx-auto px-6">
                <h2 class="text-3xl font-bold text-gray-900 mb-8">Frequently Asked Questions</h2>
                <div class="space-y-4">
                    <details class="card animate-fade-in">
                        <summary class="card-body cursor-pointer font-medium text-gray-900">How do residents request medicines?</summary>
                        <div class="px-6 pb-6 text-gray-700">Residents sign in, browse medicines, and submit a request with a proof image and patient details.</div>
                    </details>
                    <details class="card animate-fade-in">
                        <summary class="card-body cursor-pointer font-medium text-gray-900">How are approvals handled?</summary>
                        <div class="px-6 pb-6 text-gray-700">BHWs review requests and approve/reject. Approved requests automatically deduct stock FEFO from batches.</div>
                    </details>
                    <details class="card animate-fade-in">
                        <summary class="card-body cursor-pointer font-medium text-gray-900">Do emails send automatically?</summary>
                        <div class="px-6 pb-6 text-gray-700">Yes. The system emails request and user events via PHPMailer. Email attempts are logged for review.</div>
                    </details>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t py-6 text-center text-sm text-gray-500">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($brand); ?></footer>

    <!-- Sticky mobile login CTA -->
    <button onclick="openLoginModal()" class="md:hidden fixed bottom-6 right-6 btn btn-primary shadow-glow">Login</button>

    <!-- Success Notification -->
    <div id="successNotification" class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg hidden z-50">
        <div class="flex items-center space-x-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span>Registration submitted for approval! You will receive an email once approved.</span>
        </div>
    </div>

    <!-- Registration Modal -->
    <div id="registerModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-8">
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-900">Create Resident Account</h2>
                        <p class="text-gray-600 mt-1">Join MediTrack to manage your medicine requests</p>
                    </div>
                    <button onclick="closeRegisterModal()" class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- Progress Steps -->
                <div class="flex items-center justify-center mb-8">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium bg-primary-600 text-white" id="modal-step-1">1</div>
                            <span class="ml-2 text-sm font-medium text-gray-900">Personal Info</span>
                        </div>
                        <div class="w-8 h-0.5 bg-gray-300"></div>
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium bg-gray-200 text-gray-600" id="modal-step-2">2</div>
                            <span class="ml-2 text-sm font-medium text-gray-500">Family Members</span>
                        </div>
                        <div class="w-8 h-0.5 bg-gray-300"></div>
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium bg-gray-200 text-gray-600" id="modal-step-3">3</div>
                            <span class="ml-2 text-sm font-medium text-gray-500">Review</span>
                        </div>
                    </div>
                </div>
                
                <form id="registerForm" action="public/register.php" method="post" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">First Name</label>
                            <input name="first_name" required class="w-full border rounded px-3 py-2" />
                        </div>
                                    <div>
                                        <label class="block text-sm text-gray-700 mb-1">Last Name</label>
                                        <input name="last_name" required class="w-full border rounded px-3 py-2" />
                                    </div>
                                    <div>
                                        <label class="block text-sm text-gray-700 mb-1">Middle Initial</label>
                                        <input name="middle_initial" class="w-full border rounded px-3 py-2" placeholder="M.I." maxlength="10" />
                                    </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" required class="w-full border rounded px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Password</label>
                            <input type="password" name="password" required class="w-full border rounded px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Date of Birth</label>
                            <input type="date" name="date_of_birth" required class="w-full border rounded px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Phone</label>
                            <input name="phone" class="w-full border rounded px-3 py-2" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm text-gray-700 mb-1">Purok</label>
                            <select name="purok_id" required class="w-full border rounded px-3 py-2">
                                <option value="">Select Purok</option>
                                <?php
                                $puroks = db()->query('SELECT p.id, p.name, b.name AS barangay FROM puroks p JOIN barangays b ON b.id=p.barangay_id ORDER BY b.name, p.name')->fetchAll();
                                foreach ($puroks as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Family Members Section -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-3">Family Members (Optional)</h3>
                        <div id="family-members-container">
                            <div class="family-member border rounded p-4 mb-3">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-sm text-gray-700 mb-1">Full Name</label>
                                        <input type="text" name="family_members[0][full_name]" class="w-full border rounded px-3 py-2" placeholder="e.g., Juan Dela Cruz" />
                                    </div>
                                    <div>
                                        <label class="block text-sm text-gray-700 mb-1">Relationship</label>
                                        <select name="family_members[0][relationship]" class="w-full border rounded px-3 py-2">
                                            <option value="">Select Relationship</option>
                                            <option value="Father">Father</option>
                                            <option value="Mother">Mother</option>
                                            <option value="Son">Son</option>
                                            <option value="Daughter">Daughter</option>
                                            <option value="Brother">Brother</option>
                                            <option value="Sister">Sister</option>
                                            <option value="Grandfather">Grandfather</option>
                                            <option value="Grandmother">Grandmother</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm text-gray-700 mb-1">Date of Birth</label>
                                        <input type="date" name="family_members[0][date_of_birth]" class="w-full border rounded px-3 py-2" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="add-family-member" class="text-blue-600 hover:text-blue-700 text-sm font-medium">
                            + Add Family Member
                        </button>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeRegisterModal()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit for Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Login Modal -->
    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-semibold text-gray-800">Login</h2>
                    <button onclick="closeLoginModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form id="loginForm" action="public/login.php" method="post" class="space-y-4">
                    <?php if (!empty($_SESSION['flash'])): ?>
                        <div class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded px-4 py-2"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" required class="w-full border rounded px-3 py-2" placeholder="you@example.com" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" required class="w-full border rounded px-3 py-2" placeholder="••••••••" />
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition-colors">Sign in</button>
                </form>
                <p class="text-center text-sm text-gray-600 mt-4">No account yet? <button class="text-blue-600 hover:underline" onclick="closeLoginModal(); openRegisterModal();">Register as Resident</button></p>
            </div>
        </div>
    </div>

    <script>
        let familyMemberCount = 1;
        
        function openRegisterModal() {
            document.getElementById('registerModal').classList.remove('hidden');
            document.getElementById('registerModal').classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        
        function closeRegisterModal() {
            document.getElementById('registerModal').classList.add('hidden');
            document.getElementById('registerModal').classList.remove('flex');
            document.body.style.overflow = 'auto';
        }
        
        function openLoginModal() {
            document.getElementById('loginModal').classList.remove('hidden');
            document.getElementById('loginModal').classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLoginModal() {
            document.getElementById('loginModal').classList.add('hidden');
            document.getElementById('loginModal').classList.remove('flex');
            document.body.style.overflow = 'auto';
        }
        
        // Add family member functionality
        document.getElementById('add-family-member').addEventListener('click', function() {
            const container = document.getElementById('family-members-container');
            const newMember = document.createElement('div');
            newMember.className = 'family-member border rounded p-4 mb-3';
            newMember.innerHTML = `
                <div class="flex justify-between items-center mb-2">
                    <h4 class="font-medium text-gray-700">Family Member ${familyMemberCount + 1}</h4>
                    <button type="button" class="remove-family-member text-red-600 hover:text-red-700 text-sm">Remove</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Full Name</label>
                        <input type="text" name="family_members[${familyMemberCount}][full_name]" class="w-full border rounded px-3 py-2" placeholder="e.g., Juan Dela Cruz" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Relationship</label>
                        <select name="family_members[${familyMemberCount}][relationship]" class="w-full border rounded px-3 py-2">
                            <option value="">Select Relationship</option>
                            <option value="Father">Father</option>
                            <option value="Mother">Mother</option>
                            <option value="Son">Son</option>
                            <option value="Daughter">Daughter</option>
                            <option value="Brother">Brother</option>
                            <option value="Sister">Sister</option>
                            <option value="Grandfather">Grandfather</option>
                            <option value="Grandmother">Grandmother</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Date of Birth</label>
                        <input type="date" name="family_members[${familyMemberCount}][date_of_birth]" class="w-full border rounded px-3 py-2" />
                    </div>
                </div>
            `;
            container.appendChild(newMember);
            familyMemberCount++;
            
            // Add remove functionality
            newMember.querySelector('.remove-family-member').addEventListener('click', function() {
                newMember.remove();
            });
        });
        
        // Close modal when clicking outside
        document.getElementById('registerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRegisterModal();
            }
        });
        
        document.getElementById('loginModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLoginModal();
            }
        });
        
        // Handle registration success
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('registered') === '1') {
            // Show success notification
            const notification = document.getElementById('successNotification');
            notification.classList.remove('hidden');
            
            // Auto-hide notification after 5 seconds
            setTimeout(() => {
                notification.classList.add('hidden');
            }, 5000);
            
            // Clear the URL parameter
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        
        // Active link highlighting on scroll
        const sections = ['home','features','about','contact'];
        const links = Array.from(document.querySelectorAll('.nav-link'));
        const sectionEls = sections.map(id => document.getElementById(id));
        const onScroll = () => {
            const y = window.scrollY + 100; // offset for navbar
            let active = 'home';
            for (const el of sectionEls) {
                if (!el) continue;
                const top = el.offsetTop;
                if (y >= top) active = el.id;
            }
            links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + active));
        };
        document.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('load', onScroll);
    </script>
</body>
</html>


