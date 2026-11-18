import { expect, test, type Page } from "@playwright/test"
import { copyFileSync, existsSync, mkdirSync, readFileSync } from "node:fs"
import { join } from "node:path"

/**
 * Graba un vídeo por empresa ficticia recorriendo los seis pasos con los datos
 * generados por `php artisan i4s:demo:companies`. El vídeo se copia a
 * app/web/storage/app/private/demo-videos/<correo>.webm, de donde lo descargan
 * los administradores desde /admin. Requiere la pila en marcha y las cuentas creadas.
 *
 *   DEMO_COMPANIES_PASSWORD='...' corepack pnpm test:e2e tests/e2e/demo-walkthrough.spec.ts
 */

const catalogue = JSON.parse(readFileSync(join(__dirname, "../../../web/data/demo_companies.json"), "utf8")) as {
  companies: Array<{ email: string; name: string }>
}
const password = process.env.DEMO_COMPANIES_PASSWORD ?? ""
const only = (process.env.DEMO_COMPANIES_ONLY ?? "").split(",").map((value) => value.trim()).filter(Boolean)
const videosDir = join(__dirname, "../../../web/storage/app/private/demo-videos")

test.use({ video: { mode: "on", size: { width: 1366, height: 860 } }, viewport: { width: 1366, height: 860 } })

async function pause(page: Page, ms = 2500) {
  await page.waitForTimeout(ms)
}

async function scrollThrough(page: Page, steps = 4) {
  for (let index = 1; index <= steps; index += 1) {
    await page.mouse.wheel(0, 600)
    await page.waitForTimeout(900)
  }
  await page.mouse.wheel(0, -600 * steps)
  await page.waitForTimeout(600)
}

for (const company of catalogue.companies) {
  if (only.length > 0 && !only.includes(company.email)) continue

  test(`recorrido de ${company.name}`, async ({ page }, testInfo) => {
    test.skip(!password, "Define DEMO_COMPANIES_PASSWORD (la misma que usó i4s:demo:companies).")
    test.setTimeout(600_000)

    await page.goto("/login")
    await page.locator("#email").fill(company.email)
    await page.locator("#password").fill(password)
    await page.getByRole("button", { name: /Iniciar sesión|Sign in/ }).click()
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 })
    const accept = page.locator(".airis-consent-notice").getByRole("button", { name: /Aceptar opcionales|Accept optional/ })
    if (await accept.isVisible().catch(() => false)) await accept.click()
    await pause(page)

    // Paso 1: la encuesta inicial ya guardada.
    await page.goto("/wizard/step-1")
    await expect(page.getByText(company.name).first()).toBeVisible({ timeout: 30_000 })
    await scrollThrough(page, 5)

    // Paso 2: propuesta de la IA, decisiones y temas añadidos por la empresa.
    await page.goto("/wizard/step-2")
    await expect(page.getByText("Revisión guardada", { exact: true })).toBeVisible({ timeout: 30_000 })
    await pause(page, 1500)
    await page.getByRole("button", { name: "Expandir todo" }).click()
    await scrollThrough(page, 8)
    await page.getByRole("checkbox", { name: "Mostrar solo temas seleccionados" }).click()
    await pause(page, 2000)
    await scrollThrough(page, 4)

    // Paso 3: acta registrada.
    await page.goto("/wizard/step-3")
    await expect(page.getByRole("button", { name: "Registrar acta" })).toBeVisible({ timeout: 30_000 })
    await scrollThrough(page, 3)

    // Paso 4: selección final confirmada.
    await page.goto("/wizard/step-4")
    await expect(page.getByText(/temas|topics/).first()).toBeVisible({ timeout: 30_000 })
    await scrollThrough(page, 4)

    // Paso 5: respuestas con tipo, unidad y decimales.
    await page.goto("/wizard/step-5")
    const closeIntro = page.getByRole("button", { name: "Cerrar", exact: true })
    if (await closeIntro.isVisible().catch(() => false)) await closeIntro.click()
    await expect(page.getByText("Total", { exact: true })).toBeVisible({ timeout: 60_000 })
    await pause(page, 1500)
    const triggers = page.getByRole("button", { name: /^(ESRS|NEIS) .*inventariados$/ })
    if ((await triggers.count()) > 0 && (await triggers.first().getAttribute("aria-expanded")) === "false") await triggers.first().click()
    const answerMode = page.getByRole("button", { name: "Responder", exact: true })
    if (await answerMode.isVisible().catch(() => false)) await answerMode.click()
    const rows = page.getByRole("button", { name: /(Expandir|Colapsar) respuesta$/ })
    await expect(rows.first()).toBeVisible({ timeout: 30_000 })
    for (const index of [0, 2, 5]) {
      if ((await rows.count()) <= index) break
      await rows.nth(index).scrollIntoViewIfNeeded()
      await rows.nth(index).click()
      await pause(page, 2500)
    }

    // Paso 6: contenido del informe, versión aprobada y descargas.
    await page.goto("/wizard/step-6")
    await expect(page.getByRole("heading", { name: "Informe NEIS" })).toBeVisible({ timeout: 60_000 })
    await expect(page.getByText("Descargar informe XBRL")).toBeVisible({ timeout: 60_000 })
    await scrollThrough(page, 7)
    await pause(page, 2000)

    const video = page.video()
    await page.close()
    const source = await video?.path()
    if (source && existsSync(source)) {
      mkdirSync(videosDir, { recursive: true })
      const slug = company.email.toLowerCase().replace("@", "-at-").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "")
      const target = join(videosDir, `${slug}.webm`)
      copyFileSync(source, target)
      testInfo.annotations.push({ type: "vídeo", description: target })
    }
  })
}
