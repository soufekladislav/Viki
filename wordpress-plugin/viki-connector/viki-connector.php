<?php
/**
 * Plugin Name: Viki Connector
 * Description: Zabezpečený stránkovaný import a synchronizace simple i variable produktů mezi Viki a WooCommerce.
 * Version: 0.8.0
 * Author: Fresh Crackers
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) { exit; }

final class Viki_Woo_Connector
{
    private const OPTION_TOKEN = 'feedhub_connector_token';
    private const REST_NAMESPACE = 'feedhub-connector/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_menu', [self::class, 'register_admin_page']);
        add_action('admin_init', [self::class, 'ensure_token']);
    }

    public static function activate(): void { self::ensure_token(); }
    public static function ensure_token(): void
    {
        if (!get_option(self::OPTION_TOKEN)) {
            update_option(self::OPTION_TOKEN, wp_generate_password(48, false, false), false);
        }
    }

    public static function register_admin_page(): void
    {
        add_menu_page('Viki Connector', 'Viki Connector', 'manage_woocommerce', 'viki-connector', [self::class, 'admin_page'], 'dashicons-products');
    }

    public static function admin_page(): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        if (isset($_POST['viki_regenerate_token']) && check_admin_referer('viki_regenerate_token')) {
            update_option(self::OPTION_TOKEN, wp_generate_password(48, false, false), false);
            echo '<div class="notice notice-success"><p>Token byl změněn. Aktualizuj ho také ve Viki.</p></div>';
        }
        $token = esc_html((string) get_option(self::OPTION_TOKEN, ''));
        ?>
        <div class="wrap">
          <h1>Viki Connector 0.8</h1>
          <p>Stránkovaný export a zápis jednoduchých i variantních produktů.</p>
          <table class="widefat striped" style="max-width:900px"><tbody>
            <tr><td style="width:220px"><strong>Token</strong></td><td><code><?php echo $token; ?></code></td></tr>
            <tr><td><strong>Adresa webu</strong></td><td><code><?php echo esc_html(home_url('/')); ?></code></td></tr>
            <tr><td><strong>Export</strong></td><td><code><?php echo esc_url(rest_url(self::REST_NAMESPACE . '/export')); ?></code></td></tr>
          </tbody></table>
          <p>Token posílej výhradně v hlavičce <code>X-FeedHub-Token</code>.</p>
          <form method="post"><?php wp_nonce_field('viki_regenerate_token'); ?><button class="button" name="viki_regenerate_token">Vygenerovat nový token</button></form>
        </div>
        <?php
    }

    public static function register_routes(): void
    {
        foreach ([['/health', 'GET', 'health'], ['/export', 'GET', 'export'], ['/product/upsert', 'POST', 'product_upsert']] as [$path, $method, $callback]) {
            register_rest_route(self::REST_NAMESPACE, $path, [
                'methods' => $method,
                'callback' => [self::class, $callback],
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public static function health(WP_REST_Request $request): WP_REST_Response
    {
        if (!self::authorized($request)) { return self::error('Unauthorized', 401); }
        return self::ok(['site_name' => get_bloginfo('name'), 'home_url' => home_url('/'), 'wp_version' => get_bloginfo('version'), 'woo_active' => class_exists('WooCommerce'), 'connector_version' => '0.8.0']);
    }

    public static function export(WP_REST_Request $request): WP_REST_Response
    {
        if (!self::authorized($request)) { return self::error('Unauthorized', 401); }
        if (!class_exists('WooCommerce')) { return self::error('WooCommerce není aktivní.', 422); }
        $page = max(1, (int) $request->get_param('page'));
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?: 100)));
        $result = self::collect_products($page, $perPage);
        $categories = self::collect_categories();
        return self::ok([
            'generated_at' => current_time('mysql'),
            'site' => ['name' => get_bloginfo('name'), 'home_url' => home_url('/'), 'wp_version' => get_bloginfo('version'), 'woo_version' => defined('WC_VERSION') ? WC_VERSION : null, 'language' => get_locale(), 'currency' => get_woocommerce_currency()],
            'counts' => ['categories' => count($categories), 'products' => $result['total']],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'pages' => $result['pages'], 'total' => $result['total']],
            'categories' => $categories,
            'products' => $result['items'],
        ]);
    }

    public static function product_upsert(WP_REST_Request $request): WP_REST_Response
    {
        if (!self::authorized($request)) { return self::error('Unauthorized', 401); }
        if (!class_exists('WooCommerce')) { return self::error('WooCommerce není aktivní.', 422); }
        $payload = (array) $request->get_json_params();
        $name = trim((string) ($payload['name'] ?? ''));
        $type = (string) ($payload['type'] ?? 'simple');
        if ($name === '' || !in_array($type, ['simple', 'variable'], true)) { return self::error('Neplatný název nebo typ produktu.', 422); }
        try {
            $categoryMappings = self::ensure_categories((array) ($payload['categories'] ?? []));
            $existing = self::resolve_product($payload);
            $action = $existing ? 'updated' : 'created';
            $product = self::product_for_type($existing, $type);
            self::apply_product($product, $payload, $name, $type);
            $productId = $product->save();
            if (!$productId) { throw new RuntimeException('Produkt se nepodařilo uložit.'); }
            $termIds = array_values(array_filter(array_map(static fn(array $item): int => (int) ($item['woo_term_id'] ?? 0), $categoryMappings)));
            wp_set_object_terms($productId, $termIds, 'product_cat', false);
            $variationMappings = [];
            if ($type === 'variable') {
                $variationMappings = self::sync_variations((int) $productId, (array) ($payload['variants'] ?? []));
                WC_Product_Variable::sync((int) $productId);
            }
            return self::ok(['woo_product_id' => (int) $productId, 'woo_parent_id' => null, 'action' => $action, 'category_mappings' => $categoryMappings, 'variation_mappings' => $variationMappings]);
        } catch (Throwable $e) {
            return self::error($e->getMessage(), 500);
        }
    }

    private static function authorized(WP_REST_Request $request): bool
    {
        $token = (string) $request->get_header('x-feedhub-token');
        $saved = (string) get_option(self::OPTION_TOKEN, '');
        return $token !== '' && $saved !== '' && hash_equals($saved, $token);
    }

    private static function collect_categories(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms) || !is_array($terms)) { return []; }
        return array_map(static function (WP_Term $term): array {
            $thumb = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
            return ['external_id' => (int) $term->term_id, 'parent_id' => (int) $term->parent, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'description' => (string) $term->description, 'sort_order' => 0, 'image_url' => $thumb ? wp_get_attachment_url($thumb) : null, 'is_active' => true];
        }, $terms);
    }

    private static function collect_products(int $page, int $perPage): array
    {
        $query = new WC_Product_Query(['status' => array_keys((array) wc_get_product_statuses()), 'limit' => $perPage, 'page' => $page, 'paginate' => true, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']);
        $result = $query->get_products();
        $ids = is_object($result) ? (array) ($result->products ?? []) : (array) $result;
        $items = [];
        foreach ($ids as $productId) {
            $product = wc_get_product((int) $productId);
            if (!$product) { continue; }
            $gallery = array_values(array_filter(array_map('wp_get_attachment_url', $product->get_gallery_image_ids())));
            $variants = [];
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $childId) {
                    $variation = wc_get_product((int) $childId);
                    if (!$variation) { continue; }
                    $variants[] = ['external_id' => (int) $variation->get_id(), 'sku' => (string) $variation->get_sku(), 'name' => (string) $variation->get_name(), 'regular_price' => $variation->get_regular_price(), 'sale_price' => $variation->get_sale_price(), 'stock_quantity' => $variation->get_stock_quantity(), 'stock_status' => (string) $variation->get_stock_status(), 'attributes' => $variation->get_attributes(), 'is_active' => $variation->get_status() !== 'trash'];
                }
            }
            $imageId = $product->get_image_id();
            $items[] = ['external_id' => (int) $product->get_id(), 'updated_at' => $product->get_date_modified() ? $product->get_date_modified()->date('c') : null, 'sku' => (string) $product->get_sku(), 'name' => (string) $product->get_name(), 'slug' => (string) $product->get_slug(), 'type' => (string) $product->get_type(), 'status' => (string) $product->get_status(), 'short_description' => (string) $product->get_short_description(), 'description' => (string) $product->get_description(), 'regular_price' => $product->get_regular_price(), 'sale_price' => $product->get_sale_price(), 'stock_quantity' => $product->get_stock_quantity(), 'stock_status' => (string) $product->get_stock_status(), 'manage_stock' => (bool) $product->get_manage_stock(), 'image_url' => $imageId ? wp_get_attachment_url($imageId) : null, 'gallery_images' => $gallery, 'category_ids' => array_map('intval', wc_get_product_term_ids($product->get_id(), 'product_cat')), 'attributes' => self::export_attributes($product), 'variants' => $variants, 'is_active' => $product->get_status() !== 'trash'];
        }
        return ['items' => $items, 'total' => is_object($result) ? (int) ($result->total ?? count($items)) : count($items), 'pages' => max(1, is_object($result) ? (int) ($result->max_num_pages ?? 1) : 1)];
    }

    private static function export_attributes(WC_Product $product): array
    {
        $items = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute) { continue; }
            $options = $attribute->is_taxonomy() ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']) : $attribute->get_options();
            $items[] = ['name' => wc_attribute_label($attribute->get_name()), 'slug' => $attribute->get_name(), 'options' => array_values($options), 'variation' => $attribute->get_variation()];
        }
        return $items;
    }

    private static function product_for_type(?WC_Product $existing, string $type): WC_Product
    {
        if ($existing && $existing->get_type() === $type) { return $existing; }
        $id = $existing ? $existing->get_id() : 0;
        if ($id) { wp_set_object_terms($id, $type, 'product_type'); }
        return $type === 'variable' ? new WC_Product_Variable($id) : new WC_Product_Simple($id);
    }

    private static function apply_product(WC_Product $product, array $payload, string $name, string $type): void
    {
        $product->set_name($name); $product->set_slug(self::safe_slug((string) ($payload['slug'] ?? $name))); $product->set_status(self::status((string) ($payload['status'] ?? 'draft')));
        $product->set_description(wp_kses_post((string) ($payload['description'] ?? ''))); $product->set_short_description(wp_kses_post((string) ($payload['short_description'] ?? '')));
        $sku = trim((string) ($payload['sku'] ?? '')); if ($sku !== '' && $product->get_sku() !== $sku) { $product->set_sku($sku); }
        if ($type === 'simple') {
            $regular = self::price($payload['regular_price'] ?? null); $sale = self::price($payload['sale_price'] ?? null); $product->set_regular_price($regular); $product->set_sale_price($sale); $product->set_price($sale !== '' ? $sale : $regular);
            $manage = array_key_exists('manage_stock', $payload) ? (bool) $payload['manage_stock'] : true; $product->set_manage_stock($manage); $product->set_stock_quantity($manage ? self::stock($payload['stock_quantity'] ?? null) : null); $product->set_stock_status(self::stock_status((string) ($payload['stock_status'] ?? 'instock')));
        } else { $product->set_attributes(self::build_attributes($payload)); }
    }

    private static function build_attributes(array $payload): array
    {
        $raw = (array) ($payload['attributes'] ?? []); $normalized = [];
        if (isset($raw['attr1Name'])) {
            foreach ([1, 2] as $number) { $name = trim((string) ($raw['attr' . $number . 'Name'] ?? '')); $values = array_values(array_filter(array_map('trim', explode(',', (string) ($raw['attr' . $number . 'Values'] ?? ''))))); if ($name !== '' && $values !== []) { $normalized[] = ['name' => $name, 'options' => $values]; } }
        } else { $normalized = array_values($raw); }
        $result = [];
        foreach ($normalized as $index => $item) { if (!is_array($item)) { continue; } $name = trim((string) ($item['name'] ?? $item['slug'] ?? '')); $options = array_values((array) ($item['options'] ?? [])); if ($name === '' || $options === []) { continue; } $attribute = new WC_Product_Attribute(); $attribute->set_id(0); $attribute->set_name($name); $attribute->set_options($options); $attribute->set_position($index); $attribute->set_visible(true); $attribute->set_variation(true); $result[] = $attribute; }
        return $result;
    }

    private static function sync_variations(int $parentId, array $variants): array
    {
        $mappings = [];
        foreach (array_values($variants) as $variantData) {
            if (!is_array($variantData)) { continue; }
            $variation = null; $externalId = (int) ($variantData['external_id'] ?? 0);
            if ($externalId > 0) { $candidate = wc_get_product($externalId); if ($candidate instanceof WC_Product_Variation && $candidate->get_parent_id() === $parentId) { $variation = $candidate; } }
            $sku = trim((string) ($variantData['sku'] ?? ''));
            if (!$variation && $sku !== '') { $id = wc_get_product_id_by_sku($sku); $candidate = $id ? wc_get_product($id) : null; if ($candidate instanceof WC_Product_Variation && $candidate->get_parent_id() === $parentId) { $variation = $candidate; } }
            $variation = $variation ?: new WC_Product_Variation(); $variation->set_parent_id($parentId); if ($sku !== '' && $variation->get_sku() !== $sku) { $variation->set_sku($sku); }
            $variation->set_status(!empty($variantData['is_active']) || !array_key_exists('is_active', $variantData) ? 'publish' : 'private'); $variation->set_regular_price(self::price($variantData['regular_price'] ?? $variantData['price'] ?? null)); $variation->set_sale_price(self::price($variantData['sale_price'] ?? null)); $variation->set_manage_stock(true); $variation->set_stock_quantity(self::stock($variantData['stock'] ?? $variantData['stock_quantity'] ?? null)); $variation->set_stock_status(self::stock_status((string) ($variantData['stock_status'] ?? 'instock')));
            $attributes = []; foreach ((array) ($variantData['attributes'] ?? []) as $key => $value) { $attributes[sanitize_title((string) $key)] = (string) $value; } $variation->set_attributes($attributes); $id = $variation->save();
            $mappings[] = ['app_external_id' => $externalId ?: null, 'woo_variation_id' => (int) $id, 'sku' => $sku];
        }
        return $mappings;
    }

    private static function resolve_product(array $payload): ?WC_Product
    {
        $id = (int) ($payload['woo_product_id'] ?? 0); if ($id > 0) { $product = wc_get_product($id); if ($product instanceof WC_Product) { return $product; } }
        $sku = trim((string) ($payload['sku'] ?? '')); if ($sku !== '') { $id = wc_get_product_id_by_sku($sku); $product = $id ? wc_get_product($id) : null; if ($product instanceof WC_Product && !$product->is_type('variation')) { return $product; } }
        return null;
    }

    private static function ensure_categories(array $categories): array
    {
        $resolved = [];
        foreach ($categories as $item) { if (!is_array($item)) { continue; } $appId = (int) ($item['app_category_id'] ?? 0); $termId = (int) ($item['woo_term_id'] ?? 0); $term = $termId ? get_term($termId, 'product_cat') : null; $name = trim((string) ($item['name'] ?? '')); if (!$term || is_wp_error($term)) { $term = get_term_by('slug', self::safe_slug((string) ($item['slug'] ?? $name)), 'product_cat'); } if (!$term && $name !== '') { $created = wp_insert_term($name, 'product_cat', ['slug' => self::safe_slug((string) ($item['slug'] ?? $name))]); if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); } $term = get_term((int) $created['term_id'], 'product_cat'); } if ($term instanceof WP_Term) { $resolved[] = ['app_category_id' => $appId, 'woo_term_id' => (int) $term->term_id, 'woo_parent_term_id' => (int) $term->parent ?: null, 'name' => $term->name, 'slug' => $term->slug]; } }
        return $resolved;
    }

    private static function ok(array $data): WP_REST_Response { return new WP_REST_Response(['success' => true, 'data' => $data], 200); }
    private static function error(string $message, int $status): WP_REST_Response { return new WP_REST_Response(['success' => false, 'message' => $message], $status); }
    private static function safe_slug(string $value): string { $slug = sanitize_title($value); return $slug !== '' ? $slug : 'item-' . wp_generate_password(8, false, false); }
    private static function status(string $value): string { return in_array($value, ['publish', 'draft', 'private', 'pending'], true) ? $value : 'draft'; }
    private static function stock_status(string $value): string { return in_array($value, ['instock', 'outofstock', 'onbackorder'], true) ? $value : 'instock'; }
    private static function price($value): string { return $value === null || trim((string) $value) === '' ? '' : wc_format_decimal($value); }
    private static function stock($value): ?int { return $value === null || $value === '' ? null : (int) $value; }
}

register_activation_hook(__FILE__, ['Viki_Woo_Connector', 'activate']);
Viki_Woo_Connector::init();
