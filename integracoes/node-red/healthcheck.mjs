import { connect } from "node:net";
import { pathToFileURL } from "node:url";

function nodeRedListening() {
  return new Promise((resolve) => {
    const socket = connect(1880, "127.0.0.1");
    let settled = false;
    const finish = (healthy) => {
      if (settled) return;
      settled = true;
      socket.destroy();
      resolve(healthy);
    };
    socket.setTimeout(2000, () => finish(false));
    socket.once("connect", () => finish(true));
    socket.once("error", () => finish(false));
  });
}

export async function checkGatewayHealth({
  apiUrl = process.env.TRACE_API_URL,
  deviceToken = process.env.TRACE_DEVICE_TOKEN,
  simulation = process.env.TRACE_LOCAL_SIMULATION === "1",
  fetchFn = fetch,
  listening = nodeRedListening,
} = {}) {
  if (!(await listening())) return false;
  if (simulation) return true;
  if (!apiUrl || !deviceToken) return false;
  try {
    const response = await fetchFn(new URL("/api/equipamentos_gateway.php", apiUrl), {
      headers: { "X-Device-Token": deviceToken },
      signal: AbortSignal.timeout(3000),
    });
    if (!response.ok) return false;
    const equipment = (await response.json())?.data;
    return Array.isArray(equipment) && equipment.length > 0
      && equipment.every((item) => Number(item.modbus_recently_confirmed) === 1);
  } catch {
    return false;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const healthy = await checkGatewayHealth();
  process.exitCode = healthy ? 0 : 1;
}
