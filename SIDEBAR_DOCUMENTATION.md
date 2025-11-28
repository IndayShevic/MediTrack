# Super Admin Sidebar Documentation

## Overview
The sidebar is a unified navigation component used across all Super Admin pages. It supports both simple links and dropdown menus to organize navigation items.

## File Location
`public/super_admin/includes/sidebar.php`

## How It Works

### 1. Function Structure
The sidebar is rendered by the `render_super_admin_sidebar()` function, which:
- Takes an optional array of arguments (`current_page`, `user_data`)
- Generates HTML for the sidebar navigation
- Includes JavaScript for dropdown functionality
- Includes CSS for styling

### 2. Navigation Items Structure

#### Simple Link Item
```php
[
    'href' => 'super_admin/page.php',  // Path to the page
    'icon' => 'fas fa-icon',            // FontAwesome icon class
    'label' => 'Page Name'              // Display text
]
```

#### Dropdown Menu Item
```php
[
    'type' => 'dropdown',               // Marks this as a dropdown
    'label' => 'Group Name',            // Dropdown header text
    'icon' => 'fas fa-icon',            // Icon for dropdown header
    'items' => [                        // Array of sub-items
        [
            'href' => 'super_admin/subpage1.php',
            'label' => 'Sub Item 1'
        ],
        [
            'href' => 'super_admin/subpage2.php',
            'label' => 'Sub Item 2'
        ]
    ]
]
```

### 3. Active Page Detection
- Compares the current page filename with each menu item's href
- Automatically adds `active` class to matching items
- For dropdowns, checks if any child item is active and opens the dropdown

### 4. Current Organization

The sidebar is currently organized into these groups:

1. **Dashboard** (standalone)
2. **Medicine Management** (dropdown)
   - Medicines
   - Categories
   - Batches
   - Inventory
3. **User Management** (dropdown)
   - Users
   - Allocations
   - Duty Schedules
4. **Reports & Analytics** (dropdown)
   - Analytics
   - Reports Hub
   - Report Settings
5. **Settings** (dropdown)
   - Barangays & Puroks
   - Announcements

## How to Modify the Sidebar

### Adding a New Simple Link
1. Open `public/super_admin/includes/sidebar.php`
2. Find the `$nav_items` array (around line 26)
3. Add a new item:
```php
['href' => 'super_admin/your_page.php', 'icon' => 'fas fa-your-icon', 'label' => 'Your Page'],
```

### Adding a New Dropdown Group
1. Open `public/super_admin/includes/sidebar.php`
2. Find the `$nav_items` array
3. Add a new dropdown:
```php
[
    'type' => 'dropdown',
    'label' => 'Your Group Name',
    'icon' => 'fas fa-group-icon',
    'items' => [
        ['href' => 'super_admin/page1.php', 'label' => 'Page 1'],
        ['href' => 'super_admin/page2.php', 'label' => 'Page 2'],
    ]
],
```

### Moving Items Between Groups
Simply cut and paste items from one dropdown's `items` array to another, or move them to the top level for standalone links.

### Reordering Menu Items
Items appear in the sidebar in the same order they appear in the `$nav_items` array. Simply reorder the array elements.

## Styling

### CSS Classes Used
- `.sidebar-link` - Regular navigation links
- `.sidebar-dropdown` - Dropdown container
- `.sidebar-dropdown-toggle` - Dropdown header button
- `.sidebar-dropdown-menu` - Dropdown submenu container
- `.sidebar-dropdown-item` - Items inside dropdown
- `.active` - Applied to active/current page items

### Customizing Styles
Styles are included inline in the sidebar.php file. You can modify:
- Colors (currently uses purple/blue theme)
- Spacing and padding
- Animation speeds
- Hover effects

## JavaScript Functionality

### Dropdown Toggle
- Clicking a dropdown header toggles the submenu
- Chevron arrow rotates when dropdown is open
- Active dropdowns automatically open on page load

### Auto-Open Active Dropdowns
If a page inside a dropdown is active, the dropdown automatically opens when the page loads.

## Usage in Pages

### Basic Usage
```php
<?php
require_once __DIR__ . '/includes/sidebar.php';
render_super_admin_sidebar([
    'current_page' => basename($_SERVER['PHP_SELF']),
    'user_data' => $user_data
]);
?>
```

### With Custom Current Page
```php
render_super_admin_sidebar([
    'current_page' => 'custom_page.php',
    'user_data' => $user_data
]);
```

## Example: Complete Sidebar Modification

```php
$nav_items = [
    // Standalone link
    ['href' => 'super_admin/dashboardnew.php', 'icon' => 'fas fa-home', 'label' => 'Dashboard'],
    
    // Dropdown group
    [
        'type' => 'dropdown',
        'label' => 'Medicine Management',
        'icon' => 'fas fa-pills',
        'items' => [
            ['href' => 'super_admin/medicines.php', 'label' => 'Medicines'],
            ['href' => 'super_admin/categories.php', 'label' => 'Categories'],
        ]
    ],
    
    // Another standalone link
    ['href' => 'super_admin/users.php', 'icon' => 'fas fa-users', 'label' => 'Users'],
];
```

## Tips

1. **Keep groups logical**: Group related pages together
2. **Limit dropdown size**: Don't put too many items in one dropdown (5-7 max recommended)
3. **Use descriptive labels**: Make it clear what each page does
4. **Choose appropriate icons**: Use FontAwesome icons that match the page purpose
5. **Test active states**: Make sure the active page highlighting works correctly

## Troubleshooting

### Dropdown not opening
- Check browser console for JavaScript errors
- Verify the dropdown ID matches between toggle and menu
- Ensure JavaScript is enabled

### Active state not working
- Verify the page filename matches the href exactly
- Check that `current_page` is being passed correctly
- Ensure the page is using `basename($_SERVER['PHP_SELF'])`

### Styling issues
- Check if design-system.css is loaded
- Verify Tailwind CSS is included
- Check for CSS conflicts with other stylesheets

