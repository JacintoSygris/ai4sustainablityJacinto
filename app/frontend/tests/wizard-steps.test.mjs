import assert from "node:assert/strict"
import test from "node:test"

import { WIZARD_STEPS, wizardStepsForView } from "../lib/wizard-steps-data.mjs"

test("wizard step titles match the canonical six-step journey", () => {
  assert.deepEqual(
    WIZARD_STEPS.map((step) => step.title),
    [
      "Describe la organización",
      "Revisa asuntos propuestos",
      "Doble importancia relativa",
      "Confirma asuntos materiales",
      "Reúne datos normativos NEIS",
      "Resultados",
    ],
  )
})

test("wizardStepsForView marks previous, current, and upcoming steps", () => {
  assert.deepEqual(
    wizardStepsForView(3).map((step) => step.status),
    ["completed", "completed", "current", "upcoming", "upcoming", "upcoming"],
  )
})

test("wizardStepsForView opens every step already reached, keeps the viewed one current", () => {
  assert.deepEqual(
    wizardStepsForView(2, 6).map((step) => step.status),
    ["completed", "current", "completed", "completed", "completed", "available"],
  )
  assert.deepEqual(
    wizardStepsForView(2, 3).map((step) => step.status),
    ["completed", "current", "available", "upcoming", "upcoming", "upcoming"],
  )
  assert.deepEqual(wizardStepsForView(4, 2).map((step) => step.status), wizardStepsForView(4).map((step) => step.status))
})

test("wizard step copy does not expose internal codes or retired brand names", () => {
  for (const step of WIZARD_STEPS) {
    assert.doesNotMatch(step.title, /P\d/)
    assert.doesNotMatch(step.description, /P\d/)
    assert.doesNotMatch(step.title, /Airis/)
    assert.doesNotMatch(step.description, /Airis/)
  }
})
