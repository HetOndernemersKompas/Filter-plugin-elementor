/**
 * Elementor Filter Builder
 * Author: Web Squadron
 * Version: 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * -------------------------------------------------------------------------
 *  ADMIN: Filters menu + builder UI
 * -------------------------------------------------------------------------
 */

/**
 * Register the "Filters" admin menu page.
 */
add_action( 'admin_menu', function () {
    add_menu_page(
        'Filters',                      // Page title
        'Filters',                      // Menu title
        'manage_options',               // Capability
        'ws-filters-builder',           // Menu slug
        'ws_render_filters_builder',    // Callback
        'dashicons-filter',             // Icon
        58                              // Position
    );
} );

/**
 * AJAX handler to save filters.
 */
add_action( 'wp_ajax_ws_save_filters', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    check_ajax_referer( 'ws_filters_nonce', 'nonce' );

    $filters_json = isset( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : '';
    $filters      = json_decode( $filters_json, true );

    if ( ! is_array( $filters ) ) {
        wp_send_json_error( 'Invalid data' );
    }

    foreach ( $filters as &$filter ) {

        $filter['filter_name'] = sanitize_text_field( $filter['filter_name'] ?? '' );

        $filter['url_key'] = sanitize_key( $filter['url_key'] ?? '' );

        $filter['field_source'] = sanitize_text_field( $filter['field_source'] ?? '' );
        $filter['field_value']  = sanitize_text_field( $filter['field_value'] ?? '' );
        $filter['post_type']    = sanitize_text_field( $filter['post_type'] ?? 'product' );

        $allowed_input_types = array(
            'select',
            'select_pill',
            'multiselect',
            'checkbox',
            'price_range',
        );
        if ( ! in_array( $filter['input_type'] ?? '', $allowed_input_types, true ) ) {
            $filter['input_type'] = 'select';
        }

        $allowed_orientations = array( 'horizontal', 'vertical' );
        if ( ! in_array( $filter['desktop_orientation'] ?? '', $allowed_orientations, true ) ) {
            $filter['desktop_orientation'] = 'horizontal';
        }
        if ( ! in_array( $filter['mobile_orientation'] ?? '', $allowed_orientations, true ) ) {
            $filter['mobile_orientation'] = 'horizontal';
        }

        $filter['exclude_values'] = sanitize_text_field( $filter['exclude_values'] ?? '' );

        $filter['show_reset'] = ! empty( $filter['show_reset'] );
        $filter['show_label'] = isset( $filter['show_label'] ) ? (bool) $filter['show_label'] : true;

        $filter['created_at'] = sanitize_text_field( $filter['created_at'] ?? '' );
        $filter['id']         = sanitize_text_field( $filter['id'] ?? '' );
    }

    update_option( 'ws_wc_filters', $filters );

    wp_send_json_success();
} );

/**
 * AJAX: return available taxonomies or custom fields for helper dropdown.
 */
add_action( 'wp_ajax_ws_get_field_values', 'ws_get_field_values_ajax' );
function ws_get_field_values_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    check_ajax_referer( 'ws_filters_nonce', 'nonce' );

    $field_source = isset( $_POST['field_source'] )
        ? sanitize_text_field( wp_unslash( $_POST['field_source'] ) )
        : '';

    $post_type = isset( $_POST['post_type'] )
        ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) )
        : 'post';

    $results = array();

    // Taxonomy-like sources
    if ( in_array( $field_source, array( 'category', 'tag', 'attribute', 'brand' ), true ) ) {

        $taxonomies = get_object_taxonomies( $post_type, 'objects' );

        if ( ! empty( $taxonomies ) ) {
            foreach ( $taxonomies as $tax ) {
                $results[] = array(
                    'slug_or_key' => $tax->name,
                    'label'       => sprintf(
                        '%s (%s)',
                        $tax->labels->singular_name,
                        $tax->name
                    ),
                );
            }
        }

    // Custom field source – suggest meta keys
    } elseif ( 'custom_field' === $field_source ) {
        global $wpdb;

        $meta_keys = $wpdb->get_col(
            $wpdb->prepare(
                "
                SELECT DISTINCT pm.meta_key
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = %s
                  AND p.post_status = 'publish'
                LIMIT 50
                ",
                $post_type
            )
        );

        if ( ! empty( $meta_keys ) ) {

            $meta_keys = array_unique( array_filter( $meta_keys ) );

            $plain_keys = array();
            foreach ( $meta_keys as $key ) {
                if ( isset( $key[0] ) && '_' !== $key[0] ) {
                    $plain_keys[ $key ] = true;
                }
            }

            foreach ( $meta_keys as $key ) {

                if (
                    0 === strpos( $key, '_edit_' )
                    || 0 === strpos( $key, '_wp_trash_' )
                    || '_wp_page_template' === $key
                ) {
                    continue;
                }

                if ( isset( $key[0] ) && '_' === $key[0] ) {
                    $no_underscore = substr( $key, 1 );
                    if ( isset( $plain_keys[ $no_underscore ] ) ) {
                        continue;
                    }
                }

                $results[] = array(
                    'slug_or_key' => $key,
                    'label'       => ltrim( $key, '_' ),
                );
            }
        }
    }

    wp_send_json_success( $results );
}

/**
 * Render the Filters Builder admin page.
 */
