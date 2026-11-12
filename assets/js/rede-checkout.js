jQuery(function($) {
    if (typeof wc_checkout_params === 'undefined') return;

    function initRedeCheckout() {
        // Restore selected tab if it exists
        var savedTab = sessionStorage.getItem('rede_selected_tab');
        if (savedTab) {
            $('.rede-payment-tabs li').removeClass('active');
            $('.rede-payment-tabs li[data-target="' + savedTab + '"]').addClass('active');
            $('.rede-tab-content').hide();
            $('#' + savedTab).show();
            $('input[name="rede_payment_method"]').val(savedTab);
        }

        // Manipulação das Abas (Tabs)
        $('body').on('click', '.rede-payment-tabs li', function(e) {
            e.preventDefault();
            var target = $(this).data('target');
            
            // Ativar a tab selecionada
            $('.rede-payment-tabs li').removeClass('active');
            $(this).addClass('active');
            
            // Mostrar o painel correto
            $('.rede-tab-content').hide();
            $('#' + target).show();

            // Guardar no campo oculto qual foi o método selecionado
            $('input[name="rede_payment_method"]').val(target);
            sessionStorage.setItem('rede_selected_tab', target);
        });

        // Clique no botão de Multipagamento
        $('body').on('click', '#btn-rede-multipayment', function(e) {
            e.preventDefault();
            $('.rede-single-payment-container').slideUp();
            $('.rede-multipayment-container').slideDown();
            $('#rede_is_multipayment').val('1');
            
            // Move CC fields to multipayment
            updateMultiPaymentCCForm();
        });

        // Cancelar Multipagamento
        $('body').on('click', '#btn-rede-cancel-multipayment', function(e) {
            e.preventDefault();
            $('.rede-multipayment-container').slideUp();
            $('.rede-single-payment-container').slideDown();
            $('#rede_is_multipayment').val('0');
            
            // Move CC fields back to CC tab
            $('.rede-cc-fields-container').appendTo('#rede-tab-cc');
        });

        // Validação da matemática do Multipagamento
        $('body').on('change blur keyup', '#rede_multi_amount_1', function() {
            var totalOrder = parseFloat($('.rede-checkout-wrapper').data('total-order')) || 0;
            var val1 = parseFloat($(this).val()) || 0;
            if (val1 > totalOrder) {
                val1 = totalOrder;
                $(this).val(val1.toFixed(2));
            }
            var val2 = totalOrder - val1;
            $('#rede_multi_amount_2').val(val2.toFixed(2));
        });

        $('body').on('change blur keyup', '#rede_multi_amount_2', function() {
            var totalOrder = parseFloat($('.rede-checkout-wrapper').data('total-order')) || 0;
            var val2 = parseFloat($(this).val()) || 0;
            if (val2 > totalOrder) {
                val2 = totalOrder;
                $(this).val(val2.toFixed(2));
            }
            var val1 = totalOrder - val2;
            $('#rede_multi_amount_1').val(val1.toFixed(2));
        });

        function updateMultiPaymentCCForm() {
            var method1 = $('#rede_multi_method_1').val();
            var method2 = $('#rede_multi_method_2').val();
            
            if (method1 === 'cc' || method2 === 'cc') {
                $('.rede-cc-fields-container').appendTo('#rede-multi-cc-form-container').show();
            } else {
                $('.rede-cc-fields-container').hide();
            }
            
            // Prevent selecting CC in both
            $('#rede_multi_method_1 option[value="cc"]').prop('disabled', method2 === 'cc');
            $('#rede_multi_method_2 option[value="cc"]').prop('disabled', method1 === 'cc');
        }

        // Mudança no tipo de método do multipagamento
        $('body').on('change', '.rede-multi-method', function() {
            updateMultiPaymentCCForm();
        });

        // Preview visual do cartão (bandeira detectada por prefixo BIN,
        // número mascarado, nome e validade) — puramente decorativo, não
        // altera nada do que é enviado ao process_payment().
        function detectCardBrand(number) {
            var digits = (number || '').replace(/\D/g, '');
            if (/^4/.test(digits)) return 'Visa';
            if (/^(5[1-5]|2[2-7])/.test(digits)) return 'Mastercard';
            if (/^3[47]/.test(digits)) return 'Amex';
            if (/^(636368|438935|504175|451416|509|627780|636297)/.test(digits)) return 'Elo';
            return '';
        }

        function formatCardNumberDisplay(number) {
            var digits = (number || '').replace(/\D/g, '');
            var padded = digits;
            while (padded.length < 16) {
                padded += '•';
            }
            var groups = [];
            for (var i = 0; i < 16; i += 4) {
                groups.push(padded.slice(i, i + 4));
            }
            return groups.join(' ');
        }

        function updateCardPreview() {
            var number = $('#rede_credit_card-card-number').val();
            var name = $('#rede_credit_card-card-name').val();
            var expiry = $('#rede_credit_card-card-expiry').val();

            $('#rede-card-preview-number').text(formatCardNumberDisplay(number));
            $('#rede-card-preview-name').text(name ? name.toUpperCase() : 'NOME DO TITULAR');
            $('#rede-card-preview-expiry').text(expiry ? expiry.replace(/\s/g, '') : 'MM/AA');
            $('#rede-card-preview-brand').text(detectCardBrand(number));
        }

        $('body').on('input keyup', '#rede_credit_card-card-number, #rede_credit_card-card-name, #rede_credit_card-card-expiry', updateCardPreview);

        updateCardPreview();
    }

    initRedeCheckout();

    // Re-iniciar após update do checkout do WooCommerce
    $(document.body).on('updated_checkout', function() {
        // Restaurar estado visual após o HTML ser re-renderizado
        var savedTab = sessionStorage.getItem('rede_selected_tab');
        if (savedTab && $('input[name="rede_payment_method"]').length > 0) {
            $('.rede-payment-tabs li').removeClass('active');
            $('.rede-payment-tabs li[data-target="' + savedTab + '"]').addClass('active');
            $('.rede-tab-content').hide();
            $('#' + savedTab).show();
            $('input[name="rede_payment_method"]').val(savedTab);
        }
    });
});
