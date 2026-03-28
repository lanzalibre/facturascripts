#!/usr/bin/env node

const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');

const HOLDED_API_KEY = 'd1ba429d7a908a120b6c3f81a7bff9c0';
const FACTURASCRIPTS_MCP_URL = 'http://facturas.localhost/api/3/mcp';
const FACTURASCRIPTS_API_KEY = 'a92af3c4ff1db02df58b88776e61f450';
const HOLDED_ACCOUNTING_URL = 'https://api.holded.com/api/accounting/v1';
const HOLDED_INVOICING_URL = 'https://api.holded.com/api/invoicing/v1';

class Logger {
  constructor(logFile) {
    this.logFile = logFile || path.join(__dirname, 'sync_holded.log');
    const dir = path.dirname(this.logFile);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  }

  log(msg) {
    const ts = new Date().toISOString();
    const line = `[${ts}] ${msg}`;
    console.log(line);
    fs.appendFileSync(this.logFile, line + '\n');
  }

  error(msg) {
    const ts = new Date().toISOString();
    const line = `[${ts}] ERROR: ${msg}`;
    console.error(line);
    fs.appendFileSync(this.logFile, line + '\n');
  }
}

function makeRequest(url, options = {}) {
  return new Promise((resolve, reject) => {
    const timeout = options.timeout || 30000;
    const parsed = new URL(url);
    const mod = parsed.protocol === 'https:' ? https : http;
    const headers = { ...options.headers };

    if (!headers['Content-Type']) headers['Content-Type'] = 'application/json';

    const req = mod.request({
      hostname: parsed.hostname,
      port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
      path: parsed.pathname + parsed.search,
      method: options.method || 'GET',
      headers,
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, data: JSON.parse(data) });
        } catch {
          resolve({ status: res.statusCode, data });
        }
      });
    });

    req.setTimeout(timeout, () => {
      req.destroy();
      reject(new Error(`Request timeout: ${url}`));
    });

    req.on('error', reject);

    if (options.body) {
      req.write(typeof options.body === 'string' ? options.body : JSON.stringify(options.body));
    }
    req.end();
  });
}

let mcpRequestId = 1;

async function callMcpTool(name, args = {}) {
  const id = mcpRequestId++;
  const res = await makeRequest(FACTURASCRIPTS_MCP_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Auth-Token': FACTURASCRIPTS_API_KEY,
    },
    body: JSON.stringify({
      jsonrpc: '2.0',
      method: 'tools/call',
      params: { name, arguments: args },
      id,
    }),
  });

  if (res.data.error) {
    throw new Error(`MCP tool ${name} error: ${JSON.stringify(res.data.error)}`);
  }
  return res.data.result || {};
}

function holdedHeaders() {
  return { 'Accept': 'application/json', 'key': HOLDED_API_KEY };
}

async function holdedGet(url) {
  const res = await makeRequest(url, { headers: holdedHeaders() });
  if (res.status >= 400 || (typeof res.data === 'object' && res.data.info)) {
    const msg = res.data.info || JSON.stringify(res.data);
    throw new Error(`Holded API error ${res.status}: ${msg}`);
  }
  return res.data;
}

async function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

function padAccount(code) {
  const s = String(code).trim();
  if (s.length >= 10) return s;
  return s.padEnd(10, '0');
}

function parseArgs() {
  const argv = process.argv.slice(2);
  const opts = {
    step: null,
    dataDir: null,
    dryRun: false,
    skipClear: false,
    includeSales: false,
    ejercicio: '2026',
    limit: 0,
    startDate: null,
    endDate: null,
  };
  for (let i = 0; i < argv.length; i++) {
    switch (argv[i]) {
      case 'download': opts.step = 'download'; break;
      case 'upload': opts.step = 'upload'; break;
      case '--data-dir': opts.dataDir = argv[++i]; break;
      case '--dry-run': opts.dryRun = true; break;
      case '--skip-clear': opts.skipClear = true; break;
      case '--include-sales': opts.includeSales = true; break;
      case '--ejercicio': opts.ejercicio = argv[++i]; break;
      case '--limit': opts.limit = parseInt(argv[++i]) || 0; break;
      case '--start-date': opts.startDate = argv[++i]; break;
      case '--end-date': opts.endDate = argv[++i]; break;
      default: break;
    }
  }

  if (!opts.startDate) opts.startDate = `${opts.ejercicio}-01-01`;
  if (!opts.endDate) opts.endDate = `${opts.ejercicio}-12-31`;

  return opts;
}

