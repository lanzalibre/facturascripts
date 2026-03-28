#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const PHP = '/opt/homebrew/bin/php';
const QWEN_URL = 'http://localhost:8081/v1/chat/completions';
const QWEN_MODEL = 'Qwen_Qwen3-14B-Q4_K_M.gguf';

const args = process.argv.slice(2);
const opts = {
  dataDir: './sync_data',
  ejercicio: null,
  startDate: null,
  endDate: null,
  dryRun: false,
  limit: 0,
};

for (let i = 0; i < args.length; i++) {
  if (args[i] === '--data-dir') opts.dataDir = args[++i];
  else if (args[i] === '--ejercicio') opts.ejercicio = args[++i];
  else if (args[i] === '--start-date') opts.startDate = args[++i];
  else if (args[i] === '--end-date') opts.endDate = args[++i];
  else if (args[i] === '--dry-run') opts.dryRun = true;
  else if (args[i] === '--limit') opts.limit = parseInt(args[++i]) || 0;
}

if (!opts.ejercicio) {
  console.error('Error: --ejercicio is required');
  process.exit(1);
}

const dataDir = path.resolve(opts.dataDir);
if (!fs.existsSync(dataDir)) {
  console.error(`Error: data-dir '${dataDir}' not found`);
  process.exit(1);
}

const ts = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

console.log(`[${ts()}] === COMPLETE SYNC (Direct Ledger Import) ===`);
console.log(`[${ts()}] Data dir: ${dataDir}`);
console.log(`[${ts()}] Ejercicio: ${opts.ejercicio}`);
console.log(`[${ts()}] Date range: ${opts.startDate || 'all'} → ${opts.endDate || 'all'}`);
console.log(`[${ts()}] Dry-run: ${opts.dryRun}`);
console.log('');

function runPhp(script, extraArgs = []) {
  const cmd = `${PHP} ${path.join(__dirname, script)} ${extraArgs.join(' ')}`;
  console.log(`[${ts()}]   Running: ${cmd}`);
  const result = execSync(cmd, { cwd: __dirname, encoding: 'utf-8', timeout: 300000, stdio: 'pipe' });
  console.log(result);
  return result;
}

function askQwen(prompt) {
  try {
    const res = execSync(
      `curl -s --max-time 30 ${QWEN_URL} -H "Content-Type: application/json" -d '${JSON.stringify({ model: QWEN_MODEL, messages: [{ role: 'user', content: prompt }], temperature: 0.1, max_tokens: 256 })}'`,
      { encoding: 'utf-8', timeout: 35000 }
    );
    const parsed = JSON.parse(res);
    return parsed.choices?.[0]?.message?.content || '';
  } catch {
    return null;
  }
}

