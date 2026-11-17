## Evitar regressões em funcionalidades já resolvidas

1. **Registrar a base antes de editar** — confirme repositório, branch, `HEAD`
   e alterações existentes. Preserve o trabalho que já estava no diretório e
   anote os comportamentos que o usuário confirmou como resolvidos e que podem
   ser afetados, especialmente os comandos da operação, as fotos da tela inicial
   e a contagem de sacas.

2. **Mapear o impacto** — identifique as telas e fluxos que dependem dos arquivos
   compartilhados que serão alterados. Mantenha a mudança restrita ao pedido e
   evite refatorações ou ajustes sem relação direta.

3. **Validar o conjunto afetado** — confira o diff e valide o comportamento
   pedido junto com os fluxos resolvidos que compartilham o código tocado. Se
   não puder confirmar algum deles, declare a validação pendente antes de iniciar
   outra mudança.

4. **Conter regressões** — se algo antes resolvido falhar, pare a mudança nova e
   localize a alteração que introduziu a falha. Corrija ou reverta somente o que
   foi alterado na tarefa atual; preserve mudanças preexistentes e não acumule
   remendos sem confirmar a causa.

5. **Relatar evidências separadamente** — diferencie o que foi confirmado por
   revisão de código, testes, interface, CLP/equipamento e produção. Uma
   validação em uma camada não comprova as demais.
