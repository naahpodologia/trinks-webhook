<?php
// Copie para /home/<usuario>/trinks-webhook/config.php (fora da pasta pública) e ajuste.
return [
    // Senha longa exigida na URL: https://<subdominio>/webhook?token=ESTA_SENHA
    'token' => 'troque-por-uma-senha-longa',

    // Caminho do endpoint
    'caminho' => '/webhook',

    // Onde gravar os eventos. Deixe fora da pasta pública.
    // 'pastaDados' => '/home/u000000000/trinks-webhook/dados',

    // TopicArn do Trinks, quando eles informarem. Vazio = aceita qualquer tópico com assinatura válida.
    'topicos' => [],

    // Confirmar a inscrição sozinho quando o Trinks cadastrar a URL
    'confirmarAutomaticamente' => true,

    // SOMENTE PARA TESTE: aceita mensagens sem assinatura. Nunca deixe true em produção.
    'aceitarSemAssinatura' => false,
];
