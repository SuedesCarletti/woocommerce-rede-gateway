<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Rede_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_rede_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( 'toplevel_page_rede-gateway-settings' !== $hook ) {
            return;
        }
        wp_enqueue_script( 'rede-admin-js', plugin_dir_url( dirname(__FILE__) ) . 'admin/js/rede-admin.js', array( 'jquery' ), '1.0.0', true );
    }

    public function ajax_test_connection() {
        // Obter credenciais do gateway
        $options = get_option( 'woocommerce_rede_credit_card_settings' );
        if ( empty( $options['pv'] ) || empty( $options['token'] ) ) {
            wp_send_json_error( array( 'message' => 'Credenciais (PV e Token) não configuradas no Gateway.' ) );
        }

        $pv = trim( $options['pv'] );
        $token = trim( $options['token'] );

        $auth = base64_encode( $pv . ':' . $token );
        
        $base_url = ( isset($options['sandbox']) && $options['sandbox'] === 'yes' ) 
            ? 'https://sandbox-erede.useredecloud.com.br/v1/' 
            : 'https://api.userede.com.br/erede/v1/';
            
        $url  = $base_url . 'transactions'; // POST vazio para forçar validação de auth

        $response = wp_remote_post( $url, array(
            'body'    => '{}',
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $auth
            )
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Falha HTTP: ' . $response->get_error_message() ) );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );

        if ( $status === 200 || $status === 404 || $status === 400 ) { 
            if ( $status === 401 ) {
                wp_send_json_error( array( 'message' => 'Autenticação Recusada (HTTP 401). Verifique se o PV e Token estão corretos e correspondem ao ambiente.' ) );
            }
            wp_send_json_success( array( 'message' => 'Conexão estabelecida com sucesso! Código HTTP: ' . $status ) );
        } else {
            wp_send_json_error( array( 'message' => 'Erro Inesperado. Código HTTP: ' . $status . ' - ' . $body ) );
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'Rede (Itaú) Gateway',
            'Gateway Rede',
            'manage_woocommerce',
            'rede-gateway-settings',
            array( $this, 'render_admin_page' ),
            'dashicons-money-alt',
            56
        );
    }

    public function register_settings() {
        register_setting( 'rede_gateway_settings_group', 'rede_sankhya_mappings' );
    }

    public function render_admin_page() {
        $mappings = get_option( 'rede_sankhya_mappings', array() );
        
        ?>
        <div class="wrap">
            <h1>Configurações Avançadas - Rede (Itaú)</h1>
            <p>Gerencie o mapeamento de Tipos de Negociação do Sankhya e realize testes de diagnóstico.</p>

            <form method="post" action="options.php" id="rede-admin-form">
                <?php settings_fields( 'rede_gateway_settings_group' ); ?>
                
                <div class="section-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">
                    <h3>📊 Mapeamentos Sankhya Configurados</h3>
                    
                    <table class="wp-list-table widefat fixed striped table-view-list mapping-table">
                        <thead>
                            <tr>
                                <th>Método de Pagamento</th>
                                <th>Parcelas</th>
                                <th>Tipo de Negociação</th>
                                <th style="width: 100px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="mappings-tbody">
                            <?php 
                            if ( ! empty( $mappings ) ) {
                                foreach ( $mappings as $key => $val ) {
                                    $parts = explode( '_', $key );
                                    $method = $parts[0] === 'credit' ? 'credit_card' : $parts[0];
                                    $inst = end($parts);
                                    
                                    $labelMethod = '💳 Cartão';
                                    if(strpos($key, 'boleto') !== false) $labelMethod = '📄 Boleto';
                                    if(strpos($key, 'pix') !== false) $labelMethod = '💠 PIX';

                                    $labelInstal = $inst == '0' ? '-' : $inst . 'x';
                                    ?>
                                    <tr data-key="<?php echo esc_attr($key); ?>">
                                        <td><?php echo $labelMethod; ?></td>
                                        <td><?php echo $labelInstal; ?></td>
                                        <td><input type="text" name="rede_sankhya_mappings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>" /></td>
                                        <td><button type="button" class="button button-small button-delete delete-mapping" data-key="<?php echo esc_attr($key); ?>">Remover</button></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                    <br>
                    <button type="button" class="button" id="btn-add-mapping">Adicionar Mapeamento</button>
                </div>

                <div class="section-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">
                    <h3>🔧 Teste de Conexão</h3>
                    <p>Valide se as credenciais configuradas na aba de pagamentos estão se comunicando com a Rede Itaú.</p>
                    <input type="hidden" id="rede_admin_nonce" value="<?php echo wp_create_nonce('rede_test_connection'); ?>" />
                    <button type="button" class="button button-primary" id="btn-teste-conexao">Executar Teste de API</button>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
new WC_Rede_Admin();
