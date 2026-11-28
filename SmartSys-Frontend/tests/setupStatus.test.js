import assert from 'node:assert/strict';
import test from 'node:test';
import { isSettingEnabled } from '../src/utils/validators/settings.js';

test('database setup-completion flags treat zero as incomplete and one as complete', () => {
  assert.equal(isSettingEnabled(0), false);
  assert.equal(isSettingEnabled('0'), false);
  assert.equal(isSettingEnabled(false), false);
  assert.equal(isSettingEnabled(1), true);
  assert.equal(isSettingEnabled('1'), true);
  assert.equal(isSettingEnabled(true), true);
});
