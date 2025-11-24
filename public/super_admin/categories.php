<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_auth(['super_admin']);
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/ajax_helpers.php';

$isAjax = setup_dashboard_ajax_capture();
redirect_to_dashboard_shell($isAjax);

// Helper function to get upload URL
function upload_url(string $path): string {
    $clean_path = ltrim($path, '/');
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $pos = strpos($script, '/public/');
    if ($pos !== false) {
        $base = substr($script, 0, $pos);
    } else {
        $base = dirname($script);
        if ($base === '.' || $base === '/') {
            $base = '';
        }
    }
    return rtrim($base, '/') . '/' . $clean_path;
}

$user = current_user();

// Get updated user data with profile image
$userStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$user['id']]);
$user_data = $userStmt->fetch() ?: [];
if (!empty($user_data)) {
    $user = array_merge($user, $user_data);
}
if (!isset($user_data['profile_image'])) {
    $user_data['profile_image'] = null;
}

// Handle create / update / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);

    if ($action === 'create' && $name !== '') {
        // Check for duplicate name
        $checkStmt = db()->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?)');
        $checkStmt->execute([$name]);
        if ($checkStmt->fetch()) {
            set_flash('Category name already exists. Please choose a different name.', 'error');
        } else {
            $stmt = db()->prepare('INSERT INTO categories(name, description) VALUES(?,?)');
            try {
                $stmt->execute([$name, $description]);
                set_flash('Category created successfully.', 'success');
            } catch (Exception $e) {
                set_flash('Failed to create category: ' . $e->getMessage(), 'error');
            }
        }
    } elseif ($action === 'update' && $category_id > 0 && $name !== '') {
        // Check for duplicate name (excluding current category)
        $checkStmt = db()->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?');
        $checkStmt->execute([$name, $category_id]);
        if ($checkStmt->fetch()) {
            set_flash('Category name already exists. Please choose a different name.', 'error');
        } else {
            $stmt = db()->prepare('UPDATE categories SET name=?, description=? WHERE id=?');
            try {
                $stmt->execute([$name, $description, $category_id]);
                set_flash('Category updated successfully.', 'success');
            } catch (Exception $e) {
                set_flash('Failed to update category: ' . $e->getMessage(), 'error');
            }
        }
    } elseif ($action === 'delete' && $category_id > 0) {
        // Check if category is being used by any medicines
        $checkStmt = db()->prepare('SELECT COUNT(*) as count FROM medicines WHERE category_id = ?');
        $checkStmt->execute([$category_id]);
        $usage = $checkStmt->fetch()['count'];
        
        if ($usage > 0) {
            set_flash('Cannot delete category. It is being used by ' . $usage . ' medicine(s).', 'error');
        } else {
            $stmt = db()->prepare('DELETE FROM categories WHERE id=?');
            try {
                $stmt->execute([$category_id]);
                set_flash('Category deleted successfully.', 'success');
            } catch (Exception $e) {
                set_flash('Failed to delete category: ' . $e->getMessage(), 'error');
            }
        }
    }
    
    redirect_to('super_admin/categories.php');
}

