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
        carregamento_id: Number(value.carregamento_id),
        event_uuid: String(value.event_uuid || "").trim(),
        detected_at_epoch_ms: Number(value.detected_at_epoch_ms),
    };
    if (!Number.isSafeInteger(event.equipment_id) || event.equipment_id < 1
        || !Number.isSafeInteger(event.carregamento_id) || event.carregamento_id < 1
        || !EVENT_UUID.test(event.event_uuid)
        || !Number.isSafeInteger(event.detected_at_epoch_ms) || event.detected_at_epoch_ms < 1) {
        throw new TypeError("Evento do sensor sem identificador ou dados válidos.");
    }
    return event;
}

function createSensorEventOutbox({ directory = process.env.TRACE_SENSOR_EVENT_OUTBOX_DIR || "/data/trace-sensor-outbox" } = {}) {
    let operationQueue = Promise.resolve();

    const serialize = (operation) => {
        const result = operationQueue.then(operation);
        operationQueue = result.catch(() => {});
        return result;
    };

    async function syncDirectory() {
        const handle = await fs.open(directory, "r");
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
    };
}

module.exports = {
    ...createSensorEventOutbox(),
    createSensorEventOutbox,
};
