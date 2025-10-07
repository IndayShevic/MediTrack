<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
if ($user) {
    $role = $user['role'];
    if ($role === 'super_admin') { redirect_to('super_admin/dashboard.php'); }
    if ($role === 'bhw') { redirect_to('bhw/dashboard.php'); }
    if ($role === 'resident') { redirect_to('resident/dashboard.php'); }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MediTrack - Medicine Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.8s ease-out forwards;
        }

        .animate-fade-in-down {
            animation: fadeInDown 0.8s ease-out forwards;
        }

        .animate-slide-in-left {
            animation: slideInLeft 0.8s ease-out forwards;
        }

        .animate-slide-in-right {
            animation: slideInRight 0.8s ease-out forwards;
        }

        .animate-float {
            animation: float 3s ease-in-out infinite;
        }

        .animate-pulse-slow {
            animation: pulse 2s ease-in-out infinite;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .hover-scale {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .hover-scale:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .header-blur {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .btn-hover {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-hover::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn-hover:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
        }

        .service-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .service-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: left 0.5s ease;
        }

        .service-card:hover::before {
            left: 0;
        }

        .fade-in {
            opacity: 0;
            animation: fadeInUp 0.8s ease-out forwards;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-purple-50">
    <!-- Header with blur effect -->
    <header class="header-blur border-b border-gray-200 sticky top-0 z-50 animate-fade-in-down">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="text-2xl font-bold gradient-text flex items-center gap-2">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="-webkit-text-fill-color: #667eea;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                MediTrack
            </div>
            <nav class="space-x-6 hidden md:block">
                <a href="#home" class="text-gray-700 hover:text-blue-600 transition-colors duration-300 font-medium">Home</a>
                <a href="#about" class="text-gray-700 hover:text-blue-600 transition-colors duration-300 font-medium">About</a>
                <a href="#services" class="text-gray-700 hover:text-blue-600 transition-colors duration-300 font-medium">Services</a>
                <a href="#contact" class="text-gray-700 hover:text-blue-600 transition-colors duration-300 font-medium">Contact</a>
            </nav>
            <a href="#login" class="gradient-bg text-white px-6 py-2 rounded-lg btn-hover font-medium shadow-lg">Login</a>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-16 space-y-32">
        <!-- Hero Section -->
        <section id="home" class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center min-h-[60vh]">
            <div class="space-y-6 fade-in delay-1">
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-gray-900 leading-tight">
                    Medicine access, <span class="gradient-text">simplified</span>
                </h1>
                <p class="text-lg text-gray-600 leading-relaxed">
                    MediTrack lets residents request medicines online while BHWs and admins manage inventory efficiently with real-time tracking.
                </p>
                <div class="flex gap-4 pt-4">
                    <a href="#login" class="gradient-bg text-white px-8 py-3 rounded-lg btn-hover font-medium shadow-lg">
                        Get Started
                    </a>
                    <a href="#about" class="border-2 border-gray-300 text-gray-700 px-8 py-3 rounded-lg btn-hover font-medium hover:border-blue-500">
                        Learn More
                    </a>
                </div>
            </div>
            <div class="relative fade-in delay-2">
                <div class="absolute -inset-4 gradient-bg rounded-2xl blur-2xl opacity-20 animate-pulse-slow"></div>
                <img class="rounded-2xl shadow-2xl relative animate-float hover-scale" alt="MediTrack System" src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?q=80&w=1200&auto=format&fit=crop" />
            </div>
        </section>

        <!-- About Section -->
        <section id="about" class="fade-in delay-1">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">About MediTrack</h2>
                <div class="w-24 h-1 gradient-bg mx-auto rounded-full"></div>
            </div>
            <div class="glass-effect p-8 md:p-12 rounded-2xl shadow-xl hover-scale">
                <p class="text-lg text-gray-700 leading-relaxed text-center max-w-4xl mx-auto">
                    A comprehensive barangay-focused medicine inventory and request platform featuring FEFO batch handling, 
                    senior citizen allocations, and role-based dashboards. Empowering communities with efficient healthcare management.
                </p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mt-12">
                    <div class="text-center fade-in delay-2">
                        <div class="text-4xl font-bold gradient-text mb-2">100+</div>
                        <div class="text-gray-600">Medicines</div>
                    </div>
                    <div class="text-center fade-in delay-3">
                        <div class="text-4xl font-bold gradient-text mb-2">24/7</div>
                        <div class="text-gray-600">Availability</div>
                    </div>
                    <div class="text-center fade-in delay-4">
                        <div class="text-4xl font-bold gradient-text mb-2">Fast</div>
                        <div class="text-gray-600">Processing</div>
                    </div>
                    <div class="text-center fade-in delay-5">
                        <div class="text-4xl font-bold gradient-text mb-2">Secure</div>
                        <div class="text-gray-600">System</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Services Section -->
        <section id="services" class="fade-in">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Our Services</h2>
                <div class="w-24 h-1 gradient-bg mx-auto rounded-full"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="service-card glass-effect p-8 rounded-2xl shadow-lg hover-scale fade-in delay-1">
                    <div class="w-16 h-16 gradient-bg rounded-xl flex items-center justify-center mb-6 mx-auto">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <div class="text-xl font-bold text-gray-900 mb-3 text-center">Browse & Request</div>
                    <div class="text-gray-600 text-center leading-relaxed">
                        Residents can browse available medicines and submit requests with supporting documentation seamlessly.
                    </div>
                </div>
                <div class="service-card glass-effect p-8 rounded-2xl shadow-lg hover-scale fade-in delay-2">
                    <div class="w-16 h-16 gradient-bg rounded-xl flex items-center justify-center mb-6 mx-auto">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="text-xl font-bold text-gray-900 mb-3 text-center">BHW Approval</div>
                    <div class="text-gray-600 text-center leading-relaxed">
                        Barangay Health Workers efficiently approve or reject requests and manage their local residents.
                    </div>
                </div>
                <div class="service-card glass-effect p-8 rounded-2xl shadow-lg hover-scale fade-in delay-3">
                    <div class="w-16 h-16 gradient-bg rounded-xl flex items-center justify-center mb-6 mx-auto">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                    <div class="text-xl font-bold text-gray-900 mb-3 text-center">Admin Inventory</div>
                    <div class="text-gray-600 text-center leading-relaxed">
                        Super Admins manage medicines, batches, users, and senior citizen allocations with powerful tools.
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact" class="fade-in">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Get In Touch</h2>
                <div class="w-24 h-1 gradient-bg mx-auto rounded-full"></div>
            </div>
            <div class="glass-effect p-8 md:p-12 rounded-2xl shadow-xl hover-scale text-center">
                <svg class="w-16 h-16 mx-auto mb-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <div class="text-xl text-gray-700 leading-relaxed">
                    For inquiries and support, please visit your local barangay health center.
                    <br><span class="text-gray-500 text-base mt-2 block">Our team is here to help you.</span>
                </div>
            </div>
        </section>

        <!-- Login Section -->
        <section id="login" class="fade-in">
            <div class="max-w-md glass-effect shadow-2xl rounded-2xl p-8 mx-auto hover-scale">
                <div class="text-center mb-8">
                    <div class="w-16 h-16 gradient-bg rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Welcome Back</h2>
                    <p class="text-gray-600">Sign in to access your dashboard</p>
                </div>
                <form action="<?php echo htmlspecialchars(base_url('login.php')); ?>" method="post" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" name="email" required class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 transition-all duration-300" placeholder="your.email@example.com" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                        <input type="password" name="password" required class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 transition-all duration-300" placeholder="••••••••" />
                    </div>
                    <button type="submit" class="w-full gradient-bg text-white py-3 rounded-lg btn-hover font-medium text-lg shadow-lg">
                        Sign In
                    </button>
                </form>
                <div class="mt-6 text-center">
                    <p class="text-gray-600">Don't have an account?</p>
                    <a class="text-blue-600 hover:text-blue-700 font-medium transition-colors duration-300" href="<?php echo htmlspecialchars(base_url('register.php')); ?>">
                        Register as Resident →
                    </a>
                </div>
            </div>
        </section>
    </main>

    <!-- Enhanced Footer -->
    <footer class="border-t border-gray-200 py-8 mt-20">
        <div class="max-w-6xl mx-auto px-4 text-center">
            <div class="text-lg font-bold gradient-text mb-2">MediTrack</div>
            <p class="text-gray-600 text-sm">© <?php echo date('Y'); ?> MediTrack. All rights reserved.</p>
            <p class="text-gray-500 text-xs mt-2">Making healthcare accessible for everyone.</p>
        </div>
    </footer>

    <!-- Scroll to top button -->
    <button id="scrollToTop" class="fixed bottom-8 right-8 gradient-bg text-white p-4 rounded-full shadow-lg btn-hover hidden z-50">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
        </svg>
    </button>

    <script>
        // Smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Scroll to top button
        const scrollToTopBtn = document.getElementById('scrollToTop');
        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 300) {
                scrollToTopBtn.classList.remove('hidden');
            } else {
                scrollToTopBtn.classList.add('hidden');
            }
        });

        scrollToTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        // Intersection Observer for scroll animations
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

        document.querySelectorAll('.fade-in').forEach((el) => {
            observer.observe(el);
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn-hover').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                ripple.classList.add('ripple');
                this.appendChild(ripple);
                
                setTimeout(() => ripple.remove(), 600);
            });
        });
    </script>
</body>
</html>


