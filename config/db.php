<?php
// ============================================================
// ARQUIVO: config/db.php
// O QUE FAZ: Conecta o PHP ao banco de dados MySQL
// ============================================================
// Este arquivo é incluído por todos os outros arquivos
// que precisam falar com o banco de dados.
// ============================================================

$conexao = mysqli_connect(
    'localhost',    // Endereço do servidor MySQL (no XAMPP é sempre localhost)
    'root',         // Usuário do MySQL (no XAMPP o padrão é root)
    '',             // Senha do MySQL (no XAMPP padrão é vazia, sem senha)
    'norion_crm'    // Nome do banco de dados que vamos criar
);

// Verificando se a conexão funcionou
if (!$conexao) {
    // Se der erro, para tudo e mostra a mensagem de erro
    die("Erro de conexão: " . mysqli_connect_error());
}

// Garante que acentos e caracteres especiais funcionem
mysqli_set_charset($conexao, "utf8mb4");
?>