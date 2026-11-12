# Rede (Itaú) WooCommerce Gateway

Plugin de integração de pagamentos para o WooCommerce utilizando a **API e.Rede (Itaú)**. Criado para oferecer uma experiência de checkout transparente, elegante e flexível.

## Funcionalidades
- **Checkout Transparente**: O cliente digita o cartão de crédito diretamente no seu site, sem redirecionamentos.
- **Abas de Pagamento**: Interface visual moderna separada por abas (Cartão de Crédito, PIX, Boleto).
- **Multipagamento (Dual Payment)**: Permite dividir o valor total da compra em dois métodos diferentes (Ex: Pagamento 1 em Cartão, Pagamento 2 em PIX).
- **Rollback de Segurança**: Em caso de falha na segunda transação do multipagamento, o plugin aciona o estorno automático da primeira transação para evitar cobranças parciais.

## Status do Desenvolvimento
- [x] Estrutura base do Gateway WooCommerce
- [x] Painel de configurações (Ambiente Sandbox, Credenciais PV e Token)
- [x] Configurações de Parcelamento, Pix e Boleto
- [x] Interface Frontend do Checkout (CSS e Abas)
- [x] Processamento Backend de Cartão (Simples e Multipagamento)
- [ ] Campos HTML para o Cartão 2 no Multipagamento
- [ ] Implementação do Payload e Retorno para PIX
- [ ] Implementação do Payload e Retorno para Boleto Bancário

## Instalação
1. Baixe o repositório como ZIP (ou clone na pasta `wp-content/plugins/`).
2. Acesse o painel do WordPress em **Plugins > Adicionar Novo**.
3. Faça o upload e clique em **Ativar**.

## Configuração
1. No seu WooCommerce, navegue para **WooCommerce > Configurações > Pagamentos**.
2. Ative a opção **Rede - Cartão de Crédito**.
3. Insira suas chaves **PV (Número de Filiação)** e **Token**. *(Para testes, gere as chaves Sandbox no Portal do Desenvolvedor da Rede).*
4. Salve as alterações.
