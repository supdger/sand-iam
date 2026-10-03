import assert from 'node:assert/strict'
import { canUseGoal, guidanceGoals, guidanceQuery, resolveGuidanceGoal, nextGoalStep, goalStepParams, activeClientAudience } from '../src/views/plugin/sand-iam/api/goalGuidance'
const machine = resolveGuidanceGoal('machine', undefined)!
assert.ok(machine.steps.every(step => !['employee-login', 'auth-settings', 'identity-invitation', 'identity'].includes(step.key)))
const login = resolveGuidanceGoal('login', 'register')!
assert.ok(login.steps.some(step => step.key === 'self-register' && step.contractStatus === 'runtime' && !step.permission))
assert.ok(!login.steps.some(step => step.key === 'identity-invitation'))
assert.ok(!login.steps.some(step => ['resource', 'policy', 'role'].includes(step.key)))
const scim = resolveGuidanceGoal('directory', 'scim')!
assert.ok(scim.steps.some(step => step.key === 'identity-provider'))
assert.ok(scim.steps.some(step => step.key === 'scim-tokens' && !step.optional))
assert.ok(!scim.steps.some(step => step.key === 'sync-connector'))
const connector = resolveGuidanceGoal('directory', 'connector')!
assert.ok(connector.steps.some(step => step.key === 'sync-connector'))
assert.ok(!connector.steps.some(step => step.key === 'scim-tokens'))
for (const goal of guidanceGoals) {
  assert.equal(canUseGoal(goal, () => false), false)
  assert.equal(canUseGoal(goal, permission => permission === 'sand_iam:audit:index'), false)
  assert.equal(canUseGoal(goal, permission => permission === 'sand_iam:developer:openapi'), false)
}
assert.equal(canUseGoal(machine, permission => permission === 'sand_iam:credential:index'), true)
const context = { organization_id: 1, application_id: 10, environment_id: 100 }
assert.deepEqual(guidanceQuery(context, 'directory', 'scim-tokens', 'scim'), { organization_id: '1', application_id: '10', environment_id: '100', goal: 'directory', step: 'scim-tokens', method: 'scim' })
assert.equal(nextGoalStep(scim, 'identity-provider')?.key, 'scim-tokens')
assert.equal(resolveGuidanceGoal('forged', 'scim'), undefined)
console.log('Goal guidance contracts passed: independent branches, scope continuity, deny-all, and sequence')

assert.equal(goalStepParams(machine.steps[0], { application_id: 10 }), null)
assert.deepEqual(goalStepParams(machine.steps[0], { application_id: 10, environment_id: 100 }), { page: 1, limit: 1, application_id: 10, environment_id: 100 })
assert.deepEqual(goalStepParams(machine.steps[1], { application_id: 10, environment_id: 101, workload_client_id: 901 }), { page: 1, limit: 1, application_id: 10, environment_id: 101, workload_client_id: 901 })

assert.equal(activeClientAudience({ id: 901, status: 1, audience: 'provider-b' }, 901), 'provider-b')
assert.equal(activeClientAudience({ id: 901, status: 1, audience: 'provider-b' }, 902), null)
assert.equal(activeClientAudience({ id: 901, status: 2, audience: 'provider-b' }, 901), null)
assert.equal(activeClientAudience({ id: 901, status: 1, code: 'provider-b' }, 901), null)
