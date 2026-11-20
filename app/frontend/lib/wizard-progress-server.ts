import { getLaravelServerCharacterization, getLaravelServerReportReadiness } from "@/lib/laravel-server"
import { reachedWizardStep } from "@/lib/wizard-progress.mjs"

/** Furthest wizard step reached by the signed-in account, read from the platform on the server. */
export async function getReachedWizardStep(): Promise<number> {
  const [characterization, readiness] = await Promise.all([getLaravelServerCharacterization(), getLaravelServerReportReadiness()])

  return reachedWizardStep(characterization, readiness)
}
