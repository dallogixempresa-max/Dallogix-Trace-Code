/**
 * Encaminha ações administrativas de empresas e PCs industriais.
 * A aplicação injeta os efeitos externos para manter este controlador testável.
 */
const ACOES_ADMINISTRATIVAS = new Set([
  "archive-company",
  "restore-company",
  "delete-company-permanently",
  "link-industrial-pc-dala",
  "generate-industrial-pc-code",
  "toggle-industrial-pc-access",
  "archive-industrial-pc",
  "restore-industrial-pc",
  "delete-industrial-pc",
  "generate-company-activation",
  "copy-company-activation",
  "toggle-license",
  "toggle-user",
  "reset-user-password",
]);

export function criarTratadorAcoesAdministracao({
  store,
  confirmarAcao,
  solicitarTexto,
  notificar,
  render,
  copiarTexto,
}) {
  const executarAcaoAdministrativa = async (acao, node) => {
    switch (acao) {
      case "archive-company": {
        const name = node.dataset.name || "esta empresa";
        if (!await confirmarAcao(`Arquivar a empresa "${name}"? Os dados serão preservados e os logins deixarão de acessar o sistema.`)) return true;
        try {
          await store.setCompanyArchiveState(node.dataset.id, true);
          notificar("Empresa arquivada. Os dados foram preservados.");
          render();
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "restore-company": {
        const name = node.dataset.name || "esta empresa";
        if (!await confirmarAcao(`Restaurar a empresa "${name}" e liberar novamente os acessos?`)) return true;
        try {
          await store.setCompanyArchiveState(node.dataset.id, false);
          notificar("Empresa restaurada.");
          render();
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "delete-company-permanently": {
        const name = node.dataset.name || "esta empresa";
        if (!await confirmarAcao(`Excluir definitivamente a empresa "${name}"? Os dados não poderão ser recuperados.`)) return true;
        const password = await solicitarTexto("Digite sua senha atual para confirmar a exclusão definitiva:", { tipo: "password", confirmar: "Validar senha" });
        if (password === null) return true;
        const confirmation = await solicitarTexto(`Digite exatamente o nome da empresa:\n${name}`, { confirmar: "Confirmar nome" });
        if (confirmation === null) return true;
        const credentials = { password, confirmation };
        try {
          await store.deleteCompany(node.dataset.id, { permanent: true, ...credentials });
          notificar("Empresa excluída definitivamente.");
          render();
        } catch (error) {
          if (error.status === 409) {
            const purge = await confirmarAcao(
              `A empresa "${name}" possui dados vinculados. Deseja apagar também todos os dados arquivados? Esta ação não poderá ser desfeita.`,
            );
            if (!purge) return true;
            try {
              await store.deleteCompany(node.dataset.id, { permanent: true, force: true, ...credentials });
              notificar("Empresa e dados vinculados excluídos definitivamente.");
              render();
            } catch (purgeError) {
              notificar(purgeError.message);
            }
            return true;
          }
          notificar(error.message);
        }
        return true;
      }
      case "link-industrial-pc-dala": {
        const form = node.closest(".industrial-pc-link-form");
        const installationId = form?.dataset.installationId;
        const equipmentId = form?.elements?.namedItem("equipment_id")?.value;
        if (!installationId || !equipmentId) {
          notificar("Selecione a Dala que pertence a este PC industrial.");
          return true;
        }
        try {
          await store.assignIndustrialEquipment(installationId, equipmentId);
          await store.loadCompanyDetail();
          render();
          notificar("Dala vinculada ao PC industrial.");
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "generate-industrial-pc-code":
        try {
          await store.generateIndustrialActivationCode(node.dataset.id);
          render();
        } catch (error) {
          notificar(error.message);
        }
        return true;
      case "toggle-industrial-pc-access": {
        const blocked = node.dataset.blocked === "true";
        const activated = node.dataset.activated === "true";
        const name = node.dataset.name || "este PC industrial";
        const confirmation = blocked
          ? activated
            ? `Bloquear o acesso remoto do PC industrial "${name}"? A operação local continuará funcionando.`
            : `Bloquear a ativação do PC industrial "${name}"? Ele não poderá receber um código até ser liberado.`
          : activated
            ? `Liberar o acesso remoto do PC industrial "${name}"? A sincronização será retomada.`
            : `Liberar a ativação do PC industrial "${name}" para permitir a geração de um código?`;
        if (!await confirmarAcao(confirmation)) return true;
        try {
          await store.setIndustrialAccessBlocked(node.dataset.id, blocked);
          render();
          notificar(blocked
            ? (activated ? "Acesso remoto bloqueado. O PC continua funcionando localmente." : "Ativação bloqueada para este PC.")
            : (activated ? "Acesso remoto liberado novamente." : "Ativação liberada. Agora é possível gerar um código."));
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "archive-industrial-pc": {
        const name = node.dataset.name || "este PC industrial";
        if (!await confirmarAcao(`Arquivar "${name}"? O acesso e o código serão revogados. A Dala e o histórico serão preservados.`)) return true;
        try {
          await store.setIndustrialInstallationArchived(node.dataset.id, true);
          render();
          notificar("PC industrial arquivado. Para usá-lo novamente, restaure o cadastro e faça uma nova ativação.");
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "restore-industrial-pc":
        try {
          await store.setIndustrialInstallationArchived(node.dataset.id, false);
          render();
          notificar("PC restaurado. Gere um novo código e faça a ativação no PC industrial.");
        } catch (error) {
          notificar(error.message);
        }
        return true;
      case "delete-industrial-pc": {
        const name = node.dataset.name || "este PC industrial";
        if (!await confirmarAcao(`Excluir definitivamente "${name}"? Esta ação não pode ser desfeita. A Dala e o histórico de operação serão mantidos.`)) return true;
        try {
          await store.deleteArchivedIndustrialInstallation(node.dataset.id);
          render();
          notificar("Cadastro do PC industrial excluído. A Dala e o histórico foram preservados.");
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "generate-company-activation":
        try {
          await store.generateCompanyActivation(node.dataset.id);
          render();
        } catch (error) {
          notificar(error.message);
        }
        return true;
      case "copy-company-activation": {
        const code = node.dataset.code || "";
        try {
          await copiarTexto(code);
          notificar("Código copiado.");
        } catch (error) {
          notificar(`Código de ativação: ${code}`);
        }
        return true;
      }
      case "toggle-license": {
        const active = node.dataset.status === "ATIVA";
        const reason = active
          ? await solicitarTexto("Motivo do bloqueio da licença:", { valorInicial: "Bloqueio manual", confirmar: "Bloquear licença" })
          : "Licença desbloqueada manualmente";
        if (reason === null) return true;
        try {
          await store.updateLicense({
            company_id: Number(node.dataset.id),
            status: active ? "BLOQUEADA" : "ATIVA",
            blocked_reason: active ? reason : "",
          });
          render();
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "toggle-user": {
        const active = node.dataset.active !== "1";
        if (!await confirmarAcao(`${active ? "Ativar" : "Desativar"} o login de ${node.dataset.name}?`)) return true;
        try {
          await store.updateUser({
            id: node.dataset.id,
            name: node.dataset.name,
            role: node.dataset.role,
            active,
            ...(store.state.userRole === "ADMIN_DALLOGIX" && store.state.selectedCompanyId
              ? { company_id: store.state.selectedCompanyId }
              : {}),
          });
          render();
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      case "reset-user-password": {
        const password = await solicitarTexto(
          `Nova senha para ${node.dataset.name} (mínimo 6 caracteres):`,
          { tipo: "password", confirmar: "Atualizar senha" },
        );
        if (password === null) return true;
        if (password.length < 6) {
          notificar("A senha deve ter pelo menos 6 caracteres.");
          return true;
        }
        try {
          await store.updateUser({
            id: node.dataset.id,
            name: node.dataset.name,
            role: node.dataset.role,
            active: node.dataset.active === "1",
            password,
            ...(store.state.userRole === "ADMIN_DALLOGIX" && store.state.selectedCompanyId
              ? { company_id: store.state.selectedCompanyId }
              : {}),
          });
          notificar("Senha atualizada.");
          render();
        } catch (error) {
          notificar(error.message);
        }
        return true;
      }
      default:
        return false;
    }
  };

  return function tratarAcaoAdministracao(acao, node) {
    if (!ACOES_ADMINISTRATIVAS.has(acao)) return false;
    return executarAcaoAdministrativa(acao, node);
  };
}
