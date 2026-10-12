// Print SignData for any input with the configured key, to compare with another implementation (e.g. C# MakeSign).
//   npm run sign -- "24095674;123;124950000"
import { config } from '../src/config.js';
import { signData, keyFingerprint } from '../src/sadad.js';

const input = process.argv[2];
if (!input) {
  console.error('Usage: npm run sign -- "TerminalId;OrderId;Amount"   (or a Token for Verify)');
  process.exit(1);
}
const key = config.mode === 'live' ? config.sadad.terminalKey : process.env.SADAD_TERMINAL_KEY;
if (!key) {
  console.error('Set SADAD_TERMINAL_KEY in .env (or the environment) first.');
  process.exit(1);
}
console.log(`input:           ${input}`);
console.log(`key fingerprint: ${keyFingerprint(key)}`);
console.log(`SignData:        ${signData(input, key)}`);
