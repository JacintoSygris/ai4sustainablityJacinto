import { localized, topicTitle } from "./materiality-confirmation-state.mjs"

// Category keys follow the ESRS code prefix: E (environment), S (social), G (governance).
export const TOPIC_CATEGORY_KEYS = ["E", "S", "G", "O"]

export const TOPIC_CATEGORY_LABELS = {
  E: "Medioambiente",
  S: "Social",
  G: "Gobernanza",
  O: "Otros temas",
}

export function topicCategoryKey(topic) {
  const first = String(topic?.esrs_code ?? "").trim().charAt(0).toUpperCase()

  return first === "E" || first === "S" || first === "G" ? first : "O"
}

function compareTopics(left, right) {
  const codeCompare = String(left.esrs_code ?? "").localeCompare(String(right.esrs_code ?? ""))

  return codeCompare === 0 ? Number(left.id) - Number(right.id) : codeCompare
}

/**
 * Unique topics by id, first occurrence wins (so the richer catalogue entry
 * should be passed first), sorted by ESRS code and id.
 */
export function mergeTopicCatalog(...lists) {
  const byId = new Map()

  for (const list of lists) {
    for (const topic of Array.isArray(list) ? list : []) {
      const id = Number(topic?.id)

      if (Number.isInteger(id) && id > 0 && !byId.has(id)) {
        byId.set(id, topic)
      }
    }
  }

  return Array.from(byId.values()).sort(compareTopics)
}

/**
 * Category → standard (ESRS code) → group (sub-theme, or a flat group when the
 * topic has no sub-topic) → topics. Every node carries a stable `key` for
 * expand/collapse state and a flat `topics` list for counting.
 */
export function buildTopicTree(topics, locale = "es") {
  const categories = new Map()

  for (const topic of mergeTopicCatalog(topics)) {
    const categoryKey = topicCategoryKey(topic)

    if (!categories.has(categoryKey)) {
      categories.set(categoryKey, { key: `cat:${categoryKey}`, categoryKey, label: TOPIC_CATEGORY_LABELS[categoryKey], standards: new Map(), topics: [] })
    }

    const category = categories.get(categoryKey)
    const code = String(topic.esrs_code ?? "").trim() || "—"

    if (!category.standards.has(code)) {
      category.standards.set(code, { key: `std:${code}`, code, label: localized(topic.theme, locale), groups: new Map(), topics: [] })
    }

    const standard = category.standards.get(code)
    const subtopic = localized(topic.subtopic, locale)
    const subtheme = localized(topic.subtheme, locale)
    const groupLabel = subtopic ? subtheme : null
    const groupKey = groupLabel ? `grp:${code}:${groupLabel}` : `grp:${code}:_`

    if (!standard.groups.has(groupKey)) {
      standard.groups.set(groupKey, { key: groupKey, label: groupLabel, topics: [] })
    }

    standard.groups.get(groupKey).topics.push(topic)
    standard.topics.push(topic)
    category.topics.push(topic)
  }

  return TOPIC_CATEGORY_KEYS
    .filter((categoryKey) => categories.has(categoryKey))
    .map((categoryKey) => {
      const category = categories.get(categoryKey)

      return {
        ...category,
        standards: Array.from(category.standards.values()).map((standard) => ({
          ...standard,
          groups: Array.from(standard.groups.values()),
        })),
      }
    })
}

/** Prunes the tree to the topics accepted by `predicate`; empty nodes disappear. */
export function filterTopicTree(tree, predicate) {
  return tree
    .map((category) => {
      const standards = category.standards
        .map((standard) => {
          const groups = standard.groups
            .map((group) => ({ ...group, topics: group.topics.filter(predicate) }))
            .filter((group) => group.topics.length > 0)

          return { ...standard, groups, topics: groups.flatMap((group) => group.topics) }
        })
        .filter((standard) => standard.topics.length > 0)

      return { ...category, standards, topics: standards.flatMap((standard) => standard.topics) }
    })
    .filter((category) => category.topics.length > 0)
}

export function countTopics(topics, isSelected) {
  return topics.reduce((count, topic) => (isSelected(topic) ? count + 1 : count), 0)
}

/** Keys of every collapsible node in the tree (categories, standards and sub-theme groups). */
export function allTreeKeys(tree) {
  const keys = []

  for (const category of tree) {
    keys.push(category.key)

    for (const standard of category.standards) {
      keys.push(standard.key)

      for (const group of standard.groups) {
        if (group.label) keys.push(group.key)
      }
    }
  }

  return keys
}

/** Keys to open by default: nodes holding at least one topic matching `predicate`, plus every labelled group inside them. */
export function defaultExpandedKeys(tree, predicate) {
  const keys = []

  for (const category of tree) {
    if (!category.topics.some(predicate)) continue
    keys.push(category.key)

    for (const standard of category.standards) {
      if (!standard.topics.some(predicate)) continue
      keys.push(standard.key)

      for (const group of standard.groups) {
        if (group.label) keys.push(group.key)
      }
    }
  }

  return keys
}

export function topicExamples(topic, locale = "es") {
  const examples = topic?.examples?.[locale === "en" ? "en" : "es"]

  return Array.isArray(examples) ? examples.filter((example) => typeof example === "string" && example.trim()) : []
}

export { topicTitle }
