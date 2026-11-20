/**
 * Furthest wizard step the account has reached, derived from the platform state
 * (characterization + report readiness). Shared by the dashboard progress card and
 * the wizard sidebar so both agree on which steps can be opened.
 *
 * @param {{ status?: string } | null | undefined} characterization
 * @param {{ sections?: Record<string, { status?: string } | undefined> } | null | undefined} readiness
 * @returns {1 | 2 | 3 | 4 | 5 | 6}
 */
export function reachedWizardStep(characterization, readiness) {
  if (!characterization) return 1
  if (!readiness) return characterization.status === "completed" ? 2 : 1

  const sections = readiness.sections ?? {}
  if (!sectionReady(sections.characterization?.status)) return 1
  if (!sectionReady(sections.materiality_proposal?.status)) return 2
  if (!sectionReady(sections.double_materiality_guide?.status)) return 3
  if (!sectionReady(sections.materiality_confirmation?.status)) return 4
  if (!sectionReady(sections.datapoint_responses?.status)) return 5

  return 6
}

/** @param {string | undefined} status */
export function sectionReady(status) {
  return status === "ready" || status === "complete"
}
