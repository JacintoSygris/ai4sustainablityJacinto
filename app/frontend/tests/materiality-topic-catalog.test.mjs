import assert from "node:assert/strict"
import test from "node:test"

import {
  TOPIC_CATEGORY_LABELS,
  allTreeKeys,
  buildTopicTree,
  countTopics,
  defaultExpandedKeys,
  filterTopicTree,
  mergeTopicCatalog,
  topicCategoryKey,
  topicExamples,
} from "../lib/materiality-topic-catalog.mjs"

const t = (id, esrs, theme, subtheme, subtopic = null, examples = []) => ({
  id,
  esrs_code: esrs,
  theme: { es: theme, en: `${theme} (en)` },
  subtheme: { es: subtheme, en: `${subtheme} (en)` },
  subtopic: { es: subtopic, en: subtopic ? `${subtopic} (en)` : null },
  examples: { es: examples, en: examples },
})

const catalogue = [
  t(13, "E3", "Agua y recursos marinos", "Agua", "Vertidos de agua"),
  t(11, "E3", "Agua y recursos marinos", "Agua", "Consumo de agua", ["Reducir el consumo"]),
  t(1, "E1", "Cambio climático", "Adaptación al cambio climático"),
  t(2, "E1", "Cambio climático", "Mitigación del cambio climático"),
  t(83, "G1", "Conducta empresarial", "Cultura corporativa"),
  t(28, "S1", "Personal propio", "Condiciones de trabajo", "Empleo seguro"),
  t(90, "X9", "Desconocido", "Otro"),
]

test("topics are grouped into environment, social and governance by their ESRS code", () => {
  assert.equal(topicCategoryKey({ esrs_code: "E1" }), "E")
  assert.equal(topicCategoryKey({ esrs_code: "s2" }), "S")
  assert.equal(topicCategoryKey({ esrs_code: "G1" }), "G")
  assert.equal(topicCategoryKey({ esrs_code: "X9" }), "O")
  assert.equal(topicCategoryKey({}), "O")
  assert.equal(TOPIC_CATEGORY_LABELS.E, "Medioambiente")
})

test("the catalogue merges richer entries first and sorts by code and id", () => {
  const merged = mergeTopicCatalog(
    [t(11, "E3", "Agua y recursos marinos", "Agua", "Consumo de agua", ["Ejemplo"])],
    [{ id: 11, esrs_code: "E3", theme: {}, subtheme: {}, subtopic: {} }, t(1, "E1", "Cambio climático", "Energía"), { id: "bad" }],
  )

  assert.deepEqual(merged.map((topic) => topic.id), [1, 11])
  assert.deepEqual(topicExamples(merged[1], "es"), ["Ejemplo"], "the catalogue entry wins over the bare proposal summary")
})

test("the tree nests category → standard → sub-theme group → topics", () => {
  const tree = buildTopicTree(catalogue, "es")

  assert.deepEqual(tree.map((category) => category.label), ["Medioambiente", "Social", "Gobernanza", "Otros temas"])

  const environment = tree[0]
  assert.deepEqual(environment.standards.map((standard) => [standard.code, standard.label]), [
    ["E1", "Cambio climático"],
    ["E3", "Agua y recursos marinos"],
  ])

  const e1 = environment.standards[0]
  assert.deepEqual(e1.groups.map((group) => group.label), [null], "topics without sub-topic sit directly under the standard")
  assert.deepEqual(e1.groups[0].topics.map((topic) => topic.id), [1, 2])

  const e3 = environment.standards[1]
  assert.deepEqual(e3.groups.map((group) => group.label), ["Agua"], "sub-topics are grouped under their sub-theme")
  assert.deepEqual(e3.groups[0].topics.map((topic) => topic.id), [11, 13])
  assert.deepEqual(environment.topics.map((topic) => topic.id), [1, 2, 11, 13])

  assert.deepEqual(allTreeKeys(tree).slice(0, 4), ["cat:E", "std:E1", "std:E3", "grp:E3:Agua"])
})

test("filtering prunes empty branches and counting follows the selection", () => {
  const tree = buildTopicTree(catalogue, "es")
  const selected = new Set([11, 83])
  const isSelected = (topic) => selected.has(topic.id)

  assert.equal(countTopics(tree[0].topics, isSelected), 1)
  assert.equal(countTopics(tree[2].topics, isSelected), 1)

  const filtered = filterTopicTree(tree, isSelected)
  assert.deepEqual(filtered.map((category) => category.label), ["Medioambiente", "Gobernanza"])
  assert.deepEqual(filtered[0].standards.map((standard) => standard.code), ["E3"])
  assert.deepEqual(filtered[0].topics.map((topic) => topic.id), [11])

  assert.deepEqual(filterTopicTree(tree, () => false), [])
})

test("branches holding proposed topics open by default, including their sub-theme groups", () => {
  const tree = buildTopicTree(catalogue, "es")
  const proposed = new Set([13])

  assert.deepEqual(defaultExpandedKeys(tree, (topic) => proposed.has(topic.id)), ["cat:E", "std:E3", "grp:E3:Agua"])
  assert.deepEqual(defaultExpandedKeys(tree, () => false), [])
})
