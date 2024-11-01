<?php

/**
 * Plugin Name: Платежка для WooCommerce
 * Plugin URI: https://wordpress.org/plugins/yandex-billing-for-woocommerce/
 * Description: Платежный модуль для работы с сервисом Платёжка через плагин WooCommerce
 * Version: 0.0.3
 * Author: Yandex.Money
 * Author URI: http://kassa.yandex.ru
 * License URI: https://money.yandex.ru/doc.xml?id=527132
 *
 * Text Domain: ym-billing
 * Domain Path: /languages
 */


if (!defined('ABSPATH')) {
    exit;
}

define('YM_BILLING_VERSION', '0.0.3');

add_action('plugins_loaded', 'init_wc_gateway_ym_billing_class');

add_filter('woocommerce_payment_gateways', 'add_wc_gateway_ym_billing_class');
add_filter('woocommerce_available_payment_gateways', 'add_wc_gateway_ym_billing_gateway');

function add_wc_gateway_ym_billing_class($methods)
{
    $methods[] = 'WC_Gateway_Ym_Billing';
    load_plugin_textdomain('ym-billing', false, basename(dirname(__FILE__)) . '/languages/');
    return $methods;
}

function add_wc_gateway_ym_billing_gateway($gateways)
{
    if (isset($gateways['ym-billing'])) {
        $gateways['ym-billing']->icon = plugins_url('images/', __FILE__).'yandex_money.png';
    }
    return $gateways;
}

function init_wc_gateway_ym_billing_class()
{
    if (!class_exists('WC_Gateway_Ym_Billing', false)) {
        require dirname(__FILE__).'/ym-billing-gateway.class.php';
    }
}

