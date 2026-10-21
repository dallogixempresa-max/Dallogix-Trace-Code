# Deploy Oracle + Cloudflare

Instalação central nova em Oracle Linux 9 (Ampere A1), publicada apenas em trace.dallogix.com. A configuração usa Cloudflare Tunnel; não é necessário expor SSH, HTTP ou HTTPS na VM. Não altere os registros DNS de dallogix.com, www, MX ou TXT.

## Custo e rede

- Use o shape A1 dentro da franquia Always Free da região home; confirme os limites da conta antes de criar. A1 consome a franquia mensal compartilhada de OCPU e memória.
- Mantenha o volume de boot e eventuais volumes de dados dentro da franquia de armazenamento da conta.
- A VM precisa de saída para GitHub, Cloudflare, registry de containers e atualização do Oracle Linux.
- Configure a VCN para aceitar SSH somente da própria VCN/Cloud Shell, sem regra de entrada 0.0.0.0/0. A aplicação não publica portas diretamente; Nginx fica ligado a loopback e o túnel conversa pela rede interna do Compose.

## Preparação de produção

1. Crie a pasta persistente /opt/dallogix-trace e clone a branch production do repositório privado da empresa.
2. Instale Docker Engine, Compose plugin, Git, curl, Python 3 e utilitários básicos.
3. Crie o ambiente uma única vez: bash scripts/setup_production_env.sh /opt/dallogix-trace/.env https://trace.dallogix.com. O script não sobrescreve um arquivo existente.
4. No Cloudflare, crie um túnel sem rota pública e copie o token para /opt/dallogix-trace/secrets/cloudflare_tunnel_token, com proprietário root e permissão 0600. O token não entra no Git nem no .env.
5. Inicie a aplicação, aplique migrations e verifique a saúde:
   - bash scripts/deploy_production.sh up -d --build --remove-orphans
   - bash scripts/migrate.sh
   - curl --fail http://127.0.0.1:8080/api/health.php
6. Crie a conta mestre apenas por terminal interativo, sem senha em argumentos, ambiente, logs ou Git:
   - docker compose --env-file .env -f docker-compose.yml -f docker-compose.production.yml exec php php /var/www/scripts/provision_first_admin.php
   - Antes disso, revise o e-mail e a senha temporária com o responsável; a conta exige troca de senha no primeiro acesso.
7. Só depois de comprovar o primeiro acesso do administrador, adicione a rota pública trace.dallogix.com para http://nginx:80 na rede Docker. Não crie nem altere outros hostnames do domínio.

O banco não é semeado com usuário ou senha padrão. Não tente criar um usuário mestre via API pública.

## Deploy Git periódico

O servidor usa uma chave de deploy SSH somente leitura, guardada em /etc/dallogix-trace/; a chave pública é cadastrada em Deploy keys do repositório da empresa. Configure github.com em known_hosts usando a impressão digital publicada oficialmente pelo GitHub. Não habilite acesso de escrita na chave.

Instale deploy/systemd/dallogix-trace-git-deploy.service e .timer, atualizando o caminho do repositório se necessário. O timer verifica a branch production a cada cinco minutos. A rotina:

- aceita somente avanço fast-forward e não sobrescreve alterações rastreadas locais;
- adia o deploy durante carregamentos ativos ou manutenção;
- faz backup validado do MySQL antes de atualizar;
- aplica migrations e confirma a versão/commit pela API de health;
- interrompe novas tentativas automáticas para o mesmo commit após falha e preserva o banco para análise.

Promova mudanças para production somente depois da revisão e validação na branch de trabalho. A chave de deploy só lê o código; ela não publica commits no GitHub.
