<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Custom role creation and role-specific admin menu visibility.
 *
 * Menu visibility is an interface preference, not an authorization boundary.
 * WordPress capabilities remain responsible for protecting admin screens.
 */

function tk_role_management_init(): void {
    add_action('admin_init', 'tk_role_management_enforce_menu_rules', 20);
    add_action('admin_menu', 'tk_role_management_register_page', 20);
    add_action('admin_menu', 'tk_role_management_apply_menu_rules', 100000);
    add_action('admin_post_tk_role_management_save', 'tk_role_management_save');
    add_action('admin_post_tk_role_management_delete', 'tk_role_management_delete');
}

function tk_role_management_register_page(): void {
    $license_valid = (string) tk_get_option('license_status', 'inactive') === 'valid';
    $license_limited = (string) tk_get_option('license_type', '') === 'local';
    if (!tk_toolkits_can_manage() || !$license_valid || $license_limited) {
        return;
    }

    add_submenu_page(
        'tool-kits',
        __('Role Management', 'tool-kits'),
        __('Role Management', 'tool-kits'),
        'manage_options',
        'tool-kits-role-management',
        'tk_role_management_render_page'
    );
}

function tk_role_management_managed_roles(): array {
    $roles = tk_get_option('role_management_roles', array());
    if (!is_array($roles)) {
        return array();
    }

    $sanitized = array();
    foreach ($roles as $role_slug => $role_data) {
        $role_slug = sanitize_key((string) $role_slug);
        if ($role_slug === '' || $role_slug === 'administrator' || !is_array($role_data)) {
            continue;
        }
        $sanitized[$role_slug] = array(
            'name' => sanitize_text_field((string) ($role_data['name'] ?? $role_slug)),
            'base_role' => sanitize_key((string) ($role_data['base_role'] ?? 'subscriber')),
        );
    }

    return $sanitized;
}

function tk_role_management_menu_rules(): array {
    $rules = tk_get_option('role_management_menu_rules', array());
    if (!is_array($rules)) {
        return array();
    }

    $sanitized = array();
    foreach ($rules as $role_slug => $menu_slugs) {
        $role_slug = sanitize_key((string) $role_slug);
        if ($role_slug === '' || !is_array($menu_slugs)) {
            continue;
        }
        $sanitized[$role_slug] = array_values(array_unique(array_filter(array_map(
            'tk_role_management_sanitize_menu_slug',
            $menu_slugs
        ))));
    }

    return $sanitized;
}

function tk_role_management_sanitize_menu_slug($menu_slug): string {
    $menu_slug = sanitize_text_field(wp_unslash((string) $menu_slug));
    if ($menu_slug === '' || preg_match('/[\x00-\x1F\x7F]/', $menu_slug)) {
        return '';
    }
    return substr($menu_slug, 0, 200);
}

function tk_role_management_available_menus(): array {
    global $menu, $submenu;

    $items = array();
    $parent_labels = array();
    if (is_array($menu)) {
        foreach ($menu as $menu_item) {
            if (!is_array($menu_item) || empty($menu_item[2])) {
                continue;
            }
            $slug = tk_role_management_sanitize_menu_slug($menu_item[2]);
            if ($slug === '' || strpos($slug, 'separator') === 0) {
                continue;
            }
            $label = isset($menu_item[0]) ? wp_strip_all_tags((string) $menu_item[0]) : $slug;
            $label = preg_replace('/\s+\d+\s*$/', '', $label);
            $items[$slug] = $label !== '' ? $label : $slug;
            $parent_labels[$slug] = $items[$slug];
        }
    }

    if (is_array($submenu)) {
        foreach ($submenu as $parent_slug => $submenu_items) {
            $parent_slug = tk_role_management_sanitize_menu_slug($parent_slug);
            if ($parent_slug === '' || !is_array($submenu_items)) {
                continue;
            }
            $parent_label = isset($parent_labels[$parent_slug]) ? $parent_labels[$parent_slug] : $parent_slug;
            foreach ($submenu_items as $submenu_item) {
                if (!is_array($submenu_item) || empty($submenu_item[2])) {
                    continue;
                }
                $slug = tk_role_management_sanitize_menu_slug($submenu_item[2]);
                if ($slug === '' || isset($items[$slug])) {
                    continue;
                }
                $label = isset($submenu_item[0]) ? wp_strip_all_tags((string) $submenu_item[0]) : $slug;
                $label = preg_replace('/\s+\d+\s*$/', '', $label);
                $items[$slug] = sprintf('%s > %s', $parent_label, $label !== '' ? $label : $slug);
            }
        }
    }

    foreach (tk_admin_menu_get_core_items() as $slug => $data) {
        if (!isset($items[$slug])) {
            $items[$slug] = (string) $data['label'];
        }
    }

    $items['index.php'] = __('Dashboard', 'tool-kits');
    $items['tool-kits'] = __('Tool Kits', 'tool-kits');
    natcasesort($items);
    return $items;
}

function tk_role_management_available_menu_capabilities(): array {
    global $menu, $submenu;

    $capabilities = array();
    if (is_array($menu)) {
        foreach ($menu as $menu_item) {
            if (!is_array($menu_item) || empty($menu_item[1]) || empty($menu_item[2])) {
                continue;
            }
            $slug = tk_role_management_sanitize_menu_slug($menu_item[2]);
            $capability = sanitize_key((string) $menu_item[1]);
            if ($slug === '' || $capability === '' || strpos($slug, 'separator') === 0) {
                continue;
            }
            $capabilities[$slug] = $capability;
        }
    }

    if (is_array($submenu)) {
        foreach ($submenu as $submenu_items) {
            if (!is_array($submenu_items)) {
                continue;
            }
            foreach ($submenu_items as $submenu_item) {
                if (!is_array($submenu_item) || empty($submenu_item[1]) || empty($submenu_item[2])) {
                    continue;
                }
                $slug = tk_role_management_sanitize_menu_slug($submenu_item[2]);
                $capability = sanitize_key((string) $submenu_item[1]);
                if ($slug === '' || $capability === '') {
                    continue;
                }
                $capabilities[$slug] = $capability;
            }
        }
    }

    $core_capabilities = array(
        'index.php' => 'read',
        'edit.php' => 'edit_posts',
        'upload.php' => 'upload_files',
        'edit.php?post_type=page' => 'edit_pages',
        'edit-comments.php' => 'edit_posts',
        'themes.php' => 'edit_theme_options',
        'plugins.php' => 'activate_plugins',
        'users.php' => 'list_users',
        'tools.php' => 'export',
        'options-general.php' => 'manage_options',
        'theme-settings' => 'manage_options',
        'tool-kits' => tk_toolkits_capability(),
    );

    foreach ($core_capabilities as $slug => $capability) {
        if (!isset($capabilities[$slug])) {
            $capabilities[$slug] = $capability;
        }
    }

    foreach (tk_role_management_stored_menu_capabilities() as $slug => $capability) {
        if (!isset($capabilities[$slug])) {
            $capabilities[$slug] = $capability;
        }
    }

    $posted_capabilities = tk_role_management_posted_menu_capabilities();
    foreach ($posted_capabilities as $slug => $capability) {
        if (!isset($capabilities[$slug])) {
            $capabilities[$slug] = $capability;
        }
    }

    return $capabilities;
}

