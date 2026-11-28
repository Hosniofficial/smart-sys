import assert from 'node:assert/strict';
import test from 'node:test';
import { getMenuItems } from '../src/config/menuConfig.js';

test('global super-admin sees only platform navigation without an active tenant', () => {
  const items = getMenuItems('super_admin', [], {
    role: 'super_admin',
    tenant_id: 0,
    tenantId: 0,
  });

  assert.deepEqual(items.map(item => item.key), ['subscriptions']);
});

test('global super-admin can see tenant navigation after selecting a tenant', () => {
  const items = getMenuItems('super_admin', [], {
    role: 'super_admin',
    tenant_id: 0,
    tenantId: 0,
    activeTenantId: 12,
  });
  const visibleSections = items.map(item => item.key);

  assert.ok(visibleSections.includes('sales'));
  assert.ok(visibleSections.includes('inventory'));
  assert.ok(visibleSections.includes('subscriptions'));
});
