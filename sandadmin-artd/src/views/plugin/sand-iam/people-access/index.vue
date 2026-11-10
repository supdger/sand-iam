<script setup lang="ts">
  import '../components/sandIamPage.css'
  import SandIamErrorBoundary from '../components/SandIamErrorBoundary.vue'
  import TaskPath from '../components/TaskPath.vue'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    parseSandIamIdentityPage,
    type SandIamIdentityRow
  } from '../api/identityLifecycleContracts'
  import { listSandIamResource } from '../api/resource'
  import { postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndexIdentity = computed(() => hasAuth('sand_iam:identity:index'))
  const canIndexRole = computed(() => hasAuth('sand_iam:role:index'))
  const canIndexBinding = computed(() => hasAuth('sand_iam:identity_role:index'))
  const canGrant = computed(() => hasAuth('sand_iam:identity_role:grant'))
  const canRevoke = computed(() => hasAuth('sand_iam:identity_role:revoke'))

  /* ---------- 选项加载 ---------- */

  const applications = ref<SandIamResourceRow[]>([])
  const applicationId = ref('')
  const applicationLoading = ref(false)
  const applicationError = ref('')
  let applicationRequest = 0
  let disposed = false

  const people = ref<SandIamIdentityRow[]>([])
  const personId = ref('')
  const personLoading = ref(false)
  const personError = ref('')
  const personKeywords = ref('')
  let personRequest = 0

  const roles = ref<SandIamResourceRow[]>([])
  const roleId = ref('')
  const rolesLoading = ref(false)
  const rolesError = ref('')
  let rolesRequest = 0

  interface IdentityRoleBinding {
    id: number
    role_id: number
    status: number
  }
  const bindings = ref<IdentityRoleBinding[]>([])
  const bindingsLoading = ref(false)
  const bindingsError = ref('')
  let bindingsRequest = 0

  const saving = ref(false)
  const actionError = ref<SandIamRequestError | null>(null)

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  function positiveId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function personName(row: SandIamIdentityRow): string {
    return row.display_name === '' ? '未命名用户' : row.display_name
  }

  function roleName(id: number): string {
    const row = roles.value.find((item) => item.id === id)
    return typeof row?.name === 'string' && row.name !== '' ? row.name : '角色名称暂不可用'
  }

  function bindingStatusLabel(status: number): string {
    return status === 1 ? '生效中' : '已撤销'
  }

  const selectedApplication = computed(() => positiveId(applicationId.value))
  const selectedPerson = computed(() => positiveId(personId.value))
  const selectedRole = computed(() => positiveId(roleId.value))
  const canAssign = computed(
    () =>
      selectedApplication.value !== null &&
      selectedPerson.value !== null &&
      selectedRole.value !== null &&
      canGrant.value &&
      !saving.value
  )

  /* ---------- 应用 ---------- */

  async function searchApplications(keywords = ''): Promise<void> {
    const attempt = ++applicationRequest
    applicationLoading.value = true
    applicationError.value = ''
    try {
      const result = await listSandIamResource('application', {
        page: 1,
        limit: 50,
        keywords
      })
      if (disposed || attempt !== applicationRequest) return
      const rows = listRows(result)
      const selected = applications.value.find((row) => String(row.id) === applicationId.value)
      applications.value =
        selected && !rows.some((row) => row.id === selected.id) ? [selected, ...rows] : rows
    } catch (error: unknown) {
      if (!disposed && attempt === applicationRequest) {
        applicationError.value = describeSandIamError(error).detail
      }
    } finally {
      if (!disposed && attempt === applicationRequest) applicationLoading.value = false
    }
  }

  /* ---------- 员工（应用用户身份） ---------- */

  async function searchPeople(keywords = ''): Promise<void> {
    const app = selectedApplication.value
    const attempt = ++personRequest
    personKeywords.value = keywords
    if (app === null) {
      people.value = []
      return
    }
    personLoading.value = true
    personError.value = ''
    try {
      const result = await listSandIamResource('identity', {
        page: 1,
        limit: 50,
        application_id: app,
        keywords: keywords.trim()
      })
      if (disposed || attempt !== personRequest) return
      const parsed = parseSandIamIdentityPage(result)
      // 只允许选择仍启用的身份；后端授予时也会再次校验
      people.value = parsed.rows.filter(
        (row) => row.application_id === app && row.status === 1
      )
      const selected = people.value.find((row) => String(row.id) === personId.value)
      if (selected === undefined && personId.value !== '') {
        personId.value = ''
      }
    } catch (error: unknown) {
      if (!disposed && attempt === personRequest) {
        personError.value = describeSandIamError(error).detail
      }
    } finally {
      if (!disposed && attempt === personRequest) personLoading.value = false
    }
  }

  /* ---------- 角色 ---------- */

  async function loadRoles(): Promise<void> {
    const app = selectedApplication.value
    const attempt = ++rolesRequest
    if (app === null) {
      roles.value = []
      return
    }
    rolesLoading.value = true
    rolesError.value = ''
    try {
      const result = await listSandIamResource('role', {
        page: 1,
        limit: 100,
        application_id: app
      })
      if (disposed || attempt !== rolesRequest) return
      const rows = listRows(result).filter(
        (row) => row.application_id === app && row.status === 1
      )
      roles.value = rows
      const selected = rows.find((row) => String(row.id) === roleId.value)
      if (selected === undefined && roleId.value !== '') {
        roleId.value = ''
      }
    } catch (error: unknown) {
      if (!disposed && attempt === rolesRequest) {
        rolesError.value = describeSandIamError(error).detail
      }
    } finally {
      if (!disposed && attempt === rolesRequest) rolesLoading.value = false
    }
  }

  /* ---------- 已分配角色 ---------- */

  function parseBindings(value: unknown): IdentityRoleBinding[] {
    const rows = listRows(value)
    const result: IdentityRoleBinding[] = []
    for (const row of rows) {
      const id = typeof row.id === 'number' ? row.id : NaN
      const role = typeof row.role_id === 'number' ? row.role_id : NaN
      const status = typeof row.status === 'number' ? row.status : NaN
      if (Number.isInteger(id) && id > 0 && Number.isInteger(role) && role > 0) {
        result.push({ id, role_id: role, status: Number.isInteger(status) ? status : 0 })
      }
    }
    return result
  }

  async function loadBindings(): Promise<void> {
    const person = selectedPerson.value
    const attempt = ++bindingsRequest
    if (person === null) {
      bindings.value = []
      return
    }
    bindingsLoading.value = true
    bindingsError.value = ''
    try {
      const result = await listSandIamResource('identity-role', {
        page: 1,
        limit: 100,
        identity_id: person
      })
      if (disposed || attempt !== bindingsRequest) return
      bindings.value = parseBindings(result)
    } catch (error: unknown) {
      if (!disposed && attempt === bindingsRequest) {
        bindingsError.value = describeSandIamError(error).detail
      }
    } finally {
      if (!disposed && attempt === bindingsRequest) bindingsLoading.value = false
    }
  }

  /* ---------- 分配 / 撤销 ---------- */

  async function assignRole(): Promise<void> {
    const app = selectedApplication.value
    const person = selectedPerson.value
    const role = selectedRole.value
    if (app === null || person === null || role === null || saving.value || !canGrant.value) return
    const personRow = people.value.find((row) => row.id === person)
    const roleRow = roles.value.find((row) => row.id === role)
    if (personRow === undefined || roleRow === undefined) return
    saving.value = true
    actionError.value = null
    try {
      await postSandIamAction(
        'identity-role/grant',
        { identity_id: person, role_id: role },
        false
      )
      if (disposed) return
      ElMessage.success(`已为「${personName(personRow)}」分配角色「${roleName(role)}」`)
      roleId.value = ''
      await loadBindings()
    } catch (error: unknown) {
      if (!disposed) actionError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function revokeBinding(binding: IdentityRoleBinding): Promise<void> {
    if (saving.value || !canRevoke.value || binding.status !== 1) return
    const name = roleName(binding.role_id)
    try {
      await ElMessageBox.confirm(
        `撤销后「${name}」将不再对这个员工生效，相关功能可能立即不可用。`,
        '撤销角色',
        {
          type: 'warning',
          confirmButtonText: '确认撤销',
          cancelButtonText: '取消'
        }
      )
    } catch {
      return
    }
    if (saving.value || disposed) return
    saving.value = true
    actionError.value = null
    try {
      await postSandIamAction('identity-role/revoke', { id: binding.id }, false)
      if (disposed) return
      ElMessage.success(`已撤销角色「${name}」`)
      await loadBindings()
    } catch (error: unknown) {
      if (!disposed) actionError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  /* ---------- 联动 ---------- */

  watch(applicationId, (next, prev) => {
    if (next === prev) return
    personId.value = ''
    roleId.value = ''
    people.value = []
    roles.value = []
    bindings.value = []
    actionError.value = null
    void searchPeople('')
    void loadRoles()
    void loadBindings()
  })

  watch(personId, (next, prev) => {
    if (next === prev) return
    bindings.value = []
    bindingsError.value = ''
    actionError.value = null
    void loadBindings()
  })

  onMounted(() => {
    void searchApplications()
  })

  onScopeDispose(() => {
    disposed = true
    applicationRequest++
    personRequest++
    rolesRequest++
    bindingsRequest++
  })
</script>

<template>
  <SandIamErrorBoundary page="/sand-iam/people-access">
    <div class="sand-iam-page">
      <div class="workbench-wrap">
        <ElCard class="sand-iam-page-card" shadow="never">
          <div class="workbench-head">
            <h2 class="workbench-title">用户与权限</h2>
            <p class="workbench-subtitle">
              选择应用和员工，分配一个角色即可；员工能做什么由系统按角色自动匹配，无需手动配置细节。
            </p>
          </div>

          <ElAlert
            v-if="!canIndexIdentity"
            class="mb-4"
            type="warning"
            :closable="false"
            title="没有权限"
            description="当前账号无权查看应用用户。请联系平台管理员开通应用用户查看权限。"
          />
          <ElAlert
            v-if="!canGrant"
            class="mb-4"
            type="info"
            :closable="false"
            title="只读模式"
            description="你可以查看已分配的角色；分配或撤销角色需要管理员开通对应权限。"
          />
          <ElAlert
            v-if="actionError"
            class="mb-4"
            type="error"
            :closable="false"
            :title="actionError.title"
            :description="actionError.detail"
          />

          <ElForm label-width="120px" class="workbench-form">
            <ElFormItem label="接入应用" required>
              <ElSelect
                v-model="applicationId"
                filterable
                remote
                :remote-method="searchApplications"
                :loading="applicationLoading"
                clearable
                placeholder="按名称选择应用"
                class="workbench-select"
              >
                <ElOption
                  v-for="row in applications"
                  :key="String(row.id)"
                  :label="typeof row.name === 'string' ? row.name : '未命名应用'"
                  :value="String(row.id)"
                />
              </ElSelect>
              <div v-if="applicationError !== ''" class="workbench-field-error">
                {{ applicationError }}
                <ElButton size="small" @click="searchApplications()">重试</ElButton>
              </div>
            </ElFormItem>

            <ElFormItem label="员工" required>
              <ElSelect
                v-model="personId"
                filterable
                remote
                :remote-method="searchPeople"
                :loading="personLoading"
                :disabled="selectedApplication === null"
                clearable
                placeholder="选择该应用下的员工"
                class="workbench-select"
              >
                <ElOption
                  v-for="row in people"
                  :key="String(row.id)"
                  :label="personName(row)"
                  :value="String(row.id)"
                />
              </ElSelect>
              <div v-if="personError !== ''" class="workbench-field-error">
                {{ personError }}
                <ElButton size="small" @click="searchPeople(personKeywords)">重试</ElButton>
              </div>
              <div
                v-else-if="selectedApplication !== null && !personLoading && people.length === 0"
                class="workbench-hint"
              >
                这个应用还没有可分配的员工，请先在「应用用户」中添加或邀请员工。
              </div>
            </ElFormItem>

            <ElFormItem label="角色" required>
              <ElSelect
                v-model="roleId"
                filterable
                :loading="rolesLoading"
                :disabled="selectedApplication === null || !canIndexRole"
                clearable
                placeholder="选择要分配的角色"
                class="workbench-select"
              >
                <ElOption
                  v-for="row in roles"
                  :key="String(row.id)"
                  :label="typeof row.name === 'string' ? row.name : '未命名角色'"
                  :value="String(row.id)"
                />
              </ElSelect>
              <div v-if="rolesError !== ''" class="workbench-field-error">
                {{ rolesError }}
                <ElButton size="small" @click="loadRoles">重试</ElButton>
              </div>
              <div
                v-else-if="selectedApplication !== null && !rolesLoading && roles.length === 0"
                class="workbench-hint"
              >
                这个应用还没有可用角色，请先在「角色」中创建。
              </div>
            </ElFormItem>

            <ElFormItem>
              <ElButton
                type="primary"
                :disabled="!canAssign"
                :loading="saving"
                @click="assignRole"
              >
                分配角色
              </ElButton>
            </ElFormItem>
          </ElForm>

          <!-- 已分配的角色：选人后展示，资源匹配由后端完成 -->
          <div v-if="selectedPerson !== null && canIndexBinding" class="workbench-bindings">
            <h3 class="workbench-section-title">已分配的角色</h3>
            <ElAlert
              v-if="bindingsError !== ''"
              class="mb-3"
              type="warning"
              :closable="false"
              :title="bindingsError"
            />
            <ElTable
              v-loading="bindingsLoading"
              :data="bindings"
              border
              stripe
              size="small"
              empty-text="这个员工还没有分配角色"
            >
              <ElTableColumn label="角色" min-width="200">
                <template #default="scope">{{ roleName(scope.row.role_id) }}</template>
              </ElTableColumn>
              <ElTableColumn label="状态" width="110">
                <template #default="scope">{{ bindingStatusLabel(scope.row.status) }}</template>
              </ElTableColumn>
              <ElTableColumn label="操作" width="110" fixed="right">
                <template #default="scope">
                  <ElButton
                    v-if="scope.row.status === 1"
                    size="small"
                    type="danger"
                    plain
                    :disabled="!canRevoke || saving"
                    @click="revokeBinding(scope.row)"
                  >
                    撤销
                  </ElButton>
                </template>
              </ElTableColumn>
            </ElTable>
          </div>

          <!-- 高级：原十五步手动配置教程，默认收起 -->
          <ElCollapse class="workbench-advanced">
            <ElCollapseItem name="advanced" title="高级：手动配置步骤（一般不用改）">
              <TaskPath path="people-access" />
            </ElCollapseItem>
          </ElCollapse>
        </ElCard>
      </div>
    </div>
  </SandIamErrorBoundary>
</template>

<style scoped>
  .workbench-wrap {
    max-width: 760px;
    margin: 0 auto;
    width: 100%;
  }

  .workbench-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .workbench-subtitle {
    margin: 8px 0 0;
    font-size: 14px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  .workbench-form {
    margin-top: 8px;
  }

  .workbench-select {
    width: 100%;
    max-width: 420px;
  }

  .workbench-field-error {
    margin-top: 4px;
    font-size: 14px;
    color: var(--el-color-danger);
  }

  .workbench-hint {
    margin-top: 4px;
    font-size: 14px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  .workbench-bindings {
    margin-top: 20px;
  }

  .workbench-section-title {
    margin: 0 0 10px;
    font-size: 15px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .workbench-advanced {
    margin-top: 20px;
  }
</style>
