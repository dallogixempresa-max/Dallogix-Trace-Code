# Release do instalador Windows

O workflow `Windows installer` gera um instalador de revisão em cada pull request. Esse artefato não deve ser distribuído como release.

Ao receber uma tag SemVer `vN.N.N`, o workflow valida a ancestralidade em `main` e o avanço fast-forward a partir de `production`. Antes de tocar o Central, confere se a chave RSA do atualizador corresponde à chave pública versionada no repositório. Se a chave estiver ausente ou inválida, o deploy não começa.

## Assinaturas usadas

O `TraceSetup.exe` é publicado **sem assinatura Authenticode**. O Windows pode exibir publicador desconhecido ou um aviso do SmartScreen. O SHA-256 permite conferir que o arquivo baixado corresponde ao artefato acompanhado; ele não identifica quem publicou o executável. Baixe somente a release oficial deste repositório e confira `TraceSetup.sha256.txt` antes de executar.

Separadamente, o manifesto do atualizador recebe assinatura RSA com a chave privada protegida no GitHub Actions. Essa assinatura permite que o aplicativo valide os pacotes de atualização usando `servidor/configuracao/trace-update-public.pem`; ela não transforma o instalador Windows em um executável Authenticode assinado.

## Configuração do GitHub

No ambiente protegido `production-release`, configure somente:

- Secret `UPDATE_SIGNING_PRIVATE_KEY`: chave RSA PEM correspondente à chave pública versionada em `servidor/configuracao/trace-update-public.pem`.

Não coloque a chave privada no repositório, em artefatos ou no chat. O ambiente aceita tags de release com o padrão `v*.*.*`; o workflow também valida o formato SemVer estrito.

## Fluxo da publicação

O workflow envia à VM o bundle exato da tag pela chave de deploy restrita. O aplicador remoto faz backup validado, adia o deploy enquanto houver carregamento ativo ou manutenção, aplica migrations e confirma a versão/commit pelo health check. O workflow confere `https://trace.dallogix.com/api/health.php`, avança `production` por fast-forward e só então compila o instalador, calcula o SHA-256, assina o manifesto do atualizador e publica a release. Se o deploy ou o health check falhar, nenhuma release é publicada.

O pacote do atualizador inclui a saída completa do mesmo `dotnet publish` usada pelo instalador, além de `trace.ico`. O workflow valida esses arquivos no tar antes de assinar o manifesto. No PC industrial, o `TraceUpdater.ps1` confere os hashes instalados contra o pacote e, se o executável divergir, faz a troca por uma cópia temporária verificada. Qualquer divergência interrompe a atualização e aciona o rollback existente.

Se a chave RSA do atualizador for rotacionada, atualize a chave pública versionada neste repositório. Instalações existentes que ainda confiam na chave antiga precisam receber manualmente ou com assistência um novo instalador antes de aceitar pacotes assinados pela chave nova.

O configurador do PC cobre o mapa homologado do INVT TS621, parâmetros Modbus, sensor e leitores USB HID compatíveis ou KEYENCE SR-X100W por Ethernet/TCP. A integração Keyence recebe códigos por TCP e imagens de erro por FTP no perfil industrial. A configuração física e a homologação com o leitor real ainda precisam ser feitas; consulte [o guia do KEYENCE SR-X100W](KEYENCE-SR-X100W.md). O endereço IP e a porta Modbus continuam sendo os valores registrados para cada Dala no Central.
