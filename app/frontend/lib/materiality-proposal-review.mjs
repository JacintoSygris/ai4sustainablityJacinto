export const REVIEW_ACTIONS = ["accepted", "rejected", "unsure"]

export const REVIEW_REASON_KEYS = [
  "sector_fit",
  "not_relevant",
  "threshold",
  "stakeholder_input",
  "needs_adm",
  "other",
]

const reviewActionSet = new Set(REVIEW_ACTIONS)
const reasonKeySet = new Set(REVIEW_REASON_KEYS)

function canonicalTopicKey(value) {
  const key = String(value)

  return /^[1-9][0-9]*$/.test(key) ? key : null
}

function proposalTopicKeys(proposalTopicIds) {
  return proposalTopicIds.map(canonicalTopicKey).filter(Boolean)
}

function currentTopicKeySet(proposalTopicIds) {
  return new Set(proposalTopicKeys(proposalTopicIds))
}

function strictTopicIds(topicIds) {
  const unique = new Set()

  for (const value of Array.isArray(topicIds) ? topicIds : []) {
    const id = Number(value)

    if (Number.isInteger(id) && id > 0) unique.add(id)
  }

  return Array.from(unique).sort((left, right) => left - right)
}

/** Topics the user added on top of the proposal (always a subset of proposal_topic_ids). */
export function addedTopicIdsFromProposal(proposal) {
  const currentKeys = currentTopicKeySet(proposal.proposal_topic_ids ?? [])

  return strictTopicIds(proposal.review?.added_topic_ids ?? []).filter((id) => currentKeys.has(String(id)))
}

/** The proposal without the user's additions: AI candidates or stored ids. */
export function baseProposalTopicIds(proposal) {
  const added = new Set(addedTopicIdsFromProposal(proposal))

  return strictTopicIds(proposal.proposal_topic_ids ?? []).filter((id) => !added.has(id))
}

/** Topics the AI recommended, restricted to the current proposal. Absent field → none flagged. */
export function aiTopicIdsFromProposal(proposal) {
  const currentKeys = currentTopicKeySet(proposal.proposal_topic_ids ?? [])

  return strictTopicIds(proposal.ai_topic_ids ?? []).filter((id) => currentKeys.has(String(id)))
}

export function actionsFromProposal(proposal) {
  const currentKeys = currentTopicKeySet(proposal.proposal_topic_ids ?? [])
  const actions = proposal.review?.topic_actions ?? {}

  return Object.fromEntries(
    Object.entries(actions)
      .map(([topicId, action]) => [canonicalTopicKey(topicId), action])
      .filter(([topicId, action]) => topicId && currentKeys.has(topicId) && reviewActionSet.has(action)),
  )
}

/**
 * Actions shown when the review screen opens: the stored explicit actions plus the
 * AI-recommended topics marked as accepted while the user has not decided on them.
 * Stored ids without the AI flag stay undecided, so the explicit-review guard still applies to them.
 */
export function initialTopicActions(proposal) {
  const actions = actionsFromProposal(proposal)

  for (const topicId of aiTopicIdsFromProposal(proposal)) {
    const key = canonicalTopicKey(topicId)
    if (key && !actions[key]) actions[key] = "accepted"
  }

  return actions
}

export function reasonsFromProposal(proposal) {
  return cleanReasons(proposal.review?.action_reasons ?? {}, currentTopicKeySet(proposal.proposal_topic_ids ?? []))
}

export function notesFromProposal(proposal) {
  const currentKeys = currentTopicKeySet(proposal.proposal_topic_ids ?? [])

  return Object.fromEntries(
    Object.entries(proposal.review?.action_notes ?? {})
      .map(([topicId, note]) => [canonicalTopicKey(topicId), typeof note === "string" ? note : ""])
      .filter(([topicId]) => topicId && currentKeys.has(topicId)),
  )
}