function tk_role_management_stored_menu_capabilities(): array {
    $stored = tk_get_option('role_management_menu_capabilities', array());
    if (!is_array($stored)) {
        return array();
    }

    $capabilities = array();
    foreach ($stored as $slug => $capability) {
        $slug = tk_role_management_sanitize_menu_slug($slug);
        $capability = sanitize_key((string) $capability);
        if ($slug !== '' && $capability !== '') {
            $capabilities[$slug] = $capability;
        }
    }
    return $capabilities;
}

function tk_role_management_store_menu_capabilities(array $capabilities): void {
    $stored = tk_role_management_stored_menu_capabilities();
    foreach ($capabilities as $slug => $capability) {
        $slug = tk_role_management_sanitize_menu_slug($slug);
        $capability = sanitize_key((string) $capability);
        if ($slug !== '' && $capability !== '') {
            $stored[$slug] = $capability;
        }
    }
    tk_update_option('role_management_menu_capabilities', $stored);
}

function tk_role_management_available_menu_parents(): array {
    global $submenu;

    $parents = array();
    if (!is_array($submenu)) {
        return $parents;
    }

    foreach ($submenu as $parent_slug => $submenu_items) {
        $parent_slug = tk_role_management_sanitize_menu_slug($parent_slug);
        if ($parent_slug === '' || !is_array($submenu_items)) {
            continue;
        }
        foreach ($submenu_items as $submenu_item) {
            if (!is_array($submenu_item) || empty($submenu_item[2])) {
                continue;
            }
            $slug = tk_role_management_sanitize_menu_slug($submenu_item[2]);
            if ($slug !== '' && $slug !== $parent_slug) {
                $parents[$slug] = $parent_slug;
            }
        }
    }

    return $parents;
}

function tk_role_management_available_menu_children(array $available_menus, array $menu_parents): array {
    $children = array();
    foreach ($menu_parents as $child_slug => $parent_slug) {
        if (!isset($available_menus[$child_slug])) {
            continue;
        }
        $label = (string) $available_menus[$child_slug];
        $prefix = isset($available_menus[$parent_slug]) ? (string) $available_menus[$parent_slug] . ' > ' : '';
        if ($prefix !== '' && strpos($label, $prefix) === 0) {
            $label = substr($label, strlen($prefix));
        }
        if ($label === '') {
            $label = $child_slug;
        }
        if (!isset($children[$parent_slug])) {
            $children[$parent_slug] = array();
        }
        $children[$parent_slug][$child_slug] = $label;
    }
    return $children;
}

function tk_role_management_posted_menu_capabilities(): array {
    if (empty($_POST['menu_capabilities']) || !is_array($_POST['menu_capabilities'])) {
        return array();
    }

    $capabilities = array();
    foreach (wp_unslash($_POST['menu_capabilities']) as $slug => $capability) {
        $slug = tk_role_management_sanitize_menu_slug($slug);
        $capability = sanitize_key((string) $capability);
        if ($slug !== '' && $capability !== '') {
            $capabilities[$slug] = $capability;
        }
    }

    return $capabilities;
}

function tk_role_management_apply_menu_rules(): void {
    if (!is_admin() || !is_user_logged_in()) {
        return;
    }

    $allowed = tk_role_management_allowed_menus_for_current_user();
    if ($allowed === null) {
        return;
    }

    tk_role_management_enforce_menu_rules($allowed);

    global $menu, $submenu;
    if (!is_array($menu)) {
        return;
    }

    foreach ($menu as $menu_item) {
        if (!is_array($menu_item) || empty($menu_item[2])) {
            continue;
        }
        $menu_slug = (string) $menu_item[2];
        if (strpos($menu_slug, 'separator') === 0) {
            continue;
        }
        if (!in_array($menu_slug, $allowed, true)) {
            remove_menu_page($menu_slug);
        }
    }

    if (is_array($submenu)) {
        foreach ($submenu as $parent_slug => $submenu_items) {
            if (!is_array($submenu_items)) {
                continue;
            }
            foreach ($submenu_items as $submenu_item) {
                if (!is_array($submenu_item) || empty($submenu_item[2])) {
                    continue;
                }
                $submenu_slug = (string) $submenu_item[2];
                if (!in_array($submenu_slug, $allowed, true)) {
                    remove_submenu_page((string) $parent_slug, $submenu_slug);
                }
            }
        }
    }
}

function tk_role_management_allowed_menus_for_current_user(): ?array {
    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) {
        return null;
    }
    if (in_array('administrator', (array) $user->roles, true)) {
        return null;
    }

    $rules = tk_role_management_menu_rules();
    $matched = false;
    $allowed = array('index.php');
    foreach ((array) $user->roles as $role_slug) {
        $role_slug = sanitize_key((string) $role_slug);
        if (!array_key_exists($role_slug, $rules)) {
            continue;
        }
        $matched = true;
        $allowed = array_merge($allowed, $rules[$role_slug]);
        $role_capabilities = tk_role_management_role_capabilities($role_slug);
        foreach (tk_role_management_stored_menu_capabilities() as $menu_slug => $menu_capability) {
            if ($menu_capability === tk_toolkits_capability()) {
                continue;
            }
            if (in_array($menu_capability, $role_capabilities, true)) {
                $allowed[] = $menu_slug;
            }
        }
    }

    if (!$matched) {
        return null;
    }

    return array_values(array_unique(array_filter($allowed)));
}

function tk_role_management_current_user_role_has_capability(string $capability): bool {
    $capability = sanitize_key($capability);
    if ($capability === '') {
        return false;
    }

    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) {
        return false;
    }

    foreach ((array) $user->roles as $role_slug) {
        $role_slug = sanitize_key((string) $role_slug);
        if ($role_slug === '') {
            continue;
        }
        $role = get_role($role_slug);
        if ($role && !empty($role->capabilities[$capability])) {
            return true;
        }
    }

    return false;
}

