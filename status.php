<?php
// ============================================================
// ARQUIVO: config/status.php
// O QUE FAZ: Mapa centralizado de todos os status de leads
// Importado em qualquer página que precise exibir status
// ============================================================

// Array com todos os status — chave = valor no banco, valor = array com info
$STATUS_LEADS = [
    'novo'             => ['label'=>'Novo',             'badge'=>'badge-novo',      'cor'=>'#008CFF', 'light'=>'#E8F4FF'],
    'em_contato'       => ['label'=>'Em contato',       'badge'=>'badge-contato',   'cor'=>'#F59E0B', 'light'=>'#FEF3C7'],
    'proposta_enviada' => ['label'=>'Proposta enviada', 'badge'=>'badge-proposta',  'cor'=>'#7C3AED', 'light'=>'#EDE9FE'],
    'negociacao'       => ['label'=>'Negociação',       'badge'=>'badge-negociacao','cor'=>'#F97316', 'light'=>'#FFF7ED'],
    'fechado'          => ['label'=>'Fechado',           'badge'=>'badge-fechado',   'cor'=>'#10B981', 'light'=>'#D1FAE5'],
    'perdido'          => ['label'=>'Perdido',           'badge'=>'badge-perdido',   'cor'=>'#EF4444', 'light'=>'#FEE2E2'],
];

// Função auxiliar — retorna o HTML do badge de um status
function badge_status($status) {
    global $STATUS_LEADS;
    $info = $STATUS_LEADS[$status] ?? $STATUS_LEADS['novo'];
    return '<span class="badge ' . $info['badge'] . '">' . $info['label'] . '</span>';
}

// Função auxiliar — retorna o label legível de um status
function label_status($status) {
    global $STATUS_LEADS;
    return $STATUS_LEADS[$status]['label'] ?? $status;
}