# Dallogix Trace — código do sistema

Snapshot enxuto público do código executável do Dallogix Trace. Inclui interface, API PHP, migrations, integrações, configuração Docker/Nginx e instaladores necessários.

Origem do snapshot: `master` 23575b0139ad mais o ajuste local de controles de operação; commit de origem: `3a8a4f937d5d5e84f035e602e13fa8874eabe59b`. O histórico posterior inclui a implantação de produção e as mudanças de configuração do instalador.

Este repositório não contém a suíte completa de testes, seeds de demonstração, relatórios de auditoria ou configurações de IDE/Agentes. Ele inclui documentação operacional e verificações focadas de deploy e instalação. O simulador Modbus permanece porque o `docker-compose.yml` atual referencia seu contexto; ele não é necessário na operação física.

O Compose e as imagens-base do Trace suportam `linux/amd64` e `linux/arm64`. O MySQL de produção usa os RPMs oficiais assinados da versão 8.4.13 para Oracle Linux 9, permitindo execução nativa no OCI Ampere A1.

O workflow `Windows installer` compila um instalador de revisão para cada pull request. Uma tag de versão implanta o commit exato no Central pela rota restrita de deploy, confere o health check e então publica apenas artefatos assinados. Consulte [o processo de release do instalador](docs/RELEASING-WINDOWS-INSTALLER.md) antes de criar uma tag.

Na primeira instalação, o assistente abre a configuração industrial em uma janela. Depois, o atalho do menu Iniciar “Configurar CLP e leitor do Dallogix Trace” permite alterar parâmetros Modbus, sensor, leitor USB HID e janela de associação sem editar `.env`. O endereço IP e a porta do CLP continuam sendo mantidos no cadastro da Dala no Central. O mapa de escrita aprovado está limitado ao INVT TS621 e exige vínculo e confirmação explícita. Leitores KEYENCE SR-X100W por Ethernet/TCP ainda precisam de uma integração própria; esta versão só identifica leitores em modo USB HID.

Não coloque `.env`, credenciais, tokens ou configurações reais de máquina neste repositório. Use `.env.example` e `.env.production.example` como modelos e mantenha os segredos fora do Git.