function tk_role_management_request_menu_candidates(): array {
    global $menu, $pagenow, $submenu;

    $script = isset($pagenow) && is_string($pagenow) && $pagenow !== ''
        ? $pagenow
        : basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = sanitize_text_field($script);
    $page = isset($_GET['page']) ? tk_role_management_sanitize_menu_slug($_GET['page']) : '';
    $post_type = isset($_REQUEST['post_type']) ? sanitize_key(wp_unslash((string) $_REQUEST['post_type'])) : '';
    $candidates = array();

    if ($page !== '' && is_array($menu)) {
        foreach ($menu as $menu_item) {
            if (is_array($menu_item) && isset($menu_item[2]) && (string) $menu_item[2] === $page) {
                $candidates[] = $page;
            }
        }
    }

    if ($page !== '' && is_array($submenu)) {
        foreach ($submenu as $parent_slug => $submenu_items) {
            if (!is_array($submenu_items)) {
                continue;
            }
            foreach ($submenu_items as $submenu_item) {
                if (is_array($submenu_item) && isset($submenu_item[2]) && (string) $submenu_item[2] === $page) {
                    $candidates[] = tk_role_management_sanitize_menu_slug($parent_slug);
                    $candidates[] = $page;
                }
            }
        }
    }

    if ($post_type === '' && isset($_REQUEST['post'])) {
        $post_id = absint($_REQUEST['post']);
        if ($post_id > 0) {
            $detected_post_type = get_post_type($post_id);
            if (is_string($detected_post_type)) {
                $post_type = sanitize_key($detected_post_type);
            }
        }
    }

    $post_type_menu = $post_type !== '' && $post_type !== 'post'
        ? 'edit.php?post_type=' . $post_type
        : 'edit.php';

    $core_map = array(
        'index.php' => 'index.php',
        'upload.php' => 'upload.php',
        'media-new.php' => 'upload.php',
        'media.php' => 'upload.php',
        'edit-comments.php' => 'edit-comments.php',
        'comment.php' => 'edit-comments.php',
        'themes.php' => 'themes.php',
        'widgets.php' => 'themes.php',
        'nav-menus.php' => 'themes.php',
        'customize.php' => 'themes.php',
        'site-editor.php' => 'themes.php',
        'theme-editor.php' => 'themes.php',
        'plugins.php' => 'plugins.php',
        'plugin-install.php' => 'plugins.php',
        'plugin-editor.php' => 'plugins.php',
        'users.php' => 'users.php',
        'user-new.php' => 'users.php',
        'user-edit.php' => 'users.php',
        'profile.php' => 'users.php',
        'tools.php' => 'tools.php',
        'import.php' => 'tools.php',
        'export.php' => 'tools.php',
        'site-health.php' => 'tools.php',
        'export-personal-data.php' => 'tools.php',
        'erase-personal-data.php' => 'tools.php',
        'options-general.php' => 'options-general.php',
        'options.php' => 'options-general.php',
        'options-writing.php' => 'options-general.php',
        'options-reading.php' => 'options-general.php',
        'options-discussion.php' => 'options-general.php',
        'options-media.php' => 'options-general.php',
        'options-permalink.php' => 'options-general.php',
        'options-privacy.php' => 'options-general.php',
    );

    if (in_array($script, array('edit.php', 'post-new.php', 'post.php', 'edit-tags.php', 'term.php'), true)) {
        $candidates[] = $post_type_menu;
        $candidates[] = $script;
        return array_values(array_unique(array_filter($candidates)));
    }

    if ($script === 'admin.php' && $page !== '') {
        if (strpos($page, 'tool-kits') === 0) {
            $candidates[] = 'tool-kits';
            if ($page !== 'tool-kits') {
                $candidates[] = $page;
            }
            return array_values(array_unique(array_filter($candidates)));
        }
        $candidates[] = $page;
        return array_values(array_unique(array_filter($candidates)));
    }

    if (($script === 'tools.php' || $script === 'options-general.php') && $page !== '') {
        $candidates[] = $core_map[$script];
        $candidates[] = $page;
        return array_values(array_unique(array_filter($candidates)));
    }

    if (isset($core_map[$script])) {
        $candidates[] = $core_map[$script];
        return array_values(array_unique(array_filter($candidates)));
    }

    if ($script !== '') {
        $candidates[] = $script;
    }

    return array_values(array_unique(array_filter($candidates)));
}

function tk_role_management_enforce_menu_rules($allowed = null): void {
    if (!is_admin() || !is_user_logged_in()) {
        return;
    }
    global $pagenow;
    if (in_array((string) $pagenow, array('admin-post.php', 'admin-ajax.php', 'async-upload.php'), true)) {
        return;
    }

    $requested_page = isset($_GET['page']) ? tk_role_management_sanitize_menu_slug($_GET['page']) : '';
    if ($requested_page === 'theme-settings' && tk_role_management_current_user_role_has_capability('manage_options')) {
        return;
    }
    if ((string) $pagenow === 'options.php' && tk_role_management_current_user_role_has_capability('manage_options')) {
        return;
    }

    $allowed = is_array($allowed) ? $allowed : tk_role_management_allowed_menus_for_current_user();
    if ($allowed === null) {
        return;
    }

    $candidates = tk_role_management_request_menu_candidates();
    if (empty($candidates)) {
        return;
    }

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $allowed, true)) {
            return;
        }
    }

    if (in_array('theme-settings', $candidates, true) && tk_role_management_current_user_role_has_capability('manage_options')) {
        return;
    }

    $menu_capabilities = tk_role_management_available_menu_capabilities();
    foreach ($candidates as $candidate) {
        if (empty($menu_capabilities[$candidate])) {
            continue;
        }
        $required_capability = sanitize_key((string) $menu_capabilities[$candidate]);
        if (
            $required_capability !== ''
            && $required_capability !== tk_toolkits_capability()
            && (current_user_can($required_capability) || tk_role_management_current_user_role_has_capability($required_capability))
        ) {
            return;
        }
    }

    wp_die(
        '<h1>' . esc_html__('Access Restricted', 'tool-kits') . '</h1><p>' . esc_html__('This admin menu is restricted for your role.', 'tool-kits') . '</p>',
        esc_html__('Access Restricted', 'tool-kits'),
        array('response' => 403)
    );
}

function tk_role_management_base_roles(): array {
    $roles = get_editable_roles();
    foreach ($roles as $role_slug => $role_data) {
        $capabilities = isset($role_data['capabilities']) && is_array($role_data['capabilities'])
            ? $role_data['capabilities']
            : array();
        if ($role_slug === 'administrator' || !empty($capabilities['manage_options'])) {
            unset($roles[$role_slug]);
        }
    }
    return $roles;
}

function tk_role_management_capability_label(string $capability): string {
    return ucwords(str_replace('_', ' ', $capability));
}

