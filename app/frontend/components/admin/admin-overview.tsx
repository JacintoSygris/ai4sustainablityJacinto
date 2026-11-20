"use client"

import type React from "react"
import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { AlertCircle, AlertTriangle, CheckCircle2, Circle, Download, ExternalLink, FileText, ShieldCheck, Trash2 } from "lucide-react"

import { useLocale } from "@/components/locale-provider"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { ui, formatUi } from "@/lib/i18n/messages.mjs"
import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"
import {
  LaravelApiError,
  deleteLaravelAdminUser,
  deleteLaravelAdminUserReport,
  getLaravelAdminAiUsefulness,
  getLaravelAdminUsers,
  laravelApiUrl,
  type LaravelAdminUser,
  type LaravelAdminUsers,
  type LaravelAiUsefulnessReport,
  type LaravelAiUsefulnessTopic,
} from "@/lib/laravel-api"
import { cn } from "@/lib/utils"

type Locale = "es" | "en"

function formatPercent(value: number | null | undefined, locale: Locale): string {
  if (value == null || Number.isNaN(value)) return "—"

  return `${(value * 100).toLocaleString(locale === "en" ? "en-GB" : "es-ES", { maximumFractionDigits: 1 })} %`
}

function formatDate(value: string | null | undefined, locale: Locale): string {
  if (!value) return "—"
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? "—" : date.toLocaleString(locale === "en" ? "en-GB" : "es-ES", { dateStyle: "medium", timeStyle: "short" })
}

function topicLabel(topic: LaravelAiUsefulnessTopic, locale: Locale): string {
  const label = topic.label?.[locale] || topic.label?.es || ""

  return [topic.esrs_code, label].filter(Boolean).join(" ")
}

function characterizationStatusLabel(status: string | undefined, locale: Locale): string {
  const tr = (message: string) => ui(locale, message)

  return (
    {
      draft: tr("Borrador de la encuesta inicial"),
      submitted: tr("Preparando propuesta de temas"),
      processing: tr("Generando propuesta de temas"),
      waiting: tr("Esperando reintento"),
      failed: tr("No se pudo generar la propuesta"),
      timed_out: tr("Tiempo agotado"),
      completed: tr("Propuesta completada"),
    }[status ?? ""] ?? tr("Sin encuesta inicial")
  )
}

export function AdminOverview() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)
  const router = useRouter()
  const [loading, setLoading] = useState(true)
  const [users, setUsers] = useState<LaravelAdminUsers | null>(null)
  const [report, setReport] = useState<LaravelAiUsefulnessReport | null>(null)
  const [errorMessage, setErrorMessage] = useSystemMessage(null)
  const [noticeMessage, setNoticeMessage] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  useEffect(() => {
    let mounted = true

    async function load() {
      setLoading(true)
      setErrorMessage(null)
      try {
        const [usersResponse, reportResponse] = await Promise.all([getLaravelAdminUsers(), getLaravelAdminAiUsefulness()])
        if (!mounted) return
        setUsers(usersResponse.data)
        setReport(reportResponse.data)
      } catch (error) {
        if (!mounted) return
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")
          return
        }
        setErrorMessage(
          error instanceof LaravelApiError && error.status === 403
            ? systemCopy("Tu cuenta no tiene permisos de administración.")
            : systemCopy("No se ha podido cargar la administración desde la plataforma."),
        )
      } finally {
        if (mounted) setLoading(false)
      }
    }

    load()

    return () => {
      mounted = false
    }
  }, [router, reloadToken])

  // After a report or an account is deleted, both sections (accounts and AI usefulness) are stale: reload them.
  const handleDeleted = (kind: DeletionKind, displayName: string) => {
    setNoticeMessage(formatUi(locale, kind === "report" ? "Informe de {0} borrado." : "Cuenta {0} borrada.", [displayName]))
    setReloadToken((token) => token + 1)
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
          <ShieldCheck className="h-6 w-6 text-primary" aria-hidden="true" />
          {tr("Administración")}
        </h1>
        <p className="mt-1 text-muted-foreground">
          {tr("Cuentas registradas, estado de cada informe y utilidad de la propuesta de la IA. Solo visible para el personal con correo de los dominios administradores.")}
        </p>
        {users?.admin_domains?.length ? (
          <p className="mt-1 text-xs text-muted-foreground">{formatUi(locale, "Dominios administradores: {0}", [users.admin_domains.map((domain) => `@${domain}`).join(", ")])}</p>
        ) : null}
      </div>

      {errorMessage ? (
        <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {noticeMessage ? (
        <div role="status" className="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
          {noticeMessage}
        </div>
      ) : null}

      {loading ? (
        <div className="rounded-lg border border-border p-6 text-sm text-muted-foreground">{tr("Cargando administración...")}</div>
      ) : null}

      {report ? <AiUsefulnessSection report={report} locale={locale} /> : null}
      {users ? <UsersSection users={users.users} currentUserId={users.current_user_id} locale={locale} onDeleted={handleDeleted} /> : null}
    </div>
  )
}

function StatTile({ value, label, caption }: { value: string; label: string; caption: string }) {
  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <p className="text-3xl font-semibold tabular-nums text-foreground">{value}</p>
      <p className="mt-1 text-sm font-medium text-foreground">{label}</p>
      <p className="mt-0.5 text-xs text-muted-foreground">{caption}</p>
    </div>
  )
}