// Fetch categories
$categories = db()->query('SELECT c.*, COUNT(m.id) as medicine_count FROM categories c LEFT JOIN medicines m ON c.id = m.category_id GROUP BY c.id ORDER BY c.name')->fetchAll();
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - Super Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/design-system.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(base_url('assets/css/sweetalert-enhanced.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars(base_url('assets/js/logout-confirmation.js')); ?>"></script>
    <style>
        .content-header {
            position: sticky !important;
            top: 0 !important;
            z-index: 50 !important;
            background: white !important;
            border-bottom: 1px solid #e5e7eb !important;
            padding: 2rem !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 2rem !important;
        }
    </style>
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
    <!-- Sidebar -->
    <?php render_super_admin_sidebar([
        'current_page' => $current_page,
        'user_data' => $user_data
    ]); ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Content -->
        <div class="content-body">
            <?php [$flash, $ft] = get_flash(); if ($flash): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $ft==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200'; ?> animate-fade-in">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?php if ($ft === 'success'): ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            <?php else: ?>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            <?php endif; ?>
                        </svg>
                        <?php echo htmlspecialchars($flash); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Add Category Button -->
            <div class="flex justify-end mb-8">
                <button onclick="openAddModal()" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-purple-500 to-blue-600 text-white text-sm font-medium rounded-lg hover:from-purple-600 hover:to-blue-700 transition-all duration-200 shadow-lg hover:shadow-xl">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Add Category
                </button>
            </div>

            <!-- Categories List -->
            <div class="bg-white rounded-lg border border-gray-200">

                <?php if (empty($categories)): ?>
                    <div class="text-center py-16">
                        <div class="w-16 h-16 bg-gradient-to-br from-purple-100 to-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No categories found</h3>
                        <p class="text-gray-500 text-sm">Get started by creating your first category.</p>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($categories as $category): ?>
                            <div class="p-6 hover:bg-gradient-to-r hover:from-purple-50 hover:to-blue-50 transition-all duration-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3">
                                            <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($category['name']); ?></h3>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gradient-to-r from-purple-100 to-blue-100 text-purple-700 border border-purple-200">
                                                <?php echo (int)$category['medicine_count']; ?> medicines
                                            </span>
                                        </div>
                                        <?php if (!empty($category['description'])): ?>
                                            <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($category['description']); ?></p>
                                        <?php endif; ?>
                                        <p class="mt-2 text-xs text-gray-500">Created <?php echo date('M j, Y', strtotime($category['created_at'])); ?></p>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <button onclick="openEditModal(<?php echo (int)$category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>', '<?php echo htmlspecialchars($category['description'] ?? ''); ?>')" 
                                                class="p-2 text-gray-400 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition-colors duration-200"
                                                title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </button>
                                        <button onclick="deleteCategory(<?php echo (int)$category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')" 
                                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors duration-200"
                                                title="Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add/Edit Modal -->
    <div id="categoryModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full" id="modalContent">
            <div class="p-6">
                <!-- Header -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 id="modalTitle" class="text-lg font-semibold text-gray-900">Add Category</h2>
                        <p class="text-sm text-gray-500">Create a new category for organizing medicines</p>
                    </div>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form id="categoryForm" method="post" class="space-y-6">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="category_id" id="categoryId">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Category Name</label>
                        <input type="text" name="name" id="categoryName" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                               placeholder="Enter category name">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="categoryDescription" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 resize-none" 
                                  placeholder="Enter category description (optional)"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button type="button" onclick="closeModal()" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors duration-200">
                            Cancel
                        </button>
                        <button type="submit" id="submitBtn" 
                                class="px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-purple-500 to-blue-600 border border-transparent rounded-md hover:from-purple-600 hover:to-blue-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-all duration-200 shadow-lg hover:shadow-xl">
                            Add Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Category';
            document.getElementById('formAction').value = 'create';
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryDescription').value = '';
            document.getElementById('submitBtn').textContent = 'Add Category';
            
            const modal = document.getElementById('categoryModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('categoryName').focus();
        }

        function openEditModal(id, name, description) {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('formAction').value = 'update';
            document.getElementById('categoryId').value = id;
            document.getElementById('categoryName').value = name;
            document.getElementById('categoryDescription').value = description;
            document.getElementById('submitBtn').textContent = 'Update Category';
            
            const modal = document.getElementById('categoryModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('categoryName').focus();
        }

        function closeModal() {
            const modal = document.getElementById('categoryModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function deleteCategory(id, name) {
            if (confirm('Are you sure you want to delete the category "' + name + '"? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal when clicking outside
        document.getElementById('categoryModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // Initialize functions when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Logout confirmation is now handled by logout-confirmation.js
        });

        // Profile dropdown functionality
        function initProfileDropdown() {
            const toggle = document.getElementById('profile-toggle');
            const menu = document.getElementById('profile-menu');
            const arrow = document.getElementById('profile-arrow');
            
            if (!toggle || !menu || !arrow) return;
            
            toggle.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (menu.classList.contains('hidden')) {
                    menu.classList.remove('hidden');
                    arrow.classList.add('rotate-180');
                } else {
                    menu.classList.add('hidden');
                    arrow.classList.remove('rotate-180');
                }
            };
            
            // Close dropdown when clicking outside
            if (!window.superAdminProfileDropdownClickHandler) {
                window.superAdminProfileDropdownClickHandler = function(e) {
                    const toggle = document.getElementById('profile-toggle');
                    const menu = document.getElementById('profile-menu');
                    if (menu && toggle && !toggle.contains(e.target) && !menu.contains(e.target)) {
                        menu.classList.add('hidden');
                        const arrow = document.getElementById('profile-arrow');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('click', window.superAdminProfileDropdownClickHandler);
            }
            
            // Close dropdown when pressing Escape
            if (!window.superAdminProfileDropdownKeyHandler) {
                window.superAdminProfileDropdownKeyHandler = function(e) {
                    if (e.key === 'Escape') {
                        const menu = document.getElementById('profile-menu');
                        const arrow = document.getElementById('profile-arrow');
                        if (menu) menu.classList.add('hidden');
                        if (arrow) arrow.classList.remove('rotate-180');
                    }
                };
                document.addEventListener('keydown', window.superAdminProfileDropdownKeyHandler);
            }
        }

        // Initialize profile dropdown when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initProfileDropdown();
        });
    </script>
</body>
</html>
<?php deliver_dashboard_ajax_content($isAjax); ?>
