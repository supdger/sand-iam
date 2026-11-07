<script setup lang="ts">
  import '../components/sandIamPage.css'
  import SandIamErrorBoundary from '../components/SandIamErrorBoundary.vue'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import type { CheckboxValueType } from 'element-plus'
  import { isHttpError } from '@/utils/http/error'
  import {
    capabilityFailurePayload,
    fetchSandIamCapabilities,
    isUncertainCapabilityOutcome,
    parseSandIamCapabilityCatalog,
    parseSandIamCapabilitySaveResult,
    plainSandIamCapabilityMessage,
    saveSandIamCapabilities,
    type SandIamCapabilityCatalog,
    type SandIamCapabilityItem,
    type SandIamCapabilitySaveResult
  } from '../api/applicationCapabilities'
  import { describeSandIamBoundaryFault } from '../api/errorBoundary'
  import { createSandIamRequestId } from '../api/requestId'
  import { listSandIamResource } from '../api/resource'
  import type { SandIamResourceRow } from '../api/types'

  const PAGE = '/sand-iam/application-capability'
  const route = useRoute()
  const router = useRouter()

  /* ---------- 视图与数据 ---------- */

  type View = 'pick-app' | 'loading' | 'forbidden' | 'missing' | 'load-error' | 'ready'
  type Phase = 'edit' | 'submitting' | 'ok' | 'partial' | 'fail' | 'syserr'

  const view = ref<View>('loading')
  const phase = ref<Phase>('edit')
  const catalog = ref<SandIamCapabilityCatalog | null>(null)
  const checkedIds = ref<string[]>([])
  const previousChecked = ref<string[]>([])
  const loadError = ref<{ code: string; time: string; requestId?: string } | null>(null)
  const loadErrorMessage = ref('')
  const resultMessage = ref('')
  const resultDetail = ref('')
  const submitting = ref(false)

  /** 网络结果不确定时保留的原始提交；重试必须复用同一幂等键与同一请求体。 */
  interface PendingSubmission {
    key: string
    ids: string[]
    revision: string
  }
  const pendingSubmission = ref<PendingSubmission | null>(null)

  let disposed = false
  let loadVersion = 0

  const applicationId = computed<number | null>(() => {
    const raw = route.query.application_id
    const text = Array.isArray(raw) ? raw[0] : raw
    const parsed = typeof text === 'string' ? Number(text) : NaN
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  })

  const grantedIdSet = computed<ReadonlySet<string>>(
    () => new Set((catalog.value?.items ?? []).filter((item) => item.granted).map((item) => item.id))
  )

  interface CapabilityGroup {
    name: string
    items: SandIamCapabilityItem[]
  }

  /** 按 group_name 分组，保持目录返回顺序；空组名归入「其他功能」。 */
  const groups = computed<CapabilityGroup[]>(() => {
    const result: CapabilityGroup[] = []
    const index = new Map<string, CapabilityGroup>()
    for (const item of catalog.value?.items ?? []) {
      const name = item.group_name.trim() === '' ? '其他功能' : item.group_name
      let group = index.get(name)
      if (group === undefined) {
        group = { name, items: [] }
        index.set(name, group)
        result.push(group)
      }
      group.items.push(item)
    }
    return result
  })

  const pageTitle = computed(() =>
    catalog.value !== null ? `给「${catalog.value.application.name}」开通功能` : '开通功能'
  )

  const selectedCount = computed(() => checkedIds.value.length)
  const grantedCount = computed(() => grantedIdSet.value.size)

  /** 主按钮「允许使用」闸门：仅编辑态可提交；已选>0，或存在已开通项可全部收回。 */
  const canSubmit = computed(
    () =>
      view.value === 'ready' &&
      phase.value === 'edit' &&
      catalog.value !== null &&
      catalog.value.can_modify &&
      !submitting.value &&
      (selectedCount.value > 0 || grantedCount.value > 0)
  )

  function itemName(id: string): string {
    return catalog.value?.items.find((item) => item.id === id)?.name ?? ''
  }

  /* ---------- 目录加载 ---------- */

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  /* ---------- 无 application_id 时的应用选择器 ---------- */

  const appOptions = ref<SandIamResourceRow[]>([])
  const appLoading = ref(false)
  const appError = ref('')
  let appRequest = 0
  let appKeywords = ''

  async function searchApplications(keywords = ''): Promise<void> {
    const attempt = ++appRequest
    appKeywords = keywords
    appLoading.value = true
    appError.value = ''
    try {
      const result = await listSandIamResource('application', {
        page: 1,
        limit: 50,
        keywords
      })
      if (disposed || attempt !== appRequest) return
      appOptions.value = listRows(result)
    } catch {
      if (!disposed && attempt === appRequest) {
        appError.value = '应用列表暂时不可用，请稍后重试。'
      }
    } finally {
      if (!disposed && attempt === appRequest) appLoading.value = false
    }
  }

  function pickApplication(id: number): void {
    void router.replace({ path: PAGE, query: { application_id: String(id) } })
  }

  /* ---------- 目录加载与错误分流 ---------- */

  function faultOf(error: unknown): { code: string; time: string; requestId?: string } {
    const fault = describeSandIamBoundaryFault(error, PAGE)
    return {
      code: fault.code,
      time: fault.time,
      ...(fault.requestId !== undefined ? { requestId: fault.requestId } : {})
    }
  }

  async function copyLoadProblem(): Promise<void> {
    if (loadError.value === null) return
    const text = [
      'SandIAM 管理页问题信息',
      `错误码：${loadError.value.code}`,
      `时间：${loadError.value.time}`,
      `页面：${PAGE}`,
      ...(loadError.value.requestId ? [`请求编号：${loadError.value.requestId}`] : [])
    ].join('\n')
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text)
      } else {
        const area = document.createElement('textarea')
        area.value = text
        area.style.position = 'fixed'
        area.style.opacity = '0'
        document.body.appendChild(area)
        area.select()
        document.execCommand('copy')
        area.remove()
      }
      ElMessage.success('已复制，可发给管理员')
    } catch {
      ElMessage.warning('复制未完成，请重试')
    }
  }

  async function loadCatalog(preserveSelection = false): Promise<void> {
    const appId = applicationId.value
    if (appId === null) {
      view.value = 'pick-app'
      return
    }
    const version = ++loadVersion
    view.value = 'loading'
    loadError.value = null
    loadErrorMessage.value = ''
    try {
      const result = await fetchSandIamCapabilities(appId)
      if (disposed || version !== loadVersion) return
      const parsed = parseSandIamCapabilityCatalog(result)
      if (parsed === null) {
        // 结构不符按系统故障处理，不猜测渲染
        view.value = 'load-error'
        loadError.value = faultOf(new Error('capability catalog shape mismatch'))
        return
      }
      catalog.value = parsed
      const desired = preserveSelection
        ? checkedIds.value.filter((id) => parsed.items.some((item) => item.id === id))
        : parsed.items.filter((item) => item.granted).map((item) => item.id)
      checkedIds.value = desired
      previousChecked.value = [...desired]
      pendingSubmission.value = null
      phase.value = 'edit'
      resultMessage.value = ''
      resultDetail.value = ''
      view.value = 'ready'
    } catch (error: unknown) {
      if (disposed || version !== loadVersion) return
      catalog.value = null
      const http = isHttpError(error) ? error.code : null
      if (http === 403) {
        view.value = 'forbidden'
        loadErrorMessage.value =
          plainSandIamCapabilityMessage(error) ?? '当前账号没有查看这个应用功能授权的权限，请联系管理员。'
        return
      }
      if (http === 404) {
        view.value = 'missing'
        loadErrorMessage.value =
          plainSandIamCapabilityMessage(error) ?? '这个应用不存在或已停用，请返回应用列表确认。'
        return
      }
      view.value = 'load-error'
      loadError.value = faultOf(error)
    }
  }

  /* ---------- 勾选与收回确认 ---------- */

  async function onCheckedChange(next: CheckboxValueType[]): Promise<void> {
    const ids = next.filter((value): value is string => typeof value === 'string')
    const nextSet = new Set(ids)
    const revoked = previousChecked.value.filter(
      (id) => !nextSet.has(id) && grantedIdSet.value.has(id)
    )
    if (revoked.length === 0) {
      previousChecked.value = [...ids]
      return
    }
    const names = revoked.map(itemName).filter((name) => name !== '')
    const label =
      names.length === 0
        ? '这些功能'
        : names.map((name) => `『${name}』`).join('、')
    try {
      await ElMessageBox.confirm(
        `收回后这个应用就不能再用${label}了。`,
        '收回功能',
        {
          type: 'warning',
          confirmButtonText: '确认收回',
          cancelButtonText: '取消'
        }
      )
    } catch {
      // 取消收回：恢复勾选前的状态（v-model 已写入 next，回写 previous）
      checkedIds.value = [...previousChecked.value]
      return
    }
    previousChecked.value = [...ids]
  }

  /* ---------- 提交与幂等 ---------- */

  function desiredIds(): string[] {
    const wanted = new Set(checkedIds.value)
    // 保持目录顺序提交完整期望集合，而不是勾选框的点击顺序
    return (catalog.value?.items ?? []).filter((item) => wanted.has(item.id)).map((item) => item.id)
  }

  function newIdempotencyKey(): string {
    return `cap-${createSandIamRequestId()}`
  }

  /** 核心提交：key/ids/revision 由调用方决定，保证不确定重试逐字节复用。 */
  async function submitCore(pending: PendingSubmission): Promise<void> {
    const appId = applicationId.value
    const current = catalog.value
    if (appId === null || current === null || disposed) return
    submitting.value = true
    phase.value = 'submitting'
    try {
      const result = await saveSandIamCapabilities(appId, pending.ids, pending.revision, pending.key)
      if (disposed) return
      const parsed = parseSandIamCapabilitySaveResult(result)
      if (parsed === null) {
        // 结构不符视为系统故障；已拿到响应，重试用新键重新走完整流程
        pendingSubmission.value = null
        enterSystemFault(new Error('capability save shape mismatch'))
        return
      }
      pendingSubmission.value = null
      handleSaveResult(parsed)
    } catch (error: unknown) {
      if (disposed) return
      const failure = capabilityFailurePayload(error)
      if (failure !== null) {
        // HTTP 422 failed：服务端明确答复，按业务失败呈现
        pendingSubmission.value = null
        handleSaveResult(failure)
        return
      }
      if (isUncertainCapabilityOutcome(error)) {
        // 结果不确定：保留 pendingSubmission，重试复用同键同体
        enterSystemFault(error)
        return
      }
      pendingSubmission.value = null
      enterBusinessFault(error)
      // 409 修订冲突：静默刷新目录（保留失败提示与勾选），让「重试」带上新 revision
      if (isHttpError(error) && error.code === 409) {
        void reloadAfterResult()
      }
    } finally {
      submitting.value = false
    }
  }

  function handleSaveResult(result: SandIamCapabilitySaveResult): void {
    if (result.status === 'success') {
      phase.value = 'ok'
      resultMessage.value = `已允许「${catalog.value?.application.name ?? ''}」使用 ${result.granted_count} 项功能，现在就能用了。`
      resultDetail.value = ''
      void reloadAfterResult()
      return
    }
    if (result.status === 'partial') {
      phase.value = 'partial'
      const failed = result.items.filter((item) => !item.ok)
      const succeeded = result.items.filter((item) => item.ok && item.operation !== 'unchanged').length
      const parts = failed.map((item) =>
        `「${item.name}」${item.operation === 'revoke' ? '没能收回' : '没开通'}：${item.message === '' ? '请稍后重试' : item.message}`
      )
      resultMessage.value = `已开通 ${succeeded} 项${parts.length > 0 ? '；' + parts.join('；') : ''}`
      resultDetail.value = ''
      void reloadAfterResult()
      return
    }
    // status === 'failed'
    phase.value = 'fail'
    const first = result.items.find((item) => !item.ok && item.message !== '')
    const reason = first?.message ?? (result.message === '' ? '请稍后重试' : result.message)
    resultMessage.value = ''
    resultDetail.value = `没能开通：${reason}`
    void reloadAfterResult()
  }

  /**
   * 提交后静默刷新目录：同步 granted 标记与 revision。
   * 保留用户当前勾选（期望集合），不把已开通态回写成勾选态；
   * 结果文案在刷新前后原样保留。
   */
  async function reloadAfterResult(): Promise<void> {
    const keep = {
      phase: phase.value,
      message: resultMessage.value,
      detail: resultDetail.value
    }
    await loadCatalog(true)
    if (disposed || view.value !== 'ready') return
    phase.value = keep.phase
    resultMessage.value = keep.message
    resultDetail.value = keep.detail
  }

  function enterBusinessFault(error: unknown): void {
    phase.value = 'fail'
    const reason = plainSandIamCapabilityMessage(error)
    resultMessage.value = ''
    resultDetail.value = `没能开通：${reason ?? '请稍后重试'}`
  }

  function enterSystemFault(error: unknown): void {
    phase.value = 'syserr'
    const fault = describeSandIamBoundaryFault(error, PAGE)
    loadError.value = {
      code: fault.code,
      time: fault.time,
      ...(fault.requestId !== undefined ? { requestId: fault.requestId } : {})
    }
    resultMessage.value = ''
    resultDetail.value = ''
  }

  async function copyResultProblem(): Promise<void> {
    await copyLoadProblem()
  }

  /**
   * 用户主动提交：每次生成新幂等键。
   * 编辑态（「允许使用」）与业务失败态（「重试」）都走这里；
   * 系统故障态的「重试」必须走 retryUncertain 复用原键。
   */
  async function startSubmit(): Promise<void> {
    const current = catalog.value
    if (current === null || submitting.value || !current.can_modify) return
    if (phase.value !== 'edit' && phase.value !== 'fail') return
    const ids = desiredIds()
    const granted = grantedCount.value
    if (ids.length === 0 && granted === 0) return
    const pending: PendingSubmission = {
      key: newIdempotencyKey(),
      ids,
      revision: current.revision
    }
    pendingSubmission.value = pending
    await submitCore(pending)
  }

  /** 系统故障重试：复用同一幂等键与同一请求体（结果可能本来就已生效）。 */
  async function retryUncertain(): Promise<void> {
    const pending = pendingSubmission.value
    if (pending !== null) {
      await submitCore(pending)
      return
    }
    // 没有待定提交（例如目录加载失败）时退化为重新加载
    await loadCatalog(true)
  }

  /** 部分失败重试：先刷新 revision，再用当前完整期望集合 + 新幂等键提交。 */
  async function retryPartial(): Promise<void> {
    await loadCatalog(true)
    if (disposed || view.value !== 'ready') return
    await startSubmit()
  }

  function backToEdit(): void {
    phase.value = 'edit'
    resultMessage.value = ''
    resultDetail.value = ''
    void loadCatalog(true)
  }

  function finish(): void {
    void router.push('/sand-iam/application')
  }

  function cancel(): void {
    void router.push('/sand-iam/application')
  }

  /* ---------- 生命周期 ---------- */

  onMounted(() => {
    if (applicationId.value === null) {
      view.value = 'pick-app'
      void searchApplications()
    } else {
      void loadCatalog()
    }
  })

  onScopeDispose(() => {
    disposed = true
    loadVersion++
    appRequest++
  })

  watch(applicationId, (next, prev) => {
    if (next === prev) return
    catalog.value = null
    checkedIds.value = []
    previousChecked.value = []
    pendingSubmission.value = null
    phase.value = 'edit'
    if (next === null) {
      view.value = 'pick-app'
      void searchApplications()
    } else {
      void loadCatalog()
    }
  })
