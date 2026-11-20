"use client"

import { XbrlIdentificationPanel, type IdentificationSubject } from "@/components/wizard/xbrl-identification-panel"
import { datapointIdentificationSubject } from "@/lib/xbrl-identification-state.mjs"

import { useStudyError } from "@/lib/use-study-error"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"
import { EuTaxonomyPanel } from "@/components/wizard/eu-taxonomy-panel"

import { ui, formatUi } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { useCallback, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { AlertCircle, CheckCircle2, Download, ExternalLink, FileText, RefreshCw, ShieldCheck } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { LocalizedDownload } from "@/components/localized-download"
import { Card, CardContent } from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Textarea } from "@/components/ui/textarea"
import {
  LaravelApiError,
  approveLaravelReportSnapshot,
  createLaravelReportSnapshot,
  getLaravelReportDraft,
  getLaravelReportReadiness,
  getLaravelReportSnapshots,
  getLaravelReportTaxonomyStatus,
  getLaravelReportingFacts,
  getLaravelEsrsDatapoints,
  getLaravelSession,
  laravelApiUrl,
  type LaravelReportDownload,
  type LaravelReportDraft,
  type LaravelReportReadiness,
  type LaravelReportSnapshot,
  type LaravelReportTaxonomyStatus,
  type LaravelReportingFactState,
} from "@/lib/laravel-api"
import {
  actionLabel,
  actionTarget,
  allSectionsReady,
  downloadLabel,
  endpointHref,
  formatPercent,
  latestReportSnapshot,
  materialThemeGroups,
  reportDownloadRows,
  sectionLabel,
  sectionNumber,
  snapshotDownloadBlockers,
  snapshotApprovalAllowed,
  snapshotStaleMessages,
  xhtmlPresentationAvailable,
  statusLabel,
  statusTone,
  visibleNextActions,
} from "@/lib/report-draft-state.mjs"

