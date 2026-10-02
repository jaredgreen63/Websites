import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { env, envOr } from '../src/lib/env';

/**
 * GitHub Actions substitutes an unset `vars.FOO` into an `env:` block as an
 * empty string. These tests pin the behaviour that makes a default survive
 * that, because the failure it caused was silent and far from its cause: an
 * empty adapter name killed the sync, and an empty query parameter sent the
 * API key under a nameless field, which reads as a rejected key.
 */
describe('env', () => {
  const KEY = 'BCH_TEST_VAR';
  const run = (value: string | undefined, body: () => void) => {
    const before = process.env[KEY];
    if (value === undefined) delete process.env[KEY];
    else process.env[KEY] = value;
    try {
      body();
    } finally {
      if (before === undefined) delete process.env[KEY];
      else process.env[KEY] = before;
    }
  };

  it('reads a set variable', () => {
    run('shiftly', () => assert.equal(env(KEY), 'shiftly'));
  });

  it('treats an absent variable as undefined', () => {
    run(undefined, () => assert.equal(env(KEY), undefined));
  });

  it('treats an empty variable as absent — the CI case', () => {
    run('', () => assert.equal(env(KEY), undefined));
  });

  it('treats a whitespace-only variable as absent', () => {
    run('   ', () => assert.equal(env(KEY), undefined));
  });

  it('trims surrounding whitespace off a real value', () => {
    run('  shiftly \n', () => assert.equal(env(KEY), 'shiftly'));
  });

  it('falls back when empty, which plain ?? does not', () => {
    run('', () => {
      assert.equal(envOr(KEY, 'api_key'), 'api_key');
      // The bug this replaces: ?? only catches null and undefined.
      assert.equal(process.env[KEY] ?? 'api_key', '');
    });
  });

  it('keeps a set value in preference to the fallback', () => {
    run('custom_key', () => assert.equal(envOr(KEY, 'api_key'), 'custom_key'));
  });
});