function tk_role_management_post_type_capability_label(string $capability_key, string $capability): string {
    $labels = array(
        'create_posts' => __('Create', 'tool-kits'),
        'edit_posts' => __('Edit own', 'tool-kits'),
        'edit_others_posts' => __('Edit others', 'tool-kits'),
        'edit_private_posts' => __('Edit private', 'tool-kits'),
        'edit_published_posts' => __('Edit published', 'tool-kits'),
        'publish_posts' => __('Publish', 'tool-kits'),
        'read_private_posts' => __('Read private', 'tool-kits'),
        'delete_posts' => __('Delete own', 'tool-kits'),
        'delete_others_posts' => __('Delete others', 'tool-kits'),
        'delete_private_posts' => __('Delete private', 'tool-kits'),
        'delete_published_posts' => __('Delete published', 'tool-kits'),
    );

    return $labels[$capability_key] ?? tk_role_management_capability_label($capability);
}

function tk_role_management_post_type_capability_order(): array {
    return array(
        'create_posts',
        'edit_posts',
        'edit_others_posts',
        'edit_private_posts',
        'edit_published_posts',
        'publish_posts',
        'read_private_posts',
        'delete_posts',
        'delete_others_posts',
        'delete_private_posts',
        'delete_published_posts',
    );
}

function tk_role_management_capability_groups(): array {
    $groups = array(
        'general' => array(
            'label' => __('General Access', 'tool-kits'),
            'description' => __('Basic dashboard and content access.', 'tool-kits'),
            'capabilities' => array(
                'read' => __('Read and access Dashboard', 'tool-kits'),
                'unfiltered_html' => __('Use unfiltered HTML', 'tool-kits'),
            ),
        ),
    );

    $assigned = array('read' => true, 'unfiltered_html' => true);
    $post_types = get_post_types(array('show_ui' => true), 'objects');
    foreach ($post_types as $post_type) {
        if (!is_object($post_type) || empty($post_type->cap)) {
            continue;
        }
        $group_key = 'content_' . sanitize_key((string) $post_type->name);
        $label = !empty($post_type->labels->name)
            ? (string) $post_type->labels->name
            : tk_role_management_capability_label((string) $post_type->name);
        $capabilities = array();
        $post_type_capabilities = (array) $post_type->cap;
        $capability_keys = array_values(array_unique(array_merge(
            tk_role_management_post_type_capability_order(),
            array_keys($post_type_capabilities)
        )));
        foreach ($capability_keys as $capability_key) {
            if (in_array($capability_key, array('edit_post', 'read_post', 'delete_post'), true)) {
                continue;
            }
            if (!isset($post_type_capabilities[$capability_key])) {
                continue;
            }
            $capability = $post_type_capabilities[$capability_key];
            $capability = sanitize_key((string) $capability);
            if ($capability === '') {
                continue;
            }
            $capability_label = tk_role_management_post_type_capability_label((string) $capability_key, $capability);
            if (isset($capabilities[$capability])) {
                $label_parts = array_map('trim', explode('/', (string) $capabilities[$capability]));
                if (!in_array($capability_label, $label_parts, true)) {
                    $capabilities[$capability] = $capabilities[$capability] . ' / ' . $capability_label;
                }
                continue;
            }
            $capabilities[$capability] = $capability_label;
        }
        if (!empty($capabilities)) {
            $groups[$group_key] = array(
                'label' => $label,
                'description' => sprintf(__('Create, edit, publish, read, and delete permissions for %s.', 'tool-kits'), $label),
                'capabilities' => $capabilities,
            );
            foreach (array_keys($capabilities) as $capability) {
                $assigned[$capability] = true;
            }
        }
    }

    $module_groups = array(
        'tool_kits' => array(
            'label' => __('Tool Kits', 'tool-kits'),
            'description' => __('Access Tool Kits admin pages and modules.', 'tool-kits'),
            'capabilities' => array(tk_toolkits_capability()),
        ),
        'comments' => array(
            'label' => __('Comments', 'tool-kits'),
            'description' => __('Moderation and comment management.', 'tool-kits'),
            'capabilities' => array('moderate_comments'),
        ),
        'media' => array(
            'label' => __('Media', 'tool-kits'),
            'description' => __('Upload and manage media files.', 'tool-kits'),
            'capabilities' => array('upload_files'),
        ),
        'users' => array(
            'label' => __('Users and Roles', 'tool-kits'),
            'description' => __('List, create, edit, promote, remove, and delete users.', 'tool-kits'),
            'capabilities' => array('list_users', 'create_users', 'edit_users', 'promote_users', 'remove_users', 'delete_users'),
        ),
        'appearance' => array(
            'label' => __('Appearance', 'tool-kits'),
            'description' => __('Themes, widgets, navigation, and Customizer.', 'tool-kits'),
            'capabilities' => array('edit_theme_options', 'switch_themes', 'install_themes', 'update_themes', 'delete_themes', 'edit_themes'),
        ),
        'plugins' => array(
            'label' => __('Plugins', 'tool-kits'),
            'description' => __('Install, activate, update, edit, and delete plugins.', 'tool-kits'),
            'capabilities' => array('activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins'),
        ),
        'settings' => array(
            'label' => __('Settings and Tools', 'tool-kits'),
            'description' => __('Site settings, imports, exports, updates, and privacy tools.', 'tool-kits'),
            'capabilities' => array('manage_options', 'import', 'export', 'update_core', 'manage_categories', 'manage_links', 'manage_privacy_options', 'export_others_personal_data', 'erase_others_personal_data'),
        ),
    );

    foreach ($module_groups as $group_key => $group_data) {
        $capabilities = array();
        foreach ($group_data['capabilities'] as $capability) {
            if (isset($assigned[$capability])) {
                continue;
            }
            $capabilities[$capability] = tk_role_management_capability_label($capability);
            $assigned[$capability] = true;
        }
        if (!empty($capabilities)) {
            $groups[$group_key] = array(
                'label' => $group_data['label'],
                'description' => $group_data['description'],
                'capabilities' => $capabilities,
            );
        }
    }

    $menu_capabilities = array();
    foreach (array_unique(array_values(tk_role_management_available_menu_capabilities())) as $capability) {
        $capability = sanitize_key((string) $capability);
        if ($capability === '' || isset($assigned[$capability])) {
            continue;
        }
        $menu_capabilities[$capability] = sprintf(
            __('Dashboard menu access: %s', 'tool-kits'),
            tk_role_management_capability_label($capability)
        );
        $assigned[$capability] = true;
    }
    if (!empty($menu_capabilities)) {
        $groups['dashboard_menus'] = array(
            'label' => __('Dashboard Menus', 'tool-kits'),
            'description' => __('Capabilities required by custom dashboard menus registered by themes or plugins.', 'tool-kits'),
            'capabilities' => $menu_capabilities,
        );
    }

    $all_capabilities = array();
    foreach (get_editable_roles() as $role_data) {
        foreach ((array) ($role_data['capabilities'] ?? array()) as $capability => $granted) {
            $capability = sanitize_key((string) $capability);
            if ($capability !== '') {
                $all_capabilities[$capability] = true;
            }
        }
    }

    $other = array();
    foreach (array_keys($all_capabilities) as $capability) {
        if (!isset($assigned[$capability])) {
            $other[$capability] = tk_role_management_capability_label($capability);
        }
    }
    if (!empty($other)) {
        ksort($other);
        $groups['other'] = array(
            'label' => __('Other / Plugin Capabilities', 'tool-kits'),
            'description' => __('Capabilities registered by WordPress compatibility layers or other plugins.', 'tool-kits'),
            'capabilities' => $other,
        );
    }

    return $groups;
}