export function ReportDraftPanel() {

  const { locale, changing } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [xbrlRuntimeEnabled, setXbrlRuntimeEnabled] = useState(false)
  const [xbrlSubjects, setXbrlSubjects] = useState<{ locale: string; items: IdentificationSubject[] } | null>(null)
  const [xbrlCsrfToken, setXbrlCsrfToken] = useState<string>()
  const [euTaxonomyEnabled, setEuTaxonomyEnabled] = useState(false)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [storedReadiness, setReadiness] = useState<LaravelReportReadiness | null>(null)
  const [storedDraft, setDraft] = useState<LaravelReportDraft | null>(null)
  const [presentationLocale, setPresentationLocale] = useState<"es" | "en" | null>(null)
  const [presentationState, setPresentationState] = useState<"loading" | "ready" | "failed">("loading")
  const presentationReady = !changing && presentationLocale === locale && presentationState === "ready"
  const readiness = presentationReady ? storedReadiness : null
  const draft = presentationReady ? storedDraft : null
  const [facts, setFacts] = useState<LaravelReportingFactState | null>(null)
  const [taxonomyStatus, setTaxonomyStatus] = useState<LaravelReportTaxonomyStatus | null>(null)
  const [snapshots, setSnapshots] = useState<LaravelReportSnapshot[]>([])
  const [errorMessage, setErrorMessage] = useSystemMessage(null)
  useStudyError(6, errorMessage)
  const [operationMessage, setOperationMessage] = useSystemMessage(null)
  const [approveDialogOpen, setApproveDialogOpen] = useState(false)
  const [approvalDeclaration, setApprovalDeclaration] = useState("")
  const [approvalError, setApprovalError] = useSystemMessage(null)
  useStudyError(6, approvalError)
  const [busyAction, setBusyAction] = useState<string | null>(null)
  // «Generar XBRL»: prepara la versión del informe si hace falta, pide la aprobación y descarga el paquete.
  const [xbrlPending, setXbrlPending] = useState(false)
  const confirmedMaterialThemes = useMemo(
    () => materialThemeGroups(draft?.materiality.confirmed_topics ?? [], locale),
    [draft?.materiality.confirmed_topics, locale],
  )

  const refreshReport = useCallback(() => {
    setReloadCounter((current) => current + 1)
  }, [])

  useEffect(() => {
    window.addEventListener("focus", refreshReport)

    return () => {
      window.removeEventListener("focus", refreshReport)
    }
  }, [refreshReport])

  useEffect(() => {
    let mounted = true

    async function loadP10() {
      setLoadingInitial(true)
      setErrorMessage(null)

      try {
        const [sessionResponse, factsResponse, snapshotsResponse, taxonomyResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelReportingFacts(),
          getLaravelReportSnapshots(),
          getLaravelReportTaxonomyStatus(),
        ])

        if (!mounted) {
          return
        }
        setXbrlRuntimeEnabled(sessionResponse.data.xbrl_runtime_identification_enabled === true)
        setXbrlCsrfToken(sessionResponse.data.csrf_token)
        setFacts(factsResponse.data)
        setEuTaxonomyEnabled(sessionResponse.data.eu_taxonomy_eligibility_enabled === true)
        setSnapshots(snapshotsResponse.data)
        setTaxonomyStatus(taxonomyResponse.data)
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage(systemCopy("No se ha podido cargar el informe desde la plataforma."))
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP10()

    return () => {
      mounted = false
    }
  }, [reloadCounter, router])

  useEffect(() => {
    if (!xbrlRuntimeEnabled || changing) return
    let active = true
    getLaravelEsrsDatapoints().then(response => {
      if (!active || response.data?.locale !== locale) return
      const points = Object.values(response.data.blocks ?? {}).flatMap(block => block.datapoints ?? [])
      const items = (facts?.persisted_facts ?? []).filter(record => record.id).map(fact => {
        const point = points.find(item => item.id === fact.datapoint_id)
        return { ...(point ? datapointIdentificationSubject(point, locale) : { label: fact.datapoint_id, description: "", language: locale }),
          key: `fact:${fact.id}`, reporting_fact_id: fact.id, datapoint_id: fact.datapoint_id,
          value_type: fact.value_type, unit: fact.unit }
      })
      setXbrlSubjects({ locale, items })
    }).catch(() => { if (active) setXbrlSubjects(null) })
    return () => { active = false }
  }, [xbrlRuntimeEnabled, changing, locale, facts])

  useEffect(() => {
    if (changing) return
    let active = true
    setPresentationState("loading")
    Promise.all([getLaravelReportReadiness(), getLaravelReportDraft()]).then(([status, report]) => {
      if (!active) return
      if ((status.data !== null && status.data.locale !== locale) || (report.data !== null && report.data.locale !== locale)) {
        throw new Error("Report presentation locale mismatch")
      }
      setReadiness(status.data); setDraft(report.data); setPresentationLocale(locale); setPresentationState("ready")
    }).catch(() => { if (active) { setPresentationState("failed"); setErrorMessage(systemCopy("No se pudieron actualizar las etiquetas de idioma. Inténtalo de nuevo.")) } })
    return () => { active = false }
  }, [locale, changing, reloadCounter])

  const sectionRows = useMemo(() => Object.entries(readiness?.sections ?? {}), [readiness])
  const downloadRows = useMemo(
    () => reportDownloadRows(readiness, draft) as Array<[string, LaravelReportDownload]>,
    [draft, readiness],
  )
  const nextActionRows = useMemo(() => visibleNextActions(readiness) as string[], [readiness])
  const reportOnlyNextAction = allSectionsReady(readiness)
  // Completed answers that still have no value: the platform lists them as missing report content.
  const missingValueCount = Number((readiness?.sections?.report_content as { missing_datapoint_count?: number } | undefined)?.missing_datapoint_count ?? 0)
  const numericFactCount = (facts?.persisted_facts ?? []).filter(
    (fact) => fact.approval_status === "reviewed" && fact.applicability === "applicable" && ["number", "integer", "monetary"].includes(fact.value_type),
  ).length
  const latestSnapshot = latestReportSnapshot(snapshots) as LaravelReportSnapshot | null
  const snapshotBlockers = snapshotDownloadBlockers(latestSnapshot).map(tr)
  const approvedFreshSnapshot = snapshotBlockers.length === 0

  async function handleCreateSnapshot() {
    try {
      setBusyAction("create-snapshot")
      setOperationMessage(null)
      await createLaravelReportSnapshot()
      setOperationMessage(systemCopy("Versión del informe preparada."))
      refreshReport()
    } catch (error) {
      setOperationMessage(apiErrorMessage(error, tr("No se ha podido preparar la versión del informe."), locale))
    } finally {
      setBusyAction(null)
    }
  }

  const xbrlReady = readiness?.workflow_complete === true && readiness?.report_content_ready === true
    && readiness?.ixbrl_package_enabled === true && taxonomyStatus?.availability.state === "verified"

  function xbrlBlockers(): string[] {
    const blockers: string[] = []
    if (readiness?.workflow_complete !== true) blockers.push(tr("Completa los pasos anteriores: todos los datos normativos del paso 5 deben estar decididos."))
    if (readiness?.report_content_ready !== true) blockers.push(tr("Cada dato marcado como completado en el paso 5 necesita un valor; corrígelo allí."))
    if (readiness?.ixbrl_package_enabled !== true) blockers.push(tr("El paquete XBRL no está activado en esta instalación (IXBRL_PACKAGE_UI_ENABLED)."))
    if (taxonomyStatus && taxonomyStatus.availability.state !== "verified") blockers.push(tr("La taxonomía EFRAG no está disponible en el servidor: se descarga sola en el primer uso; si persiste, revisa la conexión del servidor o aprovisiónala a mano."))
    if (taxonomyStatus?.validator?.state === "missing") blockers.push(tr("El validador Arelle no está instalado en el servidor (se instala con las dependencias del servicio de IA)."))
    return blockers
  }

  // Reason codes of the XBRL endpoint translated into what the person can do about them.
  function xbrlFailureMessage(reason: string) {
    switch (reason) {
      case "xhtml_ixbrl_context_missing":
        return systemCopy("No se ha podido generar el documento XBRL: faltan el LEI o las fechas de inicio y fin del periodo. Complétalos en el paso 1 («Editar la encuesta inicial»), guarda y vuelve a preparar y aprobar la versión del informe.")
      case "xhtml_ixbrl_arelle_busy":
        return systemCopy("El validador está ocupado con otra validación. Espera un minuto y vuelve a intentarlo.")
      case "xhtml_ixbrl_arelle_unavailable":
        return systemCopy("No se ha podido generar el documento XBRL: el validador Arelle no está disponible en el servidor.")
      case "xhtml_ixbrl_arelle_validation_failed":
        return systemCopy("No se ha podido generar el documento XBRL: la validación técnica del documento ha fallado. Si es el primer intento tras instalar, espera un minuto a que termine la preparación del validador y repite.")
      case "xhtml_ixbrl_no_taggable_claims":
        return systemCopy("No se ha podido generar el documento XBRL: ningún dato completado es numérico. En el paso 5, marca al menos un dato con tipo numérico y unidad (por ejemplo, número de empleados o ingresos) y vuelve a aprobar la versión.")
      case "":
        return systemCopy("No se ha podido generar el documento XBRL.")
      default:
        return systemCopy("No se ha podido generar el documento XBRL. Motivo: {0}.", [reason])
    }
  }

  async function downloadXbrl() {
    try {
      setBusyAction("xbrl")
      setOperationMessage(systemCopy("Generando el documento XBRL y validándolo con Arelle. Puede tardar un minuto."))
      const response = await fetch(laravelApiUrl("/report/ixbrl-package"), {
        credentials: "include",
        headers: { Accept: "application/zip, application/json" },
      })
      if (!response.ok) {
        let reason = ""
        try { reason = String((await response.json())?.data?.reason_code ?? "") } catch {}
        setOperationMessage(xbrlFailureMessage(reason))
        return
      }
      const blob = await response.blob()
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement("a")
      anchor.href = url
      anchor.download = "informe-neis-ixbrl.zip"
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
      const assertions = response.headers.get("X-Taxonomy-Assertions")
      const counts = /^errors=(\d+); warnings=(\d+)$/.exec(assertions ?? "")
      setOperationMessage(counts
        ? systemCopy("Documento XBRL generado y validado. La taxonomía señala {0} datos esperados sin informar y {1} advertencias; no impiden la descarga.", [counts[1], counts[2]])
        : systemCopy("Documento XBRL generado y validado."))
    } catch {
      setOperationMessage(systemCopy("No se ha podido generar el documento XBRL."))
    } finally {
      setBusyAction(null)
    }
  }

  async function handleGenerateXbrl() {
    if (busyAction) return
    const blockers = xbrlBlockers()
    if (blockers.length > 0) {
      setOperationMessage(`${tr("Para generar el XBRL:")} ${blockers.join(" ")}`)
      return
    }
    if (approvedFreshSnapshot) {
      await downloadXbrl()
      return
    }
    setXbrlPending(true)
    if (!snapshotApprovalAllowed(latestSnapshot)) {
      await handleCreateSnapshot()
      return
    }
    setApproveDialogOpen(true)
  }

  useEffect(() => {
    if (!xbrlPending || busyAction) return
    if (approvedFreshSnapshot) {
      setXbrlPending(false)
      void downloadXbrl()
      return
    }
    if (snapshotApprovalAllowed(latestSnapshot) && latestSnapshot && !latestSnapshot.is_approved && !approveDialogOpen) {
      setApproveDialogOpen(true)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [xbrlPending, busyAction, approvedFreshSnapshot, latestSnapshot, approveDialogOpen])

  async function handleApproveSnapshot() {
    const declaration = approvalDeclaration.trim()

    if (!snapshotApprovalAllowed(latestSnapshot) || !latestSnapshot || declaration === "") {
      setApprovalError(systemCopy("Escribe una declaración de persona única antes de continuar."))

      return
    }

    try {
      setBusyAction("approve-snapshot")
      setApprovalError(null)
      await approveLaravelReportSnapshot(latestSnapshot.id, { single_person_declaration: declaration })
      setApproveDialogOpen(false)
      setApprovalDeclaration("")
      setOperationMessage(systemCopy("Versión del informe aprobada. Las descargas quedan habilitadas mientras siga vigente."))
      refreshReport()
    } catch (error) {
      setApprovalError(apiErrorMessage(error, tr("No se ha podido aprobar la versión del informe."), locale))
    } finally {
      setBusyAction(null)
    }
  }

  return (
    <div className="min-w-0 flex-1 space-y-6">
      <XbrlIdentificationPanel enabled={xbrlRuntimeEnabled} csrfToken={xbrlCsrfToken} subjects={!changing && xbrlSubjects?.locale === locale ? xbrlSubjects.items : []} />
      {euTaxonomyEnabled ? <EuTaxonomyPanel /> : null}
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">{tr("Informe NEIS")}</h1>
          <p className="mt-2 text-muted-foreground">
            {" "}{tr("Esto muestra qué asuntos materiales, datos normativos y evidencias están registrados, qué falta y qué descargas pueden generarse.")}{" "}</p>
        </div>
        <div className="flex flex-wrap gap-2 md:shrink-0 md:flex-nowrap">
          <Button type="button" onClick={handleGenerateXbrl} disabled={busyAction !== null || !presentationReady}>
            <Download className="h-4 w-4" />
            {" "}{busyAction === "xbrl" ? tr("Generando XBRL...") : tr("Generar XBRL")}{" "}</Button>
          <Button type="button" variant="outline" onClick={refreshReport}>
            <RefreshCw className="h-4 w-4" />
            {" "}{tr("Recargar")}{" "}</Button>
        </div>
      </div>

      {operationMessage ? (
        <div role="status" className="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
          {operationMessage}
        </div>
      ) : null}

      {errorMessage ? (
        <div className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {errorMessage}
        </div>
      ) : null}

      {!presentationReady ? (
        <Card><CardContent className="space-y-3 pt-6 text-sm text-muted-foreground">
          <p role="status">{presentationState === "failed" && !changing ? tr("No se pudieron actualizar las etiquetas de idioma. Inténtalo de nuevo.") : tr("Actualizando las etiquetas de idioma...")}</p>
          {reportDownloadRows(storedReadiness, storedDraft).map(([key, download]) => (
            <LocalizedDownload key={key} disabled type="button" variant="outline" href={endpointHref(download.endpoint, laravelApiUrl)}>{tr(downloadLabel(key))}</LocalizedDownload>
          ))}
          {approvedFreshSnapshot ? (
            <div className="flex flex-wrap gap-2">
              <LocalizedDownload disabled href={laravelApiUrl("/report/xhtml-ixbrl-candidate")}>{tr("Informe XBRL")}</LocalizedDownload>
              <LocalizedDownload disabled href={laravelApiUrl("/guided-report/docx")}>{tr("Informe DOCX")}</LocalizedDownload>
              <LocalizedDownload disabled href={laravelApiUrl("/guided-report/evidence-bundle")}>{tr("Paquete de evidencias")}</LocalizedDownload>
            </div>
          ) : null}
        </CardContent></Card>
      ) : loadingInitial ? (
        <Card>
          <CardContent className="pt-6 text-sm text-muted-foreground">{tr("Cargando el resumen...")}</CardContent>
        </Card>
      ) : !readiness || !draft ? (
        <Card>
          <CardContent className="space-y-4 pt-6">
            <div className="flex items-start gap-3">
              <AlertCircle className="mt-0.5 h-5 w-5 text-amber-600" />
              <div>
                <p className="font-medium text-foreground">{tr("No hay información suficiente para preparar el informe")}</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  {" "}{tr("Completa la caracterización y la materialidad antes de preparar el informe.")}{" "}</p>
              </div>
            </div>
            <Button type="button" asChild>
              <Link href="/wizard/step-1">{tr("Ir a la encuesta inicial (paso 1)")}</Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardContent className="space-y-3 pt-6">
              <h2 className="text-lg font-semibold text-foreground">{tr("Lo que está registrado")}</h2>
              <div className="space-y-2 text-sm text-foreground">
                <p>
                  {draft.materiality.is_confirmed && draft.materiality.confirmed_theme_count > 0
                    ? formatUi(locale, "Una lista de {0} temas materiales confirmados y justificados", [draft.materiality.confirmed_theme_count])
                    : tr("Pendiente: confirma tus temas materiales en el paso 4")}
                </p>
                <p>{tr("El inventario de")}{" "}{draft.datapoints.total_datapoint_count} {" "}{tr("datos que pide el estándar")}</p>
                <p>
                  {draft.datapoints.decided_count} {" "}{tr("de")}{" "}{draft.datapoints.total_datapoint_count} {" "}{tr("datos ya decididos")}{" "}</p>
                {draft.datapoints.orphaned_response_count && draft.datapoints.orphaned_response_count > 0 ? (
                  <p className="text-amber-700">
                    {draft.datapoints.orphaned_response_count} {" "}{tr("respuestas conservadas fuera de alcance")}{" "}</p>
                ) : null}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="grid gap-4 pt-6 md:grid-cols-4">
              <SummaryMetric label={tr("Estado del informe")} value={tr(statusLabel(readiness.status))} />
              <SummaryMetric label={tr("Datos decididos")} value={draft.datapoints.decided_count} />
              <SummaryMetric label={tr("Cobertura de datos")} value={formatPercent(draft.datapoints.completion_ratio)} />
              <SummaryMetric
                label={tr("Generación final")}
                value={tr(statusLabel(readiness.sections.final_report_generation?.status))}
              />
            </CardContent>
          </Card>

          {taxonomyStatus ? (
            <Card>
              <CardContent className="space-y-2 pt-6 text-sm">
                <h2 className="text-lg font-semibold text-foreground">{tr("Taxonomía NEIS externa")}</h2>
                <p className="text-foreground">
                  {tr("Taxonomía XBRL NEIS de EFRAG")} · {tr("versión")} {taxonomyStatus.taxonomy.version} · {tr("perfil")}{" "}
                  {taxonomyStatus.reporting_profile}
                </p>
                <Badge className={statusTone(taxonomyStatus.availability.state)}>
                  {tr(statusLabel(taxonomyStatus.availability.state))}
                </Badge>
                {taxonomyStatus.availability.reason_code ? (
                  <p className="text-muted-foreground">{tr("Estado:")} {tr(statusLabel(taxonomyStatus.availability.reason_code))}</p>
                ) : null}
              </CardContent>
            </Card>
          ) : null}


          {nextActionRows.length > 0 && !reportOnlyNextAction ? (
            <Card>
              <CardContent className="space-y-4 pt-6">
                <div>
                  <h2 className="text-lg font-semibold text-foreground">{tr("Siguientes acciones")}</h2>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {" "}{tr("La plataforma marca estas piezas como necesarias antes de cerrar el informe.")}{" "}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {nextActionRows.map((endpoint) => (
                    <Button key={endpoint} type="button" variant="outline" asChild>
                      <Link href={actionTarget(endpoint)}>{tr(actionLabel(endpoint))}</Link>
                    </Button>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : null}

          <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="min-w-0 space-y-6">
              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Estado por secciones")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Lectura directa del estado del informe; cada fila viene de la plataforma.")}{" "}</p>
                  </div>
                  <div className="overflow-hidden rounded-md border border-border">
                    {sectionRows.map(([key, section], index) => (
                      <div
                        key={key}
                        className={`grid gap-3 px-4 py-3 text-sm md:grid-cols-[1fr_140px_80px] md:items-center ${
                          index === 0 ? "" : "border-t border-border"
                        }`}
                      >
                        <div>
                          <p className="font-medium text-foreground">{tr(sectionLabel(key))}</p>
                          {section.endpoint ? <p className="mt-1 text-xs text-muted-foreground">{section.endpoint}</p> : null}
                        </div>
                        <Badge className={statusTone(section.status)}>{tr(statusLabel(section.status))}</Badge>
                        <p className="text-muted-foreground md:text-right">{sectionNumber(section)}</p>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Borrador renderizable")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Resumen de empresa, materialidad confirmada y cobertura de datos desde el borrador.")}{" "}</p>
                  </div>
                  <div className="grid gap-4 md:grid-cols-3">
                    <SummaryMetric label={tr("Empresa")} value={draft.company.name || tr("Sin nombre")} />
                    <SummaryMetric label={tr("Ejercicio")} value={draft.company.reporting_year ?? "-"} />
                    <SummaryMetric label="NACE" value={draft.company.nace_code || "-"} />
                    <SummaryMetric label={tr("Temas confirmados")} value={confirmedMaterialThemes.length} />
                    <SummaryMetric label={tr("Datos normativos totales")} value={draft.datapoints.total_datapoint_count} />
                    <SummaryMetric label={tr("Estado respuestas")} value={tr(statusLabel(draft.datapoints.response_status))} />
                  </div>
                  <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-foreground">{tr("Temas materiales confirmados")}</h3>
                    <div className="flex flex-wrap gap-2">
                      {confirmedMaterialThemes.length > 0 ? (
                        confirmedMaterialThemes.map((topic) => (
                          <Badge key={topic.esrs_code} variant="outline">
                            {topic.esrs_code} · {topic.label}
                          </Badge>
                        ))
                      ) : (
                        <span className="text-sm text-muted-foreground">{tr("Sin temas confirmados.")}</span>
                      )}
                    </div>
                  </div>
                  <div className="space-y-2">
                    <h3 className="text-sm font-semibold text-foreground">{tr("Bloques de datos normativos")}</h3>
                    {draft.datapoints.blocks.map((block) => (
                      <div key={block.key ?? block.title} className="rounded-md border border-border px-3 py-2 text-sm">
                        <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                          <p className="font-medium text-foreground">{block.title ?? block.key ?? tr("Bloque de datos normativos")}</p>
                          <p className="text-muted-foreground">
                            {block.decided_count}/{block.datapoint_count} {" "}{tr("decididos")}{" "}</p>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Datos del informe")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Las respuestas completadas en el paso 5 son el contenido del informe: no hay que volver a registrarlas ni revisarlas aquí. Si falta un valor, corrígelo en el paso 5.")}{" "}</p>
                  </div>
                  <div className="grid gap-4 md:grid-cols-3">
                    <SummaryMetric label={tr("Datos con valor listos para el informe")} value={facts?.claimable_fact_count ?? 0} />
                    <SummaryMetric label={tr("Datos completados sin valor")} value={missingValueCount} />
                    <SummaryMetric label={tr("Datos numéricos etiquetables en XBRL")} value={numericFactCount} />
                  </div>
                  {missingValueCount > 0 ? (
                    <Button type="button" variant="outline" asChild>
                      <Link href="/wizard/step-5">{tr("Corregir en el paso 5")}</Link>
                    </Button>
                  ) : null}
                </CardContent>
              </Card>
            </div>

            <div className="space-y-6">
              <Card>
                <CardContent className="space-y-4 pt-6">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Versión del informe y aprobación")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Las descargas del informe se habilitan solo con una versión vigente y aprobada; la versión congela las respuestas del paso 5 tal y como están ahora.")}{" "}</p>
                  </div>

                  <SnapshotSummary snapshot={latestSnapshot} />
                  {latestSnapshot && !snapshotApprovalAllowed(latestSnapshot) ? (
                    <div role="status" aria-live="polite" className="text-sm text-amber-700">
                      {snapshotStaleMessages(latestSnapshot).map((message: string) => <p key={message}>{tr(message)}</p>)}
                    </div>
                  ) : null}

                  <div className="flex flex-col gap-2">
                    <Button type="button" onClick={handleCreateSnapshot} disabled={busyAction === "create-snapshot"}>
                      <ShieldCheck className="h-4 w-4" />
                      {" "}{tr("Preparar versión del informe")}{" "}</Button>
                    {latestSnapshot && !latestSnapshot.is_approved ? (
                      <Button type="button" variant="outline" disabled={!snapshotApprovalAllowed(latestSnapshot)} onClick={() => setApproveDialogOpen(true)}>
                        <CheckCircle2 className="h-4 w-4" />
                        {" "}{tr("Aprobar versión del informe")}{" "}</Button>
                    ) : null}
                  </div>

                  <div className="rounded-md border border-border p-3">
                    <h3 className="text-sm font-semibold text-foreground">{tr("Salidas basadas en una versión aprobada")}</h3>
                    {approvedFreshSnapshot ? (
                      <div className="mt-3 space-y-2">
                        <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start" target="_blank" rel="noreferrer" href={laravelApiUrl("/report/xhtml-ixbrl-candidate?inline=1")}>
                            <ExternalLink className="h-4 w-4" />
                            {" "}{tr("Ver informe XBRL en el navegador")}{" "}</LocalizedDownload>
                        <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start" target="_blank" rel="noreferrer" href={laravelApiUrl("/report/xhtml-ixbrl-candidate")}>
                            <Download className="h-4 w-4" />
                            {" "}{tr("Descargar informe XBRL")}{" "}</LocalizedDownload>
                        <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start" target="_blank" rel="noreferrer" href={laravelApiUrl("/guided-report/docx")}>
                            <Download className="h-4 w-4" />
                            {" "}{tr("Informe DOCX")}{" "}</LocalizedDownload>
                        {readiness?.report_scope_integrity_enabled === true ? (
                          <LocalizedDownload disabled={!presentationReady || !xhtmlPresentationAvailable(readiness, latestSnapshot)} type="button" variant="outline" className="w-full justify-start" target="_blank" rel="noreferrer" href={laravelApiUrl("/report/xhtml")}>
                            <Download className="h-4 w-4" />
                            {tr("Descargar informe NEIS en XHTML")}
                          </LocalizedDownload>
                        ) : null}
                        {readiness?.ixbrl_package_enabled === true && readiness.workflow_complete && readiness.report_content_ready && taxonomyStatus?.availability.state === "verified" ? (
                          <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start whitespace-normal text-left h-auto" target="_blank" rel="noreferrer" href={laravelApiUrl("/report/ixbrl-package")}>
                            <Download className="h-4 w-4 shrink-0" />
                            {tr("Descargar XBRL (paquete iXBRL)")}
                          </LocalizedDownload>
                        ) : null}
                        <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" className="w-full justify-start" target="_blank" rel="noreferrer" href={laravelApiUrl("/guided-report/evidence-bundle")}>
                            <Download className="h-4 w-4" />
                            {" "}{tr("Paquete de evidencias")}{" "}</LocalizedDownload>
                      </div>
                    ) : (
                      <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {snapshotBlockers.map((blocker) => (
                          <li key={blocker}>{blocker}</li>
                        ))}
                      </ul>
                    )}
                  </div>
                </CardContent>
              </Card>

              <details className="group rounded-xl border border-border bg-card text-card-foreground shadow-sm">
                <summary className="cursor-pointer list-none px-6 py-4 text-sm text-muted-foreground">
                  {tr("Salidas técnicas del paquete de preparación (ocultas por defecto)")}
                </summary>
              <Card className="border-0 shadow-none">
                <CardContent className="space-y-4 pt-2">
                  <div>
                    <h2 className="text-lg font-semibold text-foreground">{tr("Paquete HTML de preparación")}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {" "}{tr("Disponibilidad del paquete antiguo de trabajo. No es un informe aprobado.")}{" "}</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {storedReadiness?.downloads?.report_package_html?.status === "ready" ? (
                      <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" size="sm" href={laravelApiUrl("/report/package")} target="_blank" rel="noreferrer">
                          <FileText className="h-4 w-4" />
                          {" "}{tr("Paquete HTML")}{" "}</LocalizedDownload>
                    ) : null}
                    <LocalizedDownload disabled={!presentationReady} type="button" variant="outline" size="sm" href={laravelApiUrl("/report/draft")} target="_blank" rel="noreferrer">
                        <FileText className="h-4 w-4" />
                        {" "}{tr("Borrador JSON")}{" "}</LocalizedDownload>
                  </div>
                  <div className="space-y-3">
                    {downloadRows.map(([key, download]) => (
                      <div key={key} className="rounded-md border border-border px-3 py-3">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-sm font-medium text-foreground">{tr(downloadLabel(key))}</p>
                            <p className="mt-1 text-xs text-muted-foreground">{download.content_type}</p>
                          </div>
                          <Badge className={statusTone(download.status)}>{tr(statusLabel(download.status))}</Badge>
                        </div>
                        <LocalizedDownload
                          variant="outline"
                          size="sm"
                          className="mt-3 w-full"
                          disabled={!presentationReady || download.status !== "ready"}
                          href={endpointHref(download.endpoint, laravelApiUrl)}
                        >
                          <Download className="h-4 w-4" />
                          {tr(download.status === "ready" ? "Abrir" : "Pendiente")}
                        </LocalizedDownload>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
              </details>

            </div>
          </div>
        </>
      )}

      <Dialog open={approveDialogOpen} onOpenChange={setApproveDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{tr("Aprobar versión del informe")}</DialogTitle>
            <DialogDescription>
              {" "}{tr("Declara que una única persona asume la preparación, la revisión y la aprobación de esta versión.")}{" "}</DialogDescription>
          </DialogHeader>
          <div>
            <label htmlFor="snapshot-approval-declaration" className="text-sm font-medium text-foreground">
              {" "}{tr("Declaración de persona única")}{" "}</label>
            <Textarea
              id="snapshot-approval-declaration"
              className="mt-1"
              value={approvalDeclaration}
              onChange={(event) => setApprovalDeclaration(event.target.value)}
            />
            {approvalError ? <p className="mt-2 text-sm text-destructive">{approvalError}</p> : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setApproveDialogOpen(false)}>
              {" "}{tr("Cancelar")}{" "}</Button>
            <Button type="button" onClick={handleApproveSnapshot} disabled={busyAction === "approve-snapshot"}>
              {" "}{tr("Aprobar versión del informe")}{" "}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function apiErrorMessage(error: unknown, fallback: string, locale: "es" | "en" = "es"): string {

  if (error instanceof LaravelApiError) {
    if (error.status === 409) {
      const payload = error.payload as { reasons?: string[]; data?: { reason_code?: string } } | null
      const reasons = payload?.reasons ?? (payload?.data?.reason_code ? [payload.data.reason_code] : [])
      if (reasons.some((reason) => ["scope_identity_missing", "ig3_corpus_changed", "ar16_dr_mapping_changed", "report_scope_changed", "scope_assets_unavailable"].includes(reason))) {
        return ui(locale, snapshotStaleMessages({ stale_reasons: reasons })[0])
      }
      return formatUi(locale, "{0} Hay un bloqueo de estado que debes resolver antes de continuar.", [fallback])
    }

    if (error.status === 422) {
      return formatUi(locale, "{0} Revisa los campos obligatorios y el formato del dato.", [fallback])
    }
  }

  return fallback
}

function SnapshotSummary({ snapshot }: { snapshot: LaravelReportSnapshot | null }) {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  if (!snapshot) {
    return (
      <div className="rounded-md border border-border p-3 text-sm text-muted-foreground">
        {" "}{tr("Aún no hay una versión del informe preparada.")}{" "}</div>
    )
  }

  return (
    <div className="rounded-md border border-border p-3 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <Badge className={snapshot.stale_state === "fresh" ? statusTone("ready") : statusTone("blocked")}>
          {snapshot.stale_state === "fresh" ? tr("Vigente") : tr("Obsoleta")}
        </Badge>
        <Badge className={snapshot.is_approved ? statusTone("ready") : statusTone("incomplete")}>
          {snapshot.is_approved ? tr("Aprobado") : tr("Sin aprobar")}
        </Badge>
      </div>
      <p className="mt-2 text-muted-foreground">{tr("Preparado:")}{" "}{formatDate(snapshot.created_at, locale)}</p>
      {snapshot.approval?.approved_at ? (
        <p className="mt-1 text-muted-foreground">{tr("Aprobado:")}{" "}{formatDate(snapshot.approval.approved_at, locale)}</p>
      ) : null}
    </div>
  )
}

function SummaryMetric({ label, value }: { label: string; value: number | string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase text-muted-foreground">{label}</p>
      <p className="mt-1 text-lg font-semibold text-foreground">{value}</p>
    </div>
  )
}

function formatDate(value: string | null, locale: "es" | "en" = "es"): string {
  if (!value) {
    return "-"
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat(locale === "en" ? "en-GB" : "es-ES", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date)
}
