import assert from "node:assert/strict"
import test from "node:test"

import {
  compactDrafts,
  defaultDecimalsFor,
  defaultUnitFor,
  suggestedValueType,
  valueMatchesType,
  valueTypeOptions,
  valueTypeRequiresUnit,
} from "../lib/esrs-datapoints-state.mjs"

test("the inventory data type suggests the answer type, mirroring the platform", () => {
  for (const [dataType, expected] of [
    [null, "text"], ["narrative", "text"], ["semi-narrative", "text"], ["Table/Narrative", "text"], ["MDR-P", "text"],
    ["monetary", "monetary"], ["Monerary", "monetary"], ["Integer", "integer"], ["Integer/percentage", "integer"],
    ["percent", "number"], ["Table/decimal", "number"], ["ghgEmissions", "number"], ["Mass", "number"], ["gYear", "date"],
  ]) {
    assert.equal(suggestedValueType(dataType), expected, String(dataType))
  }
})

test("numeric types carry a unit and decimals with sensible defaults", () => {
  assert.equal(valueTypeRequiresUnit("integer"), true)
  assert.equal(valueTypeRequiresUnit("text"), false)
  assert.equal(defaultUnitFor("monetary"), "EUR")
  assert.equal(defaultUnitFor("number", "Percent"), "%")
  assert.equal(defaultUnitFor("number", "Mass"), "pure")
  assert.equal(defaultUnitFor("text"), "")
  assert.equal(defaultDecimalsFor("integer"), 0)
  assert.equal(defaultDecimalsFor("monetary"), 2)
  assert.equal(defaultDecimalsFor("text"), null)
})

test("values are checked against their type before the platform rejects them", () => {
  assert.equal(valueMatchesType("integer", "138"), true)
  assert.equal(valueMatchesType("integer", "138.5"), false)
  assert.equal(valueMatchesType("monetary", "18500000.50"), true)
  assert.equal(valueMatchesType("number", "muchos"), false)
  assert.equal(valueMatchesType("boolean", "Sí"), true)
  assert.equal(valueMatchesType("date", "2025-12-31"), true)
  assert.equal(valueMatchesType("date", "31/12/2025"), false)
  assert.equal(valueMatchesType("text", "cualquier cosa"), true)
  assert.equal(valueMatchesType("integer", ""), true, "an empty value is a status question, not a type error")
})

test("compacted drafts carry the typed fields only when set and keep the exact lexeme", () => {
  const out = compactDrafts({
    "SBM-1_06": { status: "completed", value: " 9007199254740993.10 ", evidence_reference: "Cuentas", note: "", value_type: "monetary", unit: " EUR ", decimals: "2" },
    "GOV-1_01": { status: "completed", value: "2", evidence_reference: "", note: "", value_type: "integer", unit: "", decimals: 0 },
    "BP-1_01": { status: "completed", value: "Texto", evidence_reference: "", note: "", value_type: "text", unit: "EUR", decimals: 3 },
    "BP-1_02": { status: "completed", value: "Sin tipo", evidence_reference: "", note: "" },
  })
  assert.deepEqual(out.find((row) => row.datapoint_id === "SBM-1_06"), {
    datapoint_id: "SBM-1_06", evidence_reference: "Cuentas", note: undefined, status: "completed", value: "9007199254740993.10", value_type: "monetary", unit: "EUR", decimals: 2,
  })
  assert.deepEqual(out.find((row) => row.datapoint_id === "GOV-1_01"), {
    datapoint_id: "GOV-1_01", evidence_reference: undefined, note: undefined, status: "completed", value: "2", value_type: "integer", decimals: 0,
  })
  // Unit and decimals never travel with a text answer.
  assert.deepEqual(out.find((row) => row.datapoint_id === "BP-1_01"), {
    datapoint_id: "BP-1_01", evidence_reference: undefined, note: undefined, status: "completed", value: "Texto", value_type: "text",
  })
  assert.equal("value_type" in out.find((row) => row.datapoint_id === "BP-1_02"), false)
})

test("type options are translated as system copy", () => {
  assert.deepEqual(valueTypeOptions("es").map((option) => option.label), ["Texto", "Número", "Número entero", "Importe", "Sí o no", "Fecha"])
  assert.deepEqual(valueTypeOptions("en").map((option) => option.label), ["Text", "Number", "Integer", "Amount", "Yes or no", "Date"])
})
