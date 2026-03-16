<?php
// ============================================================
// ARQUIVO: config/permissoes.php
// ============================================================

function eh_admin() {
    return isset($_SESSION['usuario_logado']) && $_SESSION['usuario_logado'] === true;
}

function eh_colaborador() {
    return isset($_SESSION['colab_logado']) && $_SESSION['colab_logado'] === true;
}

function esta_logado() {
    return eh_admin() || eh_colaborador();
}

// Busca permissões SEMPRE do banco — garante que mudanças do admin têm efeito imediato
function get_permissoes() {
    if (eh_admin()) {
        return ['ver_dashboard'=>1,'ver_leads'=>1,'ver_financeiro'=>1,
                'editar_leads'=>1,'editar_financeiro'=>1,'gerenciar_colab'=>1];
    }
    if (eh_colaborador()) {
        global $conexao;
        // Se $conexao não estiver disponível ainda, inclui o db
        if (!isset($conexao)) { require_once __DIR__ . '/db.php'; }

        $cid = (int)$_SESSION['colab_id'];

        // Verifica se ainda está ativo
        $r = mysqli_query($conexao,
            "SELECT c.status, p.ver_dashboard, p.ver_leads, p.ver_financeiro,
                    p.editar_leads, p.editar_financeiro, p.gerenciar_colab
             FROM colaboradores c
             LEFT JOIN permissoes p ON p.colaborador_id_fk = c.id
             WHERE c.id = $cid"
        );
        if ($r && mysqli_num_rows($r) === 1) {
            $row = mysqli_fetch_assoc($r);
            // Atualiza o status na sessão
            $_SESSION['colab_status'] = $row['status'];
            if ($row['status'] !== 'ativo') return [];
            return [
                'ver_dashboard'     => (int)$row['ver_dashboard'],
                'ver_leads'         => (int)$row['ver_leads'],
                'ver_financeiro'    => (int)$row['ver_financeiro'],
                'editar_leads'      => (int)$row['editar_leads'],
                'editar_financeiro' => (int)$row['editar_financeiro'],
                'gerenciar_colab'   => (int)$row['gerenciar_colab'],
            ];
        }
        return [];
    }
    return [];
}

function tem_permissao($perm) {
    if (eh_admin()) return true;
    $perms = get_permissoes();
    return !empty($perms[$perm]);
}

function requer_login() {
    if (!esta_logado()) { header("Location: index.php"); exit(); }
}

function requer_permissao($perm) {
    requer_login();
    if (!tem_permissao($perm)) {
        // Passa o motivo via GET para a tela exibir a mensagem correta
        header("Location: sem_permissao.php?motivo=sem_permissao");
        exit();
    }
}

function nome_usuario() {
    if (eh_admin())       return $_SESSION['usuario_nome'] ?? 'Admin';
    if (eh_colaborador()) return $_SESSION['colab_nome']   ?? 'Colaborador';
    return '';
}

function inicial_usuario() {
    return strtoupper(substr(nome_usuario(), 0, 1)) ?: 'U';
}