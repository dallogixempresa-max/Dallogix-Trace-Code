# Release do instalador Windows

O workflow `Windows installer` gera um instalador de revisão em cada pull request. Esse artefato não é assinado e não deve ser instalado em produção.

Ao receber uma tag SemVer `vN.N.N`, o workflow primeiro valida a tag, a ancestralidade em `main` e o avanço fast-forward a partir de `production`. Antes de tocar o Central, ele valida o PFX, o thumbprint, o uso de assinatura de código e o par de chaves do atualizador. Se qualquer material estiver ausente ou inválido, o deploy não começa.

Configure o ambiente protegido do GitHub chamado `production-release` com:

- Secret `AUTHENTICODE_CERTIFICATE_PFX_BASE64`: certificado corporativo de assinatura de código em PFX, codificado em Base64.
- Secret `AUTHENTICODE_CERTIFICATE_PASSWORD`: senha desse PFX.
- Variable `AUTHENTICODE_CERTIFICATE_THUMBPRINT`: impressão digital esperada do certificado.
- Secret `UPDATE_SIGNING_PRIVATE_KEY`: chave RSA PEM correspondente à chave pública versionada em `servidor/configuracao/trace-update-public.pem`.

Mantenha os arquivos PFX, senhas e chave privada somente nos segredos protegidos do GitHub. Não coloque esses materiais no repositório ou no instalador. O ambiente só aceita tags de release com o padrão `v*.*.*`; o workflow também valida o formato SemVer estrito.

Depois da pré-validação, o workflow envia o bundle exato da tag pela chave de deploy restrita. O aplicador do servidor faz backup validado, adia o deploy enquanto houver carregamento ativo ou manutenção, aplica migrations e confirma a versão/commit pelo health check. O workflow confere novamente `https://trace.dallogix.com/api/health.php`, avança `production` por fast-forward e só então assina/publica o instalador e o pacote do atualizador. Se o health check falhar, nenhuma release é publicada.

Se a chave privada do atualizador for rotacionada, atualize a chave pública versionada neste repositório. Instalações existentes que ainda confiam na chave antiga precisam receber manualmente ou com assistência um novo instalador antes de aceitar pacotes assinados pela chave nova.

O configurador do PC cobre o mapa homologado do INVT TS621, parâmetros Modbus, sensor, leitor USB HID e janela de associação de leitura. O endereço IP e a porta Modbus continuam sendo os valores registrados para cada Dala no Central. O leitor KEYENCE SR-X100W via Ethernet/TCP ainda precisa de uma integração de software própria; modo de teclado USB HID só funciona se o leitor estiver configurado assim.