// =====================================================================
// STEP: download — Fetch everything from Holded, save to dataDir
// =====================================================================

async function stepDownload(opts) {
  const logger = new Logger();
  const dir = opts.dataDir;

  fs.mkdirSync(dir, { recursive: true });
  fs.mkdirSync(path.join(dir, 'pdfs'), { recursive: true });

  logger.log(`=== DOWNLOAD STEP ===`);
  logger.log(`Date range: ${opts.startDate} to ${opts.endDate}`);
  logger.log(`Output dir: ${dir}`);

  // --- Fetch purchase documents ---
  logger.log('--- Fetching purchase documents ---');
  const docMap = {};
  const startTs = Math.floor(new Date(opts.startDate).getTime() / 1000);
  const endTs = Math.floor(new Date(opts.endDate).getTime() / 1000);

  async function fetchDocs(type, endpoint) {
    let page = 1;
    let count = 0;
    while (true) {
      const url = `${HOLDED_INVOICING_URL}/documents/${endpoint}?page=${page}&pageSize=500&starttmp=${startTs}&endtmp=${endTs}`;
      logger.log(`  Fetching ${type} page ${page}...`);
      const data = await holdedGet(url);
      const docs = Array.isArray(data) ? data : [];
      if (docs.length === 0) break;

      for (const doc of docs) {
        if (opts.limit && Object.keys(docMap).length >= opts.limit) break;
        docMap[doc.id] = {
          id: doc.id,
          docType: type,
          docNumber: doc.docNumber || doc.number || '',
          contactName: doc.contactName || '',
          date: doc.date || '',
          total: doc.total || 0,
          desc: doc.desc || '',
        };
        count++;
      }
      if (opts.limit && Object.keys(docMap).length >= opts.limit) break;
      page++;
      await sleep(200);
    }
    logger.log(`  ${type}: ${count} documents`);
  }

  await fetchDocs('purchase', 'purchase');

  if (opts.includeSales) {
    await fetchDocs('invoice', 'invoice');
  }

  fs.writeFileSync(path.join(dir, 'documents.json'), JSON.stringify(docMap, null, 2));
  logger.log(`  Saved ${Object.keys(docMap).length} documents to documents.json`);

  // --- Fetch daily ledger ---
  logger.log('--- Fetching daily ledger ---');
  const ledgerEndTs = Math.floor(new Date(opts.endDate + 'T23:59:59').getTime() / 1000);

  const chunks = [];
  let chunkStart = startTs;
  while (chunkStart < ledgerEndTs) {
    let chunkEnd = chunkStart + 365 * 86400;
    if (chunkEnd > ledgerEndTs) chunkEnd = ledgerEndTs;
    chunks.push([chunkStart, chunkEnd]);
    chunkStart = chunkEnd + 1;
  }

  const seenKeys = new Set();
  const allLines = [];

  for (const [cs, ce] of chunks) {
    let emptyPages = 0;
    let page = 1;
    while (emptyPages < 3) {
      const url = `${HOLDED_ACCOUNTING_URL}/dailyledger?page=${page}&limit=500&starttmp=${cs}&endtmp=${ce}`;
      logger.log(`  Ledger ${new Date(cs * 1000).toISOString().slice(0, 10)} to ${new Date(ce * 1000).toISOString().slice(0, 10)}, page ${page}...`);
      const data = await holdedGet(url);
      const lines = Array.isArray(data) ? data : [];
      if (lines.length === 0) { emptyPages++; page++; await sleep(200); continue; }

      let newInPage = 0;
      for (const line of lines) {
        const key = `${line.entryNumber}_${line.line}`;
        if (!seenKeys.has(key)) {
          seenKeys.add(key);
          allLines.push(line);
          newInPage++;
        }
      }

      if (newInPage === 0) { emptyPages++; } else { emptyPages = 0; }
      logger.log(`    ${lines.length} lines, ${newInPage} new (total: ${allLines.length})`);
      page++;
      await sleep(200);
    }
  }

  fs.writeFileSync(path.join(dir, 'ledger_lines.json'), JSON.stringify(allLines, null, 2));
  logger.log(`  Saved ${allLines.length} unique ledger lines to ledger_lines.json`);

  // --- Group and match ---
  const groupedEntries = {};
  for (const line of allLines) {
    const entryNum = String(line.entryNumber || '');
    if (!entryNum) continue;
    if (!groupedEntries[entryNum]) groupedEntries[entryNum] = {};
    const lineKey = String(line.line || 0);
    if (!groupedEntries[entryNum][lineKey]) groupedEntries[entryNum][lineKey] = line;
  }
  const grouped = {};
  for (const [k, m] of Object.entries(groupedEntries)) {
    grouped[k] = Object.values(m);
  }

  const docByNumber = {};
  for (const doc of Object.values(docMap)) {
    if (doc.docNumber) docByNumber[doc.docNumber] = doc;
  }

  const matched = {};
  const unmatched = {};
  for (const [entryNum, lines] of Object.entries(grouped)) {
    const docDesc = lines[0].docDescription || '';
    const doc = docByNumber[docDesc];
    if (doc) {
      matched[entryNum] = { lines, doc };
    } else {
      unmatched[entryNum] = lines;
    }
  }

  fs.writeFileSync(path.join(dir, 'matched_entries.json'), JSON.stringify(matched, null, 2));
  fs.writeFileSync(path.join(dir, 'unmatched_entries.json'), JSON.stringify(unmatched, null, 2));
  logger.log(`  Matched: ${Object.keys(matched).length} entries with documents`);
  logger.log(`  Unmatched: ${Object.keys(unmatched).length} entries without documents`);

  // --- Download PDFs for matched entries ---
  logger.log('--- Downloading PDFs ---');
  const pdfIndex = {};
  const matchedKeys = Object.keys(matched);
  let pdfCount = 0;
  let pdfFail = 0;

  for (let i = 0; i < matchedKeys.length; i++) {
    const entryNum = matchedKeys[i];
    const { doc } = matched[entryNum];
    const pdfFilename = `${doc.docNumber || doc.id}.pdf`;
    const pdfPath = path.join(dir, 'pdfs', pdfFilename);

    if (fs.existsSync(pdfPath)) {
      pdfIndex[entryNum] = pdfFilename;
      pdfCount++;
      continue;
    }

    try {
      const pdfUrl = `${HOLDED_INVOICING_URL}/documents/${doc.docType}/${doc.id}/pdf`;
      const pdfRes = await makeRequest(pdfUrl, { headers: holdedHeaders(), timeout: 60000 });
      let pdfBase64 = null;

      if (pdfRes.data && pdfRes.data.data) {
        pdfBase64 = pdfRes.data.data;
      } else if (typeof pdfRes.data === 'string' && pdfRes.data.length > 100) {
        pdfBase64 = pdfRes.data;
      }

      if (pdfBase64) {
        const buf = Buffer.from(pdfBase64, 'base64');
        if (buf[0] === 0x25 && buf[1] === 0x50) {
          fs.writeFileSync(pdfPath, buf);
          pdfIndex[entryNum] = pdfFilename;
          pdfCount++;
          if ((i + 1) % 20 === 0 || i === matchedKeys.length - 1) {
            logger.log(`  PDFs: ${pdfCount}/${i + 1}`);
          }
        } else {
          pdfFail++;
        }
      } else {
        pdfFail++;
      }
    } catch (e) {
      pdfFail++;
    }

    await sleep(150);
  }

  fs.writeFileSync(path.join(dir, 'pdf_index.json'), JSON.stringify(pdfIndex, null, 2));
  logger.log(`  PDFs downloaded: ${pdfCount}, failed: ${pdfFail}`);
  logger.log('=== DOWNLOAD COMPLETE ===');
}

