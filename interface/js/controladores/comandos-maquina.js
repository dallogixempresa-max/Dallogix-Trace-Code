/** Serializa cliques por carregamento, sem descartar comandos opostos. */
export function createMachineCommandQueue({ onDrained = () => {} } = {}) {
  const queues = new Map();
  const pending = new Set();

  function enqueue(loadingId, commandKey, execute) {
    const id = Number(loadingId) || null;
    if (!id) return Promise.reject(new Error("Nenhum carregamento ativo para esta Dala."));
    const queueKey = String(id);
    const duplicateKey = `${queueKey}:${commandKey}`;
    if (pending.has(duplicateKey)) return Promise.resolve(null);

    pending.add(duplicateKey);
    const previous = queues.get(queueKey) || Promise.resolve();
    const request = previous.catch(() => {}).then(execute);
    const tail = request.catch(() => {});
    queues.set(queueKey, tail);

    return request.finally(() => {
      pending.delete(duplicateKey);
      if (queues.get(queueKey) !== tail) return;
      queues.delete(queueKey);
      onDrained(id);
    });
  }

  return { enqueue };
}