function tk_role_management_capability_catalog(array $groups): array {
    $catalog = array();
    foreach ($groups as $group) {
        foreach ((array) ($group['capabilities'] ?? array()) as $capability => $label) {
            $catalog[$capability] = $label;
        }
    }
    return $catalog;
}

function tk_role_management_role_capabilities(string $role_slug): array {
    $role = get_role($role_slug);
    if (!$role) {
        return array();
    }
    $capabilities = array();
    foreach ((array) $role->capabilities as $capability => $granted) {
        if ($granted) {
            $capabilities[] = sanitize_key((string) $capability);
        }
    }
    return array_values(array_unique(array_filter($capabilities)));
}

function tk_role_management_user_count(string $role_slug): int {
    $counts = count_users();
    return isset($counts['avail_roles'][$role_slug]) ? (int) $counts['avail_roles'][$role_slug] : 0;
}

function tk_role_management_redirect(string $status, string $role_slug = ''): void {
    $args = array(
        'page' => 'tool-kits-role-management',
        'tk_role_status' => sanitize_key($status),
    );
    if ($role_slug !== '') {
        $args['role'] = sanitize_key($role_slug);
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

function tk_role_management_save(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_role_management_save');

    $original_slug = isset($_POST['original_role_slug'])
        ? sanitize_key(wp_unslash((string) $_POST['original_role_slug']))
        : '';
    $role_slug = isset($_POST['role_slug'])
        ? sanitize_key(wp_unslash((string) $_POST['role_slug']))
        : '';
    $role_name = isset($_POST['role_name'])
        ? sanitize_text_field(wp_unslash((string) $_POST['role_name']))
        : '';
    $base_role = isset($_POST['base_role'])
        ? sanitize_key(wp_unslash((string) $_POST['base_role']))
        : 'subscriber';

    if ($role_slug === '' || $role_name === '' || $role_slug === 'administrator') {
        tk_role_management_redirect('invalid');
    }

    $managed_roles = tk_role_management_managed_roles();
    $is_editing = $original_slug !== '' && isset($managed_roles[$original_slug]);
    if ($is_editing && $role_slug !== $original_slug) {
        tk_role_management_redirect('slug_locked', $original_slug);
    }

    if (!$is_editing) {
        if (get_role($role_slug)) {
            tk_role_management_redirect('exists');
        }
        $base_roles = tk_role_management_base_roles();
        if (!isset($base_roles[$base_role])) {
            tk_role_management_redirect('invalid_base');
        }
        $base = get_role($base_role);
        if (!$base || add_role($role_slug, $role_name, (array) $base->capabilities) === null) {
            tk_role_management_redirect('create_failed');
        }
    } else {
        $base_role = $managed_roles[$original_slug]['base_role'];
        $wp_roles = wp_roles();
        if (isset($wp_roles->roles[$role_slug])) {
            $wp_roles->roles[$role_slug]['name'] = $role_name;
            $wp_roles->role_names[$role_slug] = $role_name;
            update_option($wp_roles->role_key, $wp_roles->roles);
        }
    }

    $capability_groups = tk_role_management_capability_groups();
    $capability_catalog = tk_role_management_capability_catalog($capability_groups);
    $posted_capabilities = isset($_POST['capabilities']) && is_array($_POST['capabilities'])
        ? array_map('sanitize_key', wp_unslash($_POST['capabilities']))
        : array();
    $selected_capabilities = array_values(array_intersect(
        array_keys($capability_catalog),
        array_unique(array_filter($posted_capabilities))
    ));
    if (!in_array('read', $selected_capabilities, true)) {
        $selected_capabilities[] = 'read';
    }
    $posted_menus = isset($_POST['allowed_menus']) && is_array($_POST['allowed_menus'])
        ? $_POST['allowed_menus']
        : array();
    if (in_array('manage_options', $selected_capabilities, true) && !in_array('theme-settings', $posted_menus, true)) {
        $posted_menus[] = 'theme-settings';
    }
    $allowed_menus = array_values(array_unique(array_filter(array_map(
        'tk_role_management_sanitize_menu_slug',
        $posted_menus
    ))));
    if (!in_array('index.php', $allowed_menus, true)) {
        $allowed_menus[] = 'index.php';
    }
    $menu_parents = tk_role_management_available_menu_parents();
    foreach ($allowed_menus as $menu_slug) {
        if (!empty($menu_parents[$menu_slug]) && !in_array($menu_parents[$menu_slug], $allowed_menus, true)) {
            $allowed_menus[] = $menu_parents[$menu_slug];
        }
    }

    $menu_capabilities = tk_role_management_available_menu_capabilities();
    tk_role_management_store_menu_capabilities($menu_capabilities);
    foreach ($menu_capabilities as $menu_slug => $menu_capability) {
        $menu_slug = tk_role_management_sanitize_menu_slug($menu_slug);
        $menu_capability = sanitize_key((string) $menu_capability);
        if ($menu_slug === '' || $menu_capability === '' || $menu_capability === tk_toolkits_capability()) {
            continue;
        }
        if (in_array($menu_capability, $selected_capabilities, true) && !in_array($menu_slug, $allowed_menus, true)) {
            $allowed_menus[] = $menu_slug;
        }
    }
    foreach ($allowed_menus as $menu_slug) {
        if (!empty($menu_parents[$menu_slug]) && !in_array($menu_parents[$menu_slug], $allowed_menus, true)) {
            $allowed_menus[] = $menu_parents[$menu_slug];
        }
    }

    foreach ($allowed_menus as $menu_slug) {
        if (empty($menu_capabilities[$menu_slug])) {
            continue;
        }
        $menu_capability = sanitize_key((string) $menu_capabilities[$menu_slug]);
        if ($menu_capability !== '' && isset($capability_catalog[$menu_capability]) && !in_array($menu_capability, $selected_capabilities, true)) {
            $selected_capabilities[] = $menu_capability;
        }
    }

    $role = get_role($role_slug);
    if (!$role) {
        tk_role_management_redirect('create_failed');
    }
    foreach (array_keys($capability_catalog) as $capability) {
        if (in_array($capability, $selected_capabilities, true)) {
            $role->add_cap($capability, true);
        } else {
            $role->remove_cap($capability);
        }
    }

    $managed_roles[$role_slug] = array(
        'name' => $role_name,
        'base_role' => $base_role,
    );
    tk_update_option('role_management_roles', $managed_roles);

    $rules = tk_role_management_menu_rules();
    $rules[$role_slug] = $allowed_menus;
    tk_update_option('role_management_menu_rules', $rules);

    tk_role_management_redirect('saved', $role_slug);
}

function tk_role_management_delete(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Forbidden', 'tool-kits'), '', array('response' => 403));
    }
    tk_check_nonce('tk_role_management_delete');

    $role_slug = isset($_POST['role_slug'])
        ? sanitize_key(wp_unslash((string) $_POST['role_slug']))
        : '';
    $managed_roles = tk_role_management_managed_roles();
    if ($role_slug === '' || !isset($managed_roles[$role_slug])) {
        tk_role_management_redirect('not_managed');
    }
    if (tk_role_management_user_count($role_slug) > 0) {
        tk_role_management_redirect('role_in_use', $role_slug);
    }

    remove_role($role_slug);
    unset($managed_roles[$role_slug]);
    tk_update_option('role_management_roles', $managed_roles);

    $rules = tk_role_management_menu_rules();
    unset($rules[$role_slug]);
    tk_update_option('role_management_menu_rules', $rules);

    tk_role_management_redirect('deleted');
}

function tk_role_management_render_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $managed_roles = tk_role_management_managed_roles();
    $rules = tk_role_management_menu_rules();
    $base_roles = tk_role_management_base_roles();
    $available_menus = tk_role_management_available_menus();
    $menu_capabilities = tk_role_management_available_menu_capabilities();
    tk_role_management_store_menu_capabilities($menu_capabilities);
    $menu_parents = tk_role_management_available_menu_parents();
    $menu_children = tk_role_management_available_menu_children($available_menus, $menu_parents);
    $capability_groups = tk_role_management_capability_groups();
    $capability_catalog = tk_role_management_capability_catalog($capability_groups);
    $edit_slug = isset($_GET['role']) ? sanitize_key(wp_unslash((string) $_GET['role'])) : '';
    $editing = $edit_slug !== '' && isset($managed_roles[$edit_slug]);
    $role_data = $editing ? $managed_roles[$edit_slug] : array('name' => '', 'base_role' => 'subscriber');
    if (!$editing && !isset($base_roles[$role_data['base_role']])) {
        $role_data['base_role'] = !empty($base_roles) ? (string) array_key_first($base_roles) : 'subscriber';
    }
    $selected_capabilities = tk_role_management_role_capabilities(
        $editing ? $edit_slug : (string) $role_data['base_role']
    );
    $base_capability_map = array();
    foreach ($base_roles as $base_slug => $base_data) {
        $base_capability_map[$base_slug] = array_values(array_intersect(
            array_keys($capability_catalog),
            tk_role_management_role_capabilities((string) $base_slug)
        ));
    }
    $selected_menus = $editing && isset($rules[$edit_slug]) ? $rules[$edit_slug] : array('index.php');
    foreach ($selected_menus as $menu_slug) {
        if (empty($menu_capabilities[$menu_slug])) {
            continue;
        }
        $menu_capability = sanitize_key((string) $menu_capabilities[$menu_slug]);
        if ($menu_capability !== '' && isset($capability_catalog[$menu_capability]) && !in_array($menu_capability, $selected_capabilities, true)) {
            $selected_capabilities[] = $menu_capability;
        }
    }
    $status = isset($_GET['tk_role_status']) ? sanitize_key(wp_unslash((string) $_GET['tk_role_status'])) : '';
    $notices = array(
        'saved' => array('Role settings saved.', 'success'),
        'deleted' => array('Custom role deleted.', 'success'),
        'invalid' => array('Role slug and display name are required.', 'error'),
        'exists' => array('That role slug already exists.', 'error'),
        'invalid_base' => array('The selected base role is not allowed.', 'error'),
        'create_failed' => array('WordPress could not create the role.', 'error'),
        'slug_locked' => array('A role slug cannot be changed after creation.', 'warning'),
        'role_in_use' => array('Reassign all users before deleting this role.', 'error'),
        'not_managed' => array('Only roles created by Tool Kits can be deleted here.', 'error'),
    );
    ?>
    <div class="wrap tk-wrap">
        <?php tk_render_header_branding(); ?>
        <?php tk_render_page_hero(__('Role Management', 'tool-kits'), __('Create custom roles and control which dashboard menus each role sees.', 'tool-kits'), 'dashicons-groups'); ?>

        <?php if (isset($notices[$status])) : ?>
            <?php tk_notice($notices[$status][0], $notices[$status][1]); ?>
        <?php endif; ?>

        <div class="tk-card" style="margin-bottom:24px;">
            <h2><?php esc_html_e('Managed Roles', 'tool-kits'); ?></h2>
            <?php if (empty($managed_roles)) : ?>
                <p><?php esc_html_e('No custom roles have been created yet.', 'tool-kits'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Role', 'tool-kits'); ?></th><th><?php esc_html_e('Base', 'tool-kits'); ?></th><th><?php esc_html_e('Users', 'tool-kits'); ?></th><th><?php esc_html_e('Capabilities', 'tool-kits'); ?></th><th><?php esc_html_e('Visible Menus', 'tool-kits'); ?></th><th><?php esc_html_e('Actions', 'tool-kits'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($managed_roles as $role_slug => $data) : ?>
                        <?php $user_count = tk_role_management_user_count($role_slug); ?>
                        <tr>
                            <td><strong><?php echo esc_html($data['name']); ?></strong><br><code><?php echo esc_html($role_slug); ?></code></td>
                            <td><?php echo esc_html($data['base_role']); ?></td>
                            <td><?php echo esc_html((string) $user_count); ?></td>
                            <td><?php echo esc_html((string) count(tk_role_management_role_capabilities($role_slug))); ?></td>
                            <td><?php echo esc_html((string) count($rules[$role_slug] ?? array())); ?></td>
                            <td style="display:flex; gap:8px; align-items:center;">
                                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'tool-kits-role-management', 'role' => $role_slug), admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'tool-kits'); ?></a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this custom role?', 'tool-kits')); ?>');">
                                    <?php tk_nonce_field('tk_role_management_delete'); ?>
                                    <input type="hidden" name="action" value="tk_role_management_delete">
                                    <input type="hidden" name="role_slug" value="<?php echo esc_attr($role_slug); ?>">
                                    <button class="button button-link-delete" <?php disabled($user_count > 0); ?>><?php esc_html_e('Delete', 'tool-kits'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="tk-card" style="margin-bottom:24px;">
            <h2><?php echo $editing ? esc_html__('Edit Custom Role', 'tool-kits') : esc_html__('Create Custom Role', 'tool-kits'); ?></h2>
            <p class="description"><?php esc_html_e('Choose a base preset, customize its capabilities by module, then control the dashboard sidebar visibility.', 'tool-kits'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php tk_nonce_field('tk_role_management_save'); ?>
                <input type="hidden" name="action" value="tk_role_management_save">
                <input type="hidden" name="original_role_slug" value="<?php echo esc_attr($editing ? $edit_slug : ''); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tk-role-name"><?php esc_html_e('Display Name', 'tool-kits'); ?></label></th>
                        <td><input id="tk-role-name" class="regular-text" type="text" name="role_name" required value="<?php echo esc_attr($role_data['name']); ?>" placeholder="Content Manager"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tk-role-slug"><?php esc_html_e('Role Slug', 'tool-kits'); ?></label></th>
                        <td>
                            <input id="tk-role-slug" class="regular-text" type="text" name="role_slug" required value="<?php echo esc_attr($editing ? $edit_slug : ''); ?>" placeholder="content_manager" <?php disabled($editing); ?>>
                            <?php if ($editing) : ?><input type="hidden" name="role_slug" value="<?php echo esc_attr($edit_slug); ?>"><?php endif; ?>
                            <p class="description"><?php esc_html_e('Lowercase letters, numbers, and underscores. The slug is immutable after creation.', 'tool-kits'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tk-base-role"><?php esc_html_e('Base Capabilities', 'tool-kits'); ?></label></th>
                        <td>
                            <select id="tk-base-role" name="base_role" <?php disabled($editing); ?>>
                                <?php foreach ($base_roles as $base_slug => $base_data) : ?>
                                    <option value="<?php echo esc_attr($base_slug); ?>" <?php selected($role_data['base_role'], $base_slug); ?>><?php echo esc_html($base_data['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($editing) : ?><input type="hidden" name="base_role" value="<?php echo esc_attr($role_data['base_role']); ?>"><?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div class="tk-role-tabs" style="display:flex; gap:8px; margin:28px 0 18px; border-bottom:1px solid var(--tk-border-soft);">
                    <button type="button" class="button button-primary tk-role-tab" data-role-tab="capabilities" style="border-radius:8px 8px 0 0;"><?php esc_html_e('Capabilities', 'tool-kits'); ?></button>
                    <button type="button" class="button tk-role-tab" data-role-tab="menus" style="border-radius:8px 8px 0 0;"><?php esc_html_e('Visible Dashboard Menus', 'tool-kits'); ?></button>
                </div>

                <div class="tk-role-tab-panel" data-role-tab-panel="capabilities">
                    <div style="margin:0 0 12px;">
                        <h3 style="margin-bottom:4px;"><?php esc_html_e('Capabilities', 'tool-kits'); ?></h3>
                        <p class="description"><?php esc_html_e('Capabilities enforce access. Create, edit, publish, and delete permissions can be configured independently where WordPress supports them.', 'tool-kits'); ?></p>
                    </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; margin-bottom:28px;">
                    <?php foreach ($capability_groups as $group_key => $group) : ?>
                        <fieldset class="tk-capability-group" data-capability-group="<?php echo esc_attr($group_key); ?>" style="margin:0; border:1px solid var(--tk-border-soft); border-radius:14px; padding:16px; background:var(--tk-bg-soft); min-width:0;">
                            <legend style="padding:0 8px; font-weight:700; color:var(--tk-primary);">
                                <?php echo esc_html($group['label']); ?>
                            </legend>
                            <p class="description" style="margin:0 0 12px; min-height:36px;"><?php echo esc_html($group['description']); ?></p>
                            <div style="display:flex; gap:6px; margin-bottom:12px;">
                                <button type="button" class="button button-small tk-capability-toggle" data-mode="all"><?php esc_html_e('Select all', 'tool-kits'); ?></button>
                                <button type="button" class="button button-small tk-capability-toggle" data-mode="none"><?php esc_html_e('Clear', 'tool-kits'); ?></button>
                            </div>
                            <div style="display:grid; gap:8px;">
                                <?php foreach ($group['capabilities'] as $capability => $capability_label) : ?>
                                    <?php $is_read = $capability === 'read'; ?>
                                    <label style="display:flex; align-items:flex-start; gap:9px; padding:8px 10px; background:#fff; border:1px solid var(--tk-border-soft); border-radius:9px;">
                                        <input class="tk-capability-checkbox" type="checkbox" name="capabilities[]" value="<?php echo esc_attr($capability); ?>" <?php checked($is_read || in_array($capability, $selected_capabilities, true)); ?> <?php disabled($is_read); ?> style="margin-top:2px;">
                                        <?php if ($is_read) : ?><input type="hidden" name="capabilities[]" value="read"><?php endif; ?>
                                        <span><strong><?php echo esc_html($capability_label); ?></strong><br><code style="font-size:11px; word-break:break-all;"><?php echo esc_html($capability); ?></code></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>

                <div class="notice notice-warning inline" style="margin:0 0 24px;">
                    <p><strong><?php esc_html_e('Security note:', 'tool-kits'); ?></strong> <?php esc_html_e('manage_options, user management, plugin, and theme capabilities grant powerful access. Menu hiding does not block direct URLs.', 'tool-kits'); ?></p>
                </div>
                </div>

                <div class="tk-role-tab-panel" data-role-tab-panel="menus" hidden>
                <h3><?php esc_html_e('Visible Dashboard Menus', 'tool-kits'); ?></h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; margin:16px 0 24px;">
                    <?php foreach ($available_menus as $menu_slug => $menu_label) : ?>
                        <?php if (isset($menu_parents[$menu_slug])) { continue; } ?>
                        <?php $is_dashboard = $menu_slug === 'index.php'; ?>
                        <?php $menu_capability = isset($menu_capabilities[$menu_slug]) ? sanitize_key((string) $menu_capabilities[$menu_slug]) : ''; ?>
                        <div class="tk-checkable-card tk-menu-group" style="background:var(--tk-bg-soft); border:1px solid var(--tk-border-soft); padding:14px; border-radius:12px;">
                            <label style="display:flex; gap:10px; align-items:flex-start;">
                                <input class="tk-menu-checkbox" type="checkbox" name="allowed_menus[]" value="<?php echo esc_attr($menu_slug); ?>" data-menu-capability="<?php echo esc_attr($menu_capability); ?>" <?php checked($is_dashboard || in_array($menu_slug, $selected_menus, true)); ?> <?php disabled($is_dashboard); ?>>
                                <?php if ($is_dashboard) : ?><input type="hidden" name="allowed_menus[]" value="index.php"><?php endif; ?>
                                <?php if ($menu_capability !== '') : ?><input type="hidden" name="menu_capabilities[<?php echo esc_attr($menu_slug); ?>]" value="<?php echo esc_attr($menu_capability); ?>"><?php endif; ?>
                                <span>
                                    <strong><?php echo esc_html($menu_label); ?></strong><br>
                                    <small><?php echo esc_html($menu_slug); ?></small>
                                    <?php if ($menu_capability !== '') : ?><br><small><?php printf(esc_html__('Requires: %s', 'tool-kits'), esc_html($menu_capability)); ?></small><?php endif; ?>
                                </span>
                            </label>
                            <?php if (!empty($menu_children[$menu_slug])) : ?>
                                <div style="display:flex; gap:6px; margin:12px 0 0 28px;">
                                    <button type="button" class="button button-small tk-menu-toggle" data-mode="all"><?php esc_html_e('Select all', 'tool-kits'); ?></button>
                                    <button type="button" class="button button-small tk-menu-toggle" data-mode="none"><?php esc_html_e('Clear', 'tool-kits'); ?></button>
                                </div>
                                <div style="margin:12px 0 0 28px; display:grid; gap:8px;">
                                    <?php foreach ($menu_children[$menu_slug] as $child_slug => $child_label) : ?>
                                        <?php $child_capability = isset($menu_capabilities[$child_slug]) ? sanitize_key((string) $menu_capabilities[$child_slug]) : ''; ?>
                                        <label style="display:flex; gap:8px; align-items:flex-start; padding-top:8px; border-top:1px solid var(--tk-border-soft);">
                                            <input class="tk-menu-checkbox" type="checkbox" name="allowed_menus[]" value="<?php echo esc_attr($child_slug); ?>" data-menu-capability="<?php echo esc_attr($child_capability); ?>" data-parent-menu="<?php echo esc_attr($menu_slug); ?>" <?php checked(in_array($child_slug, $selected_menus, true)); ?>>
                                            <?php if ($child_capability !== '') : ?><input type="hidden" name="menu_capabilities[<?php echo esc_attr($child_slug); ?>]" value="<?php echo esc_attr($child_capability); ?>"><?php endif; ?>
                                            <span>
                                                <strong><?php echo esc_html($child_label); ?></strong><br>
                                                <small><?php echo esc_html($child_slug); ?></small>
                                                <?php if ($child_capability !== '') : ?><br><small><?php printf(esc_html__('Requires: %s', 'tool-kits'), esc_html($child_capability)); ?></small><?php endif; ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                </div>

                <button class="button button-primary button-hero"><?php echo $editing ? esc_html__('Save Role', 'tool-kits') : esc_html__('Create Role', 'tool-kits'); ?></button>
                <?php if ($editing) : ?><a class="button button-hero" href="<?php echo esc_url(add_query_arg('page', 'tool-kits-role-management', admin_url('admin.php'))); ?>"><?php esc_html_e('Cancel', 'tool-kits'); ?></a><?php endif; ?>
            </form>
        </div>

        <?php
        $tk_role_script = <<<'JS'
        (function () {
            var baseSelect = document.getElementById('tk-base-role');
            var presets = __TK_ROLE_PRESETS__;

            document.querySelectorAll('.tk-role-tab').forEach(function (button) {
                button.addEventListener('click', function () {
                    var tab = button.getAttribute('data-role-tab');
                    document.querySelectorAll('.tk-role-tab').forEach(function (candidate) {
                        candidate.classList.toggle('button-primary', candidate === button);
                    });
                    document.querySelectorAll('.tk-role-tab-panel').forEach(function (panel) {
                        panel.hidden = panel.getAttribute('data-role-tab-panel') !== tab;
                    });
                });
            });

            function checkParentMenu(checkbox) {
                var parentSlug = checkbox.getAttribute('data-parent-menu');
                if (!parentSlug) return;
                document.querySelectorAll('.tk-menu-checkbox').forEach(function (parent) {
                    if (parent.value === parentSlug) {
                        parent.checked = true;
                    }
                });
            }

            function checkMenusForCapability(capability) {
                if (!capability) return;
                document.querySelectorAll('.tk-menu-checkbox').forEach(function (checkbox) {
                    if (checkbox.getAttribute('data-menu-capability') === capability) {
                        checkbox.checked = true;
                        checkParentMenu(checkbox);
                    }
                });
            }

            function syncMenusFromCapabilities() {
                document.querySelectorAll('.tk-capability-checkbox:checked').forEach(function (checkbox) {
                    checkMenusForCapability(checkbox.value);
                });
            }

            document.querySelectorAll('.tk-capability-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    var group = button.closest('.tk-capability-group');
                    if (!group) return;
                    group.querySelectorAll('.tk-capability-checkbox:not(:disabled)').forEach(function (checkbox) {
                        checkbox.checked = button.getAttribute('data-mode') === 'all';
                    });
                    syncMenusFromCapabilities();
                });
            });

            document.querySelectorAll('.tk-menu-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    var group = button.closest('.tk-menu-group');
                    if (!group) return;
                    group.querySelectorAll('.tk-menu-checkbox:not(:disabled)').forEach(function (checkbox) {
                        checkbox.checked = button.getAttribute('data-mode') === 'all';
                        if (checkbox.checked) {
                            checkParentMenu(checkbox);
                        }
                    });
                });
            });

            document.querySelectorAll('.tk-capability-checkbox').forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    if (checkbox.checked) {
                        checkMenusForCapability(checkbox.value);
                    }
                });
            });

            document.querySelectorAll('.tk-menu-checkbox').forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    if (checkbox.checked) {
                        checkParentMenu(checkbox);
                    }
                });
            });

            if (baseSelect && !baseSelect.disabled) {
                baseSelect.addEventListener('change', function () {
                    var selected = presets[baseSelect.value] || [];
                    document.querySelectorAll('.tk-capability-checkbox:not(:disabled)').forEach(function (checkbox) {
                        checkbox.checked = selected.indexOf(checkbox.value) !== -1;
                    });
                    syncMenusFromCapabilities();
                });
            }
        }());
JS;
        $tk_role_script = str_replace(
            '__TK_ROLE_PRESETS__',
            wp_json_encode($base_capability_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            $tk_role_script
        );
        echo '<script' . (function_exists('tk_csp_nonce_attr') ? tk_csp_nonce_attr() : '') . '>' . "\n" . $tk_role_script . "\n</script>\n";
        ?>
    </div>
    <?php
}
