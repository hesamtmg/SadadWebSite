import { test } from 'node:test';
import assert from 'node:assert/strict';
import { signData, decodeKey, localDateTime } from '../src/sadad.js';

// Vectors produced by Sadad's C# MakeSign (TripleDES.Create, ECB, PKCS7, UTF8) with .NET 8.
const KEY = 'prK/jNrK/zPp/lASBVyCRFMcx8y5dFUi';
const VECTORS = [
  ['24095674;1001;1250000', '6/5zC6mPoyX0bTyaKyBo28AbIERIj9oP'],
  ['24095674;987654321;124950000', '6/5zC6mPoyU9i1EwGfOWDTPWTvjpo53NWyj345RzM1E='],
  ['8D2F1C77A4E09B3C5D6E7F80', '2/S4taCdoFSFV9wGlJn0MHPcZ7xCMJ5L78k++F3Hk7E='],
  ['3F9A2B7C1D0E4F5A6B7C8D9E0F1A2B3C', 'B1IHgP/LaMyGI+aSY1U/mxNiV9DV732E4odbh0Z4ccfvyT74XceTsQ=='],
];

test('SignData matches the C# MakeSign output byte for byte', () => {
  for (const [input, expected] of VECTORS) assert.equal(signData(input, KEY), expected, input);
});

test('rejects keys that are not Base64 of 24 bytes', () => {
  assert.throws(() => decodeKey('not base64!'), /Base64/);
  assert.throws(() => decodeKey(Buffer.alloc(16).toString('base64')), /24 bytes/);
  assert.equal(decodeKey(` ${KEY}\n`).length, 24); // whitespace from copy-paste is ignored
});

test('LocalDateTime uses MM/dd/yyyy hh:mm:ss AM in Tehran time', () => {
  // 2026-03-20T20:30:00Z = 2026-03-21 00:00 Tehran (UTC+3:30)
  assert.equal(localDateTime(new Date('2026-03-20T20:30:00Z')), '03/21/2026 12:00:00 AM');
  assert.equal(localDateTime(new Date('2026-07-01T10:15:05Z')), '07/01/2026 01:45:05 PM');
});