export function cleanNotes(notes, validTopicKeys = null) {
  return Object.fromEntries(
    Object.entries(notes ?? {})
      .map(([topicId, note]) => [canonicalTopicKey(topicId), typeof note === "string" ? note.trim() : ""])
      .filter(([topicId, note]) => topicId && note && (!validTopicKeys || validTopicKeys.has(topicId))),
  )
}

export function cleanReasons(reasons, validTopicKeys = null) {
  return Object.fromEntries(
    Object.entries(reasons ?? {})
      .map(([topicId, selectedReasons]) => {
        const canonicalKey = canonicalTopicKey(topicId)
        const cleanSelectedReasons = Array.isArray(selectedReasons)
          ? selectedReasons.filter((reason) => reasonKeySet.has(reason))
          : []

        return [canonicalKey, Array.from(new Set(cleanSelectedReasons))]
      })
      .filter(
        ([topicId, selectedReasons]) =>
          topicId && selectedReasons.length > 0 && (!validTopicKeys || validTopicKeys.has(topicId)),
      ),
  )
}

export function missingReviewTopicIds(proposalTopicIds, topicActions) {
  const actions = Object.fromEntries(
    Object.entries(topicActions ?? {})
      .map(([topicId, action]) => [canonicalTopicKey(topicId), action])
      .filter(([topicId, action]) => topicId && reviewActionSet.has(action)),
  )

  return proposalTopicKeys(proposalTopicIds).filter((topicId) => !actions[topicId])
}

export function saveCompletionMatchesEditVersion(capturedVersion, currentVersion) {
  return Number.isInteger(capturedVersion) && capturedVersion === currentVersion
}

/**
 * `proposalTopicIds` is the base proposal; `addedTopicIds` are the user's
 * additions. Added ids already in the base are dropped, and the payload always
 * carries `added_topic_ids` because the server treats it as the full list.
 */
/**
 * @param {{ proposalTopicIds: number[], addedTopicIds?: number[], topicActions: Record<string, string>, actionReasons?: Record<string, string[]>, actionNotes?: Record<string, string> }} input
 */
export function buildDraftReviewPayload({ proposalTopicIds, addedTopicIds = [], topicActions, actionReasons = {}, actionNotes = {} }) {
  const baseTopicKeys = currentTopicKeySet(proposalTopicIds ?? [])
  const cleanAddedTopicIds = strictTopicIds(addedTopicIds).filter((id) => !baseTopicKeys.has(String(id)))
  const validTopicKeys = new Set([...baseTopicKeys, ...cleanAddedTopicIds.map(String)])
  const cleanActions = Object.fromEntries(
    Object.entries(topicActions ?? {})
      .map(([topicId, action]) => [canonicalTopicKey(topicId), action])
      .filter(([topicId, action]) => topicId && validTopicKeys.has(topicId) && reviewActionSet.has(action)),
  )
  const answeredTopicKeys = new Set(Object.keys(cleanActions))

  return {
    topic_actions: cleanActions,
    action_reasons: cleanReasons(actionReasons, answeredTopicKeys),
    action_notes: cleanNotes(actionNotes, answeredTopicKeys),
    added_topic_ids: cleanAddedTopicIds,
  }
}

/**
 * @param {{ proposalTopicIds: number[], addedTopicIds?: number[], topicActions: Record<string, string>, actionReasons?: Record<string, string[]>, actionNotes?: Record<string, string> }} input
 */
export function buildReviewPayload({ proposalTopicIds, addedTopicIds = [], topicActions, actionReasons = {}, actionNotes = {} }) {
  const payload = buildDraftReviewPayload({ proposalTopicIds, addedTopicIds, topicActions, actionReasons, actionNotes })
  const cleanActions = payload.topic_actions
  const missingTopicIds = missingReviewTopicIds([...(proposalTopicIds ?? []), ...payload.added_topic_ids], cleanActions)

  if (missingTopicIds.length > 0) {
    return {
      ok: false,
      missingTopicIds,
    }
  }

  return {
    ok: true,
    payload,
  }
}
