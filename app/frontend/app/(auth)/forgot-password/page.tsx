"use client"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import type React from "react"

import Link from "next/link"
import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { getLaravelRegisterConfig } from "@/lib/laravel-api"
import { fetchLaravelCsrfToken, laravelAuthHeaders, laravelValidationMessage } from "@/lib/laravel-auth"

const RESET_UNAVAILABLE = "La recuperación de contraseña no está disponible en este momento. Contacta con el equipo responsable del despliegue."

export default function ForgotPasswordPage() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const [email, setEmail] = useState("")
  const [error, setError] = useSystemMessage("")
  const [status, setStatus] = useSystemMessage("")
  const [loading, setLoading] = useState(false)
  const [resetEnabled, setResetEnabled] = useState(true)

  useEffect(() => {
    let mounted = true
    getLaravelRegisterConfig().then(({ data }) => {
      if (!mounted || data.password_reset_enabled !== false) return
      setResetEnabled(false)
      setError(systemCopy(RESET_UNAVAILABLE))
    }).catch(() => {})
    return () => {
      mounted = false
    }
  }, [setError])

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    setError("")
    setStatus("")
    if (!resetEnabled) {
      setError(systemCopy(RESET_UNAVAILABLE))
      return
    }
    setLoading(true)

    try {
      const csrfToken = await fetchLaravelCsrfToken("/laravel/forgot-password")
      const response = await fetch("/laravel/forgot-password", {
        body: new URLSearchParams({
          _token: csrfToken,
          email,
        }),
        credentials: "include",
        headers: laravelAuthHeaders(csrfToken),
        method: "POST",
        redirect: "manual",
      })

      if (response.status === 404) {
        setResetEnabled(false)
        setError(systemCopy(RESET_UNAVAILABLE))
        return
      }

      if (!(response.ok || response.type === "opaqueredirect" || (response.status >= 300 && response.status < 400))) {
        setError(await laravelValidationMessage(response, tr("No se ha podido solicitar el enlace. Inténtalo de nuevo."), locale))
        return
      }

      setStatus(systemCopy("Si existe una cuenta con ese email, enviaremos un enlace para restablecer la contraseña."))
    } catch {
      setError(systemCopy("No se ha podido solicitar el enlace. Inténtalo de nuevo."))
    } finally {
      setLoading(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="w-full max-w-md space-y-6">
      <div className="text-center">
        <h1 className="text-2xl font-semibold text-foreground">{tr("Recuperar contraseña")}</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {" "}{tr("Indica tu email y te enviaremos un enlace de recuperación si la cuenta existe.")}{" "}</p>
      </div>

      {status ? <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{status}</div> : null}
      {error ? <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{error}</div> : null}

      <div className="space-y-2">
        <Label htmlFor="email">{tr("Email")}</Label>
        <Input
          id="email"
          type="email"
          placeholder={tr("nombre@empresa.com")}
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          required
        />
      </div>

      <Button type="submit" className="w-full" disabled={loading || !resetEnabled}>
        {loading ? tr("Solicitando enlace...") : tr("Enviar enlace de recuperación")}
      </Button>

      <div className="text-center">
        <Link href="/login" className="text-sm text-primary hover:underline">
          {" "}{tr("Volver a iniciar sesión")}{" "}</Link>
      </div>
    </form>
  )
}
