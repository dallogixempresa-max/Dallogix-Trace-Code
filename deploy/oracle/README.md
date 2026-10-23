# Deploy Oracle

Instalação central em Oracle Linux 9, publicada em trace.dallogix.com. O DNS A do subdomínio aponta diretamente para o IP público da VM; o Caddy do servidor encaminha HTTPS para o Nginx ligado ao loopback. O deploy não depende de Cloudflare Tunnel. Não altere os registros DNS de dallogix.com, www, MX ou TXT.

## Custo e rede

- Use o shape A1 dentro da franquia Always Free da região home; confirme os limites da conta antes de criar. A1 consome a franquia mensal compartilhada de OCPU e memória.
- Mantenha o volume de boot e eventuais volumes de dados dentro da franquia de armazenamento da conta.
- A VM precisa de saída para GitHub, registry de containers e atualização do Oracle Linux.
- Mantenha SSH restrito às origens administrativas aprovadas. O Nginx deve continuar ligado a `127.0.0.1`; o Caddy da própria VM termina HTTPS e encaminha a requisição para essa porta local.

## Preparação de produção

1. A instalação atual usa o checkout Git em /opt/dallogix-trace-git e mantém os dados persistentes em /opt/dallogix-trace/armazenamento. Não substitua nem apague esses diretórios durante uma atualização.
2. Instale Docker Engine, Compose plugin, Git, curl, Python 3 e utilitários básicos.
3. O arquivo de produção existente é /opt/dallogix-trace-git/.env. Não o recrie nem o sobrescreva; ele contém as credenciais do banco que já está em uso.
4. Preserve a configuração atual do Caddy e o DNS A existente para `trace.dallogix.com`. Não configure túnel nem altere registros do domínio raiz.
5. Inicie a aplicação, aplique migrations e verifique a saúde:
   - bash scripts/deploy_production.sh up -d --build --remove-orphans
   - bash scripts/migrate.sh
   - curl --fail http://127.0.0.1:80/api/health.php
6. Crie a conta mestre apenas por terminal interativo, sem senha em argumentos, ambiente, logs ou Git:
   - docker compose --env-file .env -f docker-compose.yml -f docker-compose.production.yml exec php php /var/www/scripts/provision_first_admin.php
   - Antes disso, revise o e-mail e a senha temporária com o responsável; a conta exige troca de senha no primeiro acesso.
7. Confirme que `https://trace.dallogix.com/api/health.php` responde pela VM esperada. Não crie nem altere outros hostnames do domínio.

O banco não é semeado com usuário ou senha padrão. Não tente criar um usuário mestre via API pública.

## Deploy Git periódico

O servidor usa uma chave de deploy SSH somente leitura, guardada em /etc/dallogix-trace/; a chave pública é cadastrada em Deploy keys do repositório da empresa. Configure github.com em known_hosts com a chave SSH publicada pelo GitHub. Não habilite acesso de escrita na chave.

Instale deploy/systemd/dallogix-trace-git-deploy.service e .timer. A unidade usa /opt/dallogix-trace-git, inclui o caminho do Docker CLI da VM Oracle e verifica a branch production a cada cinco minutos. A rotina:

- aceita somente avanço fast-forward e não sobrescreve alterações rastreadas locais;
- adia o deploy durante carregamentos ativos ou manutenção;
- faz backup validado do MySQL antes de atualizar;
- aplica migrations e confirma a versão/commit pela API de health;
- interrompe novas tentativas automáticas para o mesmo commit após falha e preserva o banco para análise.

Promova mudanças para production somente depois da revisão e validação na branch de trabalho. A chave de deploy só lê o código; ela não publica commits no GitHub. O serviço usa o Caddy existente e não exige token de Cloudflare Tunnel.
