<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Custom role creation and role-specific admin menu visibility.
 *
 * Menu visibility is an interface preference, not an authorization boundary.
 * WordPress capabilities remain responsible for protecting admin screens.
 */

function tk_role_management_init(): void {
    add_action('admin_menu', 'tk_role_management_register_page', 20);
    add_action('admin_menu', 'tk_role_management_apply_menu_rules', 100000);
    add_action('admin_post_tk_role_management_save', 'tk_role_management_save');
    add_action('admin_post_tk_role_management_delete', 'tk_role_management_delete');
}

function tk_role_management_register_page(): void {
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
    global $menu;

    $items = array();
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
        }
    }

    foreach (tk_admin_menu_get_core_items() as $slug => $data) {
        if (!isset($items[$slug])) {
            $items[$slug] = (string) $data['label'];
        }
    }

    $items['index.php'] = __('Dashboard', 'tool-kits');
    natcasesort($items);
    return $items;
}

function tk_role_management_apply_menu_rules(): void {
    if (!is_admin() || !is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) {
        return;
    }
    if (in_array('administrator', (array) $user->roles, true)) {
        return;
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
    }

    if (!$matched) {
        return;
    }

    $allowed = array_values(array_unique($allowed));
    global $menu;
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

    $posted_menus = isset($_POST['allowed_menus']) && is_array($_POST['allowed_menus'])
        ? $_POST['allowed_menus']
        : array();
    $allowed_menus = array_values(array_unique(array_filter(array_map(
        'tk_role_management_sanitize_menu_slug',
        $posted_menus
    ))));
    if (!in_array('index.php', $allowed_menus, true)) {
        $allowed_menus[] = 'index.php';
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

                <div style="margin:28px 0 12px;">
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

                <h3><?php esc_html_e('Visible Dashboard Menus', 'tool-kits'); ?></h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; margin:16px 0 24px;">
                    <?php foreach ($available_menus as $menu_slug => $menu_label) : ?>
                        <?php $is_dashboard = $menu_slug === 'index.php'; ?>
                        <label class="tk-checkable-card" style="background:var(--tk-bg-soft); border:1px solid var(--tk-border-soft); padding:14px; border-radius:12px; display:flex; gap:10px; align-items:center;">
                            <input type="checkbox" name="allowed_menus[]" value="<?php echo esc_attr($menu_slug); ?>" <?php checked($is_dashboard || in_array($menu_slug, $selected_menus, true)); ?> <?php disabled($is_dashboard); ?>>
                            <?php if ($is_dashboard) : ?><input type="hidden" name="allowed_menus[]" value="index.php"><?php endif; ?>
                            <span><strong><?php echo esc_html($menu_label); ?></strong><br><small><?php echo esc_html($menu_slug); ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button class="button button-primary button-hero"><?php echo $editing ? esc_html__('Save Role', 'tool-kits') : esc_html__('Create Role', 'tool-kits'); ?></button>
                <?php if ($editing) : ?><a class="button button-hero" href="<?php echo esc_url(add_query_arg('page', 'tool-kits-role-management', admin_url('admin.php'))); ?>"><?php esc_html_e('Cancel', 'tool-kits'); ?></a><?php endif; ?>
            </form>
        </div>

        <div class="tk-card">
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
        <script<?php echo function_exists('tk_csp_nonce_attr') ? tk_csp_nonce_attr() : ''; ?>>
        (function () {
            var baseSelect = document.getElementById('tk-base-role');
            var presets = <?php echo wp_json_encode($base_capability_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            document.querySelectorAll('.tk-capability-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    var group = button.closest('.tk-capability-group');
                    if (!group) return;
                    group.querySelectorAll('.tk-capability-checkbox:not(:disabled)').forEach(function (checkbox) {
                        checkbox.checked = button.getAttribute('data-mode') === 'all';
                    });
                });
            });

            if (baseSelect && !baseSelect.disabled) {
                baseSelect.addEventListener('change', function () {
                    var selected = new Set(presets[baseSelect.value] || []);
                    document.querySelectorAll('.tk-capability-checkbox:not(:disabled)').forEach(function (checkbox) {
                        checkbox.checked = selected.has(checkbox.value);
                    });
                });
            }
        }());
        </script>
    </div>
    <?php
}