</script>

<template>
  <SandIamErrorBoundary :page="PAGE">
    <div class="sand-iam-page">
      <div class="capability-wrap">
        <header class="capability-head">
          <h1 class="capability-title">{{ pageTitle }}</h1>
          <p class="capability-subtitle">
            勾选这个应用要用的功能，点“允许使用”即可，其他设置由系统完成。
          </p>
        </header>

        <!-- 无应用上下文：先选应用 -->
        <ElCard v-if="view === 'pick-app'" class="sand-iam-page-card" shadow="never">
          <ElForm label-width="120px">
            <ElFormItem label="接入应用">
              <ElSelect
                filterable
                remote
                :remote-method="searchApplications"
                :loading="appLoading"
                placeholder="按名称选择应用"
                class="capability-app-select"
                @change="(value: unknown) => { if (typeof value === 'string' || typeof value === 'number') pickApplication(Number(value)) }"
              >
                <ElOption
                  v-for="row in appOptions"
                  :key="String(row.id)"
                  :label="typeof row.name === 'string' ? row.name : '未命名应用'"
                  :value="String(row.id)"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem v-if="appError !== ''">
              <span class="capability-muted">{{ appError }}</span>
              <ElButton size="small" @click="searchApplications(appKeywords)">重试</ElButton>
            </ElFormItem>
          </ElForm>
        </ElCard>

        <!-- 加载中 -->
        <ElCard v-else-if="view === 'loading'" class="sand-iam-page-card" shadow="never">
          <div class="capability-loading" v-loading="true" />
        </ElCard>

        <!-- 无权限查看 -->
        <ElCard v-else-if="view === 'forbidden'" class="sand-iam-page-card" shadow="never">
          <ElAlert
            type="warning"
            :closable="false"
            title="没有权限"
            :description="loadErrorMessage"
          />
          <div class="capability-actions">
            <ElButton @click="cancel">返回应用列表</ElButton>
          </div>
        </ElCard>

        <!-- 应用不存在 -->
        <ElCard v-else-if="view === 'missing'" class="sand-iam-page-card" shadow="never">
          <ElAlert
            type="info"
            :closable="false"
            title="应用不可用"
            :description="loadErrorMessage"
          />
          <div class="capability-actions">
            <ElButton @click="cancel">返回应用列表</ElButton>
          </div>
        </ElCard>

        <!-- 目录加载系统故障 -->
        <ElCard v-else-if="view === 'load-error'" class="sand-iam-page-card" shadow="never">
          <ElAlert
            type="error"
            :closable="false"
            title="系统出了点问题，请稍后再试"
            description="功能目录暂时无法加载。"
          />
          <div class="capability-actions">
            <ElButton type="primary" @click="copyLoadProblem">复制问题信息</ElButton>
            <ElButton @click="loadCatalog()">重试</ElButton>
            <ElButton @click="cancel">返回应用列表</ElButton>
          </div>
        </ElCard>

        <!-- 就绪：功能勾选 + 结果区 + 操作条 -->
        <template v-else>
          <ElAlert
            v-if="catalog !== null && !catalog.can_modify"
            class="capability-banner"
            type="warning"
            :closable="false"
            title="你只能查看，不能修改授权。请找管理员开通。"
          />

          <ElCard class="sand-iam-page-card" shadow="never">
            <div v-if="groups.length === 0" class="capability-empty">
              <ElEmpty description="这个应用暂时没有可授权的功能。" />
            </div>

            <div v-for="group in groups" :key="group.name" class="capability-group">
              <h2 class="capability-group-title">{{ group.name }}</h2>
              <ElCheckboxGroup
                v-model="checkedIds"
                class="capability-list"
                @change="onCheckedChange"
              >
                <div
                  v-for="item in group.items"
                  :key="item.id"
                  class="capability-item"
                >
                  <ElCheckbox
                    :value="item.id"
                    :disabled="!catalog?.can_modify || submitting"
                    class="capability-check"
                  >
                    <span class="capability-item-name">{{ item.name }}</span>
                    <ElTag
                      v-if="item.granted"
                      size="small"
                      type="success"
                      effect="plain"
                      class="capability-granted-tag"
                    >
                      已开通
                    </ElTag>
                  </ElCheckbox>
                  <p v-if="item.description !== ''" class="capability-item-desc">
                    {{ item.description }}
                  </p>
                </div>
              </ElCheckboxGroup>
            </div>
          </ElCard>

          <!-- 结果区：提交中 / 成功 / 部分失败 / 业务失败 / 系统故障 -->
          <ElAlert
            v-if="phase === 'submitting'"
            class="capability-banner"
            type="info"
            :closable="false"
            title="正在开通……"
          />
          <ElAlert
            v-else-if="phase === 'ok'"
            class="capability-banner"
            type="success"
            :closable="false"
            :title="resultMessage"
          />
          <ElAlert
            v-else-if="phase === 'partial'"
            class="capability-banner"
            type="warning"
            :closable="false"
            :title="resultMessage"
          />
          <ElAlert
            v-else-if="phase === 'fail'"
            class="capability-banner"
            type="warning"
            :closable="false"
            :title="resultDetail"
          />
          <ElAlert
            v-else-if="phase === 'syserr'"
            class="capability-banner"
            type="error"
            :closable="false"
            title="系统出了点问题，请稍后再试"
          />

          <!-- 底部操作条 -->
          <div class="capability-actions">
            <template v-if="phase === 'edit' || phase === 'submitting'">
              <span class="capability-count">已选 {{ selectedCount }} 项</span>
              <span class="capability-actions-right">
                <ElButton :disabled="submitting" @click="cancel">取消</ElButton>
                <ElButton
                  type="primary"
                  :loading="submitting"
                  :disabled="!canSubmit"
                  @click="startSubmit"
                >
                  允许使用
                </ElButton>
              </span>
            </template>
            <template v-else-if="phase === 'ok'">
              <span class="capability-actions-right">
                <ElButton @click="backToEdit">再改改</ElButton>
                <ElButton type="primary" @click="finish">完成</ElButton>
              </span>
            </template>
            <template v-else-if="phase === 'partial'">
              <span class="capability-actions-right">
                <ElButton @click="backToEdit">返回修改</ElButton>
                <ElButton type="primary" :disabled="submitting" @click="retryPartial">
                  重试没开通的
                </ElButton>
              </span>
            </template>
            <template v-else-if="phase === 'fail'">
              <span class="capability-actions-right">
                <ElButton @click="backToEdit">返回修改</ElButton>
                <ElButton type="primary" :disabled="submitting" @click="startSubmit">
                  重试
                </ElButton>
              </span>
            </template>
            <template v-else-if="phase === 'syserr'">
              <span class="capability-actions-right">
                <ElButton :loading="submitting" @click="copyResultProblem">复制问题信息</ElButton>
                <ElButton @click="retryUncertain">重试</ElButton>
              </span>
            </template>
          </div>
        </template>
      </div>
    </div>
  </SandIamErrorBoundary>
