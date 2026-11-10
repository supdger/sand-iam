/**
 * B3「开通功能」聚合授权接口。
 *
 * 后端契约（WP-A1）：
 * - GET  /app/sand-iam/admin/application/{id}/capabilities
 *   → { application:{id,name}, revision, can_modify, items:[{id,name,description,group_name,granted}] }
 * - POST 同路径，请求体 { capability_ids:[string], expected_revision:string }，
 *   必须携带 Idempotency-Key 请求头；每次用户主动提交生成新键，
 *   网络结果不确定的重试必须复用同一键与同一请求体。
 *   → { application_id, revision, status:'success'|'partial', message,
 *       items:[{id,name,operation,ok,message}], granted_count }
 *   status='failed' 时后端返回 HTTP 422，data 内仍是同一结构。
 */

import request from '@/utils/http'
import { isHttpError } from '@/utils/http/error'
import { createSandIamRequestId } from './requestId'
import { SAND_IAM_ADMIN_PREFIX } from './resource'

export interface SandIamCapabilityItem {
  id: string
  name: string
  description: string
  group_name: string
  granted: boolean
}

export interface SandIamCapabilityCatalog {
  application: { id: number; name: string }
  revision: string
  can_modify: boolean
  items: SandIamCapabilityItem[]
}

export interface SandIamCapabilityResultItem {
  id: string
  name: string
  operation: string
  ok: boolean
  message: string
}

