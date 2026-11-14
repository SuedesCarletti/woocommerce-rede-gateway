<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Rede_Gateway extends WC_Payment_Gateway {

    // Tempo (em minutos) que o cliente tem para pagar o PIX antes do cancelamento.
    const PIX_EXPIRATION_MINUTES = 30;

    public function __construct() {
        $this->id                 = 'rede_credit_card';
        $this->icon               = ''; // Opcional: URL da logo da Rede
        $this->has_fields         = true; // Indica que teremos campos no checkout
        $this->method_title       = 'Rede - Cartão de Crédito';
        $this->method_description = 'Receba pagamentos por cartão de crédito via Rede no seu WooCommerce.';

        // Carregar configurações
        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->pv          = $this->get_option( 'pv' );
        $this->token       = $this->get_option( 'token' );
        $this->sandbox     = 'yes' === $this->get_option( 'sandbox' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'woocommerce_thankyou', array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_view_order', array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_order_details_before_order_table', array( $this, 'thankyou_page' ) );
        add_action( 'rede_pix_cancel_unpaid_order', array( $this, 'cancel_expired_pix_order' ) );
    }

    /**
     * Cancela o pedido se o PIX não foi pago dentro do prazo (30 min).
     * Chamado pelo WP-Cron e, como garantia, ao abrir a página do pedido.
     */
    public function cancel_expired_pix_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->has_status( 'on-hold' ) ) {
            return;
        }

        $expires_at = (int) $order->get_meta( '_rede_pix_expires_at' );
        if ( ! $expires_at || time() < $expires_at ) {
            return;
        }

        $order->update_status( 'cancelled', 'PIX não pago em ' . self::PIX_EXPIRATION_MINUTES . ' minutos. Pedido cancelado automaticamente.' );
    }

    public function thankyou_page( $order_or_id ) {
        $order = wc_get_order( $order_or_id );
        if ( ! $order ) {
            return;
        }
        
        $order_id = $order->get_id();
        
        static $displayed_for_orders = array();
        if ( in_array( $order_id, $displayed_for_orders ) ) {
            return;
        }
        $displayed_for_orders[] = $order_id;
        
        $qr_image = $order->get_meta( 'rede_pix_qrcode_image' );
        $qr_data  = $order->get_meta( 'rede_pix_qrcode_data' );

        $expires_at = (int) $order->get_meta( '_rede_pix_expires_at' );
        if ( $expires_at ) {
            $this->cancel_expired_pix_order( $order_id );
            $order = wc_get_order( $order_id );
            if ( $order->has_status( 'cancelled' ) ) {
                echo '<p style="text-align:center;color:#b32d2e;font-weight:600;">O tempo para pagamento do PIX expirou e o pedido foi cancelado. Faça um novo pedido para tentar novamente.</p>';
                return;
            }
            if ( ! $order->has_status( 'on-hold' ) ) {
                return;
            }
        }

        if ( $qr_image && $qr_data ) {
            if ( $expires_at ) {
                $remaining = max( 0, $expires_at - time() );
                echo '<p style="text-align:center;font-size:16px;">Pague em até <strong id="rede-pix-timer" data-remaining="' . esc_attr( $remaining ) . '">' . esc_html( sprintf( '%02d:%02d', floor( $remaining / 60 ), $remaining % 60 ) ) . '</strong> ou o pedido será cancelado.</p>';
                echo '<script>(function(){var el=document.getElementById("rede-pix-timer");if(!el)return;var r=parseInt(el.getAttribute("data-remaining"),10);var t=setInterval(function(){r--;if(r<=0){clearInterval(t);el.textContent="00:00";location.reload();return;}var m=Math.floor(r/60),s=r%60;el.textContent=(m<10?"0":"")+m+":"+(s<10?"0":"")+s;},1000);})();</script>';
            }
            echo '<h2>Pague seu PIX</h2>';
            echo '<p>Escaneie o QR Code abaixo com o aplicativo do seu banco para concluir o pagamento.</p>';
            echo '<div style="margin: 20px 0;">';
            echo '<img src="data:image/png;base64,' . esc_attr( $qr_image ) . '" alt="QR Code PIX" style="max-width: 250px; display: block; margin: 0 auto; border: 1px solid #ccc; padding: 10px; border-radius: 8px;" />';
            echo '</div>';
            echo '<h3>Pix Copia e Cola</h3>';
            echo '<input type="text" value="' . esc_attr( $qr_data ) . '" readonly="readonly" style="width: 100%; padding: 10px; font-size: 14px; text-align: center; margin-bottom: 20px;" onclick="this.select(); document.execCommand(\'copy\'); alert(\'Código copiado!\');" />';
            echo '<p style="text-align: center; font-size: 12px; color: #666;">Clique no código acima para copiar.</p>';
        } else {
            // Em caso de erro para gerar ou exibir, mostra mensagem de debug caso a pessoa seja o admin
            if ( current_user_can('manage_options') && $order->get_meta('_rede_pix_error_missing_qr') ) {
                 echo '<p style="color:red; font-size: 12px;">DEBUG (só admin): A resposta da Rede não retornou os campos de QR Code esperados. A resposta crua foi salva no meta _rede_pix_raw_response.</p>';
            }
        }
    }

    public function payment_scripts() {
        if ( ! is_checkout() && ! is_cart() ) {
            return;
        }

        $version = defined( 'REDE_GATEWAY_VERSION' ) ? REDE_GATEWAY_VERSION : null;
        wp_enqueue_style( 'rede-checkout-style', plugin_dir_url( __DIR__ ) . 'assets/css/rede-checkout.css', array(), $version );
        wp_enqueue_script( 'rede-checkout-script', plugin_dir_url( __DIR__ ) . 'assets/js/rede-checkout.js', array( 'jquery' ), $version, true );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Habilitar/Desabilitar',
                'type'    => 'checkbox',
                'label'   => 'Habilitar Rede - Cartão de Crédito',
                'default' => 'yes'
            ),
            'sandbox' => array(
                'title'   => 'Ambiente Sandbox',
                'type'    => 'checkbox',
                'label'   => 'Habilitar ambiente de testes da Rede',
                'default' => 'yes'
            ),
            'title' => array(
                'title'       => 'Título no Checkout',
                'type'        => 'text',
                'description' => 'Isso controla o título que o usuário vê durante o checkout.',
                'default'     => 'Cartão de Crédito',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Descrição no Checkout',
                'type'        => 'textarea',
                'description' => 'A mensagem que o usuário vê durante o checkout.',
                'default'     => 'Pague de forma segura com seu cartão de crédito.'
            ),
            'pv' => array(
                'title'       => 'PV (Número de Filiação)',
                'type'        => 'text',
                'description' => 'Seu número de afiliação na Rede (PV).',
                'default'     => ''
            ),
            'token' => array(
                'title'       => 'Token',
                'type'        => 'password',
                'description' => 'Token de integração gerado no portal da Rede.',
                'default'     => ''
            ),
            'installments_section' => array(
                'title'       => 'Configurações de Parcelamento',
                'type'        => 'title',
                'description' => 'Opções para recebimento por cartão de crédito.',
            ),
            'credit_card_max_installments' => array(
                'title'       => 'Máximo de Parcelas',
                'type'        => 'number',
                'default'     => '12',
                'custom_attributes' => array('min' => 1, 'max' => 12)
            ),
            'credit_card_min_installment_value' => array(
                'title'       => 'Valor Mínimo da Parcela',
                'type'        => 'number',
                'description' => 'Valor mínimo (em R$) que cada parcela deve ter.',
                'default'     => '5'
            ),
            'credit_card_interest_rate' => array(
                'title'       => 'Taxa de Juros (%)',
                'type'        => 'number',
                'description' => 'Juros ao mês, 0 para sem juros.',
                'default'     => '0'
            ),
            'pix_section' => array(
                'title'       => 'Configurações de PIX',
                'type'        => 'title',
            ),
            'enable_pix' => array(
                'title'   => 'Ativar PIX',
                'type'    => 'checkbox',
                'label'   => 'Aceitar pagamentos via PIX',
                'default' => 'no'
            ),
            'boleto_section' => array(
                'title'       => 'Configurações de Boleto',
                'type'        => 'title',
            ),
            'enable_boleto' => array(
                'title'   => 'Ativar Boleto',
                'type'    => 'checkbox',
                'label'   => 'Aceitar pagamentos via Boleto Bancário',
                'default' => 'no'
            ),
            'multipayment_section' => array(
                'title'       => 'Configurações de Multipagamento',
                'type'        => 'title',
                'description' => 'Permite que o cliente pague usando dois métodos (ex: Dois Cartões, ou Cartão + PIX).',
            ),
            'enable_multipayment' => array(
                'title'   => 'Ativar Multipagamento',
                'type'    => 'checkbox',
                'label'   => 'Permitir dividir o pagamento do pedido',
                'default' => 'no'
            ),
            'sankhya_section' => array(
                'title'       => 'Integração Sankhya (Tipos de Negociação)',
                'type'        => 'title',
                'description' => 'Mapeie o Código do Tipo de Negociação do seu ERP Sankhya para cada método de pagamento.',
            ),
            'sankhya_cod_cartao' => array(
                'title'       => 'Cód. Negociação - Cartão',
                'type'        => 'text',
                'description' => 'Ex: 101',
                'default'     => ''
            ),
            'sankhya_cod_pix' => array(
                'title'       => 'Cód. Negociação - PIX',
                'type'        => 'text',
                'description' => 'Ex: 102',
                'default'     => ''
            ),
            'sankhya_cod_boleto' => array(
                'title'       => 'Cód. Negociação - Boleto',
                'type'        => 'text',
                'description' => 'Ex: 103',
                'default'     => ''
            ),
            'debug_section' => array(
                'title'       => 'Debug, Logs e Testes',
                'type'        => 'title',
            ),
            'enable_logging' => array(
                'title'   => 'Ativar Logs',
                'type'    => 'checkbox',
                'label'   => 'Registrar comunicação com a Rede em Log',
                'description' => 'Logs disponíveis em WooCommerce > Status > Logs',
                'default' => 'no'
            ),
            'view_logs_btn' => array(
                'title' => 'Acesso aos Logs',
                'type'  => 'view_logs_button'
            ),
        );
    }

    public function generate_view_logs_button_html( $key, $data ) {
        $log_url = admin_url( 'admin.php?page=wc-status&tab=logs' );
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <a href="<?php echo esc_url( $log_url ); ?>" target="_blank" class="button-primary">Visualizar Logs da Rede</a>
                <p class="description">Clique acima para ser redirecionado aos logs do WooCommerce e selecione 'rede-woocommerce-gateway' no seletor de data.</p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        $order_total = WC()->cart ? WC()->cart->get_total('raw') : 0;
        $max_installments = (int) $this->get_option( 'credit_card_max_installments', 12 );
        if ( $max_installments < 1 ) { $max_installments = 1; }
        if ( $max_installments > 12 ) { $max_installments = 12; }
        $plugin_url = plugin_dir_url( __DIR__ );
        ?>
        <div class="rede-checkout-wrapper" data-total-order="<?php echo esc_attr($order_total); ?>">
            <ul class="rede-payment-tabs">
                <li class="active" data-target="rede-tab-cc">
                    <span class="rede-tab-icon">💳</span>
                    <span class="rede-tab-label">Cartão de Crédito</span>
                </li>
                <?php if ( 'yes' === $this->get_option('enable_pix') ) : ?>
                <li data-target="rede-tab-pix">
                    <span class="rede-tab-icon"><img src="<?php echo esc_url( $plugin_url . 'assets/img/pix-icon.png' ); ?>" alt="" /></span>
                    <span class="rede-tab-label">PIX</span>
                </li>
                <?php endif; ?>
                <?php if ( 'yes' === $this->get_option('enable_boleto') ) : ?>
                <li data-target="rede-tab-boleto">
                    <span class="rede-tab-icon">📄</span>
                    <span class="rede-tab-label">Boleto</span>
                </li>
                <?php endif; ?>
            </ul>

            <input type="hidden" name="rede_payment_method" id="rede_payment_method" value="rede-tab-cc" />
            <input type="hidden" name="rede_is_multipayment" id="rede_is_multipayment" value="0" />

            <!-- Single Payment Container -->
            <div class="rede-single-payment-container">

                <!-- Tab Cartão -->
                <div id="rede-tab-cc" class="rede-tab-content" style="display:block;">
                    <div class="rede-cc-fields-container">
                        <div class="rede-cc-layout">
                            <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form rede-card-form" style="background:transparent;">
                                <div class="rede-card-form-grid">
                                    <p class="form-row form-row-wide">
                                        <label for="<?php echo esc_attr( $this->id ); ?>-card-number">Número do Cartão <span class="required">*</span></label>
                                        <input id="<?php echo esc_attr( $this->id ); ?>-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" name="rede_card_number" />
                                    </p>
                                    <p class="form-row form-row-wide">
                                        <label for="<?php echo esc_attr( $this->id ); ?>-card-name">Nome do Titular (como impresso) <span class="required">*</span></label>
                                        <input id="<?php echo esc_attr( $this->id ); ?>-card-name" class="input-text" type="text" autocomplete="off" name="rede_card_name" />
                                    </p>
                                    <p class="form-row form-row-first">
                                        <label for="<?php echo esc_attr( $this->id ); ?>-card-expiry">Validade (MM/AAAA) <span class="required">*</span></label>
                                        <input id="<?php echo esc_attr( $this->id ); ?>-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="MM / AAAA" name="rede_card_expiry" />
                                    </p>
                                    <p class="form-row form-row-last">
                                        <label for="<?php echo esc_attr( $this->id ); ?>-card-cvc">Código de Segurança (CVV) <span class="required">*</span></label>
                                        <input id="<?php echo esc_attr( $this->id ); ?>-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="password" maxlength="4" autocomplete="off" placeholder="CVV" name="rede_card_cvv" />
                                    </p>
                                    <p class="form-row form-row-first">
                                        <label for="rede_card_installments">Parcelas</label>
                                        <select id="rede_card_installments" name="rede_card_installments" class="rede-installments-select">
                                            <?php echo $this->get_installment_options_html( $order_total, $max_installments ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                        </select>
                                    </p>
                                    <p class="form-row form-row-last">
                                        <label for="rede_card_cpf">CPF do Titular</label>
                                        <input id="rede_card_cpf" class="input-text" type="text" autocomplete="off" placeholder="000.000.000-00" name="rede_card_cpf" />
                                    </p>
                                </div>
                                <div class="clear"></div>
                            </fieldset>

                            <div class="rede-card-preview">
                                <div class="rede-card-preview-top">
                                    <span class="rede-card-preview-chip"></span>
                                    <span class="rede-card-preview-brand" id="rede-card-preview-brand"></span>
                                </div>
                                <div class="rede-card-preview-number" id="rede-card-preview-number">•••• •••• •••• ••••</div>
                                <div class="rede-card-preview-bottom">
                                    <div class="rede-card-preview-name" id="rede-card-preview-name">NOME DO TITULAR</div>
                                    <div class="rede-card-preview-expiry" id="rede-card-preview-expiry">MM/AA</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab PIX -->
                <?php if ( 'yes' === $this->get_option('enable_pix') ) : ?>
                <div id="rede-tab-pix" class="rede-tab-content">
                    <div class="rede-method-info">
                        <span class="rede-method-info-icon"><img src="<?php echo esc_url( $plugin_url . 'assets/img/pix-icon.png' ); ?>" alt="" /></span>
                        <p>Você receberá um QRCode e um código Copia e Cola para pagar via PIX após finalizar o pedido.</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab Boleto -->
                <?php if ( 'yes' === $this->get_option('enable_boleto') ) : ?>
                <div id="rede-tab-boleto" class="rede-tab-content">
                    <div class="rede-method-info">
                        <span class="rede-method-info-icon">📄</span>
                        <p>O Boleto será gerado após finalizar a compra.</p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( 'yes' === $this->get_option('enable_multipayment') ) : ?>
                    <a href="#" id="btn-rede-multipayment">+ Dividir pagamento em 2 métodos</a>
                <?php endif; ?>
            </div>

            <!-- Multipayment Container -->
            <?php if ( 'yes' === $this->get_option('enable_multipayment') ) : ?>
            <div class="rede-multipayment-container" style="display:none;">
                <h4>Dividir Pagamento</h4>
                <a href="#" id="btn-rede-cancel-multipayment">Cancelar divisão</a>

                <div class="rede-multi-row">
                    <div class="rede-multi-col">
                        <label>Pagamento 1 - Método</label>
                        <select name="rede_multi_method_1" id="rede_multi_method_1" class="rede-multi-method">
                            <option value="cc">Cartão de Crédito</option>
                            <?php if ( 'yes' === $this->get_option('enable_pix') ) echo '<option value="pix">PIX</option>'; ?>
                            <?php if ( 'yes' === $this->get_option('enable_boleto') ) echo '<option value="boleto">Boleto</option>'; ?>
                        </select>
                    </div>
                    <div class="rede-multi-col">
                        <label>Pagamento 1 - Valor (R$)</label>
                        <input type="number" step="0.01" id="rede_multi_amount_1" name="rede_multi_amount_1" class="rede-multi-amount" value="<?php echo number_format($order_total / 2, 2, '.', ''); ?>" />
                    </div>
                </div>
                
                <div class="rede-multi-row">
                    <div class="rede-multi-col">
                        <label>Pagamento 2 - Método</label>
                        <select name="rede_multi_method_2" id="rede_multi_method_2" class="rede-multi-method">
                            <?php if ( 'yes' === $this->get_option('enable_pix') ) echo '<option value="pix" selected>PIX</option>'; ?>
                            <?php if ( 'yes' === $this->get_option('enable_boleto') ) echo '<option value="boleto">Boleto</option>'; ?>
                            <option value="cc">Cartão de Crédito</option>
                        </select>
                    </div>
                    <div class="rede-multi-col">
                        <label>Pagamento 2 - Valor (R$)</label>
                        <input type="number" step="0.01" id="rede_multi_amount_2" name="rede_multi_amount_2" class="rede-multi-amount" value="<?php echo number_format($order_total / 2, 2, '.', ''); ?>" />
                    </div>
                </div>
                
                <div id="rede-multi-cc-form-container"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Gera as <option> de parcelamento exibidas no checkout (rede_card_installments).
     *
     * Puramente visual: o valor escolhido aqui NÃO é lido em build_cc_payload(),
     * que continua enviando sempre installments => 1 à API da Rede (comportamento
     * pré-existente, mantido de propósito). Serve para dar uma prévia de parcelas
     * ao cliente enquanto o parcelamento real não é implementado ponta a ponta.
     */
    private function get_installment_options_html( $total, $max_installments ) {
        $rates = array(
            1 => 0, 2 => 0, 3 => 0,
            4 => 0.0199, 5 => 0.0199, 6 => 0.0199,
            7 => 0.0249, 8 => 0.0249, 9 => 0.0249,
            10 => 0.0299, 11 => 0.0299, 12 => 0.0299,
        );
        $min_installment_value = (float) $this->get_option( 'credit_card_min_installment_value', 5 );

        $html = '';
        for ( $n = 1; $n <= $max_installments; $n++ ) {
            $rate = $rates[ $n ];

            if ( $rate <= 0 ) {
                $parcela = $total / $n;
                $total_com_juros = $total;
            } else {
                $parcela = $total * $rate / ( 1 - pow( 1 + $rate, -$n ) );
                $total_com_juros = $parcela * $n;
            }

            if ( $n > 1 && $parcela < $min_installment_value ) {
                break;
            }

            if ( $rate <= 0 ) {
                $label = sprintf( '%dx de %s sem juros', $n, wp_strip_all_tags( wc_price( $parcela ) ) );
            } else {
                $label = sprintf(
                    '%dx de %s (%s%% a.m. — total %s)',
                    $n,
                    wp_strip_all_tags( wc_price( $parcela ) ),
                    number_format( $rate * 100, 2, ',', '.' ),
                    wp_strip_all_tags( wc_price( $total_com_juros ) )
                );
            }

            $html .= sprintf( '<option value="%d"%s>%s</option>', $n, selected( $n, 1, false ), esc_html( $label ) );
        }

        return $html;
    }

    public function process_payment( $order_id ) {
        global $woocommerce;
        $order = wc_get_order( $order_id );
        
        $is_logging = 'yes' === $this->get_option( 'enable_logging' );
        if ( $is_logging ) {
            $log_data = array();
            foreach ( $_POST as $key => $value ) {
                if ( strpos( $key, 'rede_' ) === 0 && strpos( $key, 'cvv' ) === false && strpos( $key, 'number' ) === false && strpos( $key, 'cpf' ) === false && strpos( $key, 'expiry' ) === false ) {
                    $log_data[$key] = $value;
                }
            }
            WC_Rede_Logger::debug( "process_payment chamado. Dados POST da Rede: " . wp_json_encode( $log_data ) );
        }

        $is_multipayment = isset( $_POST['rede_is_multipayment'] ) && $_POST['rede_is_multipayment'] === '1';

        if ( ! $is_multipayment ) {
            // Fluxo Único Antigo (Simplificado)
            return $this->process_single_payment( $order );
        } else {
            // Fluxo de Multipagamento
            return $this->process_multi_payment( $order );
        }
    }

    private function process_single_payment( $order ) {
        global $woocommerce;
        
        $payment_method = isset( $_POST['rede_payment_method'] ) ? sanitize_text_field( $_POST['rede_payment_method'] ) : 'rede-tab-cc';

        if ( 'rede-tab-pix' === $payment_method ) {
            $payload = $this->build_pix_payload( $order->get_total(), $order->get_id() );
            $sankhya_cod = $this->get_option( 'sankhya_cod_pix' );
        } elseif ( 'rede-tab-boleto' === $payment_method ) {
            $payload = $this->build_boleto_payload( $order->get_total(), $order->get_id() );
            $sankhya_cod = $this->get_option( 'sankhya_cod_boleto' );
        } else {
            $payload = $this->build_cc_payload( $order->get_total(), $order->get_id() );
            $sankhya_cod = $this->get_option( 'sankhya_cod_cartao' );
        }
        
        if ( ! $payload ) return;

        $response = $this->send_api_request( 'POST', 'transactions', $payload );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( 'Erro: ' . $response->get_error_message(), 'error' );
            return;
        }

        // 00 = Sucesso. PIX e Boleto podem ter returnCodes diferentes (ex: pendente), ajustado para tolerância básica
        $accepted_codes = ( 'rede-tab-cc' === $payment_method || ( 'rede-tab-pix' !== $payment_method && 'rede-tab-boleto' !== $payment_method ) )
            ? array( '00' )
            : array( '00', '0', '200' );
        if ( isset( $response->returnCode ) && in_array( (string) $response->returnCode, $accepted_codes, true ) ) {
            
            if ( 'rede-tab-pix' === $payment_method ) {
                $qr_image = '';
                $qr_data = '';

                // Log raw response for debugging
                $order->update_meta_data( '_rede_pix_raw_response', wp_json_encode($response) );

                // Extrai QR Code das URLs (Padrão Rede API v1 para PIX)
                if ( isset( $response->urls ) && is_array( $response->urls ) ) {
                    foreach ( $response->urls as $url_obj ) {
                        if ( $url_obj->kind === 'qrCodeImage' ) {
                            $qr_image = $url_obj->url;
                        }
                        if ( $url_obj->kind === 'qrCodeData' || $url_obj->kind === 'qrCode' ) {
                            $qr_data = $url_obj->url;
                        }
                    }
                }
                
                // Fallbacks caso o padrão mude
                if ( empty( $qr_image ) && isset( $response->qrCodeResponse ) ) {
                    $qr_image = isset($response->qrCodeResponse->qrCodeImage) ? $response->qrCodeResponse->qrCodeImage : '';
                    $qr_data = isset($response->qrCodeResponse->qrCodeData) ? $response->qrCodeResponse->qrCodeData : '';
                }
                
                // Fallback para qrCode no root
                if ( empty( $qr_image ) && isset( $response->qrCode ) ) {
                    if (is_string($response->qrCode)) {
                        $qr_data = $response->qrCode;
                    } elseif (is_object($response->qrCode)) {
                        $qr_image = isset($response->qrCode->qrCodeImage) ? $response->qrCode->qrCodeImage : '';
                        $qr_data = isset($response->qrCode->qrCodeData) ? $response->qrCode->qrCodeData : '';
                    }
                }

                if ( $qr_image || $qr_data ) {
                    // Remover 'data:image/png;base64,' da string se a Rede enviar, para não duplicar no frontend
                    $qr_image_clean = str_replace(array('data:image/png;base64,', 'data:image/jpeg;base64,'), '', $qr_image);
                    
                    if ( $qr_image_clean ) $order->update_meta_data( 'rede_pix_qrcode_image', $qr_image_clean );
                    if ( $qr_data ) $order->update_meta_data( 'rede_pix_qrcode_data', $qr_data );
                } else {
                    $order->update_meta_data( '_rede_pix_error_missing_qr', 'Yes' );
                }
                
                $expires_at = time() + self::PIX_EXPIRATION_MINUTES * MINUTE_IN_SECONDS;
                $order->update_meta_data( '_rede_pix_expires_at', $expires_at );
                wp_schedule_single_event( $expires_at + 30, 'rede_pix_cancel_unpaid_order', array( $order->get_id() ) );

                $order->update_status( 'on-hold', 'Aguardando pagamento do PIX (Rede) por ' . self::PIX_EXPIRATION_MINUTES . ' minutos. TID: ' . (isset($response->tid) ? $response->tid : '') );
            } elseif ( 'rede-tab-boleto' === $payment_method ) {
                // Boleto só é pago depois de compensado: o pedido fica aguardando.
                $order->update_status( 'on-hold', 'Aguardando pagamento do boleto (Rede). TID: ' . ( isset( $response->tid ) ? $response->tid : '' ) );
            } else {
                $order->payment_complete( isset($response->tid) ? $response->tid : '' );
                $order->add_order_note( sprintf( 'Pagamento registrado via %s na Rede. TID: %s', $payment_method, isset($response->tid) ? $response->tid : '' ) );
            }
            
            if ( ! empty( $sankhya_cod ) ) {
                $order->update_meta_data( 'cod_negociacao_sankhya', $sankhya_cod );
            }
            $order->save();

            $woocommerce->cart->empty_cart();
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        } else {
            $error = isset( $response->returnMessage ) ? $response->returnMessage : 'Transação recusada.';
            wc_add_notice( 'Pagamento não aprovado: ' . $error, 'error' );
            return;
        }
    }

    private function process_multi_payment( $order ) {
        global $woocommerce;
        
        $val1 = isset( $_POST['rede_multi_amount_1'] ) ? floatval( $_POST['rede_multi_amount_1'] ) : 0;
        $val2 = isset( $_POST['rede_multi_amount_2'] ) ? floatval( $_POST['rede_multi_amount_2'] ) : 0;
        
        $method1 = isset( $_POST['rede_multi_method_1'] ) ? sanitize_text_field( $_POST['rede_multi_method_1'] ) : 'cc';
        $method2 = isset( $_POST['rede_multi_method_2'] ) ? sanitize_text_field( $_POST['rede_multi_method_2'] ) : 'pix';

        // O valor vem do navegador: nunca confiar. As duas parcelas precisam ser
        // positivas e somar exatamente o total do pedido (tolerância de 1 centavo).
        $total_cents = (int) round( (float) $order->get_total() * 100 );
        $c1 = (int) round( $val1 * 100 );
        $c2 = (int) round( $val2 * 100 );
        if ( $c1 <= 0 || $c2 <= 0 || abs( ( $c1 + $c2 ) - $total_cents ) > 1 ) {
            wc_add_notice( 'Os valores dos dois pagamentos precisam somar o total do pedido.', 'error' );
            return;
        }
        $allowed_methods = array( 'cc', 'pix', 'boleto' );
        if ( ! in_array( $method1, $allowed_methods, true ) || ! in_array( $method2, $allowed_methods, true ) ) {
            wc_add_notice( 'Método de pagamento inválido.', 'error' );
            return;
        }
        
        $payload1 = $this->build_payload_by_method( $method1, $val1, $order->get_id() . '-1' );
        $payload2 = $this->build_payload_by_method( $method2, $val2, $order->get_id() . '-2' );

        if ( ! $payload1 || ! $payload2 ) return;

        // Disparar Transação 1
        $res1 = $this->send_api_request( 'POST', 'transactions', $payload1 );
        if ( is_wp_error( $res1 ) || ! isset($res1->returnCode) || $res1->returnCode !== '00' ) {
            wc_add_notice( 'Transação 1 recusada. Nenhuma cobrança foi feita.', 'error' );
            return;
        }

        // Transação 1 Aprovada. Disparar Transação 2
        $res2 = $this->send_api_request( 'POST', 'transactions', $payload2 );
        if ( is_wp_error( $res2 ) || ! isset($res2->returnCode) || $res2->returnCode !== '00' ) {
            // ROLLBACK DA TRANSAÇÃO 1
            $this->cancel_transaction( $res1->tid );
            $order->add_order_note( 'Transação 2 falhou. Ocorreu o estorno automático da Transação 1 (TID: '.$res1->tid.').' );
            wc_add_notice( 'A segunda forma de pagamento foi recusada. O valor cobrado na primeira já foi estornado. Tente novamente.', 'error' );
            return;
        }

        // Ambas as transações foram aceitas pela Rede.
        if ( 'cc' === $method1 && 'cc' === $method2 ) {
            $order->payment_complete( $res1->tid . '|' . $res2->tid );
            $order->add_order_note( sprintf( 'Multipagamento aprovado! TID1: %s | TID2: %s', $res1->tid, $res2->tid ) );
        } else {
            // PIX/boleto ainda dependem de pagamento do cliente.
            $order->update_status( 'on-hold', sprintf( 'Multipagamento criado, aguardando PIX/boleto. TID1: %s | TID2: %s', $res1->tid, $res2->tid ) );
        }
        $woocommerce->cart->empty_cart();
        
        return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
    }

    private function build_payload_by_method( $method, $amount, $reference ) {
        if ( 'pix' === $method ) {
            return $this->build_pix_payload( $amount, $reference );
        } elseif ( 'boleto' === $method ) {
            return $this->build_boleto_payload( $amount, $reference );
        } else {
            return $this->build_cc_payload( $amount, $reference );
        }
    }

    private function build_pix_payload( $amount, $reference ) {
        // dateTimeExpiration: Data de expiração do QR Code
        $expiration = gmdate( 'Y-m-d\TH:i:s', strtotime( '+' . self::PIX_EXPIRATION_MINUTES . ' minutes' ) );
        
        return array(
            'kind'      => 'pix',
            'reference' => (string) $reference,
            'amount'    => intval( round( $amount * 100 ) ),
            'qrCode'    => array(
                'dateTimeExpiration' => $expiration
            )
        );
    }

    private function build_boleto_payload( $amount, $reference ) {
        return array(
            'kind'      => 'boleto',
            'reference' => (string) $reference,
            'amount'    => intval( round( $amount * 100 ) )
        );
    }

    private function build_cc_payload( $amount, $reference ) {
        // Nota: rede_card_installments e rede_card_cpf (checkout) são apenas
        // visuais por decisão de escopo — não são lidos aqui. 'installments'
        // permanece fixo em 1, como já era antes desses campos existirem.
        $card_number = isset( $_POST['rede_card_number'] ) ? sanitize_text_field( str_replace( array(' ', '-'), '', $_POST['rede_card_number'] ) ) : '';
        $card_name   = isset( $_POST['rede_card_name'] )   ? sanitize_text_field( $_POST['rede_card_name'] ) : '';
        $card_expiry = isset( $_POST['rede_card_expiry'] ) ? sanitize_text_field( $_POST['rede_card_expiry'] ) : '';
        $card_cvv    = isset( $_POST['rede_card_cvv'] )    ? sanitize_text_field( $_POST['rede_card_cvv'] ) : '';

        if ( empty( $card_number ) || empty( $card_name ) || empty( $card_expiry ) || empty( $card_cvv ) ) {
            wc_add_notice( 'Por favor, preencha todos os dados do cartão.', 'error' );
            return false;
        }

        $expiry_parts = explode( '/', str_replace( ' ', '', $card_expiry ) );
        $exp_month = $expiry_parts[0];
        $exp_year  = isset($expiry_parts[1]) ? $expiry_parts[1] : '';
        if ( strlen( $exp_year ) == 2 ) { $exp_year = '20' . $exp_year; }

        return array(
            'capture'         => true,
            'kind'            => 'credit',
            'reference'       => (string) $reference,
            'amount'          => (int) round( $amount * 100 ),
            'installments'    => 1,
            'cardholderName'  => $card_name,
            'cardNumber'      => $card_number,
            'expirationMonth' => (int) $exp_month,
            'expirationYear'  => (int) $exp_year,
            'securityCode'    => $card_cvv
        );
    }

    /**
     * Remove dados sensíveis do cartão antes de gravar em log.
     * Nunca registrar número completo nem CVV.
     */
    private function mask_payload( $payload ) {
        if ( isset( $payload['cardNumber'] ) ) {
            $payload['cardNumber'] = str_repeat( '*', max( 0, strlen( $payload['cardNumber'] ) - 4 ) ) . substr( $payload['cardNumber'], -4 );
        }
        if ( isset( $payload['securityCode'] ) ) {
            $payload['securityCode'] = '***';
        }
        return $payload;
    }

    private function send_api_request( $method, $endpoint, $payload = null ) {
        $pv = trim( $this->pv );
        $token = trim( $this->token );

        $auth = base64_encode( $pv . ':' . $token );
        
        $base_url = 'yes' === $this->get_option( 'sandbox' ) 
            ? 'https://sandbox-erede.useredecloud.com.br/v1/' 
            : 'https://api.userede.com.br/erede/v1/';
            
        $url = $base_url . $endpoint;
        
        $is_logging = 'yes' === $this->get_option( 'enable_logging' );

        if ( $is_logging ) {
            WC_Rede_Logger::info( "Request: {$method} {$url}" );
            if ( $payload ) WC_Rede_Logger::debug( "Payload: " . wp_json_encode( $this->mask_payload( $payload ) ) );
        }

        $args = array(
            'method'  => $method,
            'timeout' => 45,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $auth
            )
        );

        if ( $payload ) {
            $args['body'] = wp_json_encode( $payload );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            if ( $is_logging ) WC_Rede_Logger::error( "API Error: " . $response->get_error_message() );
            return $response;
        }
        
        $body = wp_remote_retrieve_body( $response );
        if ( $is_logging ) WC_Rede_Logger::debug( "Response: " . $body );
        
        return json_decode( $body );
    }

    private function cancel_transaction( $tid ) {
        // Envia PUT para cancelar a transação que acabou de ser feita
        return $this->send_api_request( 'PUT', 'transactions/' . $tid . '/refunds' );
    }
}
