import { expect, test, type Locator, type Page } from "@playwright/test"

/**
 * Recorre el asistente completo con datos de una PYME industrial realista:
 * paso 1 (caracterización) → paso 2 (propuesta de la IA y revisión) → paso 3 (guía y acta)
 * → paso 4 (selección final) → paso 5 (datos normativos) → paso 6 (informe).
 * Requiere un usuario verificado, idealmente sin caracterización previa (ver README.md).
 */

const email = process.env.E2E_EMAIL?.trim()
const password = process.env.E2E_PASSWORD ?? ""

const company = {
  name: "Talleres Mecánicos del Henares, S.L.",
  naceSearch: "C25.6",
  country: "España",
  scope: "Entidad individual",
  subsidiaries: "0",
  stockListed: "No",
  currency: "EUR",
  productType: "Producto físico (fabricación)",
  regions: ["Unión Europea"],
  valueChain: ["Operaciones directas", "Actividades anteriores de la cadena de valor"],
  employees: "50-249",
  revenue: "EUR 10M-40M",
  // LEI sintácticamente válido (ISO 17442, dígitos de control MOD 97-10); no pertenece a ninguna entidad real.
  lei: "959800TLLRSHENARES33",
  periodStart: "2025-01-01",
  periodEnd: "2025-12-31",
}

// Datos numéricos de NEIS 2 con correspondencia a conceptos de la taxonomía: son los que el candidato
// iXBRL etiqueta (solo hechos numéricos sin dimensiones). Valor, tipo de hecho, unidad y texto de respuesta.
const numericAnswers = new Map<string, { value: string; valueType: "integer" | "monetary" | "number"; unit: string; concept: string; text: string; evidence: string }>([
  ["GOV-1_01", { value: "2", valueType: "integer", unit: "pure", concept: "esrs:NumberOfExecutiveMembers", text: "2 miembros ejecutivos en el consejo", evidence: "Acta del consejo, 12/02/2025" }],
  ["GOV-1_02", { value: "3", valueType: "integer", unit: "pure", concept: "esrs:NumberOfNonexecutiveMembers", text: "3 miembros no ejecutivos en el consejo", evidence: "Acta del consejo, 12/02/2025" }],
  ["GOV-1_07", { value: "40", valueType: "number", unit: "%", concept: "esrs:PercentageOfIndependentBoardMembers", text: "40 % de consejeros independientes (2 de 5)", evidence: "Acta del consejo, 12/02/2025" }],
  ["SBM-1_03", { value: "138", valueType: "integer", unit: "pure", concept: "esrs:NumberOfEmployeesHeadcountAtEndOfPeriod", text: "138 personas empleadas (plantilla media 2025)", evidence: "Resumen de nóminas 2025, RRHH" }],
  ["SBM-1_06", { value: "18500000", valueType: "monetary", unit: "EUR", concept: "esrs:Revenue", text: "Ingresos totales 2025: 18.500.000 EUR", evidence: "Cuentas anuales 2025" }],
])

async function acceptCookiesIfShown(page: Page) {
  const accept = page.locator(".airis-consent-notice").getByRole("button", { name: /Aceptar opcionales|Accept optional/ })
  if (await accept.isVisible().catch(() => false)) await accept.click()
}

async function chooseSelect(page: Page, triggerId: string, optionLabel: string) {
  await page.locator(`#${triggerId}`).click()
  await page.getByRole("option", { name: optionLabel, exact: true }).click()
}

async function checkWithin(group: Locator, labels: string[]) {
  for (const label of labels) {
    const control = group.getByLabel(label, { exact: true })
    if (await control.count()) {
      await control.check()
    } else {
      await group.getByText(label, { exact: true }).click()
    }
  }
}

test.describe.configure({ mode: "serial" })

test.beforeEach(() => {
  test.skip(!email || !password, "Define E2E_EMAIL y E2E_PASSWORD (usuario verificado sin caracterización previa).")
})

