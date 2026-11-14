<?php
/**
 * Plugin Name: Rede (Itaú) WooCommerce Gateway
 * Plugin URI: https://github.com/SuedesCarletti/woocommerce-rede-gateway
 * Description: Gateway de Pagamento da Rede (Itaú) para WooCommerce.
 * Version:     1.1.4
 * Author: Suedes Carletti
 * License: Todos os direitos reservados / All rights reserved
 * Text Domain: rede-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Usada para cache-busting do CSS/JS do checkout (query string ?ver=).
// Antes deste redesign, payment_scripts() não passava nenhuma versão pro
// wp_enqueue_style (o que faz o WordPress cair no fallback do WP_VERSION,
// praticamente estático entre deploys) e o wp_enqueue_script usava um
// '1.0.0' hardcoded que nunca mudava — então navegadores/CDN podiam
// continuar servindo a versão antiga do CSS/JS mesmo depois de o PHP já
// estar atualizado. Suba este valor a cada mudança visual relevante.
define( 'REDE_GATEWAY_VERSION', '1.1.4' );

// Garante que o WooCommerce está ativo antes de inicializar o plugin
add_action( 'plugins_loaded', 'rede_woocommerce_init', 11 );

function rede_woocommerce_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    // Incluir as classes do Gateway
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-rede-logger.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-rede-gateway.php';
    
    if ( is_admin() ) {
        require_once plugin_dir_path( __FILE__ ) . 'admin/class-wc-rede-admin.php';
    }

    // Registrar o Gateway no WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'rede_woocommerce_add_gateway' );
}

function rede_woocommerce_add_gateway( $gateways ) {
    $gateways[] = 'WC_Rede_Gateway';
    return $gateways;
}
