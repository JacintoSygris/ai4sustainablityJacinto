import { ui } from "@/lib/i18n/messages.mjs"
import { getLaravelServerLocale } from "@/lib/laravel-server"
import { ProgressCard } from "@/components/dashboard/progress-card"
import { AcademySection } from "@/components/dashboard/academy-section"
import {
  getLaravelServerCharacterization,
  getLaravelServerReportReadiness,
  getLaravelServerSession,
} from "@/lib/laravel-server"
import { reachedWizardStep } from "@/lib/wizard-progress.mjs"

export default async function DashboardPage() {
  const locale = await getLaravelServerLocale()
  const tr = (message: string) => ui(locale, message)

  const [session, characterization, readiness] = await Promise.all([
    getLaravelServerSession(),
    getLaravelServerCharacterization(),
    getLaravelServerReportReadiness(),
  ])

  const userName = session?.user?.name?.split(" ")[0] || tr("Usuario")
  const currentStep = reachedWizardStep(characterization, readiness)
  const hasStarted = characterization != null
  const totalSteps = 6

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-foreground">{tr("¡Hola,")}{" "}{userName}!</h1>
        <p className="mt-2 text-muted-foreground">
          {" "}{tr("Te damos la bienvenida a Airis, tu asistente para preparar informes NEIS 2023, organizar evidencias y revisar datos normativos antes de tu validación final.")}{" "}</p>
      </div>

      <ProgressCard currentStep={currentStep} totalSteps={totalSteps} hasStarted={hasStarted} />

      <AcademySection />
    </div>
  )
}
