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

    // Repassar cada evento, sem alteração, para outros sistemas que também usam o webhook do Trinks
    // (ex.: Retorne). Só https. Vazio = não repassa.
    // 'repassarPara' => ['https://url-do-retorne/aqui'],

    // Banco MySQL da Hostinger (hPanel > Bancos de dados). Sem este bloco, nada vai para o banco.
    // 'banco' => [
    //     'host' => 'localhost',
    //     'nome' => 'u000000000_trinks',
    //     'usuario' => 'u000000000_trinks',
    //     'senha' => 'senha-do-banco',
    // ],

    // Webhook do WhatsApp (Meta). Sem este bloco, o endereço /whatsapp responde 404.
    // verifyToken: senha que você inventa e coloca também no painel do app da Meta.
    // appSecret: "Chave secreta do app" (Meta for Developers > Configurações do app > Básico).
    // 'whatsapp' => [
    //     'verifyToken' => 'troque-por-uma-senha-longa',
    //     'appSecret' => 'chave-secreta-do-app',
    // ],

    // SOMENTE PARA TESTE: aceita mensagens sem assinatura. Nunca deixe true em produção.
    'aceitarSemAssinatura' => false,
];
