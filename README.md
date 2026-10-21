# Dallogix Trace — código do sistema

Snapshot enxuto do código executável do Dallogix Trace. Inclui a interface, API PHP, migrations, integrações de produção, configuração Docker/Nginx e instaladores necessários.

Origem: `master` 23575b0139ad mais o ajuste local de controles de operação; commit de origem: `3a8a4f937d5d5e84f035e602e13fa8874eabe59b`.

Este repositório não contém a suíte de testes, documentação interna, seeds de demonstração, relatórios de auditoria, configurações de IDE/Agentes ou arquivos de escopo do projeto. O simulador Modbus permanece porque o `docker-compose.yml` atual referencia seu contexto; ele não é necessário na operação física.

O Compose e as imagens-base do Trace suportam `linux/amd64` e `linux/arm64`. O MySQL de produção é montado com os RPMs oficiais assinados da versão 8.4.13 para Oracle Linux 9; isso permite executar nativamente no OCI Ampere A1 sem emulação x86.

O atualizador por pacotes assinados continua vinculado ao repositório `Dallogix-Trace-System`; não crie tags de release neste snapshot até migrar o workflow e os URLs de atualização. Para a nova instalação OCI, o deploy Git acompanha a branch `production`; veja `deploy/oracle/README.md`.

Não coloque `.env`, credenciais, tokens ou configurações reais de máquina neste repositório. Use os arquivos `.env.example` e `.env.production.example` como modelos e mantenha os segredos fora do Git.
