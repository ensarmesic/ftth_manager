// Syntax-checks every hand-written (non-transpiled) JS file under public/js.
// These files are loaded straight into the browser with no build step, so a
// syntax slip only surfaces as a broken map at runtime. Run via `npm run check:js`.
import { readdirSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(fileURLToPath(new URL('.', import.meta.url)), '..', 'public', 'js');

function collect(dir) {
    const out = [];
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) out.push(...collect(full));
        else if (entry.endsWith('.js')) out.push(full);
    }
    return out;
}

const files = collect(root);
let failed = 0;

for (const file of files) {
    try {
        execFileSync(process.execPath, ['--check', file], { stdio: 'pipe' });
    } catch (error) {
        failed++;
        process.stderr.write(`✗ ${file}\n${error.stderr?.toString() ?? error.message}\n`);
    }
}

if (failed > 0) {
    process.stderr.write(`\n${failed} datoteka ima sintaksnu grešku.\n`);
    process.exit(1);
}

process.stdout.write(`✓ ${files.length} JS datoteka je sintaksno ispravno.\n`);