// =====================================================================
// STEP: upload — Read from dataDir, insert into FacturaScripts
// =====================================================================

async function stepUpload(opts) {
  const logger = new Logger();
  const dir = opts.dataDir;

  logger.log(`=== UPLOAD STEP ===`);
  logger.log(`Data dir: ${dir}`);
  logger.log(`Ejercicio: ${opts.ejercicio}, dryRun: ${opts.dryRun}`);

  if (!fs.existsSync(path.join(dir, 'matched_entries.json'))) {
    logger.error('Missing matched_entries.json. Run download step first.');
    process.exit(1);
  }
  if (!fs.existsSync(path.join(dir, 'unmatched_entries.json'))) {
    logger.error('Missing unmatched_entries.json. Run download step first.');
    process.exit(1);
  }
  if (!fs.existsSync(path.join(dir, 'pdf_index.json'))) {
    logger.error('Missing pdf_index.json. Run download step first.');
    process.exit(1);
  }

  const matched = JSON.parse(fs.readFileSync(path.join(dir, 'matched_entries.json'), 'utf8'));
  const unmatched = JSON.parse(fs.readFileSync(path.join(dir, 'unmatched_entries.json'), 'utf8'));
  const pdfIndex = JSON.parse(fs.readFileSync(path.join(dir, 'pdf_index.json'), 'utf8'));

  logger.log(`  Matched entries: ${Object.keys(matched).length}`);
  logger.log(`  Unmatched entries: ${Object.keys(unmatched).length}`);
  logger.log(`  PDFs available: ${Object.keys(pdfIndex).length}`);

  // Phase 0: Clear
  if (!opts.skipClear) {
    logger.log('--- Clearing existing data ---');
    let totalFacturas = 0;
    while (true) {
      const res = await callMcpTool('list_facturasproveedor', { codejercicio: opts.ejercicio, limit: 500 });
      const facturas = res.facturas || [];
      if (facturas.length === 0) break;
      for (const f of facturas) {
        if (opts.dryRun) {
          logger.log(`  [DRY] Would delete FacturaProveedor id=${f.idfactura}`);
        } else {
          try {
            await callMcpTool('delete_factura_proveedor', { idfactura: f.idfactura });
            logger.log(`  Deleted FacturaProveedor id=${f.idfactura}`);
          } catch (e) {
            logger.error(`  Failed to delete FacturaProveedor id=${f.idfactura}: ${e.message}`);
          }
        }
        totalFacturas++;
        await sleep(100);
      }
    }
    logger.log(`  FacturaProveedor deleted: ${totalFacturas}`);

    let totalAsientos = 0;
    while (true) {
      const res = await callMcpTool('list_asientos', { codejercicio: opts.ejercicio, limit: 500 });
      const asientos = res.asientos || [];
      if (asientos.length === 0) break;
      for (const a of asientos) {
        if (opts.dryRun) {
          logger.log(`  [DRY] Would delete Asiento id=${a.idasiento}`);
        } else {
          try {
            await callMcpTool('delete_asiento', { idasiento: a.idasiento });
            logger.log(`  Deleted Asiento id=${a.idasiento}`);
          } catch (e) {
            logger.error(`  Failed to delete Asiento id=${a.idasiento}: ${e.message}`);
          }
        }
        totalAsientos++;
        await sleep(100);
      }
    }
    logger.log(`  Asientos deleted: ${totalAsientos}`);
  } else {
    logger.log('--- Clear skipped (--skip-clear) ---');
  }

  // Phase 1: Insert matched entries (with PDFs)
  logger.log('--- Inserting matched entries (with PDFs) ---');
  const matchedKeys = Object.keys(matched);
  let created = 0;
  let failed = 0;

  for (let i = 0; i < matchedKeys.length; i++) {
    if (opts.limit && created >= opts.limit) break;
    const entryNum = matchedKeys[i];
    const { lines, doc } = matched[entryNum];

    try {
      const result = await uploadEntry(logger, entryNum, lines, doc, pdfIndex[entryNum] || null, opts.ejercicio, opts.dryRun);
      if (result) created++; else failed++;
    } catch (e) {
      logger.error(`  Entry ${entryNum} failed: ${e.message}`);
      failed++;
    }
    await sleep(100);

    if ((i + 1) % 20 === 0 || i === matchedKeys.length - 1) {
      logger.log(`  Matched progress: ${i + 1}/${matchedKeys.length} (created=${created}, failed=${failed})`);
    }
  }
  logger.log(`  Matched: ${created} created, ${failed} failed`);

  // Phase 2: Insert unmatched entries (no PDF)
  logger.log('--- Inserting unmatched entries ---');
  const unmatchedKeys = Object.keys(unmatched);
  let uCreated = 0;
  let uFailed = 0;

  for (let i = 0; i < unmatchedKeys.length; i++) {
    if (opts.limit && (created + uCreated) >= opts.limit) break;
    const entryNum = unmatchedKeys[i];
    const lines = unmatched[entryNum];

    try {
      const result = await uploadEntry(logger, entryNum, lines, null, null, opts.ejercicio, opts.dryRun);
      if (result) uCreated++; else uFailed++;
    } catch (e) {
      logger.error(`  Entry ${entryNum} failed: ${e.message}`);
      uFailed++;
    }
    await sleep(100);

    if ((i + 1) % 50 === 0 || i === unmatchedKeys.length - 1) {
      logger.log(`  Unmatched progress: ${i + 1}/${unmatchedKeys.length} (created=${uCreated}, failed=${uFailed})`);
    }
  }
  logger.log(`  Unmatched: ${uCreated} created, ${uFailed} failed`);

  logger.log(`=== UPLOAD COMPLETE: ${created + uCreated} asientos created, ${failed + uFailed} failed ===`);
}

