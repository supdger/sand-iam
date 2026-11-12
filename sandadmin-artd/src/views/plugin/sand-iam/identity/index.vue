<script setup lang="ts">
  import '../components/sandIamPage.css'
  import TaskContinuation from '../components/TaskContinuation.vue'
  import { useTaskApplication } from '../api/useTaskApplication'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    identityCanDelete,
    identityCanDisable,
    identityCanEnable,
    identityCanRestore,
    identityDeleteImpact,
    identityDisableImpact,
    identityGroupNamesByMember,
    identityGroupSummaryLabel,
    lifecycleStateLabel,
    parseSandIamIdentityPage,
    parseSandIamIdentityGroupMembers,
    parseSandIamIdentityGroups,
    type SandIamIdentityGroupMember,
    type SandIamIdentityRow
  } from '../api/identityLifecycleContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction, saveSandIamResource } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:identity:index'))
  const canSave = computed(() => hasAuth('sand_iam:identity:save'))
  const canUpdate = computed(() => hasAuth('sand_iam:identity:update'))
  const canDisable = computed(() => hasAuth('sand_iam:identity:disable'))
  const canEnable = computed(() => hasAuth('sand_iam:identity:enable'))
  const canDelete = computed(() => hasAuth('sand_iam:identity:delete'))
  const canRestore = computed(() => hasAuth('sand_iam:identity:restore'))
  const canGroupIndex = computed(() => hasAuth('sand_iam:identity_group:index'))
  const canMemberIndex = computed(() => hasAuth('sand_iam:identity_group_member:index'))

  const loading = ref(false)
  const saving = ref(false)
  const currentPage = ref(1)
  const pageSize = ref(100)
  const total = ref(0)
  const keywords = ref('')
  const stateFilter = ref('')
  const editorOpen = ref(false)
  let disposed = false
  let scopeVersion = 0
  let listVersion = 0
  let summaryVersion = 0
  const applications = ref<SandIamResourceRow[]>([])
  const identities = ref<SandIamIdentityRow[]>([])
  const groupNamesByIdentity = ref<ReadonlyMap<number, readonly string[]>>(new Map())
  const groupSummaryAvailable = ref(false)
  const applicationId = ref('')
  const taskContextError = useTaskApplication(applicationId, applications)
  const displayName = ref('')
  const editingIdentity = ref<SandIamIdentityRow | null>(null)
  const code = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const groupSummaryError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const lifecycleEnabled = ref<boolean | null>(null)
  watch([applicationId, stateFilter], () => {
    if (currentPage.value !== 1) currentPage.value = 1
  }, { flush: 'sync' })
  let searchTimer = 0
  watch(keywords, () => {
    window.clearTimeout(searchTimer)
    searchTimer = window.setTimeout(() => {
      if (currentPage.value !== 1) currentPage.value = 1
      else void loadIdentities()
    }, 300)
  })
  watch([applicationId, stateFilter, currentPage, pageSize], () => {
    scopeVersion++
    listVersion++
    summaryVersion++
    identities.value = []
    total.value = 0
    groupNamesByIdentity.value = new Map()
    groupSummaryAvailable.value = false
    requestError.value = null
    groupSummaryError.value = null
    loading.value = false
    viewState.value = 'idle'
    void loadIdentities()
  }, { flush: 'sync' })
  watch(applicationId, () => {
    displayName.value = ''
    code.value = ''
    editingIdentity.value = null
    editorOpen.value = false
  }, { flush: 'sync' })
  onScopeDispose(() => { disposed = true; scopeVersion++; listVersion++; summaryVersion++ })

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function applicationName(id: number): string {
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  function groupSummary(row: SandIamIdentityRow): string {
    if (selectedId(applicationId.value) === null) return '请先选择接入应用'
    return identityGroupSummaryLabel(
      row.id,
      groupNamesByIdentity.value,
      groupSummaryAvailable.value
    )
  }

  const applicationLoading = ref(false)
  const applicationError = ref('')
  const applicationKeywords = ref('')
  let applicationRequest = 0

  async function loadApplications(keywords = ''): Promise<void> {
    const attempt = ++applicationRequest
    if (disposed) return
    applicationKeywords.value = keywords
    applicationLoading.value = true
    applicationError.value = ''
    try {
      const result = await listSandIamResource('application', { page: 1, limit: 100, keywords })
      if (disposed || attempt !== applicationRequest) return
      const selected = applications.value.find(row => String(row.id) === applicationId.value)
      const rows = listRows(result)
      applications.value = selected && !rows.some(row => row.id === selected.id) ? [selected, ...rows] : rows
    } catch (error: unknown) {
      if (!disposed && attempt === applicationRequest) applicationError.value = describeSandIamError(error).detail
    } finally {
      if (!disposed && attempt === applicationRequest) applicationLoading.value = false
    }
  }


  /**
   * 组摘要来自 identity-group/members，不是 identity 列表字段。
   * 无权或生命周期未启用必须单独提示，不能把失败写成“未加入用户组”。
   */
  async function loadGroupSummaries(application: number): Promise<void> {
    const version = ++summaryVersion
    const scope = scopeVersion
    const current = (): boolean => !disposed && version === summaryVersion && scope === scopeVersion
    groupSummaryError.value = null
    groupNamesByIdentity.value = new Map()
    groupSummaryAvailable.value = false
    if (!canGroupIndex.value || !canMemberIndex.value) return
    try {
      const groups = parseSandIamIdentityGroups(
        await getSandIamAdmin('identity-group/index', { application_id: application })
      )
      if (!current()) return
      const membersByGroupId: Record<number, SandIamIdentityGroupMember[]> = {}
      for (const group of groups) {
        membersByGroupId[group.id] = parseSandIamIdentityGroupMembers(
          await getSandIamAdmin('identity-group/members', { id: group.id })
        )
        if (!current()) return
      }
      groupNamesByIdentity.value = identityGroupNamesByMember(groups, membersByGroupId)
      groupSummaryAvailable.value = true
    } catch (error: unknown) {
      if (current()) groupSummaryError.value = describeSandIamError(error)
    }
  }

  async function loadIdentities(): Promise<void> {
    const version = ++listVersion
    summaryVersion++
    const current = (): boolean => !disposed && version === listVersion
    if (disposed || !canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      const result = await listSandIamResource('identity', {
        page: currentPage.value,
        limit: pageSize.value,
        keywords: keywords.value.trim(),
        ...(stateFilter.value === '' ? {} : { lifecycle_state: stateFilter.value }),
        ...(application === null ? {} : { application_id: application })
      })
      if (!current()) return
      const page = parseSandIamIdentityPage(result)
      const rows = page.rows
      identities.value = rows
      total.value = page.total
      lifecycleEnabled.value = page.lifecycleEnabled
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
      if (application !== null) await loadGroupSummaries(application)
      else groupSummaryAvailable.value = false
    } catch (error: unknown) {
      if (!current()) return
      requestError.value = describeSandIamError(error)
      identities.value = []
    } finally {
      if (current()) loading.value = false
    }
  }

  function editIdentity(row: SandIamIdentityRow): void {
    if (disposed || saving.value || !canUpdate.value || !identities.value.includes(row) ||
      (selectedId(applicationId.value) !== null && row.application_id !== selectedId(applicationId.value))) return
    editingIdentity.value = row
    displayName.value = row.display_name
    code.value = row.code
    editorOpen.value = true
    requestError.value = null
  }

  function freshUserCode(): string {
    const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz'
    const bytes = crypto.getRandomValues(new Uint8Array(8))
    let body = ''
    for (const byte of bytes) body += alphabet[byte % alphabet.length]
    return `user_${body}`
  }

  function openCreate(): void {
    if (saving.value || !canSave.value) return
    editingIdentity.value = null
    displayName.value = ''
    code.value = freshUserCode()
    editorOpen.value = true
    requestError.value = null
  }

  function cancelEdit(): void {
    if (saving.value) return
    editingIdentity.value = null
    displayName.value = ''
    code.value = ''
    editorOpen.value = false
  }

  async function createIdentity(): Promise<void> {
    const editing = editingIdentity.value
    if (disposed || saving.value || (editing === null ? !canSave.value :
      !canUpdate.value || !identities.value.includes(editing))) return
    const scope = scopeVersion
    const current = (): boolean => !disposed && scope === scopeVersion
    const application = editing?.application_id ?? selectedId(applicationId.value)
    if (displayName.value.trim() === '') {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 请填写显示名称'))
      return
    }
    if (application === null) {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 请先选择应用'))
      return
    }
    if (editing === null && code.value.trim() === '') code.value = freshUserCode()
    saving.value = true
    requestError.value = null
    try {
      if (editing === null) {
        await saveSandIamResource('identity', {
          application_id: application,
          display_name: displayName.value.trim(),
          code: code.value.trim(),
          status: 1
        })
      } else {
        await postSandIamAction('identity/update', { id: editing.id, display_name: displayName.value.trim() })
      }
      if (!current()) return
      const savedName = displayName.value.trim()
      ElMessage.success(editing === null ? `已新建「${savedName}」` : `已保存「${savedName}」`)
      displayName.value = ''
      code.value = ''
      editingIdentity.value = null
      editorOpen.value = false
      await loadIdentities()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  /**
   * 危险操作确认框写清会撤销什么、恢复不会恢复什么；成功文案不用“操作成功”。
   */
  async function act(
    row: SandIamIdentityRow,
    path: string,
    title: string,
    impact: string,
    success: string
  ): Promise<void> {
    const allowed = (): boolean => {
      if (!identities.value.includes(row) ||
        (selectedId(applicationId.value) !== null && row.application_id !== selectedId(applicationId.value))) return false
      switch (path) {
        case 'identity/disable': return canDisable.value && identityCanDisable(row)
        case 'identity/enable': return canEnable.value && identityCanEnable(row)
        case 'identity/delete': return canDelete.value && identityCanDelete(row)
        case 'identity/restore': return canRestore.value && identityCanRestore(row)
        default: return false
      }
    }
    if (disposed || saving.value || !allowed()) return
    const scope = scopeVersion
    const current = (): boolean => !disposed && scope === scopeVersion
    saving.value = true
    try {
      await ElMessageBox.confirm(impact, title, {
        type: 'warning',
        confirmButtonText: '确认',
        cancelButtonText: '取消'
      })
    } catch {
      saving.value = false
      return
    }
    if (!current() || !allowed()) {
      saving.value = false
      return
    }
    requestError.value = null
    try {
      await postSandIamAction(path, { id: row.id })
      if (!current()) return
      ElMessage.success(success)
      await loadIdentities()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  onMounted(() => {
    void loadApplications()
    void loadIdentities()
  })
</script>

<template>
  <div class="sand-iam-page">
    <TaskContinuation :context="applicationId ? { application_id: Number(applicationId) } : {}" />
    <ElAlert v-if="taskContextError" class="mb-4" type="warning" :closable="false" :title="taskContextError" />
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">应用用户</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">查看这个应用里的用户，可以新建、改名、停用和删除。</p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看应用用户。请联系平台管理员开通应用用户查看权限。"
      />
      <ElAlert
        v-if="requestError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="requestError.detail"
      />
      <ElAlert
        v-if="groupSummaryError"
        class="mb-4"
        type="warning"
        :closable="false"
        :title="groupSummaryError.title"
        :description="groupSummaryError.detail"
      />

      <p v-if="applicationError" role="alert" class="mb-3 text-sm">
        {{ applicationError }}
        <ElButton link :loading="applicationLoading" @click="loadApplications(applicationKeywords)">重试</ElButton>
      </p>
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
          <ElSelect v-model="applicationId" filterable remote :remote-method="loadApplications" :loading="applicationLoading" clearable placeholder="选择应用" style="width: 220px">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名应用'"
              :value="String(row.id)"
            />
          </ElSelect>
          <ElInput v-model="keywords" clearable placeholder="搜索名称" style="width: 220px" />
          <ElSelect v-model="stateFilter" clearable placeholder="状态" style="width: 140px">
            <ElOption label="已启用" value="active" />
            <ElOption label="已停用" value="disabled" />
            <ElOption label="已删除" value="deleted" />
          </ElSelect>
        </div>
        <ElButton type="primary" :disabled="!canSave || selectedId(applicationId) === null" @click="openCreate">新建用户</ElButton>
      </div>

      <ElDialog v-model="editorOpen" :title="editingIdentity === null ? '新建用户' : '编辑用户'" width="480px" :close-on-click-modal="false">
        <ElAlert
          v-if="requestError"
          class="mb-3"
          type="error"
          :closable="false"
          :title="requestError.title"
          :description="requestError.detail"
        />
        <ElForm label-width="96px">
          <ElFormItem label="显示名称">
            <ElInput v-model="displayName" maxlength="80" placeholder="请填写显示名称" />
          </ElFormItem>
          <p class="text-sm text-gray-500">系统代码会自动生成，不用填写。</p>
        </ElForm>
        <template #footer>
          <ElButton :disabled="saving" @click="cancelEdit">取消</ElButton>
          <ElButton type="primary" :disabled="editingIdentity === null ? !canSave : !canUpdate" :loading="saving" @click="createIdentity">保存</ElButton>
        </template>
      </ElDialog>

      <ElTable v-loading="loading" :data="identities" border stripe empty-text="还没有用户">
        <ElTableColumn label="应用用户名称" min-width="160">
          <template #default="scope">{{ scope.row.display_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="160">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="账号状态" min-width="120">
          <template #default="scope">{{ lifecycleStateLabel(scope.row.lifecycle_state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="登录标识" min-width="120">
          <template #default>—</template>
        </ElTableColumn>
        <ElTableColumn label="用户组" min-width="180">
          <template #default="scope">{{ groupSummary(scope.row) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="260" fixed="right">
          <template #default="scope">
            <ElButton link :disabled="!canUpdate || saving" @click="editIdentity(scope.row)">编辑</ElButton>
            <ElButton
              v-if="identityCanDisable(scope.row)"
              link
              :disabled="!canDisable || saving"
              @click="
                act(
                  scope.row,
                  'identity/disable',
                  '停用应用用户',
                  identityDisableImpact(scope.row.lifecycle_state),
                  `已停用「${scope.row.display_name}」`
                )
              "
            >
              停用
            </ElButton>
            <ElButton
              v-if="identityCanEnable(scope.row)"
              link
              :disabled="!canEnable || saving"
              @click="
                act(
                  scope.row,
                  'identity/enable',
                  '启用应用用户',
                  '启用后该用户可以重新登录，但停用前的会话不会恢复。',
                  `已启用「${scope.row.display_name}」`
                )
              "
            >
              启用
            </ElButton>
            <ElButton
              v-if="lifecycleEnabled !== false && identityCanDelete(scope.row)"
              link
              type="danger"
              :disabled="!canDelete || saving"
              @click="
                act(scope.row, 'identity/delete', '删除应用用户', identityDeleteImpact(), `已删除「${scope.row.display_name}」`)
              "
            >
              删除
            </ElButton>
            <ElButton
              v-if="identityCanRestore(scope.row)"
              link
              :disabled="!canRestore || saving"
              @click="
                act(
                  scope.row,
                  'identity/restore',
                  '恢复应用用户',
                  '恢复后旧会话、旧令牌和已移除的授权关系不会恢复。',
                  `已恢复「${scope.row.display_name}」`
                )
              "
            >
              恢复
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        :page-sizes="[20, 50, 100]"
        :total="total"
        layout="total, sizes, prev, pager, next"
        @current-change="loadIdentities"
        @size-change="currentPage = 1; loadIdentities()"
      />
    </ElCard>
  </div>
</template>