export interface SandIamCapabilitySaveResult {
  application_id: number
  revision: string
  status: 'success' | 'partial' | 'failed'
  message: string
  items: SandIamCapabilityResultItem[]
  granted_count: number
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readString(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function parseCatalogItem(value: unknown): SandIamCapabilityItem | null {
  if (!isRecord(value)) return null
  const id = readString(value.id)
  const name = readString(value.name)
  if (id === '' || name === '') return null
  return {
    id,
    name,
    description: readString(value.description),
    group_name: readString(value.group_name),
    granted: value.granted === true
  }
}

/** 目录响应守卫：缺字段或形状不符时返回 null，由页面进入系统故障态而不是猜测渲染。 */
export function parseSandIamCapabilityCatalog(value: unknown): SandIamCapabilityCatalog | null {
  if (!isRecord(value)) return null
  const application = isRecord(value.application) ? value.application : null
  const appId = typeof application?.id === 'number' ? application.id : NaN
  const appName = readString(application?.name)
  const revision = readString(value.revision)
  if (!Number.isInteger(appId) || appId <= 0 || appName === '' || revision === '') return null
  if (!Array.isArray(value.items)) return null
  const items: SandIamCapabilityItem[] = []
  for (const raw of value.items) {
    const item = parseCatalogItem(raw)
    if (item !== null) items.push(item)
  }
  return {
    application: { id: appId, name: appName },
    revision,
    can_modify: value.can_modify === true,
    items
  }
}

function parseResultItem(value: unknown): SandIamCapabilityResultItem | null {
  if (!isRecord(value)) return null
  const id = readString(value.id)
  const name = readString(value.name)
  if (id === '' || name === '') return null
  return {
    id,
    name,
    operation: readString(value.operation),
    ok: value.ok === true,
    message: readString(value.message)
  }
}

/** 保存响应守卫；status='failed' 的负载同样走这里（HTTP 422 的 data 字段）。 */
export function parseSandIamCapabilitySaveResult(value: unknown): SandIamCapabilitySaveResult | null {
  if (!isRecord(value)) return null
  const status = readString(value.status)
  if (status !== 'success' && status !== 'partial' && status !== 'failed') return null
  const appId = typeof value.application_id === 'number' ? value.application_id : NaN
  if (!Number.isInteger(appId) || appId <= 0) return null
  const items: SandIamCapabilityResultItem[] = []
  if (Array.isArray(value.items)) {
    for (const raw of value.items) {
      const item = parseResultItem(raw)
      if (item !== null) items.push(item)
    }
  }
  return {
    application_id: appId,
    revision: readString(value.revision),
    status,
    message: readString(value.message),
    items,
    granted_count: typeof value.granted_count === 'number' ? value.granted_count : 0
  }
}

function capabilityUrl(applicationId: number): string {
  return `${SAND_IAM_ADMIN_PREFIX}/application/${applicationId}/capabilities`
}

/** 把本次请求的 X-Request-Id 挂到错误对象上，供「复制问题信息」脱敏引用。 */
function attachRequestId(error: unknown, requestId: string): void {
  if (error instanceof Error) {
    ;(error as Error & { requestId?: string }).requestId = requestId
  }
}

/** 读取功能目录。showErrorMessage 关闭：页面按状态机自行呈现错误。 */
export async function fetchSandIamCapabilities(applicationId: number): Promise<unknown> {
  const requestId = createSandIamRequestId()
  try {
    return await request.get<unknown>({
      url: capabilityUrl(applicationId),
      headers: { 'X-Request-Id': requestId },
      showErrorMessage: false
    })
  } catch (error: unknown) {
    attachRequestId(error, requestId)
    throw error
  }
}

/**
 * 提交完整期望集合。idempotencyKey 由调用方按幂等规则生成/复用；
 * X-Request-Id 每次请求独立生成，不参与幂等。
 */
export async function saveSandIamCapabilities(
  applicationId: number,
  capabilityIds: readonly string[],
  expectedRevision: string,
  idempotencyKey: string
): Promise<unknown> {
  const requestId = createSandIamRequestId()
  try {
    return await request.post<unknown>({
      url: capabilityUrl(applicationId),
      data: {
        capability_ids: [...capabilityIds],
        expected_revision: expectedRevision
      },
      headers: {
        'X-Request-Id': requestId,
        'Idempotency-Key': idempotencyKey
      },
      showErrorMessage: false
    })
  } catch (error: unknown) {
    attachRequestId(error, requestId)
    throw error
  }
}

/**
 * 传输结果是否「不确定」：服务器可能已处理但响应丢失。
 * 此类失败重试必须复用同一 Idempotency-Key 与同一请求体；
 * 拿到明确 HTTP 业务响应（4xx/5xx 之外的拒绝、422 失败负载）则视为已定论。
 */
export function isUncertainCapabilityOutcome(error: unknown): boolean {
  if (!isHttpError(error)) return true
  const transport = error.transport
  if (transport === 'network' || transport === 'timeout' || transport === 'unknown') return true
  if (transport === 'cancelled') return false
  // transport === 'http'：拿到 HTTP 响应。5xx/408 可能发生在提交后，仍按不确定处理；
  // 其余状态码视为服务端明确答复。
  return typeof error.code === 'number' && error.code >= 500
}

/**
 * 从错误中提取后端白话原因：剥掉 SAND_IAM_* 机器码前缀，绝不把码上屏。
 * 优先读 HTTP 错误体的 message/msg 字段；没有可用文本时返回 null。
 */
export function plainSandIamCapabilityMessage(error: unknown): string | null {
  const candidates: string[] = []
  if (isHttpError(error)) {
    if (isRecord(error.data)) {
      const body = error.data
      if (typeof body.message === 'string') candidates.push(body.message)
      if (typeof body.msg === 'string') candidates.push(body.msg)
      if (isRecord(body.data) && typeof body.data.message === 'string') {
        candidates.push(body.data.message)
      }
    }
    // 只有信封级错误（HTTP 2xx 但业务码非 200）的 error.message 才可能是后端原文；
    // transport=http/network 的 error.message 是宿主 i18n 或 axios 兜底文案。
    if (error.transport === undefined && error.message !== '') {
      candidates.push(error.message)
    }
  } else if (error instanceof Error && error.message !== '') {
    candidates.push(error.message)
  }
  for (const raw of candidates) {
    const text = raw.replace(/^SAND_[A-Z0-9_]+:\s*/u, '').trim()
    // 机器码本身、纯英文兜底文案、空串都不算白话原因。
    if (text !== '' && !/^SAND_[A-Z0-9_]+$/u.test(text) && !/^[\x20-\x7E]+$/.test(text)) {
      return text
    }
    if (text !== '' && /[一-鿿]/u.test(text)) {
      return text
    }
  }
  return null
}

/** HTTP 422 failed 响应里的完整保存负载（若存在）。 */
export function capabilityFailurePayload(error: unknown): SandIamCapabilitySaveResult | null {
  if (!isHttpError(error) || !isRecord(error.data)) return null
  const body = error.data
  if (isRecord(body.data)) {
    const parsed = parseSandIamCapabilitySaveResult(body.data)
    if (parsed !== null) return parsed
  }
  return parseSandIamCapabilitySaveResult(body)
}
