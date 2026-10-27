# Release do instalador Windows

O workflow `Windows installer` gera um instalador de revisão em cada pull request. Esse artefato não é assinado e não deve ser instalado em produção.

Ao receber uma tag SemVer `vN.N.N`, o workflow valida a ancestralidade em `main` e o avanço fast-forward a partir de `production`. Antes de tocar o Central, ele autentica no SSL.com e confere se o certificado de assinatura de código com o thumbprint configurado está disponível no eSigner. Também valida a chave do atualizador. Se qualquer material estiver ausente ou inválido, o deploy não começa.

## Configuração única do assinador

O SSL.com eSigner exige uma conta, um certificado OV ou EV de assinatura de código emitido para a empresa e a inscrição desse certificado no serviço eSigner. A validação empresarial e a compra do certificado são feitas diretamente com o emissor; não use certificado autoassinado em produção. O eSigner mantém a chave Authenticode no HSM em nuvem, portanto não há PFX nem senha de PFX para guardar.

No ambiente protegido do GitHub chamado `production-release`, configure:

- Secret `ESIGNER_USERNAME`: usuário da conta SSL.com.
- Secret `ESIGNER_PASSWORD`: senha da conta SSL.com.
- Secret `ESIGNER_TOTP_SECRET`: segredo TOTP do autenticador eSigner, não um código temporário.
- Secret `UPDATE_SIGNING_PRIVATE_KEY`: chave RSA PEM correspondente à chave pública versionada em `servidor/configuracao/trace-update-public.pem`.
- Variable `AUTHENTICODE_CERTIFICATE_THUMBPRINT`: thumbprint de 40 caracteres do certificado de assinatura de código inscrito no eSigner.

Não coloque credenciais, sementes TOTP ou chaves privadas no repositório, em artefatos ou no chat. O ambiente só aceita tags de release com o padrão `v*.*.*`; o workflow também valida o formato SemVer estrito.

O pré-check instala o CKA do pacote oficial SSL.com fixado por versão e SHA-256, autentica a conta e verifica o certificado e seu uso de assinatura. Ele não executa uma assinatura de teste para não consumir uma operação do serviço. Depois do deploy e do health check, o workflow assina o instalador real com timestamp e verifica a assinatura Authenticode antes de criar a release.

## Fluxo da publicação

Depois do pré-check, o workflow envia o bundle exato da tag pela chave de deploy restrita. O aplicador do servidor faz backup validado, adia o deploy enquanto houver carregamento ativo ou manutenção, aplica migrations e confirma a versão/commit pelo health check. O workflow confere novamente `https://trace.dallogix.com/api/health.php`, avança `production` por fast-forward e só então assina/publica o instalador e o pacote do atualizador. Se o deploy ou o health check falhar, nenhuma release é publicada.

Se o serviço eSigner falhar durante a assinatura final, o Central já poderá estar atualizado, mas a release continuará bloqueada. Isso segue a ordem de deploy e health check antes da publicação, sem consumir uma assinatura de teste em cada tag.

Se a chave do atualizador for rotacionada, atualize a chave pública versionada neste repositório. Instalações existentes que ainda confiam na chave antiga precisam receber manualmente ou com assistência um novo instalador antes de aceitar pacotes assinados pela chave nova.

O configurador do PC cobre o mapa homologado do INVT TS621, parâmetros Modbus, sensor, leitor USB HID e janela de associação de leitura. O endereço IP e a porta Modbus continuam sendo os valores registrados para cada Dala no Central. O leitor KEYENCE SR-X100W via Ethernet/TCP ainda precisa de uma integração de software própria; modo de teclado USB HID só funciona se o leitor estiver configurado assim.