async function uploadEntry(logger, entryNumber, lines, doc, pdfFilename, codejercicio, dryRun) {
  const partidas = [];
  const seenAccounts = new Set();

  for (const line of lines) {
    const account = String(line.account || '').trim();
    if (!account) continue;
    const codsubcuenta = padAccount(account);

    if (!seenAccounts.has(codsubcuenta)) {
      seenAccounts.add(codsubcuenta);
      await ensureSubcuenta(logger, codsubcuenta, codejercicio, line.description || account, dryRun);
    }

    const debit = parseFloat(line.debit) || 0;
    const credit = parseFloat(line.credit) || 0;
    if (debit === 0 && credit === 0) continue;

    partidas.push({
      codsubcuenta,
      debe: debit,
      haber: credit,
      concepto: line.description || '',
    });
  }

  if (partidas.length === 0) {
    logger.log(`    Skipped entry ${entryNumber}: no valid lines`);
    return null;
  }

  const timestamp = lines[0].timestamp;
  let fecha;
  if (timestamp) {
    fecha = new Date(timestamp * 1000).toISOString().slice(0, 10);
  } else if (doc && doc.date) {
    fecha = new Date(doc.date * 1000).toISOString().slice(0, 10);
  } else {
    fecha = new Date().toISOString().slice(0, 10);
  }

  const concepto = doc
    ? `${doc.docType} ${doc.docNumber || doc.id} - ${doc.contactName || ''}`.trim()
    : `Holded entry ${entryNumber}`;

  if (dryRun) {
    logger.log(`    [DRY] Asiento: fecha=${fecha}, concepto="${concepto}", ${partidas.length} partidas`);
    if (pdfFilename) logger.log(`    [DRY] Would attach PDF: ${pdfFilename}`);
    return { idasiento: 'dry-run', concepto };
  }

  logger.log(`    Creating: fecha=${fecha}, concepto="${concepto}" (${partidas.length} partidas)`);
  const asientoResult = await callMcpTool('create_asiento', {
    codejercicio,
    fecha,
    concepto,
    partidas,
  });

  const idasiento = asientoResult.asiento?.idasiento;
  if (!idasiento) {
    logger.error(`    Failed to create asiento: ${JSON.stringify(asientoResult)}`);
    return null;
  }
  logger.log(`    Created Asiento id=${idasiento}`);

  if (pdfFilename && doc) {
    const pdfPath = path.join(path.dirname(__dirname), 'facturascripts', 'sync_data', 'pdfs', pdfFilename);
    // The script is invoked with --data-dir pointing to the sync folder
    const actualPdfPath = path.resolve(opts.dataDir || '.', 'pdfs', pdfFilename);
    let pdfBase64 = null;

    if (fs.existsSync(actualPdfPath)) {
      pdfBase64 = fs.readFileSync(actualPdfPath).toString('base64');
    } else {
      logger.log(`    PDF file not found: ${actualPdfPath}`);
    }

    if (pdfBase64) {
      try {
        const uploadResult = await callMcpTool('upload_file', {
          filename: pdfFilename,
          content_base64: pdfBase64,
          mimetype: 'application/pdf',
        });

        const idfile = uploadResult.file?.idfile;
        if (idfile) {
          await callMcpTool('attach_file', {
            idfile,
            model: 'Asiento',
            modelid: idasiento,
            observations: `Holded ${doc.docType} ${doc.id}`,
          });
          logger.log(`    Attached PDF: ${pdfFilename}`);
        }
      } catch (e) {
        logger.error(`    Failed to attach PDF: ${e.message}`);
      }
    }
  }

  return { idasiento, concepto };
}

