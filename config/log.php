<?php
// ============================================================
// ARQUIVO: config/log.php
// O QUE FAZ: Funções centralizadas para registrar atividades
// Importado em qualquer arquivo que precise registrar ações
// ============================================================

// Registra uma atividade no log
// $lead_id  = ID do lead afetado
// $acao     = tipo da ação (enum da tabela)
// $detalhe  = texto opcional descrevendo a mudança
function registrar_log($conexao, $lead_id, $acao, $detalhe = null) {
    // Pega o nome do usuário logado
    // Prioriza sessão de colaborador, depois admin
    if (!empty($_SESSION['colab_nome'])) {
        $usuario = $_SESSION['colab_nome'];
    } elseif (!empty($_SESSION['usuario_logado'])) {
        $usuario = 'Admin';
    } else {
        $usuario = 'Sistema';
    }

    $lead_id_i  = (int)$lead_id;
    $usuario_e  = mysqli_real_escape_string($conexao, $usuario);
    $acao_e     = mysqli_real_escape_string($conexao, $acao);
    $detalhe_e  = $detalhe
        ? "'" . mysqli_real_escape_string($conexao, substr($detalhe, 0, 490)) . "'"
        : 'NULL';

    mysqli_query($conexao,
        "INSERT INTO log_atividades (lead_id, usuario, acao, detalhe)
         VALUES ($lead_id_i, '$usuario_e', '$acao_e', $detalhe_e)"
    );
}

// Ícone SVG por tipo de ação (inline, sem dependência externa)
function icone_log($acao) {
    $icons = [
        'criou'            => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'editou'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'status_alterado'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/></svg>',
        'valor_alterado'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 1 0 0 7h5a3.5 3.5 0 1 1 0 7H6"/></svg>',
        'fechou'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
        'arquivo_enviado'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
        'arquivo_removido' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
        'lembrete_criado'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
    ];
    return $icons[$acao] ?? $icons['editou'];
}

// Cor do ícone por tipo de ação
function cor_log($acao) {
    $cores = [
        'criou'            => ['bg'=>'#D1FAE5','fg'=>'#065F46'],
        'editou'           => ['bg'=>'#E8F4FF','fg'=>'#0055B3'],
        'status_alterado'  => ['bg'=>'#EDE9FE','fg'=>'#4C1D95'],
        'valor_alterado'   => ['bg'=>'#D1FAE5','fg'=>'#065F46'],
        'fechou'           => ['bg'=>'#D1FAE5','fg'=>'#065F46'],
        'arquivo_enviado'  => ['bg'=>'#FEF3C7','fg'=>'#92400E'],
        'arquivo_removido' => ['bg'=>'#FEE2E2','fg'=>'#991B1B'],
        'lembrete_criado'  => ['bg'=>'#FEF3C7','fg'=>'#92400E'],
    ];
    return $cores[$acao] ?? $cores['editou'];
}

// Texto legível por tipo de ação
function texto_acao($acao) {
    $textos = [
        'criou'            => 'criou o lead',
        'editou'           => 'editou os dados',
        'status_alterado'  => 'alterou o status',
        'valor_alterado'   => 'alterou o valor',
        'fechou'           => 'fechou o negócio',
        'arquivo_enviado'  => 'enviou um documento',
        'arquivo_removido' => 'removeu um documento',
        'lembrete_criado'  => 'criou um lembrete',
    ];
    return $textos[$acao] ?? $acao;
}