import assert from "node:assert/strict";
import { mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import outboxModule from "../integracoes/node-red/sensor-event-outbox.cjs";

const { createSensorEventOutbox } = outboxModule;

test("fila do sensor persiste somente o evento e o recupera após recriar o serviço", async () => {
  const directory = await mkdtemp(join(tmpdir(), "trace-sensor-outbox-"));
  const event = {
    equipment_id: 12,
    carregamento_id: 34,
    event_uuid: "d4a00000-0000-4000-8000-000000000041",
    detected_at_epoch_ms: 1793314343923,
    traceToken: "segredo-que-nao-deve-ser-gravado",
  };
  try {
    const firstProcess = createSensorEventOutbox({ directory });
    await firstProcess.enqueue(event);
    const restartedProcess = createSensorEventOutbox({ directory });

    assert.deepEqual(await restartedProcess.list(), [{
      equipment_id: 12,
      carregamento_id: 34,
      event_uuid: "d4a00000-0000-4000-8000-000000000041",
      detected_at_epoch_ms: 1793314343923,
    }]);
    assert.equal(await restartedProcess.count(), 1);
    const files = await readdir(directory);
    assert.equal(files.length, 1);
    assert.doesNotMatch(await readFile(join(directory, files[0]), "utf8"), /segredo-que-nao-deve-ser-gravado/);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test("confirmação remove a passagem uma vez e é idempotente", async () => {
  const directory = await mkdtemp(join(tmpdir(), "trace-sensor-outbox-"));
  const outbox = createSensorEventOutbox({ directory });
  const event = {
    equipment_id: 12,
    carregamento_id: 34,
    event_uuid: "d4a00000-0000-4000-8000-000000000042",
    detected_at_epoch_ms: 1793314343924,
  };
  try {
    await outbox.enqueue(event);
    assert.equal(await outbox.acknowledge(event.event_uuid), true);
    assert.equal(await outbox.acknowledge(event.event_uuid), false);
    assert.deepEqual(await outbox.list(), []);
    assert.equal(await outbox.count(), 0);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test("não descarta arquivo de fila inválido nem aceita eventos incompletos", async () => {
  const directory = await mkdtemp(join(tmpdir(), "trace-sensor-outbox-"));
  const outbox = createSensorEventOutbox({ directory });
  try {
    await assert.rejects(async () => outbox.enqueue({ equipment_id: 1, carregamento_id: 2, event_uuid: "ruim", detected_at_epoch_ms: 1 }), /dados válidos/i);
    await writeFile(join(directory, "d4a00000-0000-4000-8000-000000000043.json"), "não é JSON");
    await assert.rejects(outbox.list(), SyntaxError);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
