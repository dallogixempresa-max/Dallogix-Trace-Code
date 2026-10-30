# Dallogix Trace — código do sistema

Snapshot enxuto público do código executável do Dallogix Trace. Inclui interface, API PHP, migrations, integrações, configuração Docker/Nginx e instaladores necessários.

Origem do snapshot: `master` 23575b0139ad mais o ajuste local de controles de operação; commit de origem: `3a8a4f937d5d5e84f035e602e13fa8874eabe59b`. O histórico posterior inclui a implantação de produção e as mudanças de configuração do instalador.

Este repositório não contém a suíte completa de testes, seeds de demonstração, relatórios de auditoria ou configurações de IDE/Agentes. Ele inclui documentação operacional e verificações focadas de deploy e instalação. O simulador Modbus permanece porque o `docker-compose.yml` atual referencia seu contexto; ele não é necessário na operação física.

O Compose e as imagens-base do Trace suportam `linux/amd64` e `linux/arm64`. O MySQL de produção usa os RPMs oficiais assinados da versão 8.4.13 para Oracle Linux 9, permitindo execução nativa no OCI Ampere A1.

O workflow `Windows installer` compila um instalador de revisão para cada pull request. Uma tag de versão implanta o commit exato no Central pela rota restrita de deploy, confere o health check e então publica apenas artefatos assinados. Consulte [o processo de release do instalador](docs/RELEASING-WINDOWS-INSTALLER.md) antes de criar uma tag.

Use o `TraceSetup.exe` da [última release empresarial](https://github.com/dallogixempresa-max/Dallogix-Trace-Code/releases/latest) para instalar. O assistente apresenta duas etapas em janelas: identificação e vínculo opcional da máquina; depois, CLP, sensor e leitor. Senhas são solicitadas em campos protegidos. As opções são salvas antes de iniciar serviços, e o instalador mostra um diagnóstico legível se algo impedir a conclusão. O botão final permite abrir o Trace.

O `Instalar-PC-Windows.cmd`, quando usado a partir de uma cópia deste repositório, baixa a mesma release empresarial, compara o SHA-256 e abre o `TraceSetup.exe`. A conferência detecta arquivo incompleto ou alterado, mas não autentica o publicador do EXE. O script não executa mais um fluxo local separado nem tarefas de homologação.

Depois da instalação, o atalho do menu Iniciar “Configurar CLP e leitor do Dallogix Trace” permite alterar parâmetros Modbus, sensor e leitor sem editar `.env`. Para o KEYENCE SR-X100W, o fluxo principal usa AutoID Keyboard Wedge em modo HID para entregar os códigos ao Trace e FTP para as imagens de erro; TCP direto permanece opcional. Consulte [a configuração e homologação do KEYENCE SR-X100W](docs/KEYENCE-SR-X100W.md). O endereço IP e a porta do CLP continuam sendo mantidos no cadastro da Dala no Central. Escritas físicas ficam bloqueadas até vincular a Dala, confirmar o mapa e habilitar explicitamente.

Não coloque `.env`, credenciais, tokens ou configurações reais de máquina neste repositório. Use `.env.example` e `.env.production.example` como modelos e mantenha os segredos fora do Git.