function ws_render_filters_builder() {

    $stored_filters = get_option( 'ws_wc_filters', array() );
    if ( ! is_array( $stored_filters ) ) {
        $stored_filters = array();
    }

    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce    = wp_create_nonce( 'ws_filters_nonce' );
    ?>
    <div class="wrap">
        <h1>Elementor Filter Builder v1.0.2</h1>

                <p style="
                        margin: 10px 0 20px;
                        padding: 12px 15px;
                        background: #f0f7ff;
                        border-left: 4px solid #3b82f6;
                        border-radius: 4px;
                        font-size: 14px;
                        line-height: 1.5;
                        color: #1e293b;
                ">
                        <strong>Shortcodes:</strong><br>
                        &#8226; Use <code>[wc_filter_sort label="Sort By"]</code> to show Sorting filter (ONLY for Woo and another filter must be present).<br>
                        &#8226; Use <code>[wc_filter_reset_all label="Filters verwijderen"]</code> to show Reset All button (for any Post Type, including Woo).<br>
                        &#8226; Add Name - Select Source - Select Post Type - Type value/slug or click Detect - Select Input Type.<br>
                        &#8226; For the Price Filter - Leave the Field Value blank and select Price Range (only use for Woo).<br>
                </p>
              
        <div id="ws-filters-app">
            <div id="app-container" style="width: 100%; min-height: 100%; padding: 2rem; background: #f8fafc;">
                <div style="max-width: 1200px; margin: 0;">

                    <!-- Create / Edit Filter Form -->
                    <div id="create-form"
                         style="background: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;"
                         class="card-shadow">
                        <h2 style="font-size: 1.25rem; font-weight: 600; color: #1e293b; margin-bottom: 1.5rem;">
                            Create / Edit Filter
                        </h2>

                        <form id="filter-form">
                        
                                <!-- Main row: 4 columns -->
<div class="ws-filter-grid-main">

    <!-- COLUMN 1: Filter Name + URL Key -->
    <div>
        <label for="filter-name-input"
               style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin-bottom: 0.5rem;">
            Filter Name
        </label>
        <input type="text" id="filter-name-input" required
               placeholder="e.g., Product Category"
               style="width: 100%; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem;">

        <!-- URL KEY -->
        <label for="url-key-input"
               style="display: block; font-size: 0.8rem; font-weight: 500; color: #94a3b8; margin: 0.75rem 0 0.25rem;">
            URL Key (optional)
        </label>
        <input type="text" id="url-key-input"
               placeholder="e.g., category, brand, goal"
               style="width: 100%; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.85rem;">

        <p style="margin: 0.25rem 0 0; font-size: 0.75rem; color: #94a3b8;">
            IMPORTANT: Give a unique url like cat, category, prod-cat, price, etc. If you auto-generate make sure it is not repeated anywhere else.
        </p>
    </div>

    <!-- COLUMN 2: Field Source + Target Post Type -->
    <div>
        <label for="field-source-select"
               style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin-bottom: 0.5rem;">
            Field Source
        </label>
        <select id="field-source-select" required
                style="width: 100%; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem; background: white;">
            <option value="">Select source...</option>
            <option value="category">Category</option>
            <option value="tag">Tag</option>
            <option value="attribute">Product Attribute</option>
            <option value="brand">Brand</option>
            <option value="custom_field">Custom Field</option>
        </select>

        <label for="post-type-select"
               style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin: 0.75rem 0 0.5rem;">
            Target Post Type
        </label>
        <select id="post-type-select"
                style="width: 100%; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem; background: white;">
            <?php
            $post_types = get_post_types(
                array( 'public' => true, 'show_ui' => true ),
                'objects'
            );

            $priority = array( 'product', 'post', 'page' );
            $sorted   = array();

            foreach ( $priority as $slug ) {
                if ( isset( $post_types[ $slug ] ) ) {
                    $sorted[ $slug ] = $post_types[ $slug ];
                    unset( $post_types[ $slug ] );
                }
            }

            $sorted = array_merge( $sorted, $post_types );

            foreach ( $sorted as $type ) :
                ?>
                <option value="<?php echo esc_attr( $type->name ); ?>">
                    <?php echo esc_html( $type->labels->singular_name . ' (' . $type->name . ')' ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- COLUMN 3: Field Value + Helper -->
    <div>
        <label for="field-value-input"
               style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin-bottom: 0.5rem;">
            Field Value (taxonomy slug or meta key)
        </label>

        <div style="display:flex; gap:0.5rem;">
            <input type="text" id="field-value-input"
                   placeholder="product_cat, location, product_brand, pa_color, custom_meta_key"
                   style="flex:1; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem;">

            <select id="field-value-helper"
                    style="width: 190px; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.9rem; background:white;">
                <option value="">Detect…</option>
            </select>
        </div>

        <p style="margin: 0.35rem 0 0; font-size: 0.8rem; color: #94a3b8;">
            Pick from detected taxonomies/meta keys (or enter your own).
        </p>
    </div>

    <!-- COLUMN 4: Input Type -->
    <div>
        <label for="input-type-select"
               style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin-bottom: 0.5rem;">
            Input Type
        </label>
        <select id="input-type-select" required
                style="width: 100%; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem; background: white;">
            <option value="">Select type...</option>
            <option value="select">Select Box</option>
            <option value="select_pill">Select (Pills)</option>
            <option value="multiselect">Multi-Select</option>
            <option value="checkbox">Checkbox</option>
            <option value="price_range">Price Range</option>
        </select>
    </div>

</div>

                            <!-- Third row: exclude values -->
                            <div class="ws-filter-grid-tertiary">
                                <div>
                                    <label for="exclude-values-input"
                                           style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin-bottom: 0.5rem;">
                                        Exclude Values (optional)
                                    </label>
                                    <input type="text" id="exclude-values-input"
                                           placeholder="e.g., uncategorized, red"
                                           style="width: 100%; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem;">
                                    <p style="margin: 0.35rem 0 0; font-size: 0.8rem; color: #94a3b8;">
                                        For taxonomies: use <strong>slugs</strong>. For custom fields: use the raw meta values.
                                    </p>
                                </div>
                            </div>

                            <!-- Second row: orientations + toggles -->
                            <div class="ws-filter-grid-secondary">
                                <div>
                                    <label for="desktop-orientation-select"
                                           style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin-bottom: 0.5rem;">
                                        Desktop Orientation
                                    </label>
                                    <select id="desktop-orientation-select" required
                                            style="width: 100%; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem; background: white;">
                                        <option value="horizontal">Horizontal</option>
                                        <option value="vertical">Vertical</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="mobile-orientation-select"
                                           style="display: block; font-size: 0.875rem; font-weight: 500; color: #475569; margin-bottom: 0.5rem;">
                                        Mobile Orientation
                                    </label>
                                    <select id="mobile-orientation-select" required
                                            style="width: 100%; padding: 0.625rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.95rem; background: white;">
                                        <option value="horizontal">Horizontal</option>
                                        <option value="vertical">Vertical</option>
                                    </select>
                                </div>

                                <div class="ws-filter-toggle-row">
                                    <label style="display: flex; align-items: center; cursor: pointer; margin-right: 1.5rem;">
                                        <input type="checkbox" id="show-reset-input"
                                               style="width: 18px; height: 18px; margin-right: 0.5rem; cursor: pointer;">
                                        <span style="font-size: 0.875rem; font-weight: 500; color: #475569;">
                                            Reset Icon
                                        </span>
                                    </label>
                                    <label style="display: flex; align-items: center; cursor: pointer;">
                                        <input type="checkbox" id="show-label-input" checked
                                               style="width: 18px; height: 18px; margin-right: 0.5rem; cursor: pointer;">
                                        <span style="font-size: 0.875rem; font-weight: 500; color: #475569;">
                                            Show Label
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <!-- Buttons row -->
                            <div class="ws-filter-actions-row" style="margin-top: 1.5rem; display:flex; gap:0.75rem;">
                                <button type="submit" id="create-filter-btn" data-mode="create"
                                        style="padding: 0.75rem 1.5rem; background: #3b82f6; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; font-size: 0.95rem;">
                                    Create New Filter
                                </button>
                                <button type="submit" id="update-filter-btn" data-mode="update"
                                        style="padding: 0.75rem 1.5rem; background: #10b981; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; font-size: 0.95rem; display:none;">
                                    Update Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Filters List -->
                    <div id="filters-list" style="display: grid; gap: 1rem;">
                        <!-- Filters will be inserted here -->
                    </div>

                    <!-- Empty State -->
                    <div id="empty-state"
                         style="background: white; border-radius: 12px; padding: 3rem; text-align: center; display: none;"
                         class="card-shadow">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">🎉</div>
                        <h3 style="font-size: 1.25rem; font-weight: 600; color: #1e293b; margin-bottom: 0.5rem;">
                            No Filters Yet
                        </h3>
                        <p style="color: #64748b; font-size: 0.95rem;">
                            Create your first filter to get started
                        </p>
                    </div>

                </div>
            </div>
        </div>

        <style>
            #ws-filters-app, #ws-filters-app * {
                box-sizing: border-box;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            }

            #ws-filters-app .card-shadow {
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            #ws-filters-app .copy-animation {
                animation: ws-pulse 0.3s ease-in-out;
            }

            @keyframes ws-pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(0.95); }
            }

            #ws-filters-app .ws-filter-grid-main {
                display: grid;
                grid-template-columns: 20% 15% 40% 15%;
                column-gap: 1.25rem;
                row-gap: 1rem;
                align-items: flex-start;
            }

            #ws-filters-app .ws-filter-grid-main > div {
                min-width: 0;
            }

            #ws-filters-app .ws-filter-grid-tertiary {
                margin-top: 1.25rem;
            }

            #ws-filters-app .ws-filter-grid-secondary {
                                display: grid;
                                grid-template-columns: 150px 150px minmax(0, 1fr);
                                gap: 1.25rem;
                                margin-top: 1.25rem;
                        }

            #ws-filters-app .ws-filter-toggle-row {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
            }

            @media (max-width: 960px) {
                #ws-filters-app .ws-filter-grid-main {
                    grid-template-columns: 1fr;
                }
                #ws-filters-app .ws-filter-grid-secondary {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <script>
            (function () {
                const defaultConfig = {
                    add_button_text: "Create New Filter",
                    primary_color: "#3b82f6",
                    secondary_color: "#ffffff",
                    text_color: "#1e293b",
                    accent_color: "#10b981",
                    background_color: "#f8fafc",
                    font_family: "system-ui",
                    font_size: 16
                };

                let filters = <?php echo wp_json_encode( $stored_filters ); ?> || [];
                let editingFilterId = null;

                                function wsSlugify(str) {
    return (str || '')
        .toString()
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
                              
                              
                const ajaxUrl = "<?php echo esc_js( $ajax_url ); ?>";
                const ajaxNonce = "<?php echo esc_js( $nonce ); ?>";

document.addEventListener('DOMContentLoaded', function () {
    const createBtnEl = document.getElementById('create-filter-btn');
    const updateBtnEl = document.getElementById('update-filter-btn');
    const appContainer = document.getElementById('app-container');
    const formContainer = document.getElementById('create-form');

    if (createBtnEl) {
        createBtnEl.textContent = defaultConfig.add_button_text;
        createBtnEl.style.background = defaultConfig.primary_color;
        createBtnEl.style.fontFamily = defaultConfig.font_family;
        createBtnEl.style.fontSize = (defaultConfig.font_size * 0.95) + 'px';
    }

    if (updateBtnEl) {
        updateBtnEl.style.fontFamily = defaultConfig.font_family;
        updateBtnEl.style.fontSize = (defaultConfig.font_size * 0.95) + 'px';
    }

    if (appContainer) {
        appContainer.style.background = defaultConfig.background_color;
    }

    if (formContainer) {
        formContainer.style.background = defaultConfig.secondary_color;
    }

    renderFilters();

    const form = document.getElementById('filter-form');
    if (form) {
        form.addEventListener('submit', onCreateFilterSubmit);
    }

    const fieldSourceEl       = document.getElementById('field-source-select');
    const postTypeEl          = document.getElementById('post-type-select');
    const fieldValueHelperEl  = document.getElementById('field-value-helper');
    const fieldValueInputEl   = document.getElementById('field-value-input');

    if (fieldSourceEl && postTypeEl && fieldValueHelperEl && fieldValueInputEl) {

    async function refreshFieldValueHelper() {
        const source   = fieldSourceEl.value;
        const postType = postTypeEl.value || 'product';

        fieldValueHelperEl.innerHTML = '<option value="">Detect…</option>';

        if (!source) {
            return;
        }

        try {
            const body = new URLSearchParams();
            body.append('action', 'ws_get_field_values');
            body.append('nonce', ajaxNonce);
            body.append('field_source', source);
            body.append('post_type', postType);

            const res  = await fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            });

            const data = await res.json();
            if (!data || !data.success || !Array.isArray(data.data)) {
                return;
            }

            data.data.forEach(function (item) {
                const opt = document.createElement('option');
                opt.value = item.slug_or_key;
                opt.textContent = item.label;
                fieldValueHelperEl.appendChild(opt);
            });

            const currentValue = fieldValueInputEl.value;
            if (currentValue) {
                const options = Array.from(fieldValueHelperEl.options);
                const match = options.find(function (opt) {
                    return opt.value === currentValue;
                });
                if (match) {
                    fieldValueHelperEl.value = currentValue;
                }
            }
        } catch (err) {
            console.error('ws_get_field_values failed', err);
        }
    }

        fieldSourceEl.addEventListener('change', refreshFieldValueHelper);
        postTypeEl.addEventListener('change', refreshFieldValueHelper);

        fieldValueHelperEl.addEventListener('change', function () {
            if (!fieldValueHelperEl.value) return;
            fieldValueInputEl.value = fieldValueHelperEl.value;
        });
    }
});

function renderFilters() {
    const listEl  = document.getElementById('filters-list');
    const emptyEl = document.getElementById('empty-state');

    if (!listEl || !emptyEl) return;

    if (!Array.isArray(filters) || filters.length === 0) {
        listEl.innerHTML = '';
        emptyEl.style.display = 'block';
        return;
    }

    emptyEl.style.display = 'none';

    const config = defaultConfig;

    const sortedFilters = [...filters].sort(function (a, b) {
        const aTime = a && a.created_at ? (Date.parse(a.created_at) || 0) : 0;
        const bTime = b && b.created_at ? (Date.parse(b.created_at) || 0) : 0;
        return bTime - aTime;
    });

    listEl.innerHTML = '';

    sortedFilters.forEach(function (filter) {
        if (!filter.id) return;
        const card = createFilterCard(filter, config);
        listEl.appendChild(card);
    });
}

                function createFilterCard(filter, config) {
                    const card = document.createElement('div');
                    card.dataset.filterId = filter.id;
                    card.className = 'filter-card card-shadow';
                    card.style.cssText =
                        'background: ' + (config.secondary_color || '#ffffff') +
                        '; border-radius: 12px; padding: 1.5rem;';
                    updateFilterCard(card, filter, config);
                    return card;
                }

                                function wsHumanPostTypeLabel(slug) {
    if (!slug) return '';
    switch (slug) {
        case 'product': return 'Product';
        case 'post':    return 'Post';
        case 'page':    return 'Page';
        default:
            return slug
                .toString()
                .replace(/_/g, ' ')
                .replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }
}
                              
function updateFilterCard(card, filter, config) {
    const resetAttr      = filter.show_reset ? ' show_reset="true"' : '';
    const labelAttr      = filter.show_label === false ? ' show_label="false"' : '';
    const desktopOrient  = filter.desktop_orientation ? ' desktop_orientation="' + filter.desktop_orientation + '"' : '';
    const mobileOrient   = filter.mobile_orientation ? ' mobile_orientation="' + filter.mobile_orientation + '"' : '';
    const shortcode      = '[wc_filter id="' + filter.id + '"' + resetAttr + labelAttr + desktopOrient + mobileOrient + ']';

    const fontSizeBase   = config.font_size || 16;
    const fontFamily     = config.font_family || 'system-ui';
    const textColor      = config.text_color || '#1e293b';
    const accentColor    = config.accent_color || '#10b981';

    const excludeValues  = (filter.exclude_values || '').trim();
    const postTypeSlug   = filter.post_type || 'product';
    const postTypeLabel  = wsHumanPostTypeLabel(postTypeSlug);

    const urlKey         = filter.url_key || '';

    card.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; gap: 1rem;">
          <div style="flex: 1 1 auto; min-width: 0;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:0.5rem;">
              <h3 style="font-size: ${fontSizeBase * 1.125}px; font-weight: 600; color: ${textColor}; margin: 0; font-family: ${fontFamily}, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                ${escapeHtml(filter.filter_name || '')}
              </h3>
            </div>

            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">

              <span style="padding: 0.25rem 0.75rem; background: #e5e7eb; color: #111827; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};"
                    title="Target post type">
                Post-type: ${escapeHtml(postTypeLabel)} (${escapeHtml(postTypeSlug)})
              </span>

              <span style="padding: 0.25rem 0.75rem; background: #f1f5f9; color: #475569; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};">
                Source: ${escapeHtml(filter.field_source || '')}
              </span>

              <span style="padding: 0.25rem 0.75rem; background: #e0f2fe; color: #0369a1; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};"
                    title="Field Value (taxonomy slug or meta key)">
                Field: ${escapeHtml(filter.field_value || '')}
              </span>

              <span style="padding: 0.25rem 0.75rem; background: #f1f5f9; color: #475569; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};">
                Input: ${escapeHtml(filter.input_type || '')}
              </span>

              ${
                filter.show_reset
                  ? `<span style="padding: 0.25rem 0.75rem; background: #dbeafe; color: #1e40af; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};">
                       Reset Icon
                     </span>`
                  : ''
              }

              ${
                filter.show_label !== false
                  ? `<span style="padding: 0.25rem 0.75rem; background: #f0fdf4; color: #166534; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};">
                       Show Label
                     </span>`
                  : ''
              }

              <span style="padding: 0.25rem 0.75rem; background: #fef3c7; color: #92400e; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};">
                🖥️${escapeHtml(filter.desktop_orientation || 'horizontal')}
              </span>

              <span style="padding: 0.25rem 0.75rem; background: #fef3c7; color: #92400e; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};">
                📱${escapeHtml(filter.mobile_orientation || 'horizontal')}
              </span>

              ${
                urlKey
                  ? `<span style="padding: 0.25rem 0.75rem; background: #e5e7eb; color: #111827; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};">
                       url: ${escapeHtml(urlKey)}
                     </span>`
                  : ''
              }

              ${
                excludeValues
                  ? `<span style="padding: 0.25rem 0.75rem; background: #fee2e2; color: #b91c1c; border-radius: 6px; font-size: ${fontSizeBase * 0.75}px; font-family: ${fontFamily};"
                        title="Excluded values (slugs or meta values)">
                       Exclude: ${escapeHtml(excludeValues)}
                     </span>`
                  : ''
              }
            </div>
          </div>

          <div style="display:flex; gap:0.5rem; flex-shrink:0;">
              <button type="button"
                  onclick="window.wsEditFilter('${filter.id.replace(/'/g, "\\'")}')"
                  style="padding: 0.5rem; background: #eff6ff; color: #1d4ed8; border: none; border-radius: 6px; cursor: pointer; font-size: ${fontSizeBase * 0.875}px; font-family: ${fontFamily};">
                Edit
              </button>
              <button type="button"
                  onclick="window.wsDeleteFilter('${filter.id.replace(/'/g, "\\'")}')"
                  style="padding: 0.5rem; background: #fee; color: #dc2626; border: none; border-radius: 6px; cursor: pointer; font-size: ${fontSizeBase * 0.875}px; font-family: ${fontFamily};">
                Delete
              </button>
              <button type="button"
                  onclick="window.wsDuplicateFilter('${filter.id.replace(/'/g, "\\'")}')"
                  style="padding:0.5rem; background:#ecfeff; color:#0e7490; border:none; border-radius:6px; cursor:pointer; font-size:${fontSizeBase * 0.875}px; font-family:${fontFamily};">
                Duplicate
              </button>
          </div>
        </div>

        <div style="background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px solid #e2e8f0;">
          <div style="display: flex; justify-content: space-between; align-items: center; gap:1rem;">
            <code style="font-family: 'Courier New', monospace; color: ${textColor}; font-size: ${fontSizeBase * 0.875}px; word-break: break-all;">
              ${shortcode.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
            </code>
            <button type="button"
                    class="ws-copy-shortcode-btn"
                    data-shortcode="${shortcode.replace(/"/g, '&quot;')}"
                    style="padding: 0.5rem 1rem; background: ${accentColor}; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: ${fontSizeBase * 0.875}px; font-family: ${fontFamily}; white-space: nowrap;">
              Copy
            </button>
          </div>
        </div>
    `;
}
                async function onCreateFilterSubmit(e) {
                    e.preventDefault();

                    if (!Array.isArray(filters)) {
                        filters = [];
                    }

                    if (!editingFilterId && filters.length >= 999) {
                        showNotification('Maximum limit of 999 filters reached. Please delete some filters first.', 'error');
                        return;
                    }

                    const submitter = e.submitter || document.activeElement;
                    const mode = (submitter && submitter.dataset.mode) ? submitter.dataset.mode : 'create';

                    const isEditing = (mode === 'update' && !!editingFilterId);

                    const btn = submitter;
                    const originalText = btn ? btn.textContent : '';

                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = (mode === 'update') ? 'Updating...' : 'Creating...';
                    }

const existing = isEditing ? (filters || []).find(f => f.id === editingFilterId) : null;

const filterName = getInputValue('filter-name-input');
const manualUrlKey = getInputValue('url-key-input');

let urlKey = manualUrlKey;

if (isEditing && existing && existing.url_key && !manualUrlKey) {
    urlKey = existing.url_key;
}

if (!urlKey) {
    urlKey = wsSlugify(filterName || getInputValue('field-value-input') || ((existing && existing.id) || ''));
}

const nowIso = new Date().toISOString();

const filterData = {
    id: (isEditing && existing) ? existing.id : 'filter-' + Date.now(),
    filter_name: filterName,
    url_key: urlKey,
    field_source: getInputValue('field-source-select'),
    field_value: getInputValue('field-value-input'),
    input_type: getInputValue('input-type-select'),
    exclude_values: getInputValue('exclude-values-input'),
    show_reset: !!getCheckboxValue('show-reset-input'),
    show_label: !!getCheckboxValue('show-label-input'),
    desktop_orientation: getInputValue('desktop-orientation-select') || 'horizontal',
    mobile_orientation: getInputValue('mobile-orientation-select') || 'horizontal',
    post_type: getInputValue('post-type-select') || 'product',
    created_at: nowIso
};

                    if (isEditing && existing) {
                        const idx = filters.findIndex(f => f.id === editingFilterId);
                        if (idx !== -1) {
                            filters[idx] = filterData;
                        }
                    } else {
                        filters.push(filterData);
                    }

                    try {
                        await saveFilters();
                        const form = document.getElementById('filter-form');
                        if (form) {
                            form.reset();
                        }
                        const showLabelCheckbox = document.getElementById('show-label-input');
                        if (showLabelCheckbox) {
                            showLabelCheckbox.checked = true;
                        }

                        editingFilterId = null;
                        const updateBtnEl = document.getElementById('update-filter-btn');
                        if (updateBtnEl) {
                            updateBtnEl.style.display = 'none';
                            updateBtnEl.disabled = false;
                            updateBtnEl.textContent = 'Update Filter';
                        }

                        renderFilters();
                        showNotification(isEditing ? 'Filter updated successfully!' : 'Filter created successfully!', 'success');
                    } catch (err) {
                        console.error(err);
                        showNotification('Failed to save filter', 'error');
                    } finally {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = originalText;
                        }
                    }
                }

                async function saveFilters() {
                    const body = new URLSearchParams();
                    body.append('action', 'ws_save_filters');
                    body.append('nonce', ajaxNonce);
                    body.append('filters', JSON.stringify(filters || []));

                    const response = await fetch(ajaxUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: body.toString()
                    });

                    const data = await response.json();
                    if (!data || !data.success) {
                        throw new Error(data && data.data ? data.data : 'Save failed');
                    }
                }

                function showNotification(message, type) {
                    const notification = document.createElement('div');
                    notification.style.cssText = `
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        padding: 1rem 1.5rem;
                        background: ${type === 'error' ? '#fee' : '#d1fae5'};
                        color: ${type === 'error' ? '#dc2626' : '#065f46'};
                        border-radius: 8px;
                        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                        z-index: 100000;
                        font-weight: 500;
                    `;
                    notification.textContent = message;
                    document.body.appendChild(notification);

                    setTimeout(() => {
                        notification.remove();
                    }, 3000);
                }

                function getInputValue(id) {
                    const el = document.getElementById(id);
                    return el ? el.value.trim() : '';
                }

                function getCheckboxValue(id) {
                    const el = document.getElementById(id);
                    return el ? el.checked : false;
                }

                function escapeHtml(str) {
                    if (typeof str !== 'string') return '';
                    return str
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                window.wsDeleteFilter = async function (filterId) {
                    if (!filterId) return;
                    filters = (filters || []).filter(function (f) {
                        return f.id !== filterId;
                    });

                    try {
                        await saveFilters();
                        renderFilters();
                        showNotification('Filter deleted', 'success');
                    } catch (err) {
                        console.error(err);
                        showNotification('Failed to delete filter', 'error');
                    }
                };

                window.wsEditFilter = function (filterId) {
                    if (!filterId || !Array.isArray(filters)) return;
                    const filter = filters.find(f => f.id === filterId);
                    if (!filter) return;

                    editingFilterId = filterId;

                    const setVal = (id, value) => {
                        const el = document.getElementById(id);
                        if (el) el.value = value || '';
                    };
                    const setCheck = (id, value) => {
                        const el = document.getElementById(id);
                        if (el) el.checked = !!value;
                    };

                    setVal('filter-name-input', filter.filter_name || '');
                                        setVal('url-key-input', filter.url_key || '');
                    setVal('field-source-select', filter.field_source || '');
                    setVal('field-value-input', filter.field_value || '');
                    setVal('input-type-select', filter.input_type || '');
                    setVal('exclude-values-input', filter.exclude_values || '');
                    setVal('desktop-orientation-select', filter.desktop_orientation || 'horizontal');
                    setVal('mobile-orientation-select', filter.mobile_orientation || 'horizontal');
                                        setVal('post-type-select', filter.post_type || 'product');
                    setCheck('show-reset-input', filter.show_reset);
                    setCheck('show-label-input', filter.show_label !== false);

                    const fieldSourceEl = document.getElementById('field-source-select');
                    if (fieldSourceEl) {
                        fieldSourceEl.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    const updateBtnEl = document.getElementById('update-filter-btn');
                    if (updateBtnEl) {
                        updateBtnEl.style.display = 'inline-block';
                    }

                    const form = document.getElementById('create-form');
                    if (form && form.scrollIntoView) {
                        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                };
                              
                                window.wsDuplicateFilter = async function (filterId) {
                                        if (!filterId || !Array.isArray(filters)) return;

                                        const original = filters.find(f => f.id === filterId);
                                        if (!original) return;

                                        const clone = {
                                                ...original,
                                                id: 'filter-' + Date.now(),
                                                filter_name: (original.filter_name || '') + ' (Copy)',
                                                created_at: new Date().toISOString()
                                        };

                                        filters.push(clone);

                                        try {
                                                await saveFilters();
                                                renderFilters();
                                                showNotification('Filter duplicated', 'success');
                                        } catch (err) {
                                                console.error(err);
                                                showNotification('Failed to duplicate filter', 'error');
                                        }
                                };

                function wsCopyShortcode(shortcode, button) {
                    function handleSuccess() {
                        if (button) {
                            const originalText = button.textContent;
                            button.textContent = 'Copied!';
                            button.classList.add('copy-animation');
                            setTimeout(function () {
                                button.textContent = originalText;
                                button.classList.remove('copy-animation');
                            }, 2000);
                        }
                        showNotification('Shortcode copied to clipboard', 'success');
                    }

                    function fallbackCopy() {
                        try {
                            const textarea = document.createElement('textarea');
                            textarea.value = shortcode;
                            textarea.setAttribute('readonly', '');
                            textarea.style.position = 'absolute';
                            textarea.style.left = '-9999px';
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);
                            handleSuccess();
                        } catch (e) {
                            showNotification('Failed to copy shortcode', 'error');
                        }
                    }

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(shortcode).then(function () {
                            handleSuccess();
                        }).catch(function () {
                            fallbackCopy();
                        });
                    } else {
                        fallbackCopy();
                    }
                }

                document.addEventListener('click', function (e) {
                    const copyBtn = e.target.closest('.ws-copy-shortcode-btn');
                    if (copyBtn && copyBtn.closest('#ws-filters-app')) {
                        const shortcode = copyBtn.getAttribute('data-shortcode') || '';
                        if (!shortcode) return;
                        wsCopyShortcode(shortcode, copyBtn);
                        return;
                    }
                });
            })();
        </script>
    </div>
    <?php
}

/**
 * -------------------------------------------------------------------------
 *  Helper: Flatten hierarchical taxonomy terms with labels (for select)
 * -------------------------------------------------------------------------
 */

/**
 * @param WP_Term[] $terms
 * @param int       $parent_id
 * @param int       $depth
 *
 * @return array[]
 */
function ws_wc_filters_flatten_terms( $terms, $parent_id = 0, $depth = 0 ) {
    $flat = array();

    foreach ( $terms as $term ) {
        if ( (int) $term->parent !== (int) $parent_id ) {
            continue;
        }

        $flat[] = array(
            'id'         => $term->term_id,
            'slug'       => $term->slug,
            'plain_name' => $term->name,
            'label'      => ( $depth > 0 ? str_repeat( '— ', $depth ) : '' ) . $term->name,
            'depth'      => $depth,
        );

        $flat = array_merge(
            $flat,
            ws_wc_filters_flatten_terms( $terms, $term->term_id, $depth + 1 )
        );
    }

    return $flat;
}

/**
 * -------------------------------------------------------------------------
 *  FRONT-END: [wc_filter] shortcode (renders UI + JS)
 * -------------------------------------------------------------------------
 */


/**
 * Get the query arg name for a filter.
 * If url_key is set, use that; otherwise fall back to wc_filter_{id}.
 *
 * @param array $filter
 * @return string
 */
function ws_wc_filters_get_query_arg_name( $filter ) {
    if ( ! empty( $filter['url_key'] ) ) {
        return sanitize_key( $filter['url_key'] );
    }

    if ( ! empty( $filter['id'] ) ) {
        return 'wc_filter_' . sanitize_key( $filter['id'] );
    }

    return '';
}


add_shortcode( 'wc_filter', 'ws_wc_filter_shortcode' );

function ws_wc_filter_shortcode( $atts ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'id'                   => '',
            'loop_grid_id'         => '',
            'show_reset'           => '',
            'show_label'           => '',
            'desktop_orientation'  => '',
            'mobile_orientation'   => '',
            'price_min'            => '',
            'price_max'            => '',
        ),
        $atts,
        'wc_filter'
    );

    if ( empty( $atts['id'] ) ) {
        return '';
    }

    $filters = get_option( 'ws_wc_filters', array() );
    if ( ! is_array( $filters ) || empty( $filters ) ) {
        return '';
    }

    $filter = null;
    foreach ( $filters as $f ) {
        if ( isset( $f['id'] ) && $f['id'] === $atts['id'] ) {
            $filter = $f;
            break;
        }
    }

    if ( ! $filter ) {
        return '';
    }

    $query_arg = ws_wc_filters_get_query_arg_name( $filter );
    if ( '' === $query_arg ) {
        return '';
    }

    $current_value = isset( $_GET[ $query_arg ] ) ? wp_unslash( $_GET[ $query_arg ] ) : '';

    $label_text = ! empty( $filter['filter_name'] ) ? $filter['filter_name'] : __( 'Filter', 'ws-wc-filters' );
    $show_label = array_key_exists( 'show_label', $filter ) ? (bool) $filter['show_label'] : true;

    $field_source = $filter['field_source'] ?? '';
    $field_value  = $filter['field_value']  ?? '';
    $exclude_raw  = $filter['exclude_values'] ?? '';

    $exclude_slugs_or_values = array();
    if ( is_string( $exclude_raw ) && '' !== trim( $exclude_raw ) ) {
        $exclude_slugs_or_values = array_filter( array_map( 'trim', explode( ',', $exclude_raw ) ) );
    }

    $is_taxonomy_source = in_array( $field_source, array( 'category', 'tag', 'attribute', 'brand' ), true );
    $taxonomy           = '';

    if ( $is_taxonomy_source ) {
        if ( ! empty( $field_value ) ) {
            $taxonomy = $field_value;
        } elseif ( 'category' === $field_source ) {
            $taxonomy = 'product_cat';
        } elseif ( 'tag' === $field_source ) {
            $taxonomy = 'product_tag';
        } elseif ( 'brand' === $field_source ) {
            $taxonomy = 'product_brand';
        }
    }

    // Taxonomy terms
    $terms      = array();
    $flat_terms = array();

    if ( $taxonomy ) {
        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
        );

        if ( ! empty( $exclude_slugs_or_values ) ) {
            $excluded_terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'slug'       => $exclude_slugs_or_values,
                    'hide_empty' => false,
                )
            );
            if ( ! is_wp_error( $excluded_terms ) && ! empty( $excluded_terms ) ) {
                $args['exclude'] = wp_list_pluck( $excluded_terms, 'term_id' );
            }
        }

        $terms = get_terms( $args );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            $flat_terms = ws_wc_filters_flatten_terms( $terms );
        } else {
            $terms      = array();
            $flat_terms = array();
        }
    }

    // Custom field values
    $custom_values = array();
    if ( 'custom_field' === $field_source && ! empty( $field_value ) ) {
        global $wpdb;
        $raw_meta_values = $wpdb->get_col(
                    $wpdb->prepare(
                        "
                        SELECT DISTINCT pm.meta_value
                        FROM {$wpdb->postmeta} pm
                        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                        WHERE pm.meta_key = %s
                          AND pm.meta_value <> ''
                          AND p.post_status = 'publish'
                          AND p.post_type = %s
                        LIMIT 200
                        ",
                        $field_value,
                        $filter['post_type'] ?? 'post'
                    )
                );


        if ( ! empty( $raw_meta_values ) ) {
            $seen = array();
            foreach ( $raw_meta_values as $val ) {
                if ( '' === trim( $val ) ) {
                    continue;
                }
                $parts = preg_split( '/\s*,\s*/', $val );
                foreach ( $parts as $part ) {
                    $part = trim( $part );
                    if ( '' === $part ) {
                        continue;
                    }
                    if ( isset( $seen[ $part ] ) ) {
                        continue;
                    }
                    $seen[ $part ]   = true;
                    $custom_values[] = $part;
                }
            }
        }

        if ( ! empty( $exclude_slugs_or_values ) && ! empty( $custom_values ) ) {
            $custom_values = array_values(
                array_filter(
                    $custom_values,
                    function ( $val ) use ( $exclude_slugs_or_values ) {
                        return ! in_array( $val, $exclude_slugs_or_values, true );
                    }
                )
            );
        }
    }

    // ACF choices lookup (if ACF is active)
    $acf_choices = array();
    if ( 'custom_field' === $field_source && ! empty( $field_value ) && function_exists( 'acf_get_field' ) ) {
        $acf_field = acf_get_field( $field_value );
        if ( is_array( $acf_field ) && ! empty( $acf_field['choices'] ) && is_array( $acf_field['choices'] ) ) {
            $acf_choices = $acf_field['choices'];
        }
    }

    $resolve_meta_label = function( $val ) use ( $acf_choices ) {
        if ( ! empty( $acf_choices ) && isset( $acf_choices[ $val ] ) ) {
            return (string) $acf_choices[ $val ];
        }

        if ( is_numeric( $val ) ) {
            $post = get_post( (int) $val );
            if ( $post && 'publish' === $post->post_status ) {
                return $post->post_title;
            }

            $term = get_term( (int) $val );
            if ( $term && ! is_wp_error( $term ) ) {
                return $term->name;
            }
        }

        return (string) $val;
    };

    $current_url = home_url(
        add_query_arg(
            array(),
            wp_unslash( $_SERVER['REQUEST_URI'] )
        )
    );

    // Build a reset URL that clears the correct args for this filter.
    $reset_url = $current_url;
    $input_type = $filter['input_type'] ?? '';

    if ( 'price_range' === $input_type ) {
        $price_min_arg = $query_arg . '_min';
        $price_max_arg = $query_arg . '_max';

        $reset_url = remove_query_arg(
            array( $price_min_arg, $price_max_arg ),
            $reset_url
        );
    } else {
        $reset_url = remove_query_arg( $query_arg, $reset_url );
    }

    $other_args = $_GET;

    if ( 'price_range' === $input_type ) {
        unset(
            $other_args[ $query_arg . '_min' ],
            $other_args[ $query_arg . '_max' ]
        );
    } else {
        unset( $other_args[ $query_arg ] );
    }

    $desktop_orientation = ! empty( $filter['desktop_orientation'] ) ? $filter['desktop_orientation'] : 'horizontal';
    $mobile_orientation  = ! empty( $filter['mobile_orientation'] ) ? $filter['mobile_orientation'] : 'horizontal';

    // Determine loop target selector (Elementor Loop Grid, etc.)
    $loop_target = '';

    if ( ! empty( $atts['loop_grid_id'] ) ) {
        $loop_target = '#' . ltrim( $atts['loop_grid_id'], '#.' );
    } elseif ( ! empty( $filter['loop_grid_id'] ) ) {
        $loop_target = '#' . ltrim( $filter['loop_grid_id'], '#.' );
    }

    // Normalise current values for multi-select / checkbox
    $current_values_multi = array();
    if ( is_array( $current_value ) ) {
        $current_values_multi = $current_value;
    } elseif ( is_string( $current_value ) && '' !== $current_value ) {
        $current_values_multi = array( $current_value );
    }

    ob_start();
    ?>
<div class="ws-wc-filter-wrapper ws-wc-filters-skin"
         data-desktop-orientation="<?php echo esc_attr( $desktop_orientation ); ?>"
         data-mobile-orientation="<?php echo esc_attr( $mobile_orientation ); ?>"
         <?php if ( $loop_target ) : ?>
             data-loop-target="<?php echo esc_attr( $loop_target ); ?>"
         <?php endif; ?>
    >

<form method="get" class="ws-wc-filter-form">
    <?php
    // Preserve all existing hidden inputs
    if ( ! empty( $other_args ) && is_array( $other_args ) ) {
        foreach ( $other_args as $key => $value ) {

            if ( is_array( $value ) ) {
                foreach ( $value as $v ) {
                    printf(
                        '<input type="hidden" name="%s[]" value="%s" />' . "\n",
                        esc_attr( $key ),
                        esc_attr( $v )
                    );
                }
            } else {
                printf(
                    '<input type="hidden" name="%s" value="%s" />' . "\n",
                    esc_attr( $key ),
                    esc_attr( $value )
                );
            }
        }
    }

    // === HEADER (LABEL + RESET) FIRST ===
    $show_reset_flag = array_key_exists( 'show_reset', $filter ) ? (bool) $filter['show_reset'] : false;

    if ( $show_label || $show_reset_flag ) : ?>
        <div class="ws-wc-filter-header">
            <?php if ( $show_label ) : ?>
                <label class="ws-wc-filter-label">
                    <?php echo esc_html( $label_text ); ?>
                </label>
            <?php endif; ?>

            <?php if ( $show_reset_flag ) : ?>
                <button type="button"
                        class="ws-wc-filter-reset"
                        data-reset-url="<?php echo esc_url( $reset_url ); ?>"
                        aria-label="<?php esc_attr_e( 'Reset filter', 'ws-wc-filters' ); ?>">
                    <span aria-hidden="true">⟲</span>
                    <span class="ws-wc-sr-only"><?php esc_html_e( 'Reset', 'ws-wc-filters' ); ?></span>
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php
    // NOW THE CONTROL(S)
                ?>
                <div class="ws-wc-filter-control">
                <?php
              
                $input_type = $filter['input_type'] ?? '';
              
                // 1) PRICE RANGE
                if ( 'price_range' === $input_type ) {
              
                    $price_min_arg = $query_arg . '_min';
                    $price_max_arg = $query_arg . '_max';
              
                    $range_meta_key  = ! empty( $field_value ) ? $field_value : '_price';
                    $range_post_type = ! empty( $filter['post_type'] ) ? $filter['post_type'] : 'product';
              
                    $bounds     = ws_wc_filters_get_numeric_bounds( $range_meta_key, true, $range_post_type );
                    $global_min = $bounds['min'];
                    $global_max = $bounds['max'];
              
                    $shortcode_min = '' !== $atts['price_min'] ? (float) $atts['price_min'] : $global_min;
                    $shortcode_max = '' !== $atts['price_max'] ? (float) $atts['price_max'] : $global_max;
              
                    $range_min = min( $shortcode_min, $shortcode_max );
                    $range_max = max( $shortcode_min, $shortcode_max );
              
                    $current_min = isset( $_GET[ $price_min_arg ] ) ? (float) wp_unslash( $_GET[ $price_min_arg ] ) : $range_min;
                    $current_max = isset( $_GET[ $price_max_arg ] ) ? (float) wp_unslash( $_GET[ $price_max_arg ] ) : $range_max;
              
                    $current_min = max( $range_min, min( $current_min, $current_max ) );
                    $current_max = min( $range_max, max( $current_max, $current_min ) );
                    ?>
                    <div class="ws-price-range-wrapper"
                         data-min="<?php echo esc_attr( $range_min ); ?>"
                         data-max="<?php echo esc_attr( $range_max ); ?>">
                        <div class="ws-price-range-row">
                            <input type="number"
                                   name="<?php echo esc_attr( $price_min_arg ); ?>"
                                   class="ws-price-range-input ws-price-min-input"
                                   value="<?php echo esc_attr( $current_min ); ?>"
                                   step="1" />
                            <span class="ws-price-range-separator">–</span>
                            <div class="ws-price-range-slider-wrapper">
                                <input type="range"
                                       class="ws-price-range ws-price-range-min"
                                       value="<?php echo esc_attr( $current_min ); ?>" />
                                <input type="range"
                                       class="ws-price-range ws-price-range-max"
                                       value="<?php echo esc_attr( $current_max ); ?>" />
                            </div>
                            <input type="number"
                                   name="<?php echo esc_attr( $price_max_arg ); ?>"
                                   class="ws-price-range-input ws-price-max-input"
                                   value="<?php echo esc_attr( $current_max ); ?>"
                                   step="1" />
                        </div>
                    </div>
                    <?php
              
                // 2) TAXONOMY FILTERS
                } elseif ( $is_taxonomy_source && ! empty( $terms ) ) {
              
                    if ( 'select' === $input_type ) :
              
                        $current_slug = ! is_array( $current_value ) ? $current_value : '';
                        ?>
                        <div class="ws-custom-select" data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
                            <input type="hidden"
                                   name="<?php echo esc_attr( $query_arg ); ?>"
                                   class="ws-custom-select-input"
                                   value="<?php echo esc_attr( $current_slug ); ?>" />
              
                            <button type="button" class="ws-custom-select-toggle">
                                <span class="ws-custom-select-label">
                                    <?php
                                    if ( $current_slug ) {
                                        $term  = get_term_by( 'slug', $current_slug, $taxonomy );
                                        $label = ( $term && ! is_wp_error( $term ) ) ? $term->name : __( 'Selecteer...', 'ws-wc-filters' );
                                        echo esc_html( $label );
                                    } else {
                                        esc_html_e( 'Selecteer...', 'ws-wc-filters' );
                                    }
                                    ?>
                                </span>
                                <span class="ws-custom-select-caret" aria-hidden="true">▾</span>
                            </button>
              
                            <div class="ws-custom-select-panel">
                                <button type="button" class="ws-custom-select-option" data-value="">
                                    <?php esc_html_e( 'Selecteer...', 'ws-wc-filters' ); ?>
                                </button>
              
                                <?php foreach ( $flat_terms as $item ) : ?>
                                    <button type="button"
                                            class="ws-custom-select-option"
                                            data-value="<?php echo esc_attr( $item['slug'] ); ?>">
                                        <?php echo esc_html( $item['label'] ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
              
                    elseif ( 'select_pill' === $input_type ) :
              
                        $current_slug = ! is_array( $current_value ) ? $current_value : '';
                        ?>
                        <div class="ws-wc-filter-multiselect ws-wc-filter-select-pill ws-wc-filter-multiselect-inline"
                             data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
              
                            <?php $is_any = ( '' === $current_slug || null === $current_slug ); ?>
              
                            <label class="ws-wc-filter-multiselect-option<?php echo $is_any ? ' is-active' : ''; ?>">
                                <input type="radio"
                                       name="<?php echo esc_attr( $query_arg ); ?>"
                                       value=""
                                       <?php checked( $is_any ); ?> />
                                <span><?php esc_html_e( 'Alles', 'ws-wc-filters' ); ?></span>
                            </label>
              
                            <?php foreach ( $flat_terms as $item ) : ?>
                                <?php
                                $slug    = $item['slug'];
                                $label   = $item['plain_name'];
                                $checked = ( $slug === $current_slug );
                                ?>
                                <label class="ws-wc-filter-multiselect-option<?php echo $checked ? ' is-active' : ''; ?>">
                                    <input type="radio"
                                           name="<?php echo esc_attr( $query_arg ); ?>"
                                           value="<?php echo esc_attr( $slug ); ?>"
                                           <?php checked( $checked ); ?> />
                                    <span><?php echo esc_html( $label ); ?></span>
                                </label>
                            <?php endforeach; ?>
              
                        </div>
                        <?php
              
                    elseif ( 'multiselect' === $input_type ) :
                        ?>
                        <div class="ws-wc-filter-multiselect ws-wc-filter-multiselect-inline"
                             data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
              
                            <?php foreach ( $flat_terms as $item ) : ?>
                                <?php
                                $slug    = $item['slug'];
                                $label   = $item['plain_name'];
                                $checked = in_array( $slug, $current_values_multi, true );
                                ?>
                                <label class="ws-wc-filter-multiselect-option<?php echo $checked ? ' is-active' : ''; ?>">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $query_arg ); ?>[]"
                                           value="<?php echo esc_attr( $slug ); ?>"
                                           <?php checked( $checked ); ?> />
                                    <span><?php echo esc_html( $label ); ?></span>
                                </label>
                            <?php endforeach; ?>
              
                        </div>
                        <?php
              
                    elseif ( 'checkbox' === $input_type ) :
                        ?>
                        <div class="ws-wc-filter-checkbox-group" data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
                            <?php foreach ( $flat_terms as $item ) : ?>
                                <?php
                                $slug       = $item['slug'];
                                $plain_name = $item['plain_name'];
                                $checked    = in_array( $slug, $current_values_multi, true );
                                ?>
                                <label class="ws-wc-filter-checkbox">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $query_arg ); ?>[]"
                                           value="<?php echo esc_attr( $slug ); ?>"
                                           <?php checked( $checked ); ?> />
                                    <span><?php echo esc_html( $plain_name ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php
                    endif;
              
                // 3) CUSTOM FIELD FILTERS
                } elseif ( 'custom_field' === $field_source && ! empty( $custom_values ) ) {
              
                    if ( 'select' === $input_type ) :
              
                        $current_val = ! is_array( $current_value ) ? $current_value : '';
                        ?>
                        <div class="ws-custom-select" data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
                            <input type="hidden"
                                   name="<?php echo esc_attr( $query_arg ); ?>"
                                   class="ws-custom-select-input"
                                   value="<?php echo esc_attr( $current_val ); ?>" />
              
                            <button type="button" class="ws-custom-select-toggle">
                                <span class="ws-custom-select-label">
                                    <?php
                                    if ( $current_val ) {
                                        echo esc_html( $resolve_meta_label( $current_val ) );
                                    } else {
                                        esc_html_e( 'Selecteer...', 'ws-wc-filters' );
                                    }
                                    ?>
                                </span>
                                <span class="ws-custom-select-caret" aria-hidden="true">▾</span>
                            </button>
              
                            <div class="ws-custom-select-panel">
                                <button type="button" class="ws-custom-select-option" data-value="">
                                    <?php esc_html_e( 'Selecteer...', 'ws-wc-filters' ); ?>
                                </button>
              
                                <?php foreach ( $custom_values as $val ) : ?>
                                    <?php $label = $resolve_meta_label( $val ); ?>
                                    <button type="button"
                                            class="ws-custom-select-option"
                                            data-value="<?php echo esc_attr( $val ); ?>">
                                        <?php echo esc_html( $label ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
              
                    elseif ( 'select_pill' === $input_type ) :
              
                        $current_val = ! is_array( $current_value ) ? $current_value : '';
                        ?>
                        <div class="ws-wc-filter-multiselect ws-wc-filter-select-pill ws-wc-filter-multiselect-inline"
                             data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
              
                            <?php $is_any = ( '' === $current_val || null === $current_val ); ?>
              
                            <label class="ws-wc-filter-multiselect-option<?php echo $is_any ? ' is-active' : ''; ?>">
                                <input type="radio"
                                       name="<?php echo esc_attr( $query_arg ); ?>"
                                       value=""
                                       <?php checked( $is_any ); ?> />
                                <span><?php esc_html_e( 'Alles', 'ws-wc-filters' ); ?></span>
                            </label>
              
                            <?php foreach ( $custom_values as $val ) : ?>
                                <?php
                                $checked = ( $val === $current_val );
                                $label   = $resolve_meta_label( $val );
                                ?>
                                <label class="ws-wc-filter-multiselect-option<?php echo $checked ? ' is-active' : ''; ?>">
                                    <input type="radio"
                                           name="<?php echo esc_attr( $query_arg ); ?>"
                                           value="<?php echo esc_attr( $val ); ?>"
                                           <?php checked( $checked ); ?> />
                                    <span><?php echo esc_html( $label ); ?></span>
                                </label>
                            <?php endforeach; ?>
              
                        </div>
                        <?php
              
                    elseif ( 'multiselect' === $input_type ) :
                        ?>
                        <div class="ws-wc-filter-multiselect ws-wc-filter-multiselect-inline"
                             data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
              
                            <?php foreach ( $custom_values as $val ) : ?>
                                <?php
                                $checked = in_array( $val, $current_values_multi, true );
                                $label   = $resolve_meta_label( $val );
                                ?>
                                <label class="ws-wc-filter-multiselect-option<?php echo $checked ? ' is-active' : ''; ?>">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $query_arg ); ?>[]"
                                           value="<?php echo esc_attr( $val ); ?>"
                                           <?php checked( $checked ); ?> />
                                    <span><?php echo esc_html( $label ); ?></span>
                                </label>
                            <?php endforeach; ?>
              
                        </div>
                        <?php
              
                    elseif ( 'checkbox' === $input_type ) :
                        ?>
                        <div class="ws-wc-filter-checkbox-group" data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
                            <?php foreach ( $custom_values as $val ) : ?>
                                <?php
                                $checked = in_array( $val, $current_values_multi, true );
                                $label   = $resolve_meta_label( $val );
                                ?>
                                <label class="ws-wc-filter-checkbox">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $query_arg ); ?>[]"
                                           value="<?php echo esc_attr( $val ); ?>"
                                           <?php checked( $checked ); ?> />
                                    <span><?php echo esc_html( $label ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php
                    endif;
              
                // 4) FALLBACK
                } else {
                    ?>
                    <input type="text"
                           name="<?php echo esc_attr( $query_arg ); ?>"
                           class="ws-wc-filter-input"
                           value="<?php echo is_array( $current_value ) ? '' : esc_attr( $current_value ); ?>" />
                    <?php
                }
              
                ?>
                </div>  <!-- .ws-wc-filter-control -->
        </form>     <!-- .ws-wc-filter-form -->
</div>          <!-- .ws-wc-filter-wrapper -->
<?php

    // Output the front-end JS once per page.
    static $ws_filters_front_js_printed = false;
    if ( ! $ws_filters_front_js_printed ) {
        $ws_filters_front_js_printed = true;
        ?>
        <script>
            (function () {

// ─────────────────────────────────────────────────────────────────────────────
//  FIX: Loading overlay helpers (were previously undefined)
// ─────────────────────────────────────────────────────────────────────────────
function wsShowLoadingOverlay(container) {
    if (!container) return;
    var overlay = container.querySelector('.ws-loading-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'ws-loading-overlay';
        overlay.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.7);z-index:10;display:flex;align-items:center;justify-content:center;pointer-events:none;';
        overlay.innerHTML = '<span style="font-size:1.5rem;">⏳</span>';
        container.style.position = 'relative';
        container.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}

function wsHideLoadingOverlay(container) {
    if (!container) return;
    var overlay = container.querySelector('.ws-loading-overlay');
    if (overlay) overlay.style.display = 'none';
}

// ─────────────────────────────────────────────────────────────────────────────
//  FIX: Re-init Elementor widgets after AJAX content swap
// ─────────────────────────────────────────────────────────────────────────────
function reinitElementor(container) {
    if (!container) return;

    // Elementor Pro front-end handler
    if (window.elementorFrontend && typeof elementorFrontend.elementsHandler !== 'undefined') {
        try {
            // Elementor ≥ 3.x
            if (typeof elementorFrontend.elementsHandler.runReadyTrigger === 'function') {
                elementorFrontend.elementsHandler.runReadyTrigger(container);
            }
        } catch (e) {
            console.warn('WS Filters – reinitElementor:', e);
        }
    }

    // Also trigger jQuery-based Elementor ready event if available
    if (window.jQuery) {
        try {
            jQuery(container).find('.elementor-element').each(function () {
                window.elementorFrontend && elementorFrontend.elementsHandler &&
                    typeof elementorFrontend.elementsHandler.runReadyTrigger === 'function' &&
                    elementorFrontend.elementsHandler.runReadyTrigger(jQuery(this));
            });
        } catch (e) {
            // silently ignore
        }
    }
}


let wsActiveFetchController = null;

function applyFiltersAJAX(form, resetUrl) {
    if (!form) return;

    var wrapper = form.closest('.ws-wc-filter-wrapper, .ws-wc-sort-wrapper');
    var loopTargetSelector = wrapper ? wrapper.getAttribute('data-loop-target') : null;

    var url;

    if (resetUrl) {
        // Reset: volledige page reload — state ook wissen
        wsFilterState = {};
        window.location.href = resetUrl;
        return;
    }

    // Bouw URL puur vanuit wsFilterState
    var params = new URLSearchParams();
    Object.keys(wsFilterState).forEach(function (key) {
        var val = wsFilterState[key];
        if (Array.isArray(val)) {
            val.forEach(function (v) { if (v.trim()) params.append(key, v); });
        } else {
            if (val && val.trim()) params.set(key, val.trim());
        }
    });

    url = new URL(window.location.origin + window.location.pathname);
    url.search = params.toString();

    var hasFilters = false;
    params.forEach(function (val) { if (val.trim()) hasFilters = true; });

    if (hasFilters) {
        document.body.classList.add('ws-filters-active');
    } else {
        document.body.classList.remove('ws-filters-active');
    }

    window.history.replaceState({}, '', url.toString());

    // Geen AJAX target → gewone redirect
    if (!loopTargetSelector) {
        window.location.href = url.toString();
        return;
    }

    var target = document.querySelector(loopTargetSelector);
    if (!target) {
        window.location.href = url.toString();
        return;
    }

    var outerTarget = target;
    var innerTarget = target.querySelector('.elementor-loop-container') || target;

    if (wsActiveFetchController) wsActiveFetchController.abort();
    wsActiveFetchController = new AbortController();
    var signal = wsActiveFetchController.signal;

    outerTarget.classList.add('ws-loop-loading');
    wsShowLoadingOverlay(outerTarget);

    fetch(url.toString(), {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: signal
    })
    .then(function (res) { return res.text(); })
    .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newRoot = doc.querySelector(loopTargetSelector);
        if (!newRoot) { window.location.href = url.toString(); return; }

        var newInner = newRoot.querySelector('.elementor-loop-container') || newRoot;
        innerTarget.innerHTML = newInner.innerHTML;

        // === NIEUWE PAGINERING LOGICA ===
        // Zoek zowel de nav als de anchor in de AJAX response
        var newPaginationNav = doc.querySelector('nav.elementor-pagination');
        var newLoadMoreAnchor = doc.querySelector('.e-load-more-anchor');
      
        // En in de huidige pagina
        var currentPaginationNav = document.querySelector('nav.elementor-pagination');
        var currentLoadMoreAnchor = document.querySelector('.e-load-more-anchor');

        // 1. Update of voeg <nav> toe
        if (newPaginationNav) {
            if (currentPaginationNav) {
                currentPaginationNav.outerHTML = newPaginationNav.outerHTML;
            } else {
                // Voeg toe na de loop container
                outerTarget.insertAdjacentHTML('afterend', newPaginationNav.outerHTML);
            }
        } else {
            // Geen paginering in response -> verwijder bestaande
            if (currentPaginationNav) currentPaginationNav.remove();
        }

        // 2. Update of voeg .e-load-more-anchor toe
        if (newLoadMoreAnchor) {
            if (currentLoadMoreAnchor) {
                currentLoadMoreAnchor.outerHTML = newLoadMoreAnchor.outerHTML;
            } else {
                // Voeg toe vóór de loop container
                outerTarget.insertAdjacentHTML('beforebegin', newLoadMoreAnchor.outerHTML);
            }
        } else {
            // Geen anchor in response -> verwijder bestaande
            if (currentLoadMoreAnchor) currentLoadMoreAnchor.remove();
        }

        reinitElementor(innerTarget);
        requestAnimationFrame(function () { window.dispatchEvent(new Event('resize')); });

        document.querySelectorAll('.ws-price-range-wrapper').forEach(function (w) { initPriceRange(w, true); });
        document.querySelectorAll('.ws-custom-select').forEach(function (w) { initCustomSelect(w, true); });
        document.querySelectorAll('.ws-wc-filter-multiselect').forEach(function (w) { updateMultiselectLabel(w); });
    })
    .catch(function (err) {
        if (err.name === 'AbortError') return;
        window.location.href = url.toString();
    })
    .finally(function () {
        wsHideLoadingOverlay(outerTarget);
        outerTarget.classList.remove('ws-loop-loading');
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  EXPLICIETE FILTER STATE
// ─────────────────────────────────────────────────────────────────────────────
var wsFilterState = {};

// Initialiseer vanuit de huidige URL
(function () {
    var params = new URLSearchParams(window.location.search);
    params.forEach(function (val, key) {
        // VERWIJDER de return voor paginering keys hier!
        if (!val.trim()) return;
        if (key.endsWith('[]')) {
            if (!Array.isArray(wsFilterState[key])) wsFilterState[key] = [];
            wsFilterState[key].push(val);
        } else {
            wsFilterState[key] = val;
        }
    });
})();

// ─────────────────────────────────────────────────────────────────────────────
//  DEBOUNCE voor price range
// ─────────────────────────────────────────────────────────────────────────────
var wsPriceDebounceTimer = null;
function wsDebouncedApplyFilters(form) {
    if (wsPriceDebounceTimer) clearTimeout(wsPriceDebounceTimer);
    wsPriceDebounceTimer = setTimeout(function () {
        applyFiltersAJAX(form);
    }, 300);
}

// ─────────────────────────────────────────────────────────────────────────────
//  updateMultiselectLabel
// ─────────────────────────────────────────────────────────────────────────────
function updateMultiselectLabel(wrapper) {
    if (!wrapper) return;
    var labelEl = wrapper.querySelector('.ws-wc-filter-multiselect-label');
    if (!labelEl) return;
    var checks = wrapper.querySelectorAll('input[type="checkbox"]');
    var selectedCount = 0;
    checks.forEach(function (cb) { if (cb.checked) selectedCount++; });
    labelEl.textContent = selectedCount ? selectedCount + ' selected' : 'Alles';
}

// ─────────────────────────────────────────────────────────────────────────────
//  initCustomSelect
// ─────────────────────────────────────────────────────────────────────────────
function initCustomSelect(wrapper, force) {
    force = force || false;
    var hiddenInput = wrapper.querySelector('.ws-custom-select-input');
    var toggle      = wrapper.querySelector('.ws-custom-select-toggle');
    var labelEl     = wrapper.querySelector('.ws-custom-select-label');
    var panel       = wrapper.querySelector('.ws-custom-select-panel');
    if (!hiddenInput || !toggle || !labelEl || !panel) return;

    var defaultLabel = wrapper.dataset.wsDefaultLabel || labelEl.textContent.trim() || 'Alles';
    if (!wrapper.dataset.wsDefaultLabel) wrapper.dataset.wsDefaultLabel = defaultLabel;

    function syncLabel() {
        var val = hiddenInput.value;
        if (!val) { labelEl.textContent = defaultLabel; return; }
        var opt = panel.querySelector('.ws-custom-select-option[data-value="' + CSS.escape(val) + '"]');
        labelEl.textContent = opt ? opt.textContent.trim() : val;
    }

    // Bij force re-init na AJAX: alleen label synchroniseren, geen nieuwe listeners
    if (force && wrapper.dataset.wsSelectInit === '1') {
        syncLabel();
        return;
    }
    if (wrapper.dataset.wsSelectInit === '1') return;
    wrapper.dataset.wsSelectInit = '1';

    syncLabel();

    function closeAll() {
        document.querySelectorAll('.ws-custom-select.is-open').forEach(function (w) {
            if (w !== wrapper) w.classList.remove('is-open');
        });
    }

    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        var isOpen = wrapper.classList.contains('is-open');
        closeAll();
        if (!isOpen) wrapper.classList.add('is-open');
    });

    panel.addEventListener('click', function (e) {
        var btn = e.target.closest('.ws-custom-select-option');
        if (!btn) return;

        var key    = wrapper.getAttribute('data-query-arg');
        var newVal = btn.getAttribute('data-value') || '';

        // State direct bijwerken VOOR de AJAX aanroep
        if (newVal.trim()) {
            wsFilterState[key] = newVal.trim();
        } else {
            delete wsFilterState[key];
        }

        hiddenInput.value = newVal;
        syncLabel();
        wrapper.classList.remove('is-open');

        var form = wrapper.closest('.ws-wc-filter-form');
        if (form) applyFiltersAJAX(form);
    });

    document.addEventListener('click', function (e) {
        if (!wrapper.contains(e.target)) wrapper.classList.remove('is-open');
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  initPriceRange
// ─────────────────────────────────────────────────────────────────────────────
function initPriceRange(wrapper, force) {
    force = force || false;
    if (!force && wrapper.dataset.wsPriceInit === '1') return;
    wrapper.dataset.wsPriceInit = '1';

    var minAttr = parseFloat(wrapper.getAttribute('data-min')) || 0;
    var maxAttr = parseFloat(wrapper.getAttribute('data-max')) || 1000;

    var minInput = wrapper.querySelector('.ws-price-min-input');
    var maxInput = wrapper.querySelector('.ws-price-max-input');
    var minRange = wrapper.querySelector('.ws-price-range-min');
    var maxRange = wrapper.querySelector('.ws-price-range-max');
    if (!minInput || !maxInput || !minRange || !maxRange) return;

    [minRange, maxRange].forEach(function (el) { el.min = minAttr; el.max = maxAttr; el.step = 1; });

    function clamp(val, min, max) { val = isNaN(val) ? min : val; return Math.min(Math.max(val, min), max); }

    function syncFromInputs() {
        var lo = clamp(parseFloat(minInput.value), minAttr, maxAttr);
        var hi = clamp(parseFloat(maxInput.value), minAttr, maxAttr);
        if (lo > hi) { var t = lo; lo = hi; hi = t; }
        minInput.value = lo; maxInput.value = hi; minRange.value = lo; maxRange.value = hi;
    }
    function syncFromRanges() {
        var lo = clamp(parseFloat(minRange.value), minAttr, maxAttr);
        var hi = clamp(parseFloat(maxRange.value), minAttr, maxAttr);
        if (lo > hi) { var t = lo; lo = hi; hi = t; }
        minInput.value = lo; maxInput.value = hi; minRange.value = lo; maxRange.value = hi;
    }

    syncFromInputs();
    minInput.addEventListener('change', syncFromInputs);
    maxInput.addEventListener('change', syncFromInputs);
    minRange.addEventListener('input', syncFromRanges);
    maxRange.addEventListener('input', syncFromRanges);
}

// ─────────────────────────────────────────────────────────────────────────────
//  CHANGE event — werkt wsFilterState bij en roept AJAX aan
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('change', function (e) {
    var target = e.target;
    var form   = target.closest('.ws-wc-filter-form, .ws-wc-sort-form');
    if (!form) return;

    // ── Price range ──────────────────────────────────────────────────────────
    if (target.matches('.ws-price-min-input, .ws-price-max-input, .ws-price-range-min, .ws-price-range-max')) {
        var priceWrapper = target.closest('.ws-price-range-wrapper');
        if (priceWrapper) {
            var minInp = priceWrapper.querySelector('.ws-price-min-input');
            var maxInp = priceWrapper.querySelector('.ws-price-max-input');
            if (minInp && minInp.name) wsFilterState[minInp.name] = minInp.value;
            if (maxInp && maxInp.name) wsFilterState[maxInp.name] = maxInp.value;
        }
        wsDebouncedApplyFilters(form);
        return;
    }

    // ── Custom select: wordt afgehandeld door panel click handler ────────────
    if (target.closest('.ws-custom-select')) return;

    // ── Radio (select_pill) ──────────────────────────────────────────────────
    if (target.type === 'radio') {
        var msWrapper = target.closest('[data-query-arg]');
        if (msWrapper) {
            var key = msWrapper.getAttribute('data-query-arg');
            if (target.value.trim()) {
                wsFilterState[key] = target.value.trim();
            } else {
                delete wsFilterState[key];
            }
            // Visuele active klasse updaten
            msWrapper.querySelectorAll('.ws-wc-filter-multiselect-option').forEach(function (opt) {
                var inp = opt.querySelector('input[type="radio"]');
                opt.classList.toggle('is-active', inp && inp.checked);
            });
        }
        applyFiltersAJAX(form);
        return;
    }

    // ── Checkbox (multiselect / checkbox) ────────────────────────────────────
    if (target.type === 'checkbox') {
        var msWrapper = target.closest('[data-query-arg]');
        if (msWrapper) {
            var key     = msWrapper.getAttribute('data-query-arg');
            var arrKey  = key + '[]';
            var checked = Array.from(msWrapper.querySelectorAll('input[type="checkbox"]:checked'))
                              .map(function (cb) { return cb.value; })
                              .filter(function (v) { return v.trim(); });
            if (checked.length) {
                wsFilterState[arrKey] = checked;
            } else {
                delete wsFilterState[arrKey];
            }
            // Visuele active klasse
            var chip = target.closest('.ws-wc-filter-multiselect-option');
            if (chip) chip.classList.toggle('is-active', target.checked);
            updateMultiselectLabel(msWrapper);
        }
        applyFiltersAJAX(form);
        return;
    }
});

// ─────────────────────────────────────────────────────────────────────────────
//  CLICK — reset knoppen
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('click', function (e) {
    var resetBtn = e.target.closest('.ws-wc-filter-reset');
    if (resetBtn) {
        var resetUrl = resetBtn.getAttribute('data-reset-url') || '';
        if (resetUrl) window.location.href = resetUrl;
        return;
    }
    var resetAllBtn = e.target.closest('.ws-wc-filter-reset-all');
    if (resetAllBtn) {
        var resetUrl = resetAllBtn.getAttribute('data-reset-url') || '';
        if (resetUrl) window.location.href = resetUrl;
        return;
    }
});

// ─────────────────────────────────────────────────────────────────────────────
//  DOMContentLoaded
// ─────────────────────────────────────────────────────────────────────────────
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('.ws-wc-filter-multiselect').forEach(function (wrapper) {
                        updateMultiselectLabel(wrapper);
                    });
                    document.querySelectorAll('.ws-custom-select').forEach(function (wrapper) {
                        initCustomSelect(wrapper);
                    });
                    document.querySelectorAll('.ws-price-range-wrapper').forEach(function (wrapper) {
                        initPriceRange(wrapper);
                    });
                });

                window.wsApplyFiltersAJAX = applyFiltersAJAX;
            })();
        </script>
        <?php
    }

    return ob_get_clean();
}

/**
 * -------------------------------------------------------------------------
 *  FRONT-END: Reset All Filters Shortcode
 *  [wc_filter_reset_all label="Reset All Filters"]
 * -------------------------------------------------------------------------
 */

add_shortcode( 'wc_filter_reset_all', 'ws_wc_filter_reset_all_shortcode' );

function ws_wc_filter_reset_all_shortcode( $atts ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'label' => __( 'Reset All Filters', 'ws-wc-filters' ),
        ),
        $atts,
        'wc_filter_reset_all'
    );

    $filters = get_option( 'ws_wc_filters', array() );
    if ( ! is_array( $filters ) || empty( $filters ) ) {
        return '';
    }

    $current_url = home_url(
        add_query_arg(
            array(),
            wp_unslash( $_SERVER['REQUEST_URI'] )
        )
    );
    $url = $current_url;

    foreach ( $filters as $filter ) {
        if ( empty( $filter['id'] ) ) {
            continue;
        }
        $query_arg = ws_wc_filters_get_query_arg_name( $filter );
        if ( '' === $query_arg ) {
            continue;
        }

        $url = remove_query_arg( $query_arg, $url );
        $url = remove_query_arg( $query_arg . '_min', $url );
        $url = remove_query_arg( $query_arg . '_max', $url );
    }

    $url = remove_query_arg( array( 'paged', 'page', 'wc_sort' ), $url );

    ob_start();
    ?>
    <button type="button"
            class="ws-wc-filter-reset-all"
            data-reset-url="<?php echo esc_url( $url ); ?>">
        <?php echo esc_html( $atts['label'] ); ?>
    </button>
    <?php
    return ob_get_clean();
}

/**
 * -------------------------------------------------------------------------
 *  Generic numeric bounds (Woo _price + ACF number fields)
 * -------------------------------------------------------------------------
 */

function ws_wc_filters_get_numeric_bounds( $meta_key, $respect_filters = false, $post_type = 'product' ) {
    static $cache = array();

    $get_hash  = md5( wp_json_encode( $_GET ) );
    $cache_key = ($respect_filters ? 'filtered' : 'global') . '|' . $post_type . '|' . $meta_key . '|' . $get_hash;

    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    $transient_key = 'ws_bounds_' . substr( md5( $cache_key ), 0, 32 );
    $cached = get_transient( $transient_key );
    if ( false !== $cached && is_array( $cached ) ) {
        $cache[ $cache_key ] = $cached;
        return $cached;
    }

    global $wpdb;

    // 1) GLOBAL BOUNDS (no filters)
    if ( ! $respect_filters ) {

        if ( '_price' === $meta_key ) {
            $row = $wpdb->get_row(
                "
                SELECT 
                    MIN(CAST(pm.meta_value AS DECIMAL(20,4))) AS min_val,
                    MAX(CAST(pm.meta_value AS DECIMAL(20,4))) AS max_val
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE 
                    pm.meta_key IN ('_price', '_min_variation_price', '_max_variation_price')
                    AND pm.meta_value <> ''
                    AND p.post_type IN ('product', 'product_variation')
                    AND p.post_status = 'publish'
                "
            );
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "
                    SELECT 
                        MIN(CAST(pm.meta_value AS DECIMAL(20,4))) AS min_val,
                        MAX(CAST(pm.meta_value AS DECIMAL(20,4))) AS max_val
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE 
                        pm.meta_key = %s
                        AND pm.meta_value <> ''
                        AND p.post_type = %s
                        AND p.post_status = 'publish'
                    ",
                    $meta_key,
                    $post_type
                )
            );
        }

        $min = ( $row && null !== $row->min_val ) ? (float) $row->min_val : 0;
        $max = ( $row && null !== $row->max_val ) ? (float) $row->max_val : 1000;

        $result = array(
            'min' => floor( $min ),
            'max' => ceil( $max ),
        );

        $cache[ $cache_key ] = $result;
        set_transient( $transient_key, $result, 10 * MINUTE_IN_SECONDS );

        return $result;
    }

    // 2) FILTERED BOUNDS
    $filters = get_option( 'ws_wc_filters', array() );
    if ( ! is_array( $filters ) ) {
        $filters = array();
    }

    $tax_query  = array();
    $meta_query = array();

    foreach ( $filters as $filter ) {
        if ( empty( $filter['id'] ) ) {
            continue;
        }

        $input_type   = $filter['input_type']   ?? '';
        $field_source = $filter['field_source'] ?? '';
        $field_value  = $filter['field_value']  ?? '';

        $query_arg = ws_wc_filters_get_query_arg_name( $filter );
        if ( '' === $query_arg ) {
            continue;
        }

        if ( 'price_range' === $input_type ) {
            continue;
        }

        if ( ! isset( $_GET[ $query_arg ] ) ) {
            continue;
        }

        $raw_value = wp_unslash( $_GET[ $query_arg ] );
        if ( is_array( $raw_value ) ) {
            $values = array_filter( array_map( 'sanitize_text_field', $raw_value ) );
        } else {
            $values = array_filter( array( sanitize_text_field( $raw_value ) ) );
        }

        if ( empty( $values ) ) {
            continue;
        }

        if ( in_array( $field_source, array( 'category', 'tag', 'attribute', 'brand' ), true ) ) {
            $taxonomy = '';
            if ( ! empty( $field_value ) ) {
                $taxonomy = $field_value;
            } elseif ( 'category' === $field_source ) {
                $taxonomy = 'product_cat';
            } elseif ( 'tag' === $field_source ) {
                $taxonomy = 'product_tag';
            } elseif ( 'brand' === $field_source ) {
                $taxonomy = 'product_brand';
            }

            if ( ! $taxonomy ) {
                continue;
            }

            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $values,
            );

        } elseif ( 'custom_field' === $field_source && ! empty( $field_value ) ) {
            $meta_query[] = array(
                'key'     => $field_value,
                'value'   => ( count( $values ) === 1 ) ? $values[0] : $values,
                'compare' => ( count( $values ) === 1 ) ? '=' : 'IN',
            );
        }
    }

    if ( empty( $tax_query ) && empty( $meta_query ) ) {
        $bounds = ws_wc_filters_get_numeric_bounds( $meta_key, false, $post_type );
        $cache[ $cache_key ] = $bounds;
        set_transient( $transient_key, $bounds, 10 * MINUTE_IN_SECONDS );
        return $bounds;
    }

    $args = array(
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    );

    if ( ! empty( $tax_query ) ) {
        if ( count( $tax_query ) > 1 ) {
            $tax_query['relation'] = 'AND';
        }
        $args['tax_query'] = $tax_query;
    }

    if ( ! empty( $meta_query ) ) {
        if ( count( $meta_query ) > 1 ) {
            $meta_query['relation'] = 'AND';
        }
        $args['meta_query'] = $meta_query;
    }

    $q = new WP_Query( $args );

    if ( empty( $q->posts ) ) {
        $bounds = ws_wc_filters_get_numeric_bounds( $meta_key, false, $post_type );
        $cache[ $cache_key ] = $bounds;
        set_transient( $transient_key, $bounds, 10 * MINUTE_IN_SECONDS );
        return $bounds;
    }

    $min = null;
    $max = null;

    foreach ( $q->posts as $post_id ) {

        if ( '_price' === $meta_key && function_exists( 'wc_get_product' ) && 'product' === $post_type ) {
            $product = wc_get_product( $post_id );
            if ( ! $product ) {
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                $prices = $product->get_variation_prices( true );
                if ( ! empty( $prices['price'] ) ) {
                    $local_min = (float) min( $prices['price'] );
                    $local_max = (float) max( $prices['price'] );
                } else {
                    continue;
                }
            } else {
                $price = $product->get_price();
                if ( '' === $price || null === $price ) {
                    continue;
                }
                $local_min = (float) $price;
                $local_max = (float) $price;
            }

        } else {
            $raw = get_post_meta( $post_id, $meta_key, true );
            if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
                continue;
            }
            $local_min = (float) $raw;
            $local_max = (float) $raw;
        }

        if ( null === $min || $local_min < $min ) {
            $min = $local_min;
        }
        if ( null === $max || $local_max > $max ) {
            $max = $local_max;
        }
    }

    if ( null === $min || null === $max ) {
        $bounds = ws_wc_filters_get_numeric_bounds( $meta_key, false, $post_type );
        $cache[ $cache_key ] = $bounds;
        set_transient( $transient_key, $bounds, 10 * MINUTE_IN_SECONDS );
        return $bounds;
    }

    $result = array(
        'min' => floor( $min ),
        'max' => ceil( $max ),
    );

    $cache[ $cache_key ] = $result;
    set_transient( $transient_key, $result, 10 * MINUTE_IN_SECONDS );

    return $result;
}

/**
 * Backwards-compatible wrapper
 */
function ws_wc_filters_get_price_bounds( $respect_filters = false ) {
    return ws_wc_filters_get_numeric_bounds( '_price', $respect_filters, 'product' );
}

/**
 * Invalidate transient cache when posts are saved/updated
 */
add_action( 'save_post', 'ws_wc_filters_invalidate_cache', 10, 2 );
function ws_wc_filters_invalidate_cache( $post_id, $post ) {
    if ( 'publish' !== $post->post_status ) {
        return;
    }
  
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ws_bounds_%' OR option_name LIKE '_transient_timeout_ws_bounds_%'"
    );
}

/**
 * -------------------------------------------------------------------------
 *  FRONT-END: Sort shortcode
 *  [wc_filter_sort label="Sort" loop_grid_id="elementor-loop-id"]
 * -------------------------------------------------------------------------
 */

add_shortcode( 'wc_filter_sort', 'ws_wc_filter_sort_shortcode' );

function ws_wc_filter_sort_shortcode( $atts ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return '';
    }

    $atts = shortcode_atts(
        array(
            'label'        => __( 'Sort by', 'ws-wc-filters' ),
            'loop_grid_id' => '',
        ),
        $atts,
        'wc_filter_sort'
    );

    $query_arg    = 'wc_sort';
    $current_sort = isset( $_GET[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_GET[ $query_arg ] ) ) : '';

    $current_url = home_url(
        add_query_arg(
            array(),
            wp_unslash( $_SERVER['REQUEST_URI'] )
        )
    );
    $other_args  = $_GET;
    unset( $other_args[ $query_arg ] );

    $loop_target = '';
    if ( ! empty( $atts['loop_grid_id'] ) ) {
        $loop_target = '#' . ltrim( $atts['loop_grid_id'], '#.' );
    }

    ob_start();
    ?>
    <div class="ws-wc-sort-wrapper" <?php if ( $loop_target ) : ?>data-loop-target="<​?php echo esc_attr( $loop_target ); ?>"<?php endif; ?>>
	    <form method="get" class="ws-wc-sort-form ws-wc-filter-form">
	        <?php
	        if ( ! empty( $other_args ) && is_array( $other_args ) ) {
	            foreach ( $other_args as $key => $value ) {
	                // Geen 'continue' meer voor paged, dus alles wordt meegenomen
	                if ( is_array( $value ) ) {
	                    foreach ( $value as $v ) {
	                        printf(
	                            '<input type="hidden" name="%s[]" value="%s" />' . "\n",
	                            esc_attr( $key ),
	                            esc_attr( $v )
	                        );
	                    }
	                } else {
	                    printf(
	                        '<input type="hidden" name="%s" value="%s" />' . "\n",
	                        esc_attr( $key ),
	                        esc_attr( $value )
	                    );
	                }
	            }
	        }
	        ?>

            <label class="ws-wc-filter-label"><?php echo esc_html( $atts['label'] ); ?></label>

            <div class="ws-wc-filter-control">
                <div class="ws-custom-select" data-query-arg="<?php echo esc_attr( $query_arg ); ?>">
                    <input type="hidden"
                           name="<?php echo esc_attr( $query_arg ); ?>"
                           class="ws-custom-select-input"
                           value="<?php echo esc_attr( $current_sort ); ?>" />
                    <button type="button" class="ws-custom-select-toggle">
                        <span class="ws-custom-select-label">
                            <?php
                            switch ( $current_sort ) {
                                case 'price_asc':
                                                                    esc_html_e( 'Price: Low to High', 'ws-wc-filters' );
                                                                    break;
                                                                case 'price_desc':
                                                                    esc_html_e( 'Price: High to Low', 'ws-wc-filters' );
                                                                    break;
                                case 'newest':
                                    esc_html_e( 'Newest', 'ws-wc-filters' );
                                    break;
                                default:
                                    esc_html_e( 'Default', 'ws-wc-filters' );
                                    break;
                            }
                            ?>
                        </span>
                        <span class="ws-custom-select-caret" aria-hidden="true">▾</span>
                    </button>
                    <div class="ws-custom-select-panel">
                        <button type="button" class="ws-custom-select-option" data-value="">
                            <?php esc_html_e( 'Default', 'ws-wc-filters' ); ?>
                        </button>
                        <button type="button" class="ws-custom-select-option" data-value="newest">
                            <?php esc_html_e( 'Newest', 'ws-wc-filters' ); ?>
                        </button>
                        <button type="button" class="ws-custom-select-option" data-value="price_asc">
                            <?php esc_html_e( 'Price: Low to High', 'ws-wc-filters' ); ?>
                        </button>
                        <button type="button" class="ws-custom-select-option" data-value="price_desc">
                            <?php esc_html_e( 'Price: High to Low', 'ws-wc-filters' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * -------------------------------------------------------------------------
 *  ELEMENTOR: Hook Loop Grid query (Query ID = ws_wc_filters)
 * -------------------------------------------------------------------------
 */

add_action( 'elementor/query/ws_wc_filters', 'ws_wc_filters_elementor_query' );

function ws_wc_filters_elementor_query( $query ) {
    $filters = get_option( 'ws_wc_filters', array() );
    if ( ! is_array( $filters ) || empty( $filters ) ) {
        return;
    }

    // Apply filters
    foreach ( $filters as $filter ) {
        if ( empty( $filter['id'] ) ) {
            continue;
        }

$field_source = $filter['field_source'] ?? '';
$field_value  = $filter['field_value']  ?? '';
$input_type   = $filter['input_type']   ?? '';

$query_arg = ws_wc_filters_get_query_arg_name( $filter );
if ( '' === $query_arg ) {
    continue;
}

    // Price range
                if ( 'price_range' === $input_type ) {
                    $min_arg = $query_arg . '_min';
                    $max_arg = $query_arg . '_max';
              
                    $min_val = isset( $_GET[ $min_arg ] ) ? (float) wp_unslash( $_GET[ $min_arg ] ) : null;
                    $max_val = isset( $_GET[ $max_arg ] ) ? (float) wp_unslash( $_GET[ $max_arg ] ) : null;
              
                    if ( null !== $min_val || null !== $max_val ) {
                        $meta_query = $query->get( 'meta_query' );
                        if ( ! is_array( $meta_query ) ) {
                            $meta_query = array();
                        }
              
                        $range_meta_key = ! empty( $field_value ) ? $field_value : '_price';
              
                        $range = array(
                            'key'  => $range_meta_key,
                            'type' => 'NUMERIC',
                        );
              
                        if ( null !== $min_val && null !== $max_val ) {
                            $range['value']   = array( $min_val, $max_val );
                            $range['compare'] = 'BETWEEN';
                        } elseif ( null !== $min_val ) {
                            $range['value']   = $min_val;
                            $range['compare'] = '>=';
                        } else {
                            $range['value']   = $max_val;
                            $range['compare'] = '<=';
                        }
              
                        $meta_query[] = $range;
                        $query->set( 'meta_query', $meta_query );
                    }
              
                    continue;
                }


        // Other filters (taxonomy/custom field)
        if ( ! isset( $_GET[ $query_arg ] ) ) {
            continue;
        }

        $raw_value = wp_unslash( $_GET[ $query_arg ] );
        if ( is_array( $raw_value ) ) {
            $values = array_filter( array_map( 'sanitize_text_field', $raw_value ) );
        } else {
            $values = array_filter( array( sanitize_text_field( $raw_value ) ) );
        }

        if ( empty( $values ) ) {
            continue;
        }

        if ( in_array( $field_source, array( 'category', 'tag', 'attribute', 'brand' ), true ) ) {
            $taxonomy = '';
            if ( ! empty( $field_value ) ) {
                $taxonomy = $field_value;
            } elseif ( 'category' === $field_source ) {
                $taxonomy = 'product_cat';
            } elseif ( 'tag' === $field_source ) {
                $taxonomy = 'product_tag';
            } elseif ( 'brand' === $field_source ) {
                $taxonomy = 'product_brand';
            }

            if ( ! $taxonomy ) {
                continue;
            }

            $tax_query = $query->get( 'tax_query' );
            if ( ! is_array( $tax_query ) ) {
                $tax_query = array();
            }

            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $values,
            );

            $query->set( 'tax_query', $tax_query );

        } elseif ( 'custom_field' === $field_source && ! empty( $field_value ) ) {
            $meta_query = $query->get( 'meta_query' );
            if ( ! is_array( $meta_query ) ) {
                $meta_query = array();
            }

            $meta_query[] = array(
                'key'     => $field_value,
                'value'   => ( count( $values ) === 1 ) ? $values[0] : $values,
                'compare' => ( count( $values ) === 1 ) ? '=' : 'IN',
            );

            $query->set( 'meta_query', $meta_query );
        }
    }

    // Sorting via wc_sort
    $sort = isset( $_GET['wc_sort'] ) ? sanitize_text_field( wp_unslash( $_GET['wc_sort'] ) ) : '';

    switch ( $sort ) {
        case 'price_asc':
            $query->set( 'meta_key', '_price' );
            $query->set( 'orderby', 'meta_value_num' );
            $query->set( 'order', 'ASC' );
            break;

        case 'price_desc':
            $query->set( 'meta_key', '_price' );
            $query->set( 'orderby', 'meta_value_num' );
            $query->set( 'order', 'DESC' );
            break;

        case 'newest':
            $query->set( 'orderby', 'date' );
            $query->set( 'order', 'DESC' );
            break;

        default:
            // leave Elementor defaults
            break;
    }

    // === NIEUW: Paginering en aantal posts per pagina ===
    $paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
    $query->set( 'paged', $paged );
    $query->set( 'posts_per_page', 6 );
}