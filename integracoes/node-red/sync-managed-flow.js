const fs = require('node:fs');
const path = require('node:path');

function syncManagedFlow(flowPath, seedPath) {
  const seed = JSON.parse(fs.readFileSync(seedPath, 'utf8'));
  const managedTab = seed.find((node) => node.type === 'tab' && node.id === 'trace-clp-bridge');
  if (!managedTab) throw new Error('A aba trace-clp-bridge não existe no fluxo de referência.');
  const managedSeed = seed.filter((node) => node.id === managedTab.id || node.z === managedTab.id);

  if (!fs.existsSync(flowPath)) {
    fs.mkdirSync(path.dirname(flowPath), { recursive: true });
    fs.copyFileSync(seedPath, flowPath);
    return { action: 'seeded' };
  }

  const current = JSON.parse(fs.readFileSync(flowPath, 'utf8'));
  const hasManagedTab = current.some((node) => node.type === 'tab' && node.id === managedTab.id);
  const tabs = current.filter((node) => node.type === 'tab');
  const onlyTab = tabs[0];
  const remainingNodes = onlyTab
    ? current.filter((node) => node.id !== onlyTab.id)
    : [];
  const isDefaultDockerWarning = remainingNodes.length === 1
    && remainingNodes[0].type === 'comment'
    && remainingNodes[0].z === onlyTab?.id
    && String(remainingNodes[0].name || '').startsWith(
      'WARNING: please check you have started this container with a volume that is mounted to /data\\n',
    )
    && String(remainingNodes[0].name || '').includes('If you are using named volumes you can ignore this warning.');
  const isEmptyPlaceholder = !hasManagedTab
    && tabs.length === 1
    && onlyTab.label === 'Flow 1'
    && (current.length === 1 || isDefaultDockerWarning);
  if (isEmptyPlaceholder) {
    const backupPath = `${flowPath}.backup-${Date.now()}-${process.pid}`;
    fs.copyFileSync(flowPath, backupPath);
    fs.copyFileSync(seedPath, flowPath);
    return { action: 'seeded-placeholder', backupPath };
  }
  if (!hasManagedTab) return { action: 'preserved-unmanaged-flow' };

  let inserted = false;
  const merged = [];
  for (const node of current) {
    const isManaged = node.id === managedTab.id || node.z === managedTab.id;
    if (isManaged) {
      if (!inserted) {
        merged.push(...managedSeed);
        inserted = true;
      }
      continue;
    }
    merged.push(node);
  }
  if (!inserted) throw new Error('Não foi possível localizar a aba gerenciada no fluxo persistente.');
  if (JSON.stringify(current) === JSON.stringify(merged)) return { action: 'unchanged' };

  const backupPath = `${flowPath}.backup-${Date.now()}-${process.pid}`;
  const temporaryPath = `${flowPath}.tmp-${process.pid}`;
  fs.copyFileSync(flowPath, backupPath);
  fs.writeFileSync(temporaryPath, `${JSON.stringify(merged, null, 2)}\n`, { mode: 0o600 });
  fs.renameSync(temporaryPath, flowPath);
  return { action: 'updated-managed-tab', backupPath };
}

if (require.main === module) {
  const flowPath = process.argv[2] || '/data/flows.json';
  const seedPath = process.argv[3] || '/seed/trace-clp-bridge.flow.json';
  const result = syncManagedFlow(flowPath, seedPath);
  process.stdout.write(`Node-RED flow sync: ${result.action}\n`);
}

module.exports = { syncManagedFlow };
