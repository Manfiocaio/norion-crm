<?php
// ============================================================
// ARQUIVO: leads_massa.php
// O QUE FAZ: Processa ações em massa nos leads selecionados
// Recebe os IDs via POST e executa a ação escolhida
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'config/permissoes.php';
requer_permissao('ver_leads');

$acao = $_POST['acao'] ?? '';
$ids  = $_POST['ids']  ?? [];

// Ações destrutivas exigem permissão de edição
if (in_array($acao, ['excluir','etiqueta']) && !tem_permissao('editar_leads')) {
    $_SESSION['flash_msg']  = "Sem permissão para esta ação.";
    $_SESSION['flash_tipo'] = "error";
    header("Location: leads.php");
    exit();
}
// $_POST['ids'] é um array com os IDs dos leads marcados
// Ex: ['1', '3', '7', '12']

// Segurança: converte todos os IDs para inteiro
// Isso garante que só números passem — evita SQL Injection
$ids = array_map('intval', $ids);
// array_map('intval', $array) = aplica intval() em cada item do array
// intval('3abc') = 3, intval('abc') = 0

// Remove zeros (IDs inválidos que viraram 0 após intval)
$ids = array_filter($ids, fn($id) => $id > 0);
// array_filter com função = mantém só os itens onde a função retorna true

// Se não há IDs válidos ou ação, volta para leads
if (empty($ids) || empty($acao)) {
    $_SESSION['flash_msg']  = "Nenhum lead selecionado.";
    $_SESSION['flash_tipo'] = "error";
    header("Location: leads.php");
    exit();
}

// Transforma o array de IDs em string para usar no SQL
// Ex: [1, 3, 7] → "1,3,7"
$ids_str = implode(',', $ids);
// implode(separador, array) = junta os itens com o separador

// ============================================================
// AÇÃO: Excluir em massa
// ============================================================
if ($acao === 'excluir') {

    // Confirma que realmente quer excluir (vem do formulário)
    if (!isset($_POST['confirmar_exclusao'])) {
        $_SESSION['flash_msg']  = "Confirmação necessária para excluir.";
        $_SESSION['flash_tipo'] = "error";
        header("Location: leads.php");
        exit();
    }

    // Deleta as vendas vinculadas a esses leads
    mysqli_query($conexao, "DELETE FROM vendas WHERE lead_id IN ($ids_str)");
    // IN (1,3,7) = onde o lead_id for 1 OU 3 OU 7

    // Deleta o histórico vinculado
    mysqli_query($conexao, "DELETE FROM historico_contatos WHERE lead_id IN ($ids_str)");

    // Deleta os leads
    $ok = mysqli_query($conexao, "DELETE FROM leads WHERE id IN ($ids_str)");

    if ($ok) {
        $qtd = count($ids);
        $_SESSION['flash_msg']  = "$qtd lead(s) excluído(s) com sucesso.";
        $_SESSION['flash_tipo'] = "success";
    } else {
        $_SESSION['flash_msg']  = "Erro ao excluir: " . mysqli_error($conexao);
        $_SESSION['flash_tipo'] = "error";
    }

    header("Location: leads.php");
    exit();
}

// ============================================================
// AÇÃO: Adicionar etiqueta em massa
// ============================================================
if ($acao === 'etiqueta') {

    $etiqueta = mysqli_real_escape_string($conexao, trim($_POST['etiqueta_valor'] ?? ''));

    if (empty($etiqueta)) {
        $_SESSION['flash_msg']  = "Escolha uma etiqueta.";
        $_SESSION['flash_tipo'] = "error";
        header("Location: leads.php");
        exit();
    }

    // UPDATE com IN — atualiza vários registros de uma vez
    $ok = mysqli_query($conexao,
        "UPDATE leads SET etiqueta = '$etiqueta' WHERE id IN ($ids_str)"
    );

    if ($ok) {
        $qtd = count($ids);
        $_SESSION['flash_msg']  = "Etiqueta \"$etiqueta\" aplicada em $qtd lead(s).";
        $_SESSION['flash_tipo'] = "success";
    } else {
        $_SESSION['flash_msg']  = "Erro: " . mysqli_error($conexao);
        $_SESSION['flash_tipo'] = "error";
    }

    header("Location: leads.php");
    exit();
}

// ============================================================
// AÇÃO: Exportar para CSV melhorado
// ============================================================
if ($acao === 'exportar_csv') {

    $resultado = mysqli_query($conexao,
        "SELECT nome, telefone, email, origem, status, etiqueta,
                DATE_FORMAT(criado_em, '%d/%m/%Y') as data_cadastro,
                valor
         FROM leads
         WHERE id IN ($ids_str)
         ORDER BY criado_em DESC"
    );

    // Content-Type com charset garante acentos corretos
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="leads_norion_' . date('d-m-Y') . '.csv"');

    // BOM UTF-8 — obrigatório para o Excel reconhecer acentos
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Cabeçalho com separador ponto-e-vírgula (padrão Excel BR)
    fputcsv($output, [
        'Nome',
        'Telefone',
        'Email',
        'Origem',
        'Status',
        'Etiqueta',
        'Valor (R$)',
        'Data de cadastro'
    ], ';');

    $status_labels = [
        'novo'       => 'Novo',
        'em_contato' => 'Em contato',
        'fechado'    => 'Fechado',
        'perdido'    => 'Perdido',
    ];

    while ($row = mysqli_fetch_assoc($resultado)) {

        // PROBLEMA DO TELEFONE COMO NÚMERO CIENTÍFICO:
        // O Excel tenta interpretar 15996640354 como número e mostra 1,6E+10
        // Solução: prefixar com \t (tab) — o Excel trata a célula como texto
        // Outra opção seria ="" mas o \t é mais limpo visualmente
        $telefone_formatado = !empty($row['telefone'])
            ? "\t" . $row['telefone']
            : '';

        // Valor formatado como moeda brasileira
        $valor_formatado = $row['valor'] > 0
            ? number_format((float)$row['valor'], 2, ',', '.')
            : '';

        fputcsv($output, [
            $row['nome'],
            $telefone_formatado,
            $row['email'],
            $row['origem'],
            $status_labels[$row['status']] ?? $row['status'],
            $row['etiqueta'] ?? '',
            $valor_formatado,
            $row['data_cadastro'],
        ], ';');
    }

    fclose($output);
    exit();
}

// Se chegou aqui, ação inválida
header("Location: leads.php");
exit();