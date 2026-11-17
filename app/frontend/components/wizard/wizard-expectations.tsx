"use client"

import { useLocale } from "@/components/locale-provider"
import { useOptionalStorage } from "@/lib/consent-storage"

import { useEffect, useState } from "react"
import { X } from "lucide-react"
import { Button } from "@/components/ui/button"

const storageKey = "wizard_expectations_dismissed"

const copy = {
  es: {
    title: "Qué vas a hacer aquí",
    intro:
      "Este asistente te lleva por el proceso de materialidad del estándar europeo NEIS en 6 pasos. No necesitas conocimientos previos: cada paso explica lo que tienes que hacer.",
    timeTitle: "Cuánto tiempo lleva",
    time:
      "Los pasos 1, 2 y 4 llevan entre 10 y 30 minutos cada uno. El paso 3 se hace con tu equipo fuera de la aplicación y puede llevar días. El paso 5 lleva 1-2 h la primera vez y el paso 6, unos 10 minutos.",
    needsTitle: "Qué necesitas",
    needs:
      "Conocer tu empresa (actividad, plantilla, facturación aproximada) y poder hablar con las personas que la conocen por dentro.",
    outcomeTitle: "Qué obtienes",
    outcome:
      "Una lista justificada de temas materiales, el inventario de datos que pide el estándar y descargas para compartir con tu gestoría o consultoría.",
    dismiss: "Ocultar",
  },
  en: {
    title: "What you will do here",
    intro:
      "This assistant takes you through the materiality process for the European ESRS standard in 6 steps. You do not need previous knowledge: each step explains what to do.",
    timeTitle: "How long it takes",
    time:
      "Steps 1, 2 and 4 take 10 to 30 minutes each. Step 3 happens with your team outside the application and can take days. Step 5 takes 1-2 h the first time and step 6 about 10 minutes.",
    needsTitle: "What you need",
    needs:
      "Know your company (activity, workforce, approximate revenue) and be able to speak with the people who know it from the inside.",
    outcomeTitle: "What you get",
    outcome:
      "A justified list of material topics, the inventory of data requested by the standard, and downloads to share with your accountant or consultant.",
    dismiss: "Hide",
  },
}

export function WizardExpectations() {
  const { locale } = useLocale()
  const preferenceStorage = useOptionalStorage("preferences")
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    setVisible(preferenceStorage.getItem(storageKey) !== "true")
  }, [])

  const dismiss = () => {
    preferenceStorage.setItem(storageKey, "true")
    setVisible(false)
  }

  if (!visible) {
    return null
  }

  return (
    <section className="rounded-xl border border-border bg-card p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold text-foreground">{copy[locale].title}</h2>
          <p className="mt-2 text-sm text-muted-foreground">{copy[locale].intro}</p>
        </div>
        <Button type="button" variant="ghost" size="sm" onClick={dismiss} aria-label={copy[locale].dismiss}>
          <X className="h-4 w-4" />
        </Button>
      </div>

      {/* The six steps themselves are already listed in the sidebar next to this panel. */}
      <div className="mt-4 grid gap-3 text-sm md:grid-cols-2">
        <div className="rounded-lg bg-muted/50 p-3 md:col-span-2">
          <h3 className="font-medium text-foreground">{copy[locale].timeTitle}</h3>
          <p className="mt-1 text-muted-foreground">{copy[locale].time}</p>
        </div>
        <div className="rounded-lg bg-muted/50 p-3">
          <h3 className="font-medium text-foreground">{copy[locale].needsTitle}</h3>
          <p className="mt-1 text-muted-foreground">{copy[locale].needs}</p>
        </div>
        <div className="rounded-lg bg-muted/50 p-3">
          <h3 className="font-medium text-foreground">{copy[locale].outcomeTitle}</h3>
          <p className="mt-1 text-muted-foreground">{copy[locale].outcome}</p>
        </div>
      </div>
    </section>
  )
}
