# Release do instalador Windows

O workflow `Windows installer` gera um instalador de revisão em cada pull request. Esse artefato não é assinado e não deve ser instalado em produção.

Uma tag `vN.N.N` só publica uma release depois que o mesmo commit já estiver implantado em `https://trace.dallogix.com`. O workflow confere o health endpoint, a ancestralidade em `main`, a assinatura Authenticode e a assinatura do manifesto do atualizador. Qualquer segredo ausente ou certificado inválido reprova a publicação.

Configure o ambiente protegido do GitHub chamado `production-release` com:

- Secret `AUTHENTICODE_CERTIFICATE_PFX_BASE64`: certificado corporativo de assinatura de código em PFX, codificado em Base64.
- Secret `AUTHENTICODE_CERTIFICATE_PASSWORD`: senha desse PFX.
- Variable `AUTHENTICODE_CERTIFICATE_THUMBPRINT`: impressão digital esperada do certificado.
- Secret `UPDATE_SIGNING_PRIVATE_KEY`: chave RSA PEM correspondente à chave pública versionada em `servidor/configuracao/trace-update-public.pem`.

Mantenha os arquivos PFX, senhas e chave privada somente nos segredos protegidos do GitHub. Não coloque esses materiais no repositório ou no instalador. Faça o deploy do Central para o mesmo commit antes de criar a tag; o workflow não implanta o Central automaticamente.

O configurador do PC cobre o mapa homologado do INVT TS621, parâmetros Modbus, sensor, leitor USB HID e janela de associação de leitura. O endereço IP e a porta Modbus continuam sendo os valores registrados para cada Dala no Central. O leitor KEYENCE SR-X100W via Ethernet/TCP ainda precisa de uma integração de software própria; modo de teclado USB HID só funciona se o leitor estiver configurado assim.