function BarRow({ label, value, total, caption }: { label: string; value: number; total: number; caption: string }) {
  const share = total > 0 ? value / total : 0

  return (
    <div className="grid grid-cols-[minmax(7rem,1fr)_3fr_auto] items-center gap-3 text-sm" title={`${label}: ${caption}`}>
      <span className="text-foreground">{label}</span>
      <div className="h-2.5 overflow-hidden rounded-sm bg-primary/10" aria-hidden="true">
        <div className="h-full rounded-r-sm bg-primary" style={{ width: `${Math.max(share > 0 ? 2 : 0, Math.round(share * 100))}%` }} />
      </div>
      <span className="tabular-nums text-muted-foreground">{caption}</span>
    </div>
  )
}

function TopicList({ title, topics, locale }: { title: string; topics: LaravelAiUsefulnessTopic[]; locale: Locale }) {
  const tr = (message: string) => ui(locale, message)

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <h3 className="text-sm font-semibold text-foreground">{title}</h3>
      {topics.length === 0 ? (
        <p className="mt-2 text-sm text-muted-foreground">{tr("Todavía no hay datos.")}</p>
      ) : (
        <ol className="mt-2 space-y-1.5">
          {topics.map((topic) => (
            <li key={topic.id} className="flex items-start justify-between gap-3 text-sm">
              <span className="text-foreground">{topicLabel(topic, locale)}</span>
              <span className="shrink-0 tabular-nums text-muted-foreground">{topic.count === 1 ? tr("1 empresa") : formatUi(locale, "{0} empresas", [topic.count])}</span>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}

function AiUsefulnessSection({ report, locale }: { report: LaravelAiUsefulnessReport; locale: Locale }) {
  const tr = (message: string) => ui(locale, message)
  const { summary } = report
  const review = summary.review
  const final = summary.final
  const decisions = [
    { key: "accepted", label: tr("Aceptados"), value: review.accepted },
    { key: "unsure", label: tr("En duda"), value: review.unsure },
    { key: "rejected", label: tr("Rechazados"), value: review.rejected },
    { key: "pending", label: tr("Pendientes"), value: review.pending },
  ]
  const maxBucket = Math.max(1, ...summary.acceptance_distribution.map((bucket) => bucket.count))

  return (
    <section aria-labelledby="ai-usefulness-title" className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="ai-usefulness-title" className="text-xl font-semibold text-foreground">{tr("Utilidad de la IA")}</h2>
          <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
            {tr("Cuántos de los temas recomendados por la IA mantienen los clientes y cuántos añaden por su cuenta. «Revisión» son las decisiones del paso 2; «selección final», los temas confirmados en el paso 4.")}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {formatUi(locale, "Generado el {0}", [formatDate(report.generated_at, locale)])} · {tr("Empresas con propuesta de la IA")}: {summary.companies_with_proposal} · {tr("Con revisión")}: {summary.companies_with_review} · {tr("Con selección confirmada")}: {summary.companies_with_confirmation}
          </p>
        </div>
        <Button asChild variant="outline">
          <a href={laravelApiUrl("/admin/ai-usefulness?format=pdf")}>
            <Download className="h-4 w-4" aria-hidden="true" />
            {tr("Descargar PDF")}
          </a>
        </Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile
          value={formatPercent(review.acceptance_rate, locale)}
          label={tr("Temas de la IA aceptados en la revisión")}
          caption={formatUi(locale, "{0} de {1} temas recomendados", [review.accepted, review.ai_recommended_total])}
        />
        <StatTile
          value={formatPercent(final.precision, locale)}
          label={tr("Temas de la IA mantenidos en la selección final")}
          caption={formatUi(locale, "{0} de {1} temas recomendados", [final.ai_kept, final.ai_recommended_total])}
        />
        <StatTile
          value={formatPercent(final.coverage, locale)}
          label={tr("Selección final que la IA había recomendado")}
          caption={formatUi(locale, "{0} de {1} temas confirmados", [final.ai_kept, final.confirmed_total])}
        />
        <StatTile
          value={String(review.added_by_client + final.added_by_client)}
          label={tr("Temas añadidos por los clientes")}
          caption={formatUi(locale, "{0} en la revisión · {1} en la selección final", [review.added_by_client, final.added_by_client])}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="rounded-lg border border-border bg-card p-4">
          <h3 className="text-sm font-semibold text-foreground">{tr("Decisiones sobre los temas recomendados (paso 2)")}</h3>
          <div className="mt-3 space-y-2">
            {decisions.map((row) => (
              <BarRow
                key={row.key}
                label={row.label}
                value={row.value}
                total={review.ai_recommended_total}
                caption={`${row.value} · ${formatPercent(review.ai_recommended_total > 0 ? row.value / review.ai_recommended_total : null, locale)}`}
              />
            ))}
          </div>
          <p className="mt-3 text-xs text-muted-foreground">{formatUi(locale, "Aceptación media por empresa: {0}", [formatPercent(review.average_acceptance_rate, locale)])}</p>
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <h3 className="text-sm font-semibold text-foreground">{tr("Empresas por tasa de aceptación")}</h3>
          <div className="mt-3 space-y-2">
            {summary.acceptance_distribution.map((bucket) => (
              <BarRow
                key={bucket.key}
                label={`${bucket.key} %`}
                value={bucket.count}
                total={maxBucket}
                caption={bucket.count === 1 ? tr("1 empresa") : formatUi(locale, "{0} empresas", [bucket.count])}
              />
            ))}
          </div>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <TopicList title={tr("Temas de la IA más aceptados")} topics={report.top_accepted_topics} locale={locale} />
        <TopicList title={tr("Temas que más añaden los clientes")} topics={report.top_added_topics} locale={locale} />
        <TopicList title={tr("Temas de la IA más rechazados")} topics={report.top_rejected_topics} locale={locale} />
      </div>

      <details open className="rounded-lg border border-border bg-card">
        <summary className="cursor-pointer px-4 py-3 text-sm font-semibold text-foreground">{tr("Por empresa")}</summary>
        <div className="overflow-x-auto border-t border-border">
          <table className="w-full text-sm">
            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="px-3 py-2 font-medium">{tr("Empresa")}</th>
                <th className="px-3 py-2 font-medium">{tr("Usuario")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("Temas IA")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("Aceptados")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("En duda")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("Rechazados")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("Añadidos")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("Aceptación")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("Final: IA mantenidos")}</th>
                <th className="px-3 py-2 text-right font-medium">{tr("Final: añadidos")}</th>
              </tr>
            </thead>
            <tbody>
              {report.companies.length === 0 ? (
                <tr>
                  <td colSpan={10} className="px-3 py-4 text-muted-foreground">{tr("Ninguna empresa tiene todavía una propuesta de la IA.")}</td>
                </tr>
              ) : (
                report.companies.map((company) => (
                  <tr key={company.user_id} className="border-t border-border">
                    <td className="px-3 py-2 text-foreground">{company.company_name || "—"}</td>
                    <td className="px-3 py-2 text-muted-foreground">{company.user_name || "—"}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{company.ai_recommended_count}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{company.review.accepted}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{company.review.unsure}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{company.review.rejected}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{company.review.added_by_client}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatPercent(company.review.acceptance_rate, locale)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{company.final.available ? `${company.final.ai_kept} / ${company.ai_recommended_count}` : "—"}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{company.final.available ? company.final.added_by_client : "—"}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </details>
    </section>
  )
}

function ProgressChip({ done, label }: { done: boolean; label: string }) {
  return (
    <span className={cn("inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs", done ? "border-emerald-300 bg-emerald-50 text-emerald-900" : "border-border text-muted-foreground")}>
      {done ? <CheckCircle2 className="h-3 w-3" aria-hidden="true" /> : <Circle className="h-3 w-3" aria-hidden="true" />}
      {label}
    </span>
  )
}

type DeletionKind = "report" | "account"

function UsersSection({
  users,
  currentUserId,
  locale,
  onDeleted,
}: {
  users: LaravelAdminUser[]
  currentUserId: number
  locale: Locale
  onDeleted: (kind: DeletionKind, displayName: string) => void
}) {
  const tr = (message: string) => ui(locale, message)
  const [pendingDeletion, setPendingDeletion] = useState<{ user: LaravelAdminUser; kind: DeletionKind } | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deletionError, setDeletionError] = useState<string | null>(null)
  const kind = pendingDeletion?.kind ?? "report"
  const pendingName = pendingDeletion
    ? kind === "report"
      ? pendingDeletion.user.characterization?.company_name || pendingDeletion.user.name || String(pendingDeletion.user.id)
      : pendingDeletion.user.name || pendingDeletion.user.characterization?.company_name || String(pendingDeletion.user.id)
    : ""

  const handleConfirmDeletion = async () => {
    if (!pendingDeletion) return
    setDeleting(true)
    setDeletionError(null)
    try {
      if (kind === "report") {
        await deleteLaravelAdminUserReport(pendingDeletion.user.id)
      } else {
        await deleteLaravelAdminUser(pendingDeletion.user.id)
      }
      setPendingDeletion(null)
      onDeleted(kind, pendingName)
    } catch (error) {
      const fallback = kind === "report" ? tr("No se ha podido borrar el informe.") : tr("No se ha podido borrar la cuenta.")
      setDeletionError(error instanceof LaravelApiError && error.message ? error.message : fallback)
    } finally {
      setDeleting(false)
    }
  }

  return (
    <section aria-labelledby="users-title" className="space-y-3">
      <h2 id="users-title" className="text-xl font-semibold text-foreground">{tr("Usuarios e informes")}</h2>
      <div className="overflow-x-auto rounded-lg border border-border bg-card">
        <table className="w-full text-sm">
          <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
            <tr>
              <th className="px-3 py-2 font-medium">{tr("Usuario")}</th>
              <th className="px-3 py-2 font-medium">{tr("Empresa")}</th>
              <th className="px-3 py-2 font-medium">{tr("Estado")}</th>
              <th className="px-3 py-2 font-medium">{tr("Progreso")}</th>
              <th className="px-3 py-2 font-medium">{tr("Informes")}</th>
            </tr>
          </thead>
          <tbody>
            {users.map((user) => (
              <UserRow
                key={user.id}
                user={user}
                isCurrentUser={user.id === currentUserId}
                locale={locale}
                onDelete={(requested) => {
                  setDeletionError(null)
                  setPendingDeletion({ user, kind: requested })
                }}
              />
            ))}
          </tbody>
        </table>
      </div>

      <Dialog open={pendingDeletion !== null} onOpenChange={(open) => (!open && !deleting ? setPendingDeletion(null) : null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <AlertTriangle className="h-5 w-5 text-amber-500" aria-hidden="true" />
              {formatUi(locale, kind === "report" ? "Borrar el informe de {0}" : "Borrar la cuenta de {0}", [pendingName])}
            </DialogTitle>
            <DialogDescription>
              {kind === "report"
                ? formatUi(locale, "Se borrarán la encuesta inicial, la propuesta de la IA y su revisión, el acta, los temas confirmados, las respuestas del paso 5, los documentos y todas las versiones del informe de {0}. La cuenta se conserva y podrá empezar el asistente de nuevo. Esta acción no se puede deshacer.", [pendingName])
                : formatUi(locale, "Se borrará la cuenta {0} con todo lo que introdujo: encuesta inicial, propuesta de la IA y su revisión, acta, temas confirmados, respuestas del paso 5, documentos y versiones del informe. La persona dejará de poder entrar y tendría que registrarse de nuevo. Esta acción no se puede deshacer.", [pendingName])}
            </DialogDescription>
          </DialogHeader>
          {deletionError ? (
            <p role="alert" className="text-sm text-destructive">{deletionError}</p>
          ) : null}
          <DialogFooter className="flex-row justify-end gap-2 sm:gap-0">
            <Button variant="ghost" disabled={deleting} onClick={() => setPendingDeletion(null)}>
              {tr("Cancelar")}
            </Button>
            <Button variant="destructive" disabled={deleting} onClick={handleConfirmDeletion}>
              <Trash2 className="h-4 w-4" aria-hidden="true" />
              {deleting ? tr("Borrando...") : kind === "report" ? tr("Borrar informe") : tr("Borrar usuario")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  )
}

function UserRow({
  user,
  isCurrentUser,
  locale,
  onDelete,
}: {
  user: LaravelAdminUser
  isCurrentUser: boolean
  locale: Locale
  onDelete: (kind: DeletionKind) => void
}) {
  const tr = (message: string) => ui(locale, message)
  const displayName = user.name || user.characterization?.company_name || String(user.id)
  const characterization = user.characterization
  const progress = user.progress
  const reportReady = progress.report?.workflow_complete === true
  const asUser = `as_user=${user.id}`

  return (
    <tr className="border-t border-border align-top">
      <td className="px-3 py-3">
        <p className="font-medium text-foreground">{displayName}</p>
        <div className="mt-1 flex flex-wrap gap-1">
          {user.is_admin ? <Badge variant="secondary">{tr("Administrador")}</Badge> : null}
          {isCurrentUser ? <Badge variant="outline">{tr("Tu cuenta")}</Badge> : null}
          {!user.email_verified ? <Badge variant="outline" className="border-amber-400 text-amber-900">{tr("Sin verificar")}</Badge> : null}
        </div>
      </td>
      <td className="px-3 py-3">
        {characterization ? (
          <>
            <p className="text-foreground">{characterization.company_name || "—"}</p>
            <p className="text-xs text-muted-foreground">{characterization.nace_code || ""}</p>
          </>
        ) : (
          <span className="text-muted-foreground">{tr("Sin encuesta inicial")}</span>
        )}
      </td>
      <td className="px-3 py-3">
        <p className="text-foreground">{characterizationStatusLabel(characterization?.status, locale)}</p>
        <p className="text-xs text-muted-foreground">{formatDate(characterization?.updated_at, locale)}</p>
      </td>
      <td className="px-3 py-3">
        <div className="flex flex-wrap gap-1">
          <ProgressChip done={progress.proposal_completed} label={tr("Propuesta")} />
          <ProgressChip done={progress.review_started} label={tr("Revisión")} />
          <ProgressChip done={progress.acta_registered} label={tr("Acta")} />
          <ProgressChip done={progress.confirmed} label={tr("Confirmación")} />
          <ProgressChip done={progress.datapoint_responses > 0} label={formatUi(locale, "{0} datos", [progress.datapoint_responses])} />
          <ProgressChip done={progress.snapshots > 0} label={formatUi(locale, "{0} versiones ({1} aprobadas)", [progress.snapshots, progress.approved_snapshots])} />
        </div>
      </td>
      <td className="px-3 py-3">
        {characterization ? (
          <div className="flex flex-col gap-1.5">
            <span className={cn("inline-flex items-center gap-1 text-xs", reportReady ? "text-emerald-800" : "text-muted-foreground")}>
              {reportReady ? <CheckCircle2 className="h-3 w-3" aria-hidden="true" /> : <AlertCircle className="h-3 w-3" aria-hidden="true" />}
              {reportReady ? tr("Informe listo") : tr("Informe incompleto")}
            </span>
            {reportReady ? (
              <>
                <a
                  className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                  href={laravelApiUrl(`/report/xhtml-ixbrl-candidate?inline=1&${asUser}`)}
                  target="_blank"
                  rel="noreferrer"
                  aria-label={formatUi(locale, "Abrir el informe de {0}", [displayName])}
                >
                  <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                  {tr("Ver informe")}
                </a>
                <a
                  className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                  href={laravelApiUrl(`/guided-report/docx?${asUser}`)}
                  aria-label={formatUi(locale, "Descargar el informe DOCX de {0}", [displayName])}
                >
                  <Download className="h-3.5 w-3.5" aria-hidden="true" />
                  {tr("Descargar DOCX")}
                </a>
              </>
            ) : (
              <span className="text-xs text-muted-foreground">{tr("El informe se puede abrir cuando la cuenta completa los seis pasos.")}</span>
            )}
            <a
              className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
              href={`/characterization/summary?format=pdf&${asUser}`}
              aria-label={formatUi(locale, "Descargar el resumen de la encuesta de {0}", [displayName])}
            >
              <FileText className="h-3.5 w-3.5" aria-hidden="true" />
              {tr("Resumen de la encuesta (PDF)")}
            </a>
            <button
              type="button"
              className="inline-flex items-center gap-1 text-sm text-destructive hover:underline"
              onClick={() => onDelete("report")}
              aria-label={formatUi(locale, "Borrar el informe de {0}", [displayName])}
            >
              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
              {tr("Borrar informe")}
            </button>
          </div>
        ) : (
          <span className="text-xs text-muted-foreground">—</span>
        )}
        {!isCurrentUser ? (
          <button
            type="button"
            className="mt-2 inline-flex items-center gap-1 text-sm text-destructive hover:underline"
            onClick={() => onDelete("account")}
            aria-label={formatUi(locale, "Borrar la cuenta de {0}", [displayName])}
          >
            <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
            {tr("Borrar usuario")}
          </button>
        ) : null}
      </td>
    </tr>
  )
}
