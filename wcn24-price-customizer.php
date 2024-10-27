<?php

/**
* WCN WooCommerce Price Customizer
*
* @package   WCN_WooCommerce_Price_Customizer
* @author    Tabi Idris <tabidrizy@gmail.com>
* @license   GPL-2.0+
* @link
* @copyright 2024 Tabi Idris, WCN24
*
* @wordpress-plugin
* Plugin Name:       WCN WooCommerce Price Customizer
* Plugin URI:
* Description:       A plugin to customize the WooCommerce price display based on conditions like customer roles, product attributes, or location.
* Version:           1.0.0
* Author:            Tabi Idris
* Author URI:        https://github.com/DriZy
* Text Domain:       wn-woocommerce-price-customizer
* License:           GPL-2.0+
* License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
* Domain Path:       /languages
* GitHub Plugin URI:
* GitHub Branch:     master
*/

// If this file is called directly, abort.
defined('ABSPATH') || exit;

define('WCN_VERSION', '1.0.0');
define('WCN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCN_TEXT_DOMAIN', 'wcn');

use GeoIp2\Database\Reader;

class WCN_Custom_WooCommerce_Price_Display
{

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'wcn_activate']);
        register_deactivation_hook(__FILE__, [$this, 'wcn_deactivate']);

        // Hooks
        add_action('admin_menu', [$this, 'wcn_add_settings_page']);
        add_action('admin_init', [$this, 'wcn_register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'wcn_enqueue_styles']);

        add_filter('woocommerce_get_price_html', [$this, 'wcn_custom_price_display'], 100, 2);
        add_filter('woocommerce_currency', [$this, 'wcn_change_currency_by_location'], 10);
        add_filter('woocommerce_currency_symbol', [$this, 'wcn_change_currency_symbol_by_location'], 10, 2);


    }



    public function wcn_activate()
    {
        // Check if WooCommerce is active
        if (!is_plugin_active('woocommerce/woocommerce.php')) {
            deactivate_plugins(WCN_PLUGIN_DIR);
            wp_die('This plugin requires WooCommerce to be installed and activated.');
        }
    }

    public function wcn_deactivate()
    {
        error_log('WCN WooCommerce Price Customizer plugin deactivated.');
    }

    public function wcn_enqueue_styles()
    {
        wp_enqueue_style(
            'wcn-price-customizer-style',
            WCN_PLUGIN_URL . 'assets/css/wcn-styles.css',
            [],
            WCN_VERSION
        );
    }

    public function wcn_add_settings_page()
    {
        add_menu_page(
            __('WCN Price Customizer', WCN_TEXT_DOMAIN),
            __('WCN Price Customizer', WCN_TEXT_DOMAIN),
            'manage_options',
            'wcn_settings_page',
            [$this, 'wcn_settings_page_callback']
        );
    }
    public function wcn_register_settings()
    {
        register_setting('wcn_settings_group', 'wcn_settings', [$this, 'wcn_validate_settings']);
        add_settings_section('wcn_settings_section', 'Price Customization Settings', [$this, 'wcn_settings_section_callback'], 'wcn_settings_page');
//        add_settings_field('wcn_settings_field', 'Custom Price Settings', [$this, 'wcn_settings_field_callback'], 'wcn_settings_page', 'wcn_settings_section');
        add_settings_field('wcn_price_display_style', 'Price Display Style', [$this, 'wcn_price_display_style_callback'], 'wcn_settings_page', 'wcn_settings_section');
    }

    public function wcn_settings_page_callback() {
        ?>
        <div class="wrap">
            <h1><?php _e('WCN Price Customizer Settings', WCN_TEXT_DOMAIN); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wcn_settings_group');
                do_settings_sections('wcn_settings_page');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function wcn_settings_section_callback() {
        echo '<p>' . __('Customize the WooCommerce price display based on roles, location, etc.', WCN_TEXT_DOMAIN) . '</p>';
    }

    public function wcn_custom_price_display($price, $product)
    {
        $wcn_settings = get_option('wcn_settings');
        $selected_style = isset($wcn_settings['price_display_style']) ? $wcn_settings['price_display_style'] : 'default';
        $original_price = floatval($product->get_price());
        $discounted_price = $original_price * 0.85; // 15% discount
        $discount_percentage = 15;
        $formatted_price = $price;

        switch ($selected_style) {
            case 'discounted':
                $formatted_price = '<span class="original-price">' . wc_price($original_price) . '</span><br>';
                $formatted_price .= '<span class="discounted-price">' . wc_price($discounted_price) . '</span><br>';
                $formatted_price .= '<span class="discount-info">' . sprintf(__('You save %d%%!', WCN_TEXT_DOMAIN), $discount_percentage) . '</span>';
                break;

            case 'strikethrough':
                $formatted_price = '<span class="original-price"><s>' . wc_price($original_price) . '</s></span> ' . $price;
                break;

            case 'default':
            default:
                $formatted_price = $price;
                break;
        }

        return $formatted_price;
    }

    public function wcn_price_display_style_callback()
    {
        $wcn_settings = get_option('wcn_settings');
        $selected_style = isset($wcn_settings['price_display_style']) ? $wcn_settings['price_display_style'] : 'default';

        echo '<input type="radio" name="wcn_settings[price_display_style]" value="default" ' . checked('default', $selected_style, false) . ' /> Default<br />';
        echo '<input type="radio" name="wcn_settings[price_display_style]" value="discounted" ' . checked('discounted', $selected_style, false) . ' /> Discounted<br />';
        echo '<input type="radio" name="wcn_settings[price_display_style]" value="strikethrough" ' . checked('strikethrough', $selected_style, false) . ' /> Strikethrough with Original Price<br />';
    }

    public function wcn_validate_settings($input)
    {
        // Validate the display style
        if (!in_array($input['price_display_style'], ['default', 'discounted', 'strikethrough'])) {
            $input['price_display_style'] = 'default';
        }

        return $input;
    }

    // Step 2: Get the user's location
    public function wcn_get_user_location() {
        try {
            if (class_exists('WC_Geolocation')) {
                // Get the user's IP address and country using WooCommerce's built-in Geolocation
                $geolocation = new WC_Geolocation();
                $user_ip = $geolocation->get_ip_address();
                $location = $geolocation->geolocate_ip($user_ip);

                $wcn_location = [
                    'country' => $location['country'],
                    'city'    => $location['city'] ?? 'Unknown', // GeoIP might not return city info
                ];

                error_log('User location detected by WooCommerce GeoIP: ' . json_encode($wcn_location));
                return $wcn_location;
            } else {
                error_log('WC_Geolocation class not found.');
                return ['country' => 'US', 'city' => 'Unknown'];
            }
        } catch (Exception $e) {
            error_log('Error detecting user location: ' . $e->getMessage());
//            return ['country' => 'US', 'city' => 'Unknown'];
        }
    }

    public function wcn_change_currency_by_location($currency) {
        $wcn_location = $this->wcn_get_user_location();

        // Example: Change currency to EUR if the user is from Germany
        if ($wcn_location['country'] === 'DE') {
            return 'EUR';
        } elseif ($wcn_location['country'] === 'GB') {
            return 'GBP';
        } else {
            return $currency; // Default WooCommerce currency
        }
    }

    public function wcn_change_currency_symbol_by_location($currency_symbol, $currency) {
        $location = $this->wcn_get_user_location();

        // Change the currency symbol based on the detected country
        switch ($currency) {
            case 'EUR':
                $currency_symbol = '€';
                break;
            case 'GBP':
                $currency_symbol = '£';
                break;
            case 'USD':
            default:
                $currency_symbol = '$';
                break;
        }

        return $currency_symbol;
    }


}

// Initialize the plugin
new WCN_Custom_WooCommerce_Price_Display();