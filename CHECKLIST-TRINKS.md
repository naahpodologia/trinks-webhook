# O que pedir ao Trinks

## Para ativar o webhook
- [ ] **Como cadastrar a URL do webhook.** É pelo painel, pelo suporte ou por API? Quem pode pedir (o dono da conta)?
- [ ] **Quais eventos vêm ativados.** Os 14 eventos ou é preciso escolher? (A doc diz que estoque, evento 14, vem para todos.)
- [ ] **Se a conta é tratada como franquia ou estabelecimento único.** A doc diz que vai uma URL por franquia. Confirmar o `IdDoEstabelecimento` da Naah.
- [ ] **TopicArn e região do SNS** de onde as mensagens vão sair, para travarmos o receptor só nesse tópico.
- [ ] **Se aceitam `?token=` na URL** (camada extra de segurança).

## Sobre as mensagens
- [ ] **Exemplo real do envelope SNS** (com `TopicArn`, `SignatureVersion`, `Subject`). O exemplo da doc vem incompleto.
- [ ] **Exemplos de payload dos eventos de agendamento (11, 12, 13)**, estorno (2), alteração de cliente (4) e profissionais (5 a 7).
- [ ] **Política de reenvio.** Quantas vezes o SNS tenta de novo se o endpoint falhar, e por quanto tempo?
- [ ] **Fuso dos horários.** A doc de estoque fala em horário de Brasília. Vale para todos os eventos?
- [ ] **Ambiente de teste (sandbox)** ou forma de disparar eventos de teste antes de ir para produção.

## Para a API (consulta de dados)
- [ ] **Token de API.** Gerado pelo dono em Meu Cadastro > "Token de API Pessoal" > "Gerar token". Vai no cabeçalho `X-Api-Key`. Guardar como segredo: ele dá acesso a todos os estabelecimentos.
- [ ] **URL base da API** e se é preciso informar o estabelecimento em cada chamada (cabeçalho ou parâmetro).
- [ ] **Limites de uso** (requisições por minuto) e custo, se houver.

## Do nosso lado, antes de mandar a URL
- [ ] Publicar o receptor em <subdominio> com HTTPS.
- [ ] Conferir que `https://.../health` responde.
- [ ] Criar o config.php com o token em /home/<usuario>/trinks-webhook/.
- [ ] Depois do cadastro, ver `dados/inscricoes.jsonl` mostrar "confirmada".