async function main() {
  const ledgerPath = path.join(dataDir, 'ledger_lines.json');
  const matchedPath = path.join(dataDir, 'matched_entries.json');
  const pdfIndexPath = path.join(dataDir, 'pdf_index.json');

  if (!fs.existsSync(ledgerPath)) {
    console.error(`Missing: ${ledgerPath}. Run download step first.`);
    process.exit(1);
  }

  const pdfIndex = fs.existsSync(pdfIndexPath)
    ? JSON.parse(fs.readFileSync(pdfIndexPath, 'utf8'))
    : {};

  const purchaseMatched = fs.existsSync(matchedPath)
    ? Object.entries(JSON.parse(fs.readFileSync(matchedPath, 'utf8'))).filter(([, e]) => e.doc?.docType === 'purchase')
    : [];

  const saleMatched = fs.existsSync(matchedPath)
    ? Object.entries(JSON.parse(fs.readFileSync(matchedPath, 'utf8'))).filter(([, e]) => e.doc?.docType === 'invoice')
    : [];

  const matchedInDateRange = [...purchaseMatched, ...saleMatched].filter(([, e]) => {
    if (!opts.startDate && !opts.endDate) return true;
    const d = new Date(e.doc.date * 1000).toISOString().slice(0, 10);
    if (opts.startDate && d < opts.startDate) return false;
    if (opts.endDate && d > opts.endDate) return false;
    return true;
  });

  console.log(`[${ts()}] --- Loading data ---`);
  console.log(`[${ts()}]   Purchase matched (in range): ${matchedInDateRange.filter(([, e]) => e.doc?.docType === 'purchase').length}`);
  console.log(`[${ts()}]   Sale matched (in range): ${matchedInDateRange.filter(([, e]) => e.doc?.docType === 'invoice').length}`);
  console.log(`[${ts()}]   PDFs available: ${Object.keys(pdfIndex).length}`);
  console.log('');

  const clearArgs = ['--ejercicio', opts.ejercicio];
  if (opts.dryRun) clearArgs.push('--dry-run');

  console.log(`[${ts()}] === Phase 1: Clear ===`);
  runPhp('sync_clear_all.php', clearArgs);

  console.log(`[${ts()}] === Phase 2: Import ALL Asientos ===`);
  const uploadArgs = ['--data-dir', dataDir, '--ejercicio', opts.ejercicio];
  if (opts.startDate) uploadArgs.push('--start-date', opts.startDate);
  if (opts.endDate) uploadArgs.push('--end-date', opts.endDate);
  if (opts.dryRun) uploadArgs.push('--dry-run');
  if (opts.limit > 0) uploadArgs.push('--limit', String(opts.limit));
  runPhp('sync_upload_fast.php', uploadArgs);

  if (matchedInDateRange.length > 0) {
    console.log(`[${ts()}] === Phase 3: Create FacturaProveedor (${matchedInDateRange.filter(([, e]) => e.doc?.docType === 'purchase').length} entries) ===`);
    const fpArgs = ['--data-dir', dataDir, '--ejercicio', opts.ejercicio, '--type', 'purchase', '--output', 'fp_results.json'];
    if (opts.dryRun) fpArgs.push('--dry-run');
    if (opts.limit > 0) fpArgs.push('--limit', String(opts.limit));
    runPhp('sync_create_invoice.php', fpArgs);
  }

  if (matchedInDateRange.filter(([, e]) => e.doc?.docType === 'invoice').length > 0) {
    console.log(`[${ts()}] === Phase 4: Create FacturaCliente (${matchedInDateRange.filter(([, e]) => e.doc?.docType === 'invoice').length} entries) ===`);
    const fcArgs = ['--data-dir', dataDir, '--ejercicio', opts.ejercicio, '--type', 'sale', '--output', 'fc_results.json'];
    if (opts.dryRun) fcArgs.push('--dry-run');
    if (opts.limit > 0) fcArgs.push('--limit', String(opts.limit));
    runPhp('sync_create_invoice.php', fcArgs);
  }

  console.log(`[${ts()}] === Phase 5: Attach PDFs ===`);
  if (Object.keys(pdfIndex).length > 0) {
    const pdfMapping = {};
    for (const [entryNum, pdfFile] of Object.entries(pdfIndex)) {
      const trimmed = pdfFile.trim();
      const safe = trimmed.replace(/[#]/g, '_').replace(/[^\w.\-]/g, '_');
      if (fs.existsSync(path.join(dataDir, 'pdfs', safe))) {
        pdfMapping[entryNum] = safe;
      } else if (fs.existsSync(path.join(dataDir, 'pdfs', trimmed))) {
        pdfMapping[entryNum] = trimmed;
      } else {
        pdfMapping[entryNum] = pdfFile;
      }
    }

    const pdfEntries = Object.entries(pdfMapping);
    console.log(`[${ts()}]   PDFs to attach: ${pdfEntries.length}`);

    if (opts.dryRun) {
      for (const [entryNum, pdfFile] of pdfEntries) {
        console.log(`[${ts()}]   [DRY] Would attach ${pdfFile} to entry #${entryNum}`);
      }
    } else {
      const entryResultsPath = path.join(dataDir, 'entry_results.json');
      const fpResultsPath = path.join(dataDir, 'fp_results.json');
      const fcResultsPath = path.join(dataDir, 'fc_results.json');
      const attachArgs = ['--pdf-dir', path.join(dataDir, 'pdfs')];
      if (fs.existsSync(entryResultsPath)) attachArgs.push('--entry-results', entryResultsPath);
      if (fs.existsSync(fpResultsPath)) attachArgs.push('--invoice-results', fpResultsPath);
      else if (fs.existsSync(fcResultsPath)) attachArgs.push('--invoice-results', fcResultsPath);

      const mappingJson = JSON.stringify(pdfMapping);
      const escaped = mappingJson.replace(/'/g, "'\\''");
      try {
        const result = execSync(
          `echo '${escaped}' | ${PHP} ${path.join(__dirname, 'sync_attach_pdfs.php')} ${attachArgs.join(' ')}`,
          { cwd: __dirname, encoding: 'utf-8', timeout: 120000 }
        );
        console.log(result);
      } catch (e) {
        console.log(`[${ts()}]   PDF attach error: ${e.message}`);
      }
    }
  } else {
    console.log(`[${ts()}]   No PDFs to attach`);
  }

  console.log('');
  console.log(`[${ts()}] === SYNC COMPLETE ===`);
}

main().catch(err => {
  console.error(`[${ts()}] Fatal error:`, err.message);
  process.exit(1);
});
