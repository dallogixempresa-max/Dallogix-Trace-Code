"use strict";

const fs = require("node:fs/promises");
const path = require("node:path");

const EVENT_UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function normalizeEvent(value) {
    if (!value || typeof value !== "object" || Array.isArray(value)) {
        throw new TypeError("Evento do sensor inválido para persistência.");
    }
    const event = {
        equipment_id: Number(value.equipment_id),
        carregamento_id: value.carregamento_id == null ? null : Number(value.carregamento_id),
        event_uuid: String(value.event_uuid || "").trim(),
        detected_at_epoch_ms: Number(value.detected_at_epoch_ms),
    };
    if (!Number.isSafeInteger(event.equipment_id) || event.equipment_id < 1
        || (event.carregamento_id !== null && (!Number.isSafeInteger(event.carregamento_id) || event.carregamento_id < 1))
        || !EVENT_UUID.test(event.event_uuid)
        || !Number.isSafeInteger(event.detected_at_epoch_ms) || event.detected_at_epoch_ms < 1) {
        throw new TypeError("Evento do sensor sem identificador ou dados válidos.");
    }
    return event;
}

function createSensorEventOutbox({ directory = process.env.TRACE_SENSOR_EVENT_OUTBOX_DIR || "/data/trace-sensor-outbox" } = {}) {
    const rejectedDirectory = path.join(directory, "rejected");
    let operationQueue = Promise.resolve();

    const serialize = (operation) => {
        const result = operationQueue.then(operation);
        operationQueue = result.catch(() => {});
        return result;
    };

    async function syncDirectory(targetDirectory = directory) {
        const handle = await fs.open(targetDirectory, "r");
        try {
            await handle.sync();
        } finally {
            await handle.close();
        }
    }

    async function writeEvent(event) {
        await fs.mkdir(directory, { recursive: true, mode: 0o700 });
        const target = path.join(directory, `${event.event_uuid}.json`);
        try {
            const existing = JSON.parse(await fs.readFile(target, "utf8"));
            if (JSON.stringify(normalizeEvent(existing.event)) !== JSON.stringify(event)) {
                throw new Error(`O UUID ${event.event_uuid} já existe com dados diferentes.`);
            }
            return target;
        } catch (error) {
            if (error?.code !== "ENOENT") throw error;
        }

        const temporary = path.join(directory, `.${event.event_uuid}.${process.pid}.${Date.now()}.tmp`);
        const handle = await fs.open(temporary, "wx", 0o600);
        try {
            await handle.writeFile(`${JSON.stringify({ version: 1, event })}\n`, "utf8");
            await handle.sync();
        } catch (error) {
            await handle.close().catch(() => {});
            await fs.unlink(temporary).catch(() => {});
            throw error;
        }
        await handle.close();
        try {
            await fs.rename(temporary, target);
            await syncDirectory();
        } catch (error) {
            await fs.unlink(temporary).catch(() => {});
            throw error;
        }
        return target;
    }

    return {
        enqueue(value) {
            const event = normalizeEvent(value);
            return serialize(async () => {
                await writeEvent(event);
                return event;
            });
        },
        list() {
            return serialize(async () => {
                await fs.mkdir(directory, { recursive: true, mode: 0o700 });
                const names = (await fs.readdir(directory))
                    .filter((name) => EVENT_UUID.test(name.replace(/\.json$/i, "")) && name.toLowerCase().endsWith(".json"));
                const events = [];
                for (const name of names) {
                    const record = JSON.parse(await fs.readFile(path.join(directory, name), "utf8"));
                    const event = normalizeEvent(record.event);
                    if (record.version !== 1 || name !== `${event.event_uuid}.json`) {
                        throw new Error(`Registro inválido na fila local do sensor: ${name}.`);
                    }
                    events.push(event);
                }
                return events.sort((left, right) =>
                    left.detected_at_epoch_ms - right.detected_at_epoch_ms
                    || left.event_uuid.localeCompare(right.event_uuid));
            });
        },
        count() {
            return serialize(async () => {
                await fs.mkdir(directory, { recursive: true, mode: 0o700 });
                const names = await fs.readdir(directory);
                return names.filter((name) => name.toLowerCase().endsWith(".json")).length;
            });
        },
        acknowledge(eventUuid) {
            const id = String(eventUuid || "").trim();
            if (!EVENT_UUID.test(id)) throw new TypeError("UUID inválido para confirmar passagem do sensor.");
            return serialize(async () => {
                const target = path.join(directory, `${id}.json`);
                try {
                    await fs.unlink(target);
                    await syncDirectory();
                    return true;
                } catch (error) {
                    if (error?.code === "ENOENT") return false;
                    throw error;
                }
            });
        },
        reject(eventUuid, status, reason) {
            const id = String(eventUuid || "").trim();
            if (!EVENT_UUID.test(id)) throw new TypeError("UUID inválido para rejeitar passagem do sensor.");
            const httpStatus = Number(status);
            if (![409, 422].includes(httpStatus)) throw new TypeError("Somente rejeições definitivas podem sair da fila.");
            const safeReason = String(reason || "rejeitado pelo servidor").slice(0, 500);
            return serialize(async () => {
                const pending = path.join(directory, `${id}.json`);
                const archived = path.join(rejectedDirectory, `${id}.json`);
                let event;
                try {
                    const record = JSON.parse(await fs.readFile(pending, "utf8"));
                    event = normalizeEvent(record.event);
                    if (event.event_uuid !== id || record.version !== 1) throw new Error("Evento pendente inválido.");
                } catch (error) {
                    if (error?.code !== "ENOENT") throw error;
                    if (await fs.stat(archived).catch(() => null)) return false;
                    throw error;
                }
                await fs.mkdir(rejectedDirectory, { recursive: true, mode: 0o700 });
                const previousArchive = await fs.readFile(archived, "utf8").catch((error) => {
                    if (error?.code === "ENOENT") return null;
                    throw error;
                });
                if (previousArchive !== null) {
                    const previous = JSON.parse(previousArchive);
                    if (JSON.stringify(normalizeEvent(previous.event)) !== JSON.stringify(event)) {
                        throw new Error(`Arquivo de rejeição conflitante para ${id}.`);
                    }
                    await fs.unlink(pending);
                    await syncDirectory();
                    return true;
                }
                const audit = { version: 1, event, rejected_at: new Date().toISOString(), http_status: httpStatus, reason: safeReason };
                const temporary = path.join(rejectedDirectory, `.${id}.${process.pid}.${Date.now()}.tmp`);
                const handle = await fs.open(temporary, "wx", 0o600);
                try {
                    await handle.writeFile(`${JSON.stringify(audit)}\n`, "utf8");
                    await handle.sync();
                } catch (error) {
                    await handle.close().catch(() => {});
                    await fs.unlink(temporary).catch(() => {});
                    throw error;
                }
                await handle.close();
                try {
                    await fs.rename(temporary, archived);
                    await syncDirectory(rejectedDirectory);
                    await fs.unlink(pending);
                    await syncDirectory();
                } catch (error) {
                    await fs.unlink(temporary).catch(() => {});
                    throw error;
                }
                return true;
            });
        },
        rejectedCount() {
            return serialize(async () => {
                const names = await fs.readdir(rejectedDirectory).catch((error) => {
                    if (error?.code === "ENOENT") return [];
                    throw error;
                });
                return names.filter((name) => name.toLowerCase().endsWith(".json")).length;
            });
        },
    };
}

module.exports = {
    ...createSensorEventOutbox(),
    createSensorEventOutbox,
};