</template>

<style scoped>
  .capability-wrap {
    max-width: 760px;
    margin: 0 auto;
    width: 100%;
  }

  .capability-head {
    margin-bottom: 16px;
  }

  .capability-title {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--el-text-color-primary);
    word-break: break-word;
  }

  .capability-subtitle {
    margin: 8px 0 0;
    font-size: 14px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  .capability-banner {
    margin-bottom: 12px;
  }

  .capability-group {
    margin-bottom: 8px;
  }

  .capability-group-title {
    margin: 12px 0 8px;
    font-size: 15px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .capability-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .capability-item {
    padding: 8px 4px;
    border-radius: 6px;
  }

  .capability-item:hover {
    background: var(--el-fill-color-light);
  }

  .capability-check {
    height: auto;
    align-items: flex-start;
  }

  .capability-item-name {
    font-size: 14px;
    color: var(--el-text-color-primary);
    margin-right: 8px;
  }

  .capability-granted-tag {
    vertical-align: 1px;
  }

  .capability-item-desc {
    margin: 4px 0 0;
    padding-left: 24px;
    font-size: 14px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
    word-break: break-word;
  }

  .capability-empty {
    padding: 24px 0;
  }

  .capability-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 16px;
    flex-wrap: wrap;
  }

  .capability-count {
    font-size: 14px;
    color: var(--el-text-color-secondary);
  }

  .capability-actions-right {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
  }

  .capability-loading {
    min-height: 200px;
  }

  .capability-muted {
    font-size: 14px;
    color: var(--el-text-color-secondary);
    margin-right: 8px;
  }

  .capability-app-select {
    width: 100%;
    max-width: 420px;
  }
</style>