test("el asistente se completa de principio a fin con datos de una PYME industrial", async ({ page }) => {
  await test.step("iniciar sesión", async () => {
    await page.goto("/login")
    await page.locator("#email").fill(email!)
    await page.locator("#password").fill(password)
    await page.getByRole("button", { name: /Iniciar sesión|Sign in/ }).click()
    await expect(page).toHaveURL(/\/dashboard/)
    await acceptCookiesIfShown(page)
  })

  await test.step("paso 1: caracterización inicial", async () => {
    await page.goto("/wizard/step-1")
    await expect(page.getByRole("heading", { name: "Caracterización inicial" })).toBeVisible()

    // Si el usuario ya tiene la encuesta guardada (segunda ejecución), se continúa sin rellenarla.
    const alreadySaved = page.getByRole("button", { name: "Continuar a la propuesta de temas" })
    await expect(alreadySaved.or(page.locator("#companyName")).first()).toBeVisible({ timeout: 30_000 })
    if (await alreadySaved.isVisible()) {
      // El candidato iXBRL exige LEI y periodo del informe: si la encuesta guardada no los tiene
      // (por ejemplo, de una ejecución anterior), se edita solo para completarlos.
      await page.getByRole("button", { name: "Editar la encuesta inicial" }).click()
      await page.getByRole("dialog").getByRole("button", { name: "Editar la encuesta inicial" }).click()
      const leiField = page.locator("#entityIdentifier")
      await expect(leiField).toBeVisible()
      let changed = false
      if (company.lei && (await leiField.inputValue()) !== company.lei) {
        await leiField.fill(company.lei)
        changed = true
      }
      const periodStart = page.locator("#reportingPeriodStart")
      if (await periodStart.isVisible().catch(() => false)) {
        if ((await periodStart.inputValue()) === "" || (await page.locator("#reportingPeriodEnd").inputValue()) === "") {
          await periodStart.fill(company.periodStart)
          await page.locator("#reportingPeriodEnd").fill(company.periodEnd)
          changed = true
        }
      }
      if (changed) {
        test.info().annotations.push({ type: "paso 1", description: "encuesta ya guardada; se completan LEI y periodo del informe" })
        await page.getByRole("button", { name: "Guardar y continuar" }).click()
        await expect(page).toHaveURL(/\/wizard\/step-2/, { timeout: 30_000 })
        return
      }
      test.info().annotations.push({ type: "paso 1", description: "encuesta ya guardada; se reutiliza" })
      await page.goto("/wizard/step-1")
      await alreadySaved.click()
      await expect(page).toHaveURL(/\/wizard\/step-2/)
      return
    }

    await page.locator("#companyName").fill(company.name)

    // Búsqueda en el catálogo NACE y selección de un resultado real.
    await page.locator("#naceCode").fill(company.naceSearch)
    const naceResult = page.locator("#naceCode ~ div button").first()
    await expect(naceResult).toBeVisible()
    const naceLabel = (await naceResult.textContent())?.trim() ?? ""
    await naceResult.click()
    await expect(page.locator("#naceCode")).toHaveValue(/^C25\.6/)
    test.info().annotations.push({ type: "nace", description: naceLabel })

    await chooseSelect(page, "headquartersCountry", company.country)
    await chooseSelect(page, "reportingScope", company.scope)
    await page.locator("#numSubsidiariesCountries").fill(company.subsidiaries)
    await chooseSelect(page, "stockListed", company.stockListed)
    await chooseSelect(page, "reportingCurrency", company.currency)
    await chooseSelect(page, "productServiceType", company.productType)
    if (company.lei) await page.locator("#entityIdentifier").fill(company.lei)
    // Fechas del periodo: solo aparecen con IXBRL_PACKAGE_UI_ENABLED=true; el candidato iXBRL las exige.
    const periodStart = page.locator("#reportingPeriodStart")
    if (await periodStart.isVisible().catch(() => false)) {
      await periodStart.fill(company.periodStart)
      await page.locator("#reportingPeriodEnd").fill(company.periodEnd)
    }

    await checkWithin(page.locator("#regions"), company.regions)
    await checkWithin(page.locator("#valueChain"), company.valueChain)
    await chooseSelect(page, "employeeCountRange", company.employees)
    await chooseSelect(page, "revenueRange", company.revenue)

    // Preguntas sobre la actividad: un taller mecánico responde «Sí» a instalaciones,
    // sustancias peligrosas, fabricación y proveedores; «No» al resto.
    const yesPatterns = [/instalaciones físicas/i, /sustancias peligrosas/i, /fabrica, envasa/i, /proveedores, subcontratas/i]
    const questions = page.locator("fieldset", { has: page.locator("input[type=radio]") })
    const count = await questions.count()
    for (let index = 0; index < count; index += 1) {
      const question = questions.nth(index)
      const legend = (await question.locator("legend").textContent()) ?? ""
      const answer = yesPatterns.some((pattern) => pattern.test(legend)) ? "Sí" : "No"
      await question.getByLabel(answer, { exact: true }).check()
    }

    await expect(page.getByText("100%")).toBeVisible()
    await page.getByRole("button", { name: "Guardar y continuar" }).click()
    await expect(page).toHaveURL(/\/wizard\/step-2/, { timeout: 30_000 })
  })

  await test.step("paso 2: propuesta de temas con la IA y revisión", async () => {
    const generate = page.getByRole("button", { name: "Generar propuesta con inteligencia artificial" })
    const reviewed = page.getByText("Revisión guardada", { exact: true })
    await expect(generate.or(reviewed).or(page.getByRole("group", { name: /^Revisar / })).first()).toBeVisible({ timeout: 30_000 })
    if (await reviewed.isVisible()) {
      test.info().annotations.push({ type: "paso 2", description: "revisión ya guardada; se reutiliza" })
      await page.goto("/wizard/step-3")
      return
    }
    if (await generate.isVisible()) await generate.click()

    // La propuesta llega por la cola y el servicio de IA; la página sondea el estado sola.
    const topicGroups = page.getByRole("group", { name: /^Revisar / })
    await expect(topicGroups.first()).toBeVisible({ timeout: 150_000 })
    const topics = await topicGroups.count()
    expect(topics).toBeGreaterThan(0)
    test.info().annotations.push({ type: "temas propuestos", description: String(topics) })

    // Decisión explícita en cada tema: se aceptan todos salvo el último, que queda en duda.
    for (let index = 0; index < topics; index += 1) {
      const group = topicGroups.nth(index)
      await group.getByRole("button", { name: index === topics - 1 ? "Duda" : "Aceptar", exact: true }).click()
    }

    await page.getByRole("button", { name: "Guardar y continuar" }).click()
    await expect(page).toHaveURL(/\/wizard\/step-3/, { timeout: 30_000 })
  })

  await test.step("paso 3: guía de doble importancia relativa y acta", async () => {
    const continueButton = page.getByRole("button", { name: "Volver a la selección final de temas", exact: true })
    await expect(continueButton).toBeEnabled({ timeout: 30_000 })

    await page.locator('input[type="date"]').first().fill("2026-11-10")
    await page.getByPlaceholder("Taller interno con dirección, entrevistas...").fill("Taller interno con dirección y responsables de planta")
    await page.getByPlaceholder("Gerencia, RRHH, producción").fill("Gerencia, RRHH, producción, prevención de riesgos")
    await page.getByRole("button", { name: "Registrar acta" }).click()
    await expect(page.getByRole("button", { name: "Registrar acta" })).toBeEnabled({ timeout: 15_000 })

    await continueButton.click()
    await expect(page).toHaveURL(/\/wizard\/step-4/)
  })

  await test.step("paso 4: selección final de temas", async () => {
    const confirm = page.getByRole("button", { name: "Confirmar y continuar" })
    await expect(confirm).toBeEnabled({ timeout: 30_000 })
    await confirm.click()
    await expect(page).toHaveURL(/\/wizard\/step-5/, { timeout: 30_000 })
  })

  // Datos decididos en el paso 5; el paso 6 los comprueba como contenido del informe.
  const completedDatapoints = new Map<string, [string, string]>()
  let notApplicableCount = 0
  // Tamaño del inventario tras el paso 5: el informe cuenta también lo decidido en ejecuciones anteriores.
  let inventorySize = 0

  await test.step("paso 5: datos normativos NEIS", async () => {
    test.setTimeout(900_000)
    const closeIntro = page.getByRole("button", { name: "Cerrar", exact: true })
    if (await closeIntro.isVisible().catch(() => false)) await closeIntro.click()
    await expect(page.getByText("Total", { exact: true })).toBeVisible({ timeout: 30_000 })

    // Inventario completo (obligatorios y voluntarios), no solo los obligatorios.
    await page.getByRole("button", { name: "Todos", exact: true }).click()

    // Inventario rápido por estándar: lo de NEIS 2 (gobernanza y estrategia) ya se tiene;
    // lo temático hay que buscarlo. El selector de cada estándar marca todo su grupo. Los
    // estándares se llaman «NEIS 2 (190)» o «E1 (214)»; los requisitos llevan « · » y se excluyen.
    const standardSelects = page.getByRole("combobox", { name: /^Marcar todos los datos de [^·]+ \(\d+\)/ })
    const standards = await standardSelects.count()
    expect(standards).toBeGreaterThan(0)
    for (let index = 0; index < standards; index += 1) {
      const select = standardSelects.nth(index)
      const label = (await select.getAttribute("aria-label")) ?? ""
      await select.selectOption(/(ESRS|NEIS) 2 \(/.test(label) ? "have_it" : "need_to_find")
    }
    test.info().annotations.push({ type: "estándares", description: String(standards) })

    // Desplegar NEIS 2 y pasar a modo «Responder». Los estándares temáticos (varios cientos de
    // datos con el mapa AR16 activo) quedan plegados: se deciden en bloque por la API más abajo,
    // porque abrirlos uno a uno tarda unos tres segundos por fila.
    const standardTriggers = page.getByRole("button", { name: /^(ESRS|NEIS) .*inventariados$/ })
    for (let index = 0; index < (await standardTriggers.count()); index += 1) {
      const trigger = standardTriggers.nth(index)
      if ((await trigger.getAttribute("aria-expanded")) === "false") await trigger.click()
    }
    await page.getByRole("button", { name: "Responder", exact: true }).click()

    // Los primeros datos llevan cifras concretas de la empresa; uno de cada cuatro de ellos
    // se marca como «No aplica» con su justificación. Después se completan unos pocos más
    // remitiendo a la memoria de sostenibilidad y el resto queda como «No aplica» justificado,
    // para que todo el inventario quede decidido. Cada respuesta completada con valor es ya el
    // contenido del informe: el paso 6 no vuelve a pedirla.
    const completedTarget = 11
    const answers = [
      ["Consejo de administración de 5 miembros, 2 independientes; comité de sostenibilidad desde 2024", "Acta del consejo, 12/02/2025"],
      ["Política de sostenibilidad aprobada por el consejo en marzo de 2024", "Política de sostenibilidad v2, intranet"],
      ["Plantilla media 2025: 138 personas (61 % hombres, 39 % mujeres)", "Resumen de nóminas 2025, RRHH"],
      ["Emisiones de alcance 1 en 2025: 412 tCO2e (gas natural y flota propia)", "Inventario GEI 2025, hoja «Alcance 1»"],
      ["Emisiones de alcance 2 en 2025: 236 tCO2e (electricidad, método basado en la ubicación)", "Facturas eléctricas 2025 y factor del MITECO"],
      ["Consumo energético total 2025: 3.840 MWh, 18 % de origen renovable certificado", "Garantías de origen 2025"],
      ["Residuos peligrosos 2025: 27 t (taladrinas, lodos de pintura), gestionados por gestor autorizado", "Documentos de identificación de residuos 2025"],
      ["Accidentes con baja 2025: 3; índice de frecuencia 11,2", "Registro de accidentes, servicio de prevención"],
      ["Formación 2025: 14 horas por persona de media", "Plan de formación 2025, RRHH"],
      ["Evaluación de proveedores críticos: 24 de 31 evaluados en 2025", "Procedimiento de compras PC-07 y registros"],
      ["Canal de denuncias externo operativo desde 2023; 2 comunicaciones en 2025, ninguna confirmada", "Informe anual del canal de denuncias"],
      ["Riesgo físico principal: olas de calor en la nave de producción; medida: climatización por evaporación (2026)", "Análisis de riesgos climáticos 2025"],
    ]
    const notApplicableNote = "No aplica: la empresa no realiza esta actividad ni tiene instalaciones afectadas."
    const conditionalNote = "No aplica en el ejercicio 2025: dato condicional sin hecho desencadenante en la empresa; se revisará cada ejercicio."

    // Los datos son un acordeón: solo uno abierto a la vez, así que se abre cada uno antes de rellenarlo.
    const rowsToOpen = page.getByRole("button", { name: /(Expandir|Colapsar) respuesta$/ })
    await expect(rowsToOpen.first()).toBeVisible()
    const total = await rowsToOpen.count()
    expect(total).toBeGreaterThan(0)
    for (let index = 0; index < total; index += 1) {
      await rowsToOpen.nth(index).click()
      const statusSelect = page.locator('select[id$="-status"]')
      await expect(statusSelect).toHaveCount(1)
      const datapointId = ((await statusSelect.getAttribute("id")) ?? "").replace(/-status$/, "")
      const numeric = numericAnswers.get(datapointId)
      const detailed = index < answers.length
      const applies = numeric ? true : detailed ? index % 4 !== 3 : completedDatapoints.size < completedTarget
      if (detailed) {
        // Revisión del dato: relevante y contestado, salvo los que no aplican.
        await page.locator(`[id="${datapointId}-review-relevant"]`).selectOption(applies ? "true" : "false")
        await page.locator(`[id="${datapointId}-review-selected_to_answer"]`).selectOption(applies ? "true" : "false")
      }
      if (!applies) {
        await statusSelect.selectOption("not_applicable")
        await page.locator(`[id="${datapointId}-note"]`).fill(detailed ? notApplicableNote : conditionalNote)
        notApplicableCount += 1
        continue
      }
      const [value, evidence] = numeric
        ? [numeric.text, numeric.evidence]
        : detailed
          ? answers[index]
          : [`Información recogida en la memoria de sostenibilidad 2025 (dato ${datapointId}).`, "Memoria de sostenibilidad 2025 y registros internos"]
      await statusSelect.selectOption("completed")
      if (numeric) {
        // Cifras: tipo, valor exacto y unidad quedan en la respuesta; son los hechos del informe y del XBRL.
        await page.locator(`[id="${datapointId}-value-type"]`).selectOption(numeric.valueType)
        await page.locator(`[id="${datapointId}-value"]`).fill(numeric.value)
        await page.locator(`[id="${datapointId}-unit"]`).fill(numeric.unit)
        await page.locator(`[id="${datapointId}-decimals"]`).fill("0")
      } else {
        await page.locator(`[id="${datapointId}-value-type"]`).selectOption("text")
        await page.locator(`[id="${datapointId}-value"]`).fill(value)
      }
      await page.locator(`[id="${datapointId}-evidence"]`).fill(evidence)
      completedDatapoints.set(datapointId, [value, evidence])
    }
    test.info().annotations.push({ type: "datos decididos", description: `${completedDatapoints.size} completados, ${notApplicableCount} no aplican` })

    await page.getByRole("button", { name: "Guardar respuestas" }).click()
    await expect(page.getByText(/Guardado automáticamente/)).toBeVisible({ timeout: 60_000 })

    // Los datos temáticos pendientes se deciden en bloque por la API con la sesión del navegador,
    // como haría una integración: «No aplica» justificado. La petición sustituye el conjunto
    // completo, así que se reenvían también las respuestas ya guardadas.
    const session = await page.request.get("/api/auth/session", { headers: { Accept: "application/json" } })
    expect(session.ok()).toBeTruthy()
    const headers = { Accept: "application/json", "X-CSRF-TOKEN": (await session.json()).data.csrf_token as string, "X-Requested-With": "XMLHttpRequest" }
    const workspace = (await (await page.request.get("/api/esrs-datapoints", { headers })).json()) as { data: { blocks: Record<string, { datapoints?: Array<{ id: string }> }> }; response_state?: { revision?: number } }
    const corpus = workspace.data
    const corpusIds: string[] = Object.values(corpus.blocks as Record<string, { datapoints?: Array<{ id: string }> }>).flatMap((block) => (block.datapoints ?? []).map((datapoint) => datapoint.id))
    inventorySize = corpusIds.length
    const storedState = (await (await page.request.get("/api/esrs-datapoints/responses", { headers })).json()).data as { revision?: number; responses?: Record<string, { status: string; value?: string; value_type?: string; unit?: string; decimals?: number | null; evidence_reference?: string | null; note?: string | null; triage?: string | null }> } | null
    const stored = storedState?.responses ?? {}
    const topicalNote = "No aplica en el ejercicio 2025: dato temático sin hecho desencadenante en la empresa; se revisará cada ejercicio."
    let bulkDecided = 0
    const responses = corpusIds.map((datapointId) => {
      const current = stored[datapointId]
      if (current && current.status !== "draft") {
        return { datapoint_id: datapointId, status: current.status, value: current.value ?? null, evidence_reference: current.evidence_reference ?? null, note: current.note ?? null, ...(current.value_type ? { value_type: current.value_type, unit: current.unit ?? null, decimals: current.decimals ?? null } : {}), ...(current.triage ? { triage: current.triage } : {}) }
      }
      bulkDecided += 1
      return { datapoint_id: datapointId, status: "not_applicable", value: null, evidence_reference: null, note: topicalNote, triage: "not_applicable_candidate" }
    })
    if (bulkDecided > 0) {
      // La petición exige la revisión vigente, como hace el formulario, para detectar conflictos.
      const bulk = await page.request.put("/api/esrs-datapoints/responses", { headers, data: { expected_revision: workspace.response_state?.revision ?? storedState?.revision ?? 0, responses } })
      expect(bulk.ok(), await bulk.text()).toBeTruthy()
      notApplicableCount += bulkDecided
      test.info().annotations.push({ type: "datos temáticos por API", description: `${bulkDecided} no aplican` })
      // Se recarga para que la pantalla parta del estado guardado y no reenvíe borradores antiguos.
      await page.goto("/wizard/step-5")
      await expect(page.getByText("Total", { exact: true })).toBeVisible({ timeout: 30_000 })
    }
    await page.getByRole("button", { name: "Guardar y continuar" }).click()
    await expect(page).toHaveURL(/\/wizard\/step-6/, { timeout: 60_000 })
  })

  await test.step("paso 6: contenido del informe derivado del paso 5", async () => {
    await expect(page.getByRole("heading", { name: "Informe NEIS" })).toBeVisible({ timeout: 30_000 })
    const decided = inventorySize || completedDatapoints.size + notApplicableCount
    await expect(page.getByText(`${decided} de ${decided} datos ya decididos`)).toBeVisible()

    // Las respuestas completadas son los hechos del informe: no hay formulario ni revisión uno a uno.
    await expect(page.getByText("Datos factuales y revisión")).toHaveCount(0)
    const facts = (await (await page.request.get("/api/report/facts", { headers: { Accept: "application/json" } })).json()).data as { persisted_facts: Array<{ datapoint_id: string; approval_status: string; value_type: string }>; claimable_fact_count: number }
    const reviewed = facts.persisted_facts.filter((fact) => fact.approval_status === "reviewed")
    expect(facts.claimable_fact_count).toBe(completedDatapoints.size)
    for (const [id, numeric] of numericAnswers) expect(reviewed.find((fact) => fact.datapoint_id === id)?.value_type, `${id} sin tipo numérico`).toBe(numeric.valueType)
    test.info().annotations.push({ type: "hechos del informe", description: `${facts.claimable_fact_count} con valor, ${reviewed.length} revisados` })

    await expect(page.getByText("Siguientes acciones")).toHaveCount(0, { timeout: 30_000 })
    await expect(page.getByText("¿Y ahora qué?")).toBeVisible()
  })

  await test.step("paso 6: versión del informe aprobada y descargas", async () => {
    await page.getByRole("button", { name: "Preparar versión del informe" }).click()
    const approve = page.getByRole("button", { name: "Aprobar versión del informe" })
    await expect(approve).toBeEnabled({ timeout: 30_000 })
    await approve.click()
    const approveDialog = page.getByRole("dialog")
    await approveDialog.locator("#snapshot-approval-declaration").fill("Yo, como persona responsable, asumo la preparación, revisión y aprobación de esta versión.")
    await approveDialog.getByRole("button", { name: "Aprobar versión del informe" }).click()
    await expect(approveDialog).toBeHidden({ timeout: 30_000 })
    await expect(page.getByText("Descargar informe XBRL")).toBeVisible({ timeout: 30_000 })

    // Taxonomía externa: verificada cuando el manifiesto, el paquete EFRAG y Arelle están aprovisionados;
    // si no, el candidato iXBRL queda bloqueado y la prueba lo deja constar sin fallar.
    const taxonomyCard = page.locator('[data-slot="card"]', { has: page.getByRole("heading", { name: "Taxonomía NEIS externa" }) }).first()
    const taxonomy = (await (await page.request.get("/api/report/taxonomy", { headers: { Accept: "application/json" } })).json()).data
    const taxonomyVerified = taxonomy.availability.state === "verified"
    await expect(taxonomyCard.getByText(taxonomyVerified ? "Verificado" : "Bloqueado")).toBeVisible()
    test.info().annotations.push({ type: "taxonomía", description: `${taxonomy.taxonomy.name} ${taxonomy.taxonomy.version}: ${taxonomy.availability.state}${taxonomy.availability.reason_code ? ` (${taxonomy.availability.reason_code})` : ""}` })

    // Todas las descargas del paquete de preparación quedan listas.
    const packageCard = page.locator('[data-slot="card"]', { has: page.getByRole("heading", { name: "Paquete HTML de preparación" }) }).first()
    await expect(packageCard.getByText("Pendiente", { exact: true })).toHaveCount(0)
    expect(await packageCard.getByText("Abrir", { exact: true }).count()).toBeGreaterThanOrEqual(6)

    // Cada descarga responde con su formato, usando la sesión del navegador.
    const checks: Array<[string, RegExp, (body: Buffer) => boolean]> = [
      ["/api/report/draft", /application\/json/, (body) => JSON.parse(body.toString()).data.workflow_complete === true],
      ["/api/report/package", /text\/html/, (body) => /<html/i.test(body.toString())],
      ["/api/report/evidence-bundle", /application\/json/, (body) => typeof JSON.parse(body.toString()).data === "object"],
      ["/api/report/html", /text\/html/, (body) => /<html/i.test(body.toString())],
      ["/api/guided-report/docx", /officedocument|octet-stream|zip/, (body) => body.subarray(0, 2).toString() === "PK"],
      ["/api/guided-report/evidence-bundle", /application\/json/, (body) => typeof JSON.parse(body.toString()).data === "object"],
      ["/api/materiality-confirmation/decision-sheet", /application\/json/, (body) => body.length > 2],
      ["/api/esrs-datapoints/responses/export.localized.csv", /text\/csv/, (body) => body.toString().includes("BP-1_01")],
      ["/api/esrs-datapoints/export.localized.csv", /text\/csv/, (body) => body.toString().split("\n").length > 100],
      ["/characterization/summary?format=pdf", /application\/pdf/, (body) => body.subarray(0, 4).toString() === "%PDF"],
    ]
    for (const [path, contentType, valid] of checks) {
      const response = await page.request.get(path, { timeout: 120_000 })
      expect(response.status(), `${path} → HTTP ${response.status()}`).toBe(200)
      expect(response.headers()["content-type"] ?? "", `${path} content-type`).toMatch(contentType)
      const body = await response.body()
      expect(body.length, `${path} vacío`).toBeGreaterThan(0)
      expect(valid(body), `${path} contenido`).toBeTruthy()
    }
    const draft = (await (await page.request.get("/api/report/draft")).json()).data
    if (draft.report_scope_integrity_enabled !== true) {
      await expect(page.getByText("Descargar informe NEIS en XHTML")).toHaveCount(0)
    }
    test.info().annotations.push({ type: "descargas", description: String(checks.length) })
  })

  await test.step("paso 6: documento XBRL (candidato XHTML/iXBRL)", async () => {
    const taxonomy = (await (await page.request.get("/api/report/taxonomy", { headers: { Accept: "application/json" } })).json()).data
    test.skip(taxonomy.availability.state !== "verified", "Taxonomía EFRAG externa no aprovisionada: el candidato iXBRL no puede generarse en este entorno.")

    await expect(page.getByRole("button", { name: "Generar XBRL" })).toBeEnabled({ timeout: 30_000 })
    await expect(page.getByText("Descargar XBRL (paquete iXBRL)")).toBeVisible()

    // Arelle valida cada salida sin red y con un bloqueo global; puede tardar medio minuto por documento.
    const xhtml = await page.request.get("/api/report/xhtml-ixbrl-candidate", { timeout: 300_000 })
    expect(xhtml.status(), await xhtml.text().catch(() => "")).toBe(200)
    expect(xhtml.headers()["content-type"] ?? "").toMatch(/application\/xhtml\+xml/)
    const xhtmlBody = await xhtml.text()
    expect(xhtmlBody).toContain("<ix:nonFraction")
    expect(xhtmlBody).toContain(company.lei)
    for (const [id, numeric] of numericAnswers) expect(xhtmlBody, `${id} sin etiquetar`).toContain(`name="${numeric.concept}"`)
    const tagged = (xhtmlBody.match(/<ix:nonFraction\b/g) ?? []).length
    // Las aserciones de completitud de EFRAG no bloquean; viajan en la cabecera como evidencia.
    expect(xhtml.headers()["x-taxonomy-assertions"] ?? "").toMatch(/^errors=\d+; warnings=\d+$/)
    test.info().annotations.push({ type: "aserciones EFRAG", description: xhtml.headers()["x-taxonomy-assertions"] ?? "" })
    await test.info().attach("candidato-ixbrl.xhtml", { body: xhtmlBody, contentType: "application/xhtml+xml" })

    const pkg = await page.request.get("/api/report/ixbrl-package", { timeout: 300_000 })
    expect(pkg.status(), await pkg.text().catch(() => "")).toBe(200)
    expect(pkg.headers()["content-type"] ?? "").toMatch(/application\/zip/)
    expect(pkg.headers()["x-report-publication-state"]).toBe("validated_candidate")
    const zip = await pkg.body()
    expect(zip.subarray(0, 2).toString()).toBe("PK")
    for (const entry of ["META-INF/reportPackage.json", "reports/candidate.xhtml", "evidence.json"]) {
      expect(zip.includes(Buffer.from(entry)), `${entry} ausente del paquete`).toBeTruthy()
    }
    await test.info().attach("candidato-ixbrl-provisional.zip", { body: zip, contentType: "application/zip" })
    test.info().annotations.push({ type: "iXBRL", description: `${tagged} hechos etiquetados; paquete de ${zip.length} bytes validado por Arelle` })
  })
})
