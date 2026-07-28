import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');
const asJson = process.argv.includes('--json');
const casesPath = path.join(__dirname, 'geo_normalization_cases.json');
const normalizerPath = path.join(root, 'assets', 'js', 'geo-normalizer.js');

const cases = JSON.parse(fs.readFileSync(casesPath, 'utf8'));
const script = fs.readFileSync(normalizerPath, 'utf8');
const context = { window: {} };
vm.runInNewContext(script, context, { filename: 'geo-normalizer.js' });

const normalizer = context.window.BIOMIE_GEO_NORMALIZER;
const results = cases.map((item, index) => {
  const actual = normalizer.normalizeNeighborhoodKey(item.input);
  return {
    index,
    input: item.input ?? null,
    expected: item.expected,
    actual,
    ok: actual === item.expected,
    explain: normalizer.explainNeighborhoodNormalization(item.input),
  };
});

const failures = results.filter((item) => !item.ok).length;
const payload = {
  status: failures === 0 ? 'ok' : 'fail',
  checked: results.length,
  failures,
  normalizer: 'assets/js/geo-normalizer.js',
  results,
};

if (asJson) {
  console.log(JSON.stringify(payload, null, 2));
} else {
  console.log('--- GEO NORMALIZATION AUDIT JS ---');
  console.log(`Status: ${payload.status}`);
  console.log(`Checked: ${payload.checked}`);
  console.log(`Failures: ${payload.failures}`);
  for (const result of results) {
    const label = result.input === null ? 'null' : `"${String(result.input)}"`;
    const mark = result.ok ? 'OK' : 'FAIL';
    const suffix = result.ok ? '' : ` (expected ${result.expected})`;
    console.log(`${mark} ${label} => ${result.actual}${suffix}`);
  }
}

process.exit(failures === 0 ? 0 : 1);
