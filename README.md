# Controle de Locações Lite

Sistema simples em PHP + MySQL para gerenciar:
- apartamentos/unidades
- clientes
- reservas
- agenda visual
- valores automáticos por diária

## O que esta versão Lite mantém
- login
- home
- agenda
- cadastro de apartamentos
- cadastro de clientes
- criação e edição de reservas
- cálculo automático por diária comum, final de semana e feriado

## O que foi removido
- painel de administrador avançado
- agenda manual de valores
- contratos
- ficha separada do cliente
- confirmação de pagamento restante
- fechamento manual de agenda

## Requisitos
- PHP 7.4 ou superior
- MySQL / MariaDB
- Extensão mysqli habilitada

## Instalação
1. Importe o arquivo `sql/schema.sql`
2. Copie `config.example.php` para `config.php`
3. Ajuste os dados do banco no `config.php`
4. Abra o sistema no navegador

## Observações
- o status da reserva é atualizado automaticamente pelas datas
- o último dia não é cobrado quando houver pernoite
- a entrada é descontada do saldo total da reserva

## Publicação no Git
- não suba o `config.php`
- não suba dados reais de clientes ou reservas
- use o `.gitignore` incluído no projeto
"# controle-locacoes-lite" 
