#!/usr/bin/env node

/**
 * Sync Agent: Holded → FacturaScripts via Qwen3-14B
 * Orchestrates the sync process using a local LLM as the decision maker
 */

const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');

// Config
const QWEN_URL = 'http://localhost:8081/v1/chat/completions';
const QWEN_MODEL = 'qwen';  // llama-cpp-server exposes the model name
const FACTURASCRIPTS_MCP_URL = 'http://facturas.localhost/api/3/mcp';
const FACTURASCRIPTS_API_KEY = 'a92af3c4ff1db02df58b88776e61f450';
const FS_FOLDER = __dirname;

// Request utilities
async function makeRequest(url, options = {}) {
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

// Qwen API call
async function callQwen(messages, tools = [], opts = {}) {
  const body = {
    model: QWEN_MODEL,
    messages,
    temperature: opts.temperature ?? 0.3,
    max_tokens: opts.maxTokens ?? 2048,
  };
  if (tools.length > 0) {
    body.tools = tools;
    body.tool_choice = 'auto';
  }
  const res = await makeRequest(QWEN_URL, { method: 'POST', body, timeout: 60000 });
  if (res.status >= 400 || res.data.error) {
    throw new Error(`Qwen API error: ${JSON.stringify(res.data.error || res.data)}`);
  }
  return res.data;
}

// MCP tool call
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

// Tool implementations for Qwen
async function executeTool(name, args) {
  console.log(`\n[TOOL] ${name}: ${JSON.stringify(args)}\n`);

  switch (name) {
    case 'read_file': {
      try {
        const content = fs.readFileSync(args.path, 'utf8');
        return { success: true, content: JSON.parse(content) };
      } catch (e) {
        return { success: false, error: e.message };
      }
    }

    case 'call_mcp': {
      try {
        const result = await callMcpTool(args.tool, args.args || {});
        return { success: true, result };
      } catch (e) {
        return { success: false, error: e.message };
      }
    }

    case 'run_php': {
      try {
        const flags = [
          '--data-dir', args.dataDir,
          '--ejercicio', args.ejercicio,
          args.clear ? '--clear' : '',
          args.dryRun ? '--dry-run' : '',
        ].filter(Boolean);

        const cmd = `cd ${FS_FOLDER} && php sync_upload_fast.php ${flags.join(' ')}`;
        const output = execSync(cmd, { encoding: 'utf8', timeout: 300000 });
        return { success: true, output };
      } catch (e) {
        return { success: false, error: e.message, output: e.stdout };
      }
    }

    default:
      return { success: false, error: `Unknown tool: ${name}` };
  }
}

// Tool definitions for Qwen
const TOOLS = [
  {
    type: 'function',
    function: {
      name: 'read_file',
      description: 'Read a JSON file from the local filesystem and parse it',
      parameters: {
        type: 'object',
        properties: {
          path: {
            type: 'string',
            description: 'Absolute or relative path to the file'
          }
        },
        required: ['path']
      }
    }
  },
  {
    type: 'function',
    function: {
      name: 'call_mcp',
      description: 'Call a FacturaScripts MCP tool (e.g. list_asientos, delete_asiento)',
      parameters: {
        type: 'object',
        properties: {
          tool: {
            type: 'string',
            description: 'MCP tool name'
          },
          args: {
            type: 'object',
            description: 'Tool arguments'
          }
        },
        required: ['tool']
      }
    }
  },
  {
    type: 'function',
    function: {
      name: 'run_php',
      description: 'Run the fast PHP sync script for bulk import/clear',
      parameters: {
        type: 'object',
        properties: {
          ejercicio: {
            type: 'string',
            description: 'Fiscal year (e.g. "2024", "2025")'
          },
          dataDir: {
            type: 'string',
            description: 'Path to sync_data folder'
          },
          clear: {
            type: 'boolean',
            description: 'Clear existing asientos before loading (default: false)'
          },
          dryRun: {
            type: 'boolean',
            description: 'Dry run mode (log actions, no writes)'
          }
        },
        required: ['ejercicio', 'dataDir']
      }
    }
  }
];

// Agent loop
async function runAgent(systemPrompt, userMessage) {
  const messages = [
    { role: 'system', content: systemPrompt },
    { role: 'user', content: userMessage }
  ];

  console.log('\n=== STARTING AGENT ===\n');
  console.log('System:', systemPrompt);
  console.log('Task:', userMessage);
  console.log('\n');

  let iterations = 0;
  const maxIterations = 50;

  while (iterations < maxIterations) {
    iterations++;
    console.log(`\n--- Agent iteration ${iterations} ---\n`);

    const response = await callQwen(messages, TOOLS);
    const choice = response.choices[0];

    if (!choice) {
      console.log('No choice in response');
      break;
    }

    // Add assistant's message
    messages.push(choice.message);

    if (choice.finish_reason === 'stop') {
      console.log('\n=== AGENT FINISHED ===\n');
      console.log('Final response:\n', choice.message.content);
      return choice.message.content;
    }

    if (choice.finish_reason === 'tool_calls' && choice.message.tool_calls) {
      for (const tc of choice.message.tool_calls) {
        console.log(`Executing tool: ${tc.function.name}`);
        const toolArgs = JSON.parse(tc.function.arguments);
        const toolResult = await executeTool(tc.function.name, toolArgs);

        // Truncate large outputs
        const resultStr = JSON.stringify(toolResult);
        const truncated = resultStr.length > 4000
          ? resultStr.substring(0, 4000) + '... [truncated]'
          : resultStr;

        messages.push({
          role: 'tool',
          tool_call_id: tc.id,
          content: truncated
        });

        console.log(`Tool result: ${truncated.substring(0, 200)}`);
      }
    } else {
      console.log('Unexpected finish reason:', choice.finish_reason);
      break;
    }
  }

  if (iterations >= maxIterations) {
    console.log(`\nMax iterations (${maxIterations}) reached`);
  }

  return 'Agent loop finished';
}

// Main
async function main() {
  const SYSTEM_PROMPT = `You are a data synchronization agent for FacturaScripts, a Spanish ERP system.
You have access to tools to:
1. Read local JSON files from the sync_data folder
2. Call FacturaScripts MCP tools (create, list, delete entities)
3. Run a fast PHP script to bulk-import accounting entries

Your task is to orchestrate clearing and reloading accounting data from Holded into FacturaScripts.
Always verify each step succeeds before proceeding to the next.
When errors occur, examine the error and decide on the best recovery action.
Be thorough and report all results clearly.`;

  const USER_TASK = `Please execute this sync workflow:

1. Check the data directory (facturascripts/sync_data) to understand what data is available
2. List the contents: matched_entries.json, unmatched_entries.json, pdf_index.json
3. Clear all Asientos and FacturasProveedor for ejercicio 2024 using MCP delete tools
4. Run the fast PHP upload script for 2024: run_php with ejercicio="2024", dataDir="facturascripts/sync_data", clear=true
5. After 2024 completes, check if 2025 data exists at facturascripts/sync_data_2025
6. If 2025 exists, repeat step 4 for 2025
7. Verify the results by calling list_asientos for each year and report the counts
8. Summarize what was done

Be careful with file paths and always confirm success at each step.`;

  try {
    await runAgent(SYSTEM_PROMPT, USER_TASK);
  } catch (e) {
    console.error('Agent error:', e.message);
    process.exit(1);
  }
}

main();
