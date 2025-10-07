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
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MediTrack</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">
    <header class="bg-white border-b">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="text-lg font-semibold">MediTrack</div>
            <nav class="space-x-6 hidden md:block">
                <a href="#home" class="text-gray-700 hover:text-blue-600">Home</a>
                <a href="#about" class="text-gray-700 hover:text-blue-600">About</a>
                <a href="#services" class="text-gray-700 hover:text-blue-600">Services</a>
                <a href="#contact" class="text-gray-700 hover:text-blue-600">Contact</a>
            </nav>
            <a href="#login" class="bg-blue-600 text-white px-4 py-2 rounded">Login</a>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-10 space-y-24">
        <section id="home" class="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
            <div>
                <h1 class="text-3xl md:text-4xl font-bold mb-3">Medicine access, simplified</h1>
                <p class="text-gray-600">MediTrack lets residents request medicines online while BHWs and admins manage inventory efficiently.</p>
            </div>
            <img class="rounded shadow hidden md:block" alt="MediTrack" src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?q=80&w=1200&auto=format&fit=crop" />
        </section>

        <section id="about">
            <h2 class="text-2xl font-semibold mb-3">About</h2>
            <p class="text-gray-600">A barangay-focused medicine inventory and request platform with FEFO batch handling, senior allocations, and role-based dashboards.</p>
        </section>

        <section id="services" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white p-4 rounded shadow">
                <div class="font-medium mb-1">Browse & Request</div>
                <div class="text-gray-600 text-sm">Residents can browse available medicines and submit requests with proof.</div>
            </div>
            <div class="bg-white p-4 rounded shadow">
                <div class="font-medium mb-1">BHW Approval</div>
                <div class="text-gray-600 text-sm">BHWs approve or reject requests and manage local residents.</div>
            </div>
            <div class="bg-white p-4 rounded shadow">
                <div class="font-medium mb-1">Admin Inventory</div>
                <div class="text-gray-600 text-sm">Super Admins manage medicines, batches, users, and senior allocations.</div>
            </div>
        </section>

        <section id="contact">
            <h2 class="text-2xl font-semibold mb-3">Contact</h2>
            <div class="bg-white p-4 rounded shadow">
                <div class="text-gray-600">For inquiries, visit your barangay health center.</div>
            </div>
        </section>

        <section id="login">
            <div class="max-w-md bg-white shadow rounded-lg p-6 mx-auto">
                <h2 class="text-xl font-semibold text-gray-800 mb-4 text-center">Login</h2>
                <form action="<?php echo htmlspecialchars(base_url('login.php')); ?>" method="post" class="space-y-4">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" required class="w-full border rounded px-3 py-2" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" required class="w-full border rounded px-3 py-2" />
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Sign in</button>
                </form>
                <p class="text-center text-sm text-gray-600 mt-4">No account yet? <a class="text-blue-600 hover:underline" href="<?php echo htmlspecialchars(base_url('register.php')); ?>">Register as Resident</a></p>
            </div>
        </section>
    </main>

    <footer class="border-t py-6 text-center text-sm text-gray-500">© <?php echo date('Y'); ?> MediTrack</footer>
</body>
</html>