async function ensureSubcuenta(logger, codsubcuenta, codejercicio, descripcion, dryRun) {
  try {
    try {
      await callMcpTool('get_subcuenta', { codejercicio, codsubcuenta });
      return;
    } catch (e) {
      // Not found, proceed to create
    }

    if (dryRun) {
      logger.log(`        [DRY] Would create subcuenta ${codsubcuenta}`);
      return;
    }

    await callMcpTool('create_subcuenta', {
      codejercicio,
      codsubcuenta,
      descripcion: descripcion || codsubcuenta,
    });
    logger.log(`        Created subcuenta ${codsubcuenta}: ${descripcion}`);
  } catch (e) {
    if (e.message.includes('already exists') || e.message.includes('duplicad')) {
      logger.log(`        Subcuenta ${codsubcuenta} already exists`);
    } else {
      logger.error(`        Failed to create subcuenta ${codsubcuenta}: ${e.message}`);
    }
  }
}

// =====================================================================
// Main
// =====================================================================

async function main() {
  const opts = parseArgs();

  if (!opts.step) {
    console.log('Usage: sync_holded_to_facturascripts.js <download|upload> --data-dir <folder> [options]');
    console.log('');
    console.log('Steps:');
    console.log('  download   Fetch all data from Holded, save to --data-dir');
    console.log('  upload     Read from --data-dir, insert into FacturaScripts');
    console.log('');
    console.log('Options:');
    console.log('  --ejercicio YYYY     Fiscal year (default: 2026)');
    console.log('  --start-date YYYY-MM-DD  Start date (default: YYYY-01-01)');
    console.log('  --end-date YYYY-MM-DD    End date (default: YYYY-12-31)');
    console.log('  --dry-run            Log actions, no writes');
    console.log('  --skip-clear         Skip clearing existing data');
    console.log('  --include-sales      Also process sale invoices');
    console.log('  --limit N            Process only first N documents');
    process.exit(1);
  }

  if (!opts.dataDir) {
    console.error('Error: --data-dir is required');
    process.exit(1);
  }

  try {
    if (opts.step === 'download') {
      await stepDownload(opts);
    } else if (opts.step === 'upload') {
      await stepUpload(opts);
    }
  } catch (e) {
    console.error(`Fatal error: ${e.message}`);
    console.error(e.stack);
    process.exit(1);
  }
}

main();
