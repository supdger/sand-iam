<script setup lang="ts">
  import '../components/sandIamPage.css'
  import TaskContinuation from '../components/TaskContinuation.vue'
  import { useTaskApplication } from '../api/useTaskApplication'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    groupDeleteRefusal,
    lifecycleStateLabel,
    parseSandIamIdentities,
    parseSandIamIdentityGroupMembers,
    parseSandIamIdentityGroupRoles,
    parseSandIamIdentityGroups,
    parseSandIamRoleOptions,
    selectableGroupRoles,
    selectableGroupIdentities,
    type SandIamIdentityGroupMember,
    type SandIamIdentityGroupRole,
    type SandIamIdentityGroupRow,
    type SandIamIdentityRow,
    type SandIamRoleOption
  } from '../api/identityLifecycleContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:identity_group:index'))
  const canSave = computed(() => hasAuth('sand_iam:identity_group:save'))
  const canDisable = computed(() => hasAuth('sand_iam:identity_group:disable'))
  // 删除复用停用权限：只有能停用的管理员才能删除已停用的空组。
  const canDelete = canDisable
  const canMemberIndex = computed(() => hasAuth('sand_iam:identity_group_member:index'))
  const canMemberAdd = computed(() => hasAuth('sand_iam:identity_group_member:add'))
  const canMemberRemove = computed(() => hasAuth('sand_iam:identity_group_member:remove'))
  const canRoleIndex = computed(() => hasAuth('sand_iam:identity_group_role:index'))
  const canRoleGrant = computed(() => hasAuth('sand_iam:identity_group_role:grant'))
  const canRoleRevoke = computed(() => hasAuth('sand_iam:identity_group_role:revoke'))
  const canRoleResourceIndex = computed(() => hasAuth('sand_iam:role:index'))
  const canIdentityIndex = computed(() => hasAuth('sand_iam:identity:index'))
  const canUpdate = computed(() => hasAuth('sand_iam:identity_group:update'))
  const editingGroup = ref<SandIamIdentityGroupRow | null>(null)
  const editorOpen = ref(false)
  const groupKeywords = ref('')
  const description = ref('')
  const groupStatus = ref(1)

  const loading = ref(false)
  const groupsLoading = ref(false)
  const identitiesLoading = ref(false)
  const rolesLoading = ref(false)
  const relationLoading = ref(false)
  const relationSaving = ref(false)
  const relationMode = ref<'members' | 'roles' | null>(null)
  let disposed = false
  let appVersion = 0
  let listVersion = 0
  let relationVersion = 0
  let identityVersion = 0
  let roleVersion = 0

  function resetApplication(): void {
    appVersion++
    listVersion++
    relationVersion++
    identityVersion++
    roleVersion++
    identitiesLoading.value = false
    rolesLoading.value = false
    groups.value = []
    identities.value = []
    roles.value = []
    members.value = []
    groupRoles.value = []
    selectedGroupId.value = ''
    memberIdentityId.value = ''
    roleId.value = ''
    parentId.value = ''
    name.value = ''
    code.value = ''
    description.value = ''
    editingGroup.value = null
    editorOpen.value = false
    groupStatus.value = 1
    relationMode.value = null
    relationLoading.value = false
    groupsLoading.value = false
    requestError.value = null
    groupsReadError.value = null
    identitiesReadError.value = null
    rolesReadError.value = null
    viewState.value = 'idle'
  }
  onScopeDispose(() => { disposed = true; listVersion++; relationVersion++; identityVersion++; roleVersion++ })
  const applications = ref<SandIamResourceRow[]>([])
  const groups = ref<SandIamIdentityGroupRow[]>([])
  const identities = ref<SandIamIdentityRow[]>([])
  const members = ref<SandIamIdentityGroupMember[]>([])
  const roles = ref<SandIamRoleOption[]>([])
  const groupRoles = ref<SandIamIdentityGroupRole[]>([])
  const applicationId = ref('')
  const taskContextError = useTaskApplication(applicationId, applications)
  const name = ref('')
  const code = ref('')
  const parentId = ref('')
  const selectedGroupId = ref('')
  const memberIdentityId = ref('')
  const roleId = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const groupsReadError = ref<SandIamRequestError | null>(null)
  const identitiesReadError = ref<SandIamRequestError | null>(null)
  const rolesReadError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  watch(applicationId, () => {
    resetApplication()
    if (selectedId(applicationId.value) !== null) void loadGroups()
  }, { flush: 'sync' })
  let groupSearchTimer = 0
  watch(groupKeywords, () => {
    window.clearTimeout(groupSearchTimer)
    groupSearchTimer = window.setTimeout(() => {
      if (selectedId(applicationId.value) !== null) void loadGroups()
    }, 300)
  })

  const memberCandidates = computed(() => selectableGroupIdentities(identities.value))
  const roleCandidates = computed(() => {
    const application = selectedId(applicationId.value)
    return application === null ? [] : selectableGroupRoles(roles.value, application)
  })
  const selectedGroup = computed(() => {
    const group = selectedId(selectedGroupId.value)
    return group === null ? null : (groups.value.find((item) => item.id === group) ?? null)
  })

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

  function selectedApplicationName(): string {
    const id = selectedId(applicationId.value)
    if (id === null) return '请先选择接入应用'
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  function freshGroupCode(): string {
    const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz'
    const bytes = crypto.getRandomValues(new Uint8Array(8))
    let body = ''
    for (const byte of bytes) body += alphabet[byte % alphabet.length]
    return `group_${body}`
  }

  function groupStatusLabel(status: number): string {
    return status === 1 ? '已启用' : '已停用'
  }

  function groupRoleStatusLabel(binding: SandIamIdentityGroupRole): string {
    if (binding.status !== 1) return '已撤销'
    return binding.role_status === 1 ? '已授予' : '角色已停用'
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
   * 用户组接口要求 application_id；成员候选只加载当前应用，从根上拒绝跨应用选择。
   */
  async function loadGroups(): Promise<void> {
    const version = ++listVersion
    const app = appVersion
    const current = (): boolean => !disposed && version === listVersion && app === appVersion
    relationVersion++
    relationMode.value = null
    relationLoading.value = false
    const application = selectedId(applicationId.value)
    if (application === null) {
      groups.value = []
      viewState.value = 'idle'
      return
    }
    if (!canIndex.value) return
    groupsLoading.value = true
    groups.value = []
    identities.value = []
    roles.value = []
    requestError.value = null
    groupsReadError.value = null
    identitiesReadError.value = null
    rolesReadError.value = null
    members.value = []
    groupRoles.value = []
    selectedGroupId.value = ''
    memberIdentityId.value = ''
    roleId.value = ''

    try {
      await Promise.all([
        (async (): Promise<void> => {
          try {
            const result = parseSandIamIdentityGroups(
              await getSandIamAdmin('identity-group/index', {
                application_id: application,
                keywords: groupKeywords.value.trim()
              })
            )
            if (!current()) return
            groups.value = result
            viewState.value = groups.value.length === 0 ? 'empty' : 'ready'
          } catch (error: unknown) {
            if (!current()) return
            groups.value = []
            viewState.value = 'idle'
            groupsReadError.value = describeSandIamError(error)
          }
        })(),
        searchIdentities(''),
        searchRoles('')
      ])
    } finally {
      if (current()) groupsLoading.value = false
    }
  }

  async function searchIdentities(keywords: string): Promise<void> {
    const version = ++identityVersion
    const app = appVersion
    const application = selectedId(applicationId.value)
    const current = (): boolean => !disposed && app === appVersion && version === identityVersion
    // 搜索改变时清除旧选择，避免把已不在候选中的账号提交到当前用户组。
    memberIdentityId.value = ''
    identities.value = []
    identitiesReadError.value = null
    identitiesLoading.value = false
    if (disposed || application === null || !canIdentityIndex.value) return
    identitiesLoading.value = true
    try {
      const result = parseSandIamIdentities(await listSandIamResource('identity', {
        page: 1,
        limit: 100,
        application_id: application,
        keywords: keywords.trim()
      }))
      if (current()) identities.value = result.filter(row => row.application_id === application)
    } catch (error: unknown) {
      if (current()) identitiesReadError.value = describeSandIamError(error)
    } finally {
      if (current()) identitiesLoading.value = false
    }
  }

  async function searchRoles(keywords: string): Promise<void> {
    const version = ++roleVersion
    const app = appVersion
    const application = selectedId(applicationId.value)
    const current = (): boolean => !disposed && app === appVersion && version === roleVersion
    roleId.value = ''
    roles.value = []
    rolesReadError.value = null
    rolesLoading.value = false
    if (disposed || application === null || !canRoleResourceIndex.value) return
    rolesLoading.value = true
    try {
      const result = parseSandIamRoleOptions(await listSandIamResource('role', {
        page: 1,
        limit: 100,
        application_id: application,
        keywords: keywords.trim()
      }))
      if (current()) roles.value = result.filter(row => row.application_id === application)
    } catch (error: unknown) {
      if (current()) rolesReadError.value = describeSandIamError(error)
    } finally {
      if (current()) rolesLoading.value = false
    }
  }

  function editGroup(row: SandIamIdentityGroupRow): void {
    if (!canUpdate.value || loading.value || relationSaving.value || !validGroup(row)) return
    editingGroup.value = row
    name.value = row.name
    code.value = row.code
    description.value = row.description
    parentId.value = row.parent_id === null ? '' : String(row.parent_id)
    groupStatus.value = row.status
    editorOpen.value = true
    requestError.value = null
  }

  function openCreateGroup(): void {
    if (loading.value || relationSaving.value || !canSave.value) return
    clearGroupDraft()
    code.value = freshGroupCode()
    editorOpen.value = true
  }

  function cancelEdit(): void {
    if (loading.value) return
    clearGroupDraft()
  }

  function clearGroupDraft(): void {
    editingGroup.value = null
    name.value = ''
    code.value = ''
    description.value = ''
    parentId.value = ''
    groupStatus.value = 1
    editorOpen.value = false
  }

  async function createGroup(): Promise<void> {
    const editing = editingGroup.value
    if (disposed || loading.value || relationSaving.value ||
      (editing === null ? !canSave.value : !canUpdate.value || !validGroup(editing))) return
    const application = selectedId(applicationId.value)
    if (name.value.trim() === '') {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 请填写显示名称'))
      return
    }
    if (application === null) {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 请先选择应用'))
      return
    }
    if (editing === null && code.value.trim() === '') code.value = freshGroupCode()
    const parent = selectedId(parentId.value)
    if (parentId.value !== '' && (parent === null ||
      parent === editing?.id ||
      !groups.value.some(row => row.id === parent && row.application_id === application))) {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 请选择当前应用的上级用户组'))
      return
    }
    const app = appVersion
    const current = (): boolean => !disposed && app === appVersion && editingGroup.value === editing
    const fields = {
      name: name.value.trim(),
      description: description.value,
      parent_id: parent
    }
    const payload = editing === null ? {
      ...fields,
      application_id: application,
      code: code.value.trim()
    } : { ...fields, id: editing.id, status: groupStatus.value }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction(editing === null ? 'identity-group/save' : 'identity-group/update', payload)
      if (!current()) return
      const savedName = name.value.trim()
      ElMessage.success(editing === null ? `已新建「${savedName}」` : `已保存「${savedName}」`)
      clearGroupDraft()
      await loadGroups()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableGroup(row: SandIamIdentityGroupRow): Promise<void> {
    if (loading.value || relationSaving.value || !canDisable.value || !validGroup(row) || row.status !== 1) return
    const app = appVersion
    const current = (): boolean => !disposed && app === appVersion && validGroup(row)
    loading.value = true
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？如仍有关联内容，页面会提示下一步。`,
        '停用用户组',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      loading.value = false
      return
    }
    if (!current() || !canDisable.value || row.status !== 1) {
      loading.value = false
      return
    }
    requestError.value = null
    try {
      await postSandIamAction('identity-group/disable', { id: row.id })
      if (!current()) return
      ElMessage.success(`已停用「${row.name}」`)
      await loadGroups()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function deleteGroup(row: SandIamIdentityGroupRow): Promise<void> {
    if (loading.value || relationSaving.value || !canDelete.value || !validGroup(row) || groupDeleteRefusal(row) !== null) return
    const app = appVersion
    const current = (): boolean => !disposed && app === appVersion && validGroup(row)
    loading.value = true
    try {
      await ElMessageBox.confirm(
        '删除后不能恢复。',
        `删除「${row.name}」？`,
        {
          type: 'warning',
          confirmButtonText: '删除',
          cancelButtonText: '再想想',
          confirmButtonClass: 'el-button--danger'
        }
      )
    } catch {
      loading.value = false
      return
    }
    if (!current() || !canDelete.value || groupDeleteRefusal(row) !== null) {
      loading.value = false
      return
    }
    requestError.value = null
    try {
      await postSandIamAction('identity-group/delete', { id: row.id })
      if (!current()) return
      ElMessage.success(`已删除「${row.name}」`)
      await loadGroups()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function loadMembers(row: SandIamIdentityGroupRow): Promise<void> {
    if (!canMemberIndex.value || !validGroup(row)) return
    ++relationVersion
    selectedGroupId.value = String(row.id)
    relationMode.value = 'members'
    const current = relationContext()
    members.value = []
    memberIdentityId.value = ''
    groupRoles.value = []
    roleId.value = ''
    relationLoading.value = true
    requestError.value = null
    try {
      const result = parseSandIamIdentityGroupMembers(
        await getSandIamAdmin('identity-group/members', { id: row.id })
      )
      if (current()) members.value = result
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      if (current()) relationLoading.value = false
    }
  }

  async function loadGroupRoles(row: SandIamIdentityGroupRow): Promise<void> {
    if (!canRoleIndex.value || !validGroup(row)) return
    ++relationVersion
    selectedGroupId.value = String(row.id)
    relationMode.value = 'roles'
    const current = relationContext()
    groupRoles.value = []
    members.value = []
    memberIdentityId.value = ''
    roleId.value = ''
    relationLoading.value = true
    requestError.value = null
    try {
      const result = parseSandIamIdentityGroupRoles(
        await getSandIamAdmin('identity-group-role/index', {
          identity_group_id: row.id
        })
      )
      if (current()) groupRoles.value = result
    } catch (error: unknown) {
      if (!current()) return
      requestError.value = describeSandIamError(error)
      groupRoles.value = []
    } finally {
      if (current()) relationLoading.value = false
    }
  }

  function validGroup(group: SandIamIdentityGroupRow): boolean {
    return !disposed && groups.value.includes(group) &&
      group.application_id === selectedId(applicationId.value)
  }

  function relationContext(): () => boolean {
    const app = appVersion
    const version = relationVersion
    const group = selectedGroup.value
    const mode = relationMode.value
    return () => !disposed && app === appVersion && version === relationVersion &&
      group !== null && validGroup(group) && selectedGroup.value === group &&
      relationMode.value === mode
  }

  async function grantRole(): Promise<void> {
    if (disposed || loading.value || rolesLoading.value || relationSaving.value || relationLoading.value || relationMode.value !== 'roles' ||
      !canRoleGrant.value || !canRoleResourceIndex.value || rolesReadError.value !== null) return
    const group = selectedId(selectedGroupId.value)
    const role = selectedId(roleId.value)
    const application = selectedId(applicationId.value)
    const selectedRole = roles.value.find((item) => item.id === role)
    const selectedGroupRow = groups.value.find((item) => item.id === group)
    if (
      group === null ||
      role === null ||
      application === null ||
      selectedGroupRow === undefined ||
      selectedRole === undefined
    ) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择用户组和角色')
      )
      return
    }
    if (
      selectedGroupRow.application_id !== application ||
      selectedRole.application_id !== application
    ) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 用户组和角色必须属于当前接入应用')
      )
      return
    }
    if (selectedGroupRow.status !== 1 || !roleCandidates.value.includes(selectedRole)) return
    const current = relationContext()
    relationSaving.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group-role/grant', {
        identity_group_id: group,
        role_id: role
      })
      if (!current()) return
      ElMessage.success('已授予角色')
      roleId.value = ''
      await loadGroupRoles(selectedGroupRow)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
    }
  }

  async function revokeRole(binding: SandIamIdentityGroupRole): Promise<void> {
    const group = selectedGroup.value
    if (group === null || !validGroup(group) || loading.value || relationSaving.value || relationLoading.value ||
      relationMode.value !== 'roles' || !canRoleRevoke.value ||
      !groupRoles.value.includes(binding) || binding.identity_group_id !== group.id || binding.status !== 1) return
    const current = relationContext()
    relationSaving.value = true
    try {
      await ElMessageBox.confirm(
        `撤销「${binding.role_name}」后，该用户组成员将不再通过此用户组获得该角色。确认撤销吗？`,
        '撤销用户组角色',
        { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' }
      )
    } catch {
      relationSaving.value = false
      return
    }
    if (!current() || !canRoleRevoke.value || !groupRoles.value.includes(binding)) {
      relationSaving.value = false
      return
    }
    requestError.value = null
    try {
      await postSandIamAction('identity-group-role/revoke', { id: binding.id })
      if (!current()) return
      ElMessage.success('已撤销角色')
      await loadGroupRoles(group)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
    }
  }

  async function addMember(): Promise<void> {
    if (disposed || loading.value || identitiesLoading.value || relationSaving.value || relationLoading.value || relationMode.value !== 'members' ||
      !canMemberAdd.value || !canIdentityIndex.value || identitiesReadError.value !== null) return
    const group = selectedId(selectedGroupId.value)
    const identity = selectedId(memberIdentityId.value)
    const row = selectedGroup.value
    const candidate = memberCandidates.value.find(item => item.id === identity)
    if (row === null || !validGroup(row) || candidate === undefined ||
      candidate.application_id !== selectedId(applicationId.value)) return
    if (group === null || identity === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择用户组和本应用用户')
      )
      return
    }
    const current = relationContext()
    relationSaving.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/member/add', {
        id: group,
        identity_id: identity
      })
      if (!current()) return
      ElMessage.success(`已加入「${candidate.display_name}」`)
      await loadMembers(row)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
    }
  }

  async function removeMember(member: SandIamIdentityGroupMember): Promise<void> {
    const group = selectedId(selectedGroupId.value)
    const row = selectedGroup.value
    if (group === null || row === null || !validGroup(row) || loading.value || relationSaving.value ||
      relationLoading.value || relationMode.value !== 'members' || !canMemberRemove.value ||
      !members.value.includes(member)) return
    const current = relationContext()
    relationSaving.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/member/remove', {
        id: group,
        identity_id: member.identity_id
      })
      if (!current()) return
      ElMessage.success(`已移出「${member.display_name}」`)
      await loadMembers(row)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
    }
  }

  async function enableGroup(row: SandIamIdentityGroupRow): Promise<void> {
    if (loading.value || relationSaving.value || !canUpdate.value || !validGroup(row) || row.status === 1) return
    const app = appVersion
    const current = (): boolean => !disposed && app === appVersion && validGroup(row)
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/update', {
        id: row.id,
        name: row.name,
        description: row.description,
        parent_id: row.parent_id,
        status: 1
      })
      if (!current()) return
      ElMessage.success(`已启用「${row.name}」`)
      await loadGroups()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadApplications()
  })
</script>

<template>
  <div class="sand-iam-page">
    <TaskContinuation :context="applicationId ? { application_id: Number(applicationId) } : {}" />
    <ElAlert v-if="taskContextError" class="mb-4" type="warning" :closable="false" :title="taskContextError" />
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">用户组</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">按应用管理用户组，可以新建、改名、停用和删除。</p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号暂时不能查看用户组。请联系管理员开通用户组管理范围后再试。"
      />
      <ElAlert
        v-if="groupsReadError"
        class="mb-4"
        type="error"
        :closable="false"
        title="暂时无法读取用户组"
        description="请检查网络后重试；持续失败时联系管理员。其他页面数据不会因此丢失。"
      />
      <ElAlert
        v-if="requestError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="requestError.detail"
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
          <ElInput v-model="groupKeywords" clearable placeholder="搜索名称" style="width: 220px" />
        </div>
        <ElButton type="primary" :disabled="!canSave || selectedId(applicationId) === null" @click="openCreateGroup">新建用户组</ElButton>
      </div>
      <ElDialog v-model="editorOpen" :title="editingGroup === null ? '新建用户组' : '编辑用户组'" width="520px" :close-on-click-modal="false">
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
            <ElInput v-model="name" maxlength="80" placeholder="请填写显示名称" />
          </ElFormItem>
          <ElFormItem label="说明">
            <ElInput v-model="description" type="textarea" />
          </ElFormItem>
          <ElFormItem v-if="editingGroup !== null" label="状态">
            <ElRadioGroup v-model="groupStatus">
              <ElRadio :value="1">启用</ElRadio>
              <ElRadio :value="2">停用</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem label="上级用户组">
            <ElSelect v-model="parentId" filterable clearable placeholder="可不选">
              <ElOption
                v-for="row in groups.filter(item => item.id !== editingGroup?.id)"
                :key="String(row.id)"
                :label="row.name"
                :value="String(row.id)"
              />
            </ElSelect>
          </ElFormItem>
          <p v-if="editingGroup === null" class="text-sm text-gray-500">系统代码会自动生成，不用填写。</p>
        </ElForm>
        <template #footer>
          <ElButton :disabled="loading" @click="cancelEdit">取消</ElButton>
          <ElButton type="primary" :disabled="(editingGroup === null ? !canSave : !canUpdate) || relationSaving" :loading="loading" @click="createGroup">保存</ElButton>
        </template>
      </ElDialog>

      <ElTable v-loading="loading || groupsLoading" :data="groups" border stripe empty-text="还没有用户组">
        <ElTableColumn label="用户组名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="160">
          <template #default>{{ selectedApplicationName() }}</template>
        </ElTableColumn>
        <ElTableColumn label="上级用户组" min-width="140">
          <template #default="scope">{{ scope.row.parent_name || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="成员数量" min-width="90">
          <template #default="scope">{{ scope.row.member_count }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="90">
          <template #default="scope">{{ groupStatusLabel(scope.row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="420" fixed="right">
          <template #default="scope">
            <ElButton link :disabled="!canUpdate || loading || relationSaving" @click="editGroup(scope.row)">编辑</ElButton>
            <ElButton link :disabled="!canMemberIndex" @click="loadMembers(scope.row)">成员</ElButton>
            <ElButton link :disabled="!canRoleIndex" @click="loadGroupRoles(scope.row)">角色</ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              link
              :disabled="!canDisable || loading || relationSaving"
              @click="disableGroup(scope.row)"
            >
              停用
            </ElButton>
            <ElButton
              v-else
              link
              :disabled="!canUpdate || loading || relationSaving"
              @click="enableGroup(scope.row)"
            >
              启用
            </ElButton>
            <ElTooltip
              :disabled="groupDeleteRefusal(scope.row) === null"
              :content="groupDeleteRefusal(scope.row) ?? ''"
              placement="top"
            >
              <span>
                <ElButton
                  link
                  type="danger"
                  :disabled="!canDelete || loading || relationSaving || groupDeleteRefusal(scope.row) !== null"
                  @click="deleteGroup(scope.row)"
                >
                  删除
                </ElButton>
              </span>
            </ElTooltip>
          </template>
        </ElTableColumn>
      </ElTable>

      <h3 class="mt-8 text-base">组成员</h3>
      <ElAlert
        v-if="!canIdentityIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="暂时无法读取可选用户"
        description="当前账号不能读取本应用的用户，不能按名称添加成员。请联系管理员开通用户查看范围后再试。"
      />
      <ElAlert
        v-else-if="identitiesReadError"
        class="mb-4"
        type="warning"
        :closable="false"
        title="暂时无法读取可选用户"
        description="请检查网络后重试。已加载的用户组不会受影响。"
      />
      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="本应用用户">
          <ElSelect
            v-model="memberIdentityId"
            filterable
            remote
            :remote-method="searchIdentities"
            :loading="identitiesLoading"
            :disabled="!canIdentityIndex"
            clearable
            placeholder="输入用户名称搜索当前应用"
          >
            <ElOption
              v-for="row in memberCandidates"
              :key="String(row.id)"
              :label="row.display_name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton
            :disabled="!canMemberAdd || !canIdentityIndex || identitiesReadError !== null || identitiesLoading || relationMode !== 'members' || relationLoading || relationSaving || loading"
            :loading="relationSaving"
            @click="addMember"
            >加入</ElButton
          >
        </ElFormItem>
      </ElForm>
      <ElTable v-loading="relationMode === 'members' && relationLoading" :data="members" border stripe empty-text="先选择一个用户组查看成员">
        <ElTableColumn label="应用用户" min-width="160">
          <template #default="scope">{{ scope.row.display_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="账号状态" min-width="120">
          <template #default="scope">{{ lifecycleStateLabel(scope.row.lifecycle_state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="100" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canMemberRemove || relationSaving || relationLoading || loading" @click="removeMember(scope.row)">
              移出
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <h3 class="mt-8 text-base">用户组角色</h3>
      <ElAlert
        v-if="selectedGroup === null || relationMode !== 'roles'"
        class="mb-4"
        type="info"
        :closable="false"
        title="先选择一个用户组"
        description="在上方用户组列表中点击“角色”，再按名称选择要授予的角色。"
      />
      <template v-else>
        <p class="mb-4 mt-2 text-sm text-gray-500">
          正在管理「{{
            selectedGroup.name
          }}」的角色。只可授予当前接入应用内启用的角色；撤销后成员立即不再通过该用户组获得该角色。
        </p>
        <ElAlert
          v-if="!canRoleResourceIndex"
          class="mb-4"
          type="warning"
          :closable="false"
          title="暂时无法读取可授予角色"
          description="当前账号可以查看用户组，但没有读取角色列表的权限。请联系管理员补齐角色查看权限后再授予角色。"
        />
        <ElAlert
          v-else-if="rolesReadError"
          class="mb-4"
          type="warning"
          :closable="false"
          title="暂时无法读取可授予角色"
          description="请检查网络后重试。已授予的角色不会受影响。"
        />
        <ElForm label-width="160px" class="mb-4">
          <ElFormItem label="角色">
            <ElSelect
              v-model="roleId"
              filterable
              remote
              :remote-method="searchRoles"
              :loading="rolesLoading"
              :disabled="!canRoleResourceIndex"
              clearable
              placeholder="输入角色名称搜索当前应用"
            >
              <ElOption
                v-for="row in roleCandidates"
                :key="String(row.id)"
                :label="`${row.name}（${row.code}）`"
                :value="String(row.id)"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton
              type="primary"
              :disabled="
                !canRoleGrant ||
                relationSaving || relationLoading || rolesLoading || loading ||
                !canRoleResourceIndex ||
                rolesReadError !== null ||
                selectedGroup.status !== 1
              "
              :loading="relationSaving"
              @click="grantRole"
            >
              授予角色
            </ElButton>
          </ElFormItem>
        </ElForm>
        <ElTable v-loading="relationLoading" :data="groupRoles" border stripe empty-text="该用户组尚未获授角色">
          <ElTableColumn label="角色名称" min-width="180">
            <template #default="scope">{{ scope.row.role_name }}</template>
          </ElTableColumn>
          <ElTableColumn label="系统代码" min-width="160">
            <template #default="scope">{{ scope.row.role_code }}</template>
          </ElTableColumn>
          <ElTableColumn label="状态" min-width="120">
            <template #default="scope">{{ groupRoleStatusLabel(scope.row) }}</template>
          </ElTableColumn>
          <ElTableColumn label="操作" min-width="100" fixed="right">
            <template #default="scope">
              <ElButton
                size="small"
                type="danger"
                :disabled="!canRoleRevoke || scope.row.status !== 1 || relationSaving || relationLoading || loading"
                @click="revokeRole(scope.row)"
              >
                撤销
              </ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
      </template>
    </ElCard>
  </div>
</template>
