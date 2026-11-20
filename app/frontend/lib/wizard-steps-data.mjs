/**
 * @typedef {"completed" | "current" | "available" | "upcoming"} WizardStepStatus
 * @typedef {{ id: number, title: string, description: string }} WizardStepDefinition
 * @typedef {WizardStepDefinition & { status: WizardStepStatus }} WizardStep
 */

/** @type {WizardStepDefinition[]} */
export const WIZARD_STEPS = [
  {
    id: 1,
    title: "Describe la organización",
    description: "Registra actividad, tamaño, alcance y ejercicio de referencia.",
  },
  {
    id: 2,
    title: "Revisa asuntos propuestos",
    description: "Contrasta las propuestas automáticas ASG y ajusta la lista con criterio humano.",
  },
  {
    id: 3,
    title: "Doble importancia relativa",
    description: "Usa la guía para ordenar el análisis que realiza tu equipo.",
  },
  {
    id: 4,
    title: "Confirma asuntos materiales",
    description: "Guarda la selección final y los motivos que quieras conservar.",
  },
  {
    id: 5,
    title: "Reúne datos normativos NEIS",
    description: "Registra respuestas, pendientes y no aplicables justificados.",
  },
  {
    id: 6,
    title: "Resultados",
    description: "Revisa el resumen y las descargas de preparación disponibles.",
  },
]

/**
 * Sidebar statuses for the step being viewed. `reachedStep` is the furthest step the
 * account has reached (see lib/wizard-progress.mjs): every step before it is done and
 * can be reopened, the reached step itself is "available" (next thing to do) and the
 * rest stay "upcoming". Without it, only the steps before the viewed one are open.
 *
 * @param {number} viewingStep
 * @param {number} [reachedStep]
 * @returns {WizardStep[]}
 */
export function wizardStepsForView(viewingStep, reachedStep = viewingStep) {
  const furthest = Math.max(viewingStep, reachedStep)

  return WIZARD_STEPS.map((step) => ({
    ...step,
    status:
      step.id === viewingStep
        ? "current"
        : step.id < furthest
          ? "completed"
          : step.id === furthest
            ? "available"
            : "upcoming",
  }))
}
