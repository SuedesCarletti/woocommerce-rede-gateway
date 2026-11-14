jQuery(document).ready(function($) {

    // Adicionar novo mapeamento
    $('#btn-add-mapping').on('click', function(e) {
        e.preventDefault();
        
        var method = prompt('Qual o Método? (Ex: credit_card, boleto, pix)');
        if (!method) return;
        
        var installment = prompt('Qual a parcela? (Ex: 1, 2, 3... ou 0 para boleto/pix)');
        if (!installment) return;

        var key = method + '_' + installment;
        
        var labelMethod = '💳 Cartão';
        if(method == 'boleto') labelMethod = '📄 Boleto';
        if(method == 'pix') labelMethod = '💠 PIX';

        var labelInstal = installment == '0' ? '-' : installment + 'x';

        var tr = '<tr data-key="' + key + '">' +
                 '<td>' + labelMethod + '</td>' +
                 '<td>' + labelInstal + '</td>' +
                 '<td><input type="text" name="rede_sankhya_mappings[' + key + ']" value="" placeholder="Cód ERP" /></td>' +
                 '<td><button type="button" class="button button-small button-delete delete-mapping" data-key="' + key + '">🗑️ Remover</button></td>' +
                 '</tr>';
                 
        $('#mappings-tbody').append(tr);
    });

    // Remover mapeamento
    $(document).on('click', '.delete-mapping', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();
    });

    // Teste de conexão
    $('#btn-teste-conexao').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.text('Testando... aguarde').prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'rede_test_connection',
                security: $('#rede_admin_nonce').val()
            },
            success: function(response) {
                if(response.success) {
                    alert('SUCESSO! A API da Rede respondeu corretamente.\n\nMensagem: ' + response.data.message);
                } else {
                    alert('❌ ERRO!\n\nMensagem: ' + response.data.message);
                }
            },
            error: function() {
                alert('Erro ao tentar se comunicar com o servidor do WordPress.');
            },
            complete: function() {
                $btn.text('Executar Teste de API').prop('disabled', false);
            }
        });
    });

});
