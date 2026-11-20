"use client"

import { SectorComparison } from "@/components/wizard/sector-comparison"
import { useSectorComparison } from "@/lib/use-sector-comparison"

import { completeStudyStep } from "@/lib/usability-study.mjs"
import { useStudyError } from "@/lib/use-study-error"

import { useSystemMessage, systemCopy } from "@/lib/i18n/use-system-message"

import { ui, formatUi } from "@/lib/i18n/messages.mjs"

import { useLocale } from "@/components/locale-provider"
import { topicTitle, topicMatches } from "@/lib/materiality-confirmation-state.mjs"
import {
  allTreeKeys,
  buildTopicTree,
  countTopics,
  defaultExpandedKeys,
  filterTopicTree,
  mergeTopicCatalog,
  topicExamples,
} from "@/lib/materiality-topic-catalog.mjs"
import { PRODUCT_NAME } from "@/lib/brand.mjs"

import type React from "react"

import { useEffect, useMemo, useRef, useState } from "react"
import { useRouter } from "next/navigation"
import {
  AlertCircle,
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  HelpCircle,
  Loader2,
  Info,
  RefreshCw,
  Search,
  Sparkles,
  UserPlus,
  XCircle,
} from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip"
import { DocumentEvidencePanel } from "@/components/wizard/document-evidence-panel"
import { Term } from "@/components/wizard/term"
import { cn } from "@/lib/utils"
import {
  DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD,
  documentEvidenceFromProposal,
  evidenceNeedsDocumentReview,
  flattenTopicEvidence,
  topicDocumentProvenance,
  topicHasNegativeDocumentEvidence,
} from "@/lib/p6-document-evidence.mjs"
import {
  REVIEW_REASON_KEYS,
  initialTopicActions,
  addedTopicIdsFromProposal,
  aiTopicIdsFromProposal,
  baseProposalTopicIds,
  buildDraftReviewPayload,
  buildReviewPayload,
  missingReviewTopicIds,
  notesFromProposal,
  reasonsFromProposal,
  saveCompletionMatchesEditVersion,
} from "@/lib/materiality-proposal-review.mjs"
import {
  LaravelApiError,
  getLaravelCharacterization,
  getLaravelEsrsTopics,
  getLaravelMaterialityProposal,
  getLaravelSession,
  submitLaravelCharacterization,
  updateLaravelMaterialityProposalReview,
  type LaravelCharacterization,
  type LaravelDocumentEvidence,
  type LaravelEsrsTopic,
  type LaravelMaterialityProposal,
  type LaravelMaterialityProposalReviewPayload,
  type LaravelMaterialityTopic,
  type LaravelTopicAction,
} from "@/lib/laravel-api"

type TopicActions = Record<string, LaravelTopicAction>
type ActionReasons = Record<string, string[]>
type ActionNotes = Record<string, string>
type CatalogTopic = LaravelMaterialityTopic & Partial<LaravelEsrsTopic>

type TopicGroupNode = { key: string; label: string | null; topics: CatalogTopic[] }
type StandardNode = { key: string; code: string; label: string; groups: TopicGroupNode[]; topics: CatalogTopic[] }
type CategoryNode = { key: string; categoryKey: string; label: string; standards: StandardNode[]; topics: CatalogTopic[] }

const actionLabels: Record<LaravelTopicAction, string> = {
  accepted: "Aceptar",
  rejected: "Rechazar",
  unsure: "Duda",
}

const actionDescriptions: Record<LaravelTopicAction, string> = {
  accepted: "Mantener en la propuesta del paso 2",
  rejected: "Descartar de la propuesta del paso 2",
  unsure: "Revisar en doble importancia relativa",
}

const PREDICTION_REFRESH_SECONDS = 10

const reasonLabels: Record<string, string> = {
  sector_fit: "Encaje sectorial",
  not_relevant: "No relevante",
  threshold: "No supera el umbral de importancia",
  stakeholder_input: "Aportación de grupos de interés",
  needs_adm: "Revisar en el análisis de doble importancia relativa",
  other: "Otro",
}

function p6StatusLabel(status: string, locale: "es" | "en" = "es"): string {
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
    }[status] ?? tr("Estado pendiente de revisión")
  )
}

function selectedCountLabel(count: number, locale: "es" | "en"): string {
  return count === 1 ? ui(locale, "1 seleccionado") : formatUi(locale, "{0} seleccionados", [count])
}

function p5IsComplete(characterization: LaravelCharacterization | null): boolean {
  if (!characterization) {
    return false
  }

  const companyProfile = characterization.form_data.company_profile ?? {}
  const operations = characterization.form_data.operations ?? {}

  return Boolean(
    characterization.nace_code &&
      companyProfile.company_name &&
      companyProfile.headquarters_country &&
      companyProfile.reporting_year &&
      companyProfile.reporting_scope &&
      companyProfile.num_subsidiaries_countries != null &&
      companyProfile.stock_listed != null &&
      companyProfile.reporting_currency &&
      companyProfile.product_service_type &&
      Array.isArray(operations.regions) &&
      operations.regions.length > 0 &&
      Array.isArray(operations.value_chain) &&
      operations.value_chain.length > 0 &&
      operations.employee_count_range &&
      operations.revenue_range,
  )
}

function buildSubmitPayload(characterization: LaravelCharacterization) {
  // El backend asigna `entity_identifier_scheme` al guardar el LEI y lo devuelve en
  // `form_data`, pero rechaza (422) cualquier envío que lo incluya. Al reenviar el
  // perfil completo hay que quitarlo o la predicción nunca llega a dispararse.
  const companyProfile = { ...(characterization.form_data.company_profile ?? {}) }
  delete companyProfile.entity_identifier_scheme

  return {
    action: "submit" as const,
    step: "review" as const,
    nace_code: characterization.nace_code,
    esrs_topic_ids: characterization.esrs_topic_ids ?? [],
    form_data: {
      ...characterization.form_data,
      company_profile: companyProfile,
    },
  }
}

export function MaterialTopicsForm() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const router = useRouter()
  const [reloadCounter, setReloadCounter] = useState(0)
  const [loadingInitial, setLoadingInitial] = useState(true)
  const [submittingPrediction, setSubmittingPrediction] = useState(false)
  const [savingReview, setSavingReview] = useState(false)
  const [isInfoModalOpen, setIsInfoModalOpen] = useState(false)
  const [isEditMode, setIsEditMode] = useState(false)
  const [showEditConfirmDialog, setShowEditConfirmDialog] = useState(false)
  const [expandedTopics, setExpandedTopics] = useState<number[]>([])
  const [expandedKeys, setExpandedKeys] = useState<Set<string>>(() => new Set())
  const [showOnlySelected, setShowOnlySelected] = useState(false)
  const [searchQuery, setSearchQuery] = useState("")
  const [csrfToken, setCsrfToken] = useState<string>()
  const [characterization, setCharacterization] = useState<LaravelCharacterization | null>(null)
  const [proposal, setProposal] = useState<LaravelMaterialityProposal | null>(null)
  const [catalog, setCatalog] = useState<LaravelEsrsTopic[]>([])
  const [catalogUnavailable, setCatalogUnavailable] = useState(false)
  const sectorComparison = useSectorComparison(proposal)
  const [topicActions, setTopicActions] = useState<TopicActions>({})
  const [actionReasons, setActionReasons] = useState<ActionReasons>({})
  const [actionNotes, setActionNotes] = useState<ActionNotes>({})
  const [addedTopicIds, setAddedTopicIds] = useState<number[]>([])
  const [errorMessage, setErrorMessage] = useSystemMessage(null)
  useStudyError(2, errorMessage)
  const [saveMessage, setSaveMessage] = useSystemMessage(null)
  const reviewEditVersion = useRef(0)

  useEffect(() => {
    let mounted = true

    async function loadP6() {
      setLoadingInitial(true)
      setErrorMessage(null)

      try {
        const [sessionResponse, characterizationResponse, proposalResponse, catalogResponse] = await Promise.all([
          getLaravelSession(),
          getLaravelCharacterization(),
          getLaravelMaterialityProposal(),
          // The full catalogue lets the user add topics the AI did not propose.
          // It is optional context: without it the page still reviews the proposal.
          Promise.resolve()
            .then(() => getLaravelEsrsTopics({ per_page: 200 }))
            .catch((error) => {
              if (error instanceof LaravelApiError && error.status === 401) throw error
              return null
            }),
        ])

        if (!mounted) {
          return
        }

        setCsrfToken(sessionResponse.data.csrf_token)
        setCharacterization(characterizationResponse.data)
        setProposal(proposalResponse.data)
        setCatalog(catalogResponse?.data ?? [])
        setCatalogUnavailable(catalogResponse == null)

        if (proposalResponse.data) {
          setTopicActions(initialTopicActions(proposalResponse.data))
          setActionReasons(reasonsFromProposal(proposalResponse.data))
          setActionNotes(notesFromProposal(proposalResponse.data))
          setAddedTopicIds(addedTopicIdsFromProposal(proposalResponse.data))
          setIsEditMode(proposalResponse.data.review.status !== "reviewed")
        }
      } catch (error) {
        if (error instanceof LaravelApiError && error.status === 401) {
          router.replace("/login")

          return
        }

        setErrorMessage(systemCopy("No se ha podido cargar la propuesta del paso 2 desde la plataforma."))
      } finally {
        if (mounted) {
          setLoadingInitial(false)
        }
      }
    }

    loadP6()

    return () => {
      mounted = false
    }
  }, [reloadCounter, router])

  // Base proposal (AI candidates or stored ids) vs. topics the user added on top of it.
  const baseTopicIds = useMemo(() => (proposal ? baseProposalTopicIds(proposal) : []), [proposal])
  const baseTopicIdSet = useMemo(() => new Set(baseTopicIds), [baseTopicIds])
  const aiTopicIdSet = useMemo(() => new Set(proposal ? aiTopicIdsFromProposal(proposal) : []), [proposal])
  const addedTopicIdSet = useMemo(() => new Set(addedTopicIds), [addedTopicIds])

  const allTopics = useMemo<CatalogTopic[]>(
    () => mergeTopicCatalog(catalog, proposal?.proposal_topics ?? []) as CatalogTopic[],
    [catalog, proposal],
  )
  const topicTree = useMemo(() => buildTopicTree(allTopics, locale) as CategoryNode[], [allTopics, locale])

  const isSelected = (topic: CatalogTopic) =>
    baseTopicIdSet.has(topic.id) ? topicActions[String(topic.id)] === "accepted" : addedTopicIdSet.has(topic.id)

  const visibleTree = useMemo(() => {
    const query = searchQuery.trim()

    return filterTopicTree(topicTree, (topic: CatalogTopic) => {
      if (query && !topicMatches(topic, query, locale)) return false
      if (showOnlySelected && !isSelected(topic)) return false

      return true
    }) as CategoryNode[]
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [topicTree, searchQuery, showOnlySelected, locale, topicActions, addedTopicIdSet, baseTopicIdSet])

  // Open the branches holding proposed topics once the tree is known.
  const initializedTreeFor = useRef<string | null>(null)
  useEffect(() => {
    const signature = `${proposal?.characterization_id ?? "none"}:${allTopics.length}:${locale}`
    if (topicTree.length === 0 || initializedTreeFor.current === signature) return
    initializedTreeFor.current = signature
    setExpandedKeys(new Set(defaultExpandedKeys(topicTree, (topic: CatalogTopic) => baseTopicIdSet.has(topic.id) || addedTopicIdSet.has(topic.id))))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [topicTree, proposal?.characterization_id, allTopics.length, locale])

  const expandAll = () => {
    setExpandedKeys(new Set(allTreeKeys(topicTree)))
    setExpandedTopics([...baseTopicIds, ...addedTopicIds])
  }
  const collapseAll = () => {
    setExpandedKeys(new Set())
    setExpandedTopics([])
  }

  const filtering = searchQuery.trim() !== "" || showOnlySelected
  const isExpanded = (key: string) => filtering || expandedKeys.has(key)
  const setExpanded = (key: string, open: boolean) =>
    setExpandedKeys((current) => {
      const next = new Set(current)
      if (open) next.add(key)
      else next.delete(key)
      return next
    })

  const missingTopicIds = proposal ? missingReviewTopicIds(baseTopicIds, topicActions) : []
  const reviewedCount = Math.max(baseTopicIds.length - missingTopicIds.length, 0)
  const totalTopics = baseTopicIds.length
  const readOnlyMode = proposal?.review.status === "reviewed" && !isEditMode
  const reviewReady = totalTopics + addedTopicIds.length > 0 && missingTopicIds.length === 0
  const selectedTotal = countTopics(allTopics, isSelected)
  const p5Complete = p5IsComplete(characterization)
  // Feature detection: the platform only sends the document_evidence block when
  // document extraction is enabled. When absent, render nothing document-related.
  const documentEvidence: LaravelDocumentEvidence | null = documentEvidenceFromProposal(proposal)

  const predictionPending = characterization != null && ["submitted", "processing", "waiting"].includes(characterization.status)

  // Poll only while waiting, before any review can be edited. Clean up on navigation.
  useEffect(() => {
    if (!predictionPending || loadingInitial) return
    const timer = setTimeout(() => setReloadCounter((current) => current + 1), PREDICTION_REFRESH_SECONDS * 1000)
    return () => clearTimeout(timer)
  }, [predictionPending, loadingInitial])

  const reload = () => setReloadCounter((current) => current + 1)

  const touch = () => {
    reviewEditVersion.current += 1
    setErrorMessage(null)
    setSaveMessage(null)
  }

  const setTopicAction = (topicId: number, action: LaravelTopicAction) => {
    touch()
    setTopicActions((current) => ({ ...current, [String(topicId)]: action }))
  }

  const setTopicNote = (topicId: number, note: string) => {
    touch()
    setActionNotes((current) => ({ ...current, [String(topicId)]: note }))
  }

  const setTopicReason = (topicId: number, reasonKey: string, checked: boolean) => {
    touch()
    setActionReasons((current) => {
      const topicKey = String(topicId)
      const selectedReasons = new Set(current[topicKey] ?? [])

      if (checked) {
        selectedReasons.add(reasonKey)
      } else {
        selectedReasons.delete(reasonKey)
      }

      const next = { ...current }
      const reasonList = Array.from(selectedReasons)

      if (reasonList.length > 0) {
        next[topicKey] = reasonList
      } else {
        delete next[topicKey]
      }

      return next
    })
  }

  // Ticking a topic outside the proposal adds it as accepted; unticking removes it and its notes.
  const setTopicAdded = (topicId: number, added: boolean) => {
    touch()
    const topicKey = String(topicId)

    if (added) {
      setAddedTopicIds((current) => (current.includes(topicId) ? current : [...current, topicId]))
      setTopicActions((current) => ({ ...current, [topicKey]: "accepted" }))

      return
    }

    setAddedTopicIds((current) => current.filter((id) => id !== topicId))
    setTopicActions((current) => {
      const next = { ...current }
      delete next[topicKey]
      return next
    })
    setActionReasons((current) => {
      const next = { ...current }
      delete next[topicKey]
      return next
    })
    setActionNotes((current) => {
      const next = { ...current }
      delete next[topicKey]
      return next
    })
    setExpandedTopics((current) => current.filter((id) => id !== topicId))
  }

  const handleSubmitPrediction = async () => {
    if (!characterization || !p5Complete) {
      setErrorMessage(systemCopy("Completa primero la encuesta inicial antes de generar la propuesta del paso 2."))

      return
    }

    setSubmittingPrediction(true)
    setErrorMessage(null)

    try {
      await submitLaravelCharacterization(buildSubmitPayload(characterization), { csrfToken })
      reload()
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      setErrorMessage(systemCopy("La plataforma no ha podido enviar la caracterización a predicción."))
    } finally {
      setSubmittingPrediction(false)
    }
  }

  const handleSaveReview = async (continueAfterSave: boolean) => {
    if (!proposal || totalTopics + addedTopicIds.length === 0) {
      return
    }

    let reviewPayloadData
    if (continueAfterSave) {
      const reviewPayload = buildReviewPayload({
        proposalTopicIds: baseTopicIds,
        addedTopicIds,
        topicActions,
        actionReasons,
        actionNotes,
      })

      if (!reviewPayload.ok) {
        setErrorMessage(systemCopy("Marca una decisión explícita en {0} tema(s) antes de continuar.",[reviewPayload.missingTopicIds.length]))

        return
      }
      reviewPayloadData = reviewPayload.payload
    } else {
      reviewPayloadData = buildDraftReviewPayload({
        proposalTopicIds: baseTopicIds,
        addedTopicIds,
        topicActions,
        actionReasons,
        actionNotes,
      })
      if (Object.keys(reviewPayloadData.topic_actions).length === 0) {
        setErrorMessage(systemCopy("Marca al menos una decisión antes de guardar el borrador."))

        return
      }
    }

    setSavingReview(true)
    setErrorMessage(null)
    setSaveMessage(null)
    const savedEditVersion = reviewEditVersion.current

    try {
      const payload = {
        ...reviewPayloadData,
        expected_revision: proposal.review.revision,
      } as LaravelMaterialityProposalReviewPayload
      await updateLaravelMaterialityProposalReview(
        payload,
        { csrfToken },
      )
      if (!saveCompletionMatchesEditVersion(savedEditVersion, reviewEditVersion.current)) {
        setSaveMessage(systemCopy("Se guardó la versión anterior. Conservamos tus cambios más recientes para el siguiente guardado."))

        return
      }
      if (continueAfterSave) {
        completeStudyStep(2)
        router.push("/wizard/step-3")
        router.refresh()
      } else {
        setSaveMessage(systemCopy("Borrador guardado."))
        reload()
      }
    } catch (error) {
      if (error instanceof LaravelApiError && error.status === 401) {
        router.replace("/login")

        return
      }

      if (error instanceof LaravelApiError && error.status === 409) {
        setErrorMessage(systemCopy("La revisión ha cambiado en otra pestaña. Actualiza el paso antes de volver a guardar."))

        return
      }

      setErrorMessage(systemCopy("La plataforma no ha podido guardar la revisión del paso 2."))
    } finally {
      setSavingReview(false)
    }
  }

  return (
    <TooltipProvider>
      <div className="min-w-0 flex-1">
        <div className="mb-2 flex items-start justify-between">
          <h1 className="text-2xl font-semibold text-foreground">{tr("Revisión de temas materiales")}</h1>
          <button
            type="button"
            aria-label={tr("Abrir información sobre la revisión de temas materiales")}
            onClick={() => setIsInfoModalOpen(true)}
            className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <Info className="h-4 w-4" />
            {" "}{tr("Info")}{" "}</button>
        </div>

        <p className="mb-6 text-muted-foreground">
          {" "}{tr("Revisa la propuesta de")}{" "}<Term k="materialidad">{tr("temas materiales")}</Term> {" "}{tr("generada desde la encuesta inicial y deja trazada tu decisión por cada tema.")}{" "}</p>

        {errorMessage ? (
          <div role="alert" className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
            {errorMessage}
          </div>
        ) : null}
        {saveMessage ? (
          <div role="status" className="mb-4 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {saveMessage}
          </div>
        ) : null}

        {!loadingInitial && characterization?.status === "completed" && proposal && !Array.isArray(proposal.ai.feature_metadata?.derived_fields)
          && (proposal.ai.feature_metadata?.derived_fields.encoding_coverage as { status?: string } | undefined)?.status === "degraded" ? (
          <div role="status" className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {tr("El modelo no cubre todos los datos de tu perfil. La propuesta puede ser menos representativa; revisa también los temas no propuestos antes de confirmar la doble importancia relativa.")}
          </div>
        ) : null}

        {loadingInitial ? (
          <div className="rounded-lg border border-border p-6 text-sm text-muted-foreground">
            {" "}{tr("Cargando propuesta del paso 2...")}{" "}</div>
        ) : !characterization ? (
          <StatePanel
            icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
            title={tr("No hay encuesta inicial")}
            description={tr("Vuelve al paso 1 para crear la encuesta inicial que alimenta la propuesta del paso 2.")}
            action={
              <Button type="button" onClick={() => router.push("/wizard/step-1")}>
                {" "}{tr("Volver a la encuesta inicial")}{" "}</Button>
            }
          />
        ) : characterization.status !== "completed" ? (
          <StatePanel
            icon={predictionPending ? <Loader2 className="h-5 w-5 animate-spin motion-reduce:animate-none text-primary" aria-hidden="true" /> : <AlertCircle className="h-5 w-5 text-primary" />}
            title={p6StatusLabel(characterization.status, locale)}
            description={
              characterization.status === "draft"
                ? tr("La encuesta inicial está guardada, pero todavía no se ha enviado a predicción.")
                : predictionPending
                  ? formatUi(locale, "Estamos preparando la propuesta de temas materiales. Puede tardar unos minutos. Comprobamos el estado automáticamente cada {0} segundos; también puedes pulsar Actualizar.", [PREDICTION_REFRESH_SECONDS])
                  : tr("La propuesta no se ha completado. Puedes volver a generar la propuesta.")
            }
            progress={predictionPending ? <PredictionProgressIndicator /> : null}
            action={
              <div className="flex flex-wrap gap-2">
                {characterization.status === "draft" || characterization.status === "failed" || characterization.status === "timed_out" ? (
                  <Button type="button" onClick={handleSubmitPrediction} disabled={!p5Complete || submittingPrediction}>
                    {submittingPrediction ? tr("Generando...") : tr("Generar propuesta con inteligencia artificial")}
                  </Button>
                ) : null}
                <Button type="button" variant="outline" onClick={reload}>
                  <RefreshCw className="h-4 w-4" />
                  {" "}{tr("Actualizar")}{" "}</Button>
              </div>
            }
          />
        ) : !proposal || (proposal.proposal_topics.length === 0 && allTopics.length === 0) ? (
          <StatePanel
            icon={<AlertCircle className="h-5 w-5 text-amber-600" />}
            title={tr("Propuesta del paso 2 vacía")}
            description={tr("La plataforma no ha devuelto temas propuestos para revisar.")}
            action={
              <Button type="button" variant="outline" onClick={reload}>
                <RefreshCw className="h-4 w-4" />
                {" "}{tr("Actualizar")}{" "}</Button>
            }
          />
        ) : (
          <>
            {documentEvidence ? (
              <DocumentEvidencePanel
                documentEvidence={documentEvidence}
                csrfToken={csrfToken}
                onDocumentsChanged={reload}
              />
            ) : null}

            {proposal.ai.review_required_prediction_keys.length > 0 ? (
              <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <div className="mb-2 flex items-center gap-2 font-medium">
                  <AlertTriangle className="h-4 w-4" />
                  {" "}{tr("Claves de predicción pendientes de revisión manual")}{" "}</div>
                <div className="flex flex-wrap gap-2">
                  {proposal.ai.review_required_prediction_keys.map((key) => (
                    <Badge key={key} variant="outline" className="border-amber-400 bg-white text-amber-900">
                      {key}
                    </Badge>
                  ))}
                </div>
              </div>
            ) : null}

            {catalogUnavailable ? (
              <div role="status" className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {tr("No se ha podido cargar el catálogo completo de temas; se muestran solo los propuestos.")}
              </div>
            ) : null}

            <div className="mb-5 flex flex-col gap-3 rounded-lg border border-border p-4 sm:flex-row sm:items-start sm:justify-between">
              <div className="space-y-2">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={proposal.source === "ai_prediction" ? "default" : "secondary"}>
                    {proposal.source === "ai_prediction" ? tr("IA") : tr("Temas guardados")}
                  </Badge>
                  <Badge variant="outline">{p6StatusLabel(proposal.status, locale)}</Badge>
                  <span className="text-sm text-muted-foreground">
                    {reviewedCount}/{totalTopics} {" "}{tr("temas con acción")}{" "}</span>
                  <span className="text-sm font-medium text-foreground">{formatUi(locale, "{0} seleccionados en total", [selectedTotal])}</span>
                </div>
                <p className="text-sm text-muted-foreground">
                  {proposal.source === "ai_prediction"
                    ? formatUi(locale, "La IA ha recomendado {0} temas. Marca o desmarca los que consideres y añade cualquier otro tema del catálogo.", [totalTopics])
                    : formatUi(locale, "{0} temas para revisar. Marca o desmarca los que consideres y añade cualquier otro tema del catálogo.", [totalTopics])}
                  {addedTopicIds.length > 0 ? (
                    <>
                      {" "}
                      {addedTopicIds.length === 1
                        ? tr("Has añadido 1 tema que la IA no proponía.")
                        : formatUi(locale, "Has añadido {0} temas que la IA no proponía.", [addedTopicIds.length])}
                    </>
                  ) : null}
                </p>
                <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                  <span className="inline-flex items-center gap-1.5">
                    <RecommendedBadge locale={locale} />
                    {tr("Recomendado por la IA")}
                  </span>
                  <span className="inline-flex items-center gap-1.5">
                    <AddedBadge locale={locale} />
                  </span>
                </div>
              </div>
              <Button type="button" variant="outline" onClick={reload}>
                <RefreshCw className="h-4 w-4" />
                {" "}{tr("Actualizar")}{" "}</Button>
            </div>

            <div className="mb-6 flex flex-wrap items-center gap-3">
              <div className="relative max-w-md flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  aria-label={tr("Buscar tema NEIS")}
                  placeholder={tr("Buscar tema NEIS...")}
                  value={searchQuery}
                  onChange={(event) => setSearchQuery(event.target.value)}
                  className="pl-9"
                />
              </div>
              <label className="flex items-center gap-2 text-sm text-foreground">
                <Checkbox checked={showOnlySelected} onCheckedChange={(checked) => setShowOnlySelected(checked === true)} />
                <span>{tr("Mostrar solo temas seleccionados")}</span>
              </label>
              <div className="flex gap-2">
                <Button type="button" variant="outline" size="sm" onClick={expandAll}>{tr("Expandir todo")}</Button>
                <Button type="button" variant="ghost" size="sm" onClick={collapseAll}>{tr("Colapsar todo")}</Button>
              </div>
            </div>

            {visibleTree.length === 0 ? (
              <p className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">{tr("Ningún tema coincide con el filtro.")}</p>
            ) : null}

            <div className="@container space-y-4">
              {visibleTree.map((category) => (
                <Collapsible key={category.key} open={isExpanded(category.key)} onOpenChange={(open) => setExpanded(category.key, open)} className="rounded-lg border border-border bg-card">
                  <CollapsibleTrigger className="group flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                    <span className="flex items-center gap-3">
                      <CategoryIcon categoryKey={category.categoryKey} />
                      <span className="text-base font-semibold text-foreground">{tr(category.label)}</span>
                      <span className="text-sm text-muted-foreground">({selectedCountLabel(countTopics(category.topics, isSelected), locale)})</span>
                    </span>
                    <ChevronDown className="h-5 w-5 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
                  </CollapsibleTrigger>
                  <CollapsibleContent className="space-y-2 border-t border-border px-3 py-3">
                    {category.standards.map((standard) => (
                      <Collapsible key={standard.key} open={isExpanded(standard.key)} onOpenChange={(open) => setExpanded(standard.key, open)} className="rounded-md border border-border/70">
                        <CollapsibleTrigger className="group flex w-full items-center justify-between gap-3 px-3 py-2 text-left">
                          <span className="flex min-w-0 items-center gap-2">
                            <ChevronDown className="h-4 w-4 shrink-0 -rotate-90 text-muted-foreground transition-transform group-data-[state=open]:rotate-0" aria-hidden="true" />
                            <Badge variant="outline" className="shrink-0 bg-background">{standard.code}</Badge>
                            <span className="truncate text-sm font-semibold text-foreground">{standard.label}</span>
                            <span className="shrink-0 text-xs text-muted-foreground">({selectedCountLabel(countTopics(standard.topics, isSelected), locale)})</span>
                          </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="space-y-2 px-3 pb-3">
                          {standard.groups.map((group) =>
                            group.label ? (
                              <Collapsible key={group.key} open={isExpanded(group.key)} onOpenChange={(open) => setExpanded(group.key, open)} className="rounded-md bg-muted/30">
                                <CollapsibleTrigger className="group flex w-full items-center gap-2 px-3 py-2 text-left">
                                  <ChevronDown className="h-4 w-4 shrink-0 -rotate-90 text-muted-foreground transition-transform group-data-[state=open]:rotate-0" aria-hidden="true" />
                                  <span className="text-sm font-medium text-foreground">{group.label}</span>
                                  <span className="text-xs text-muted-foreground">({selectedCountLabel(countTopics(group.topics, isSelected), locale)})</span>
                                </CollapsibleTrigger>
                                <CollapsibleContent className="space-y-2 px-3 pb-3">
                                  {group.topics.map((topic) => renderTopic(topic))}
                                </CollapsibleContent>
                              </Collapsible>
                            ) : (
                              <div key={group.key} className="space-y-2">
                                {group.topics.map((topic) => renderTopic(topic))}
                              </div>
                            ),
                          )}
                        </CollapsibleContent>
                      </Collapsible>
                    ))}
                  </CollapsibleContent>
                </Collapsible>
              ))}
            </div>

            <div className="mt-8 flex justify-end">
              {readOnlyMode ? (
                <div className="flex items-center gap-3">
                  <Badge variant="secondary" className="gap-1">
                    <CheckCircle2 className="h-3 w-3" />
                    {" "}{tr("Revisión guardada")}{" "}</Badge>
                  <Button type="button" variant="outline" onClick={() => setShowEditConfirmDialog(true)}>
                    {" "}{tr("Editar este paso")}{" "}</Button>
                  <Button type="button" onClick={() => router.push("/wizard/step-3")}>
                    {" "}{tr("Continuar al paso 3")}{" "}</Button>
                </div>
              ) : (
                <div className="flex flex-col items-end gap-2">
                  {!reviewReady ? (
                    <p className="text-sm text-muted-foreground">{tr("Decide sobre cada tema recomendado antes de continuar.")}</p>
                  ) : null}
                  <div className="flex flex-wrap justify-end gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => handleSaveReview(false)}
                      disabled={savingReview || (reviewedCount === 0 && addedTopicIds.length === 0)}
                    >
                      {" "}{tr("Guardar borrador")}{" "}</Button>
                    <Button type="button" onClick={() => handleSaveReview(true)} disabled={savingReview || !reviewReady}>
                      {savingReview ? tr("Guardando...") : tr("Guardar y continuar")}
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </>
        )}

        <Dialog open={isInfoModalOpen} onOpenChange={setIsInfoModalOpen}>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-3">
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-medium text-primary-foreground">
                  2
                </div>
                {" "}{tr("Revisión de temas materiales")}{" "}</DialogTitle>
            </DialogHeader>
            <div className="space-y-4 text-sm text-muted-foreground">
              <p>
                {" "}{tr("Estos son los temas materiales que hemos identificado para tu empresa. Marca o desmarca los temas si lo consideras necesario y añade cualquier otro del catálogo completo.")}{" "}</p>
              <p>
                {" "}{tr("La propuesta del paso 2 procede del estado de la encuesta inicial en la plataforma. Si está completada, puedes confirmar tema por tema antes de pasar a la doble importancia relativa.")}{" "}</p>
              <div className="rounded-lg bg-muted/50 p-3">
                <h4 className="mb-1 font-medium text-foreground">{tr("Nota importante")}</h4>
                <p>
                  {" "}{tr("Esta revisión no sustituye a la confirmación final del paso 4; deja una trazabilidad previa para el análisis de doble importancia relativa.")}{" "}</p>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog open={showEditConfirmDialog} onOpenChange={setShowEditConfirmDialog}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <AlertTriangle className="h-5 w-5 text-amber-500" />
                {" "}{tr("Editar este paso")}{" "}</DialogTitle>
              <DialogDescription>
                {" "}{tr("Cambiar la revisión del paso 2 puede afectar a los pasos posteriores que dependan de esta propuesta.")}{" "}</DialogDescription>
            </DialogHeader>
            <DialogFooter className="flex-row justify-end gap-2 sm:gap-0">
              <Button variant="ghost" onClick={() => setShowEditConfirmDialog(false)}>
                {" "}{tr("Cancelar")}{" "}</Button>
              <Button
                onClick={() => {
                  setShowEditConfirmDialog(false)
                  setIsEditMode(true)
                }}
              >
                {" "}{tr("Editar la revisión")}{" "}</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </TooltipProvider>
  )

  function renderTopic(topic: CatalogTopic) {
    const isBase = baseTopicIdSet.has(topic.id)
    const isAdded = addedTopicIdSet.has(topic.id)

    return (
      <TopicReviewCard
        key={topic.id}
        locale={locale}
        topic={topic}
        isBase={isBase}
        isRecommended={aiTopicIdSet.has(topic.id)}
        isAdded={isAdded}
        expanded={expandedTopics.includes(topic.id)}
        onExpandedChange={(open) => setExpandedTopics((current) => (open ? [...current, topic.id] : current.filter((id) => id !== topic.id)))}
        documentEvidence={documentEvidence}
        action={topicActions[String(topic.id)]}
        reasons={actionReasons[String(topic.id)] ?? []}
        note={actionNotes[String(topic.id)] ?? ""}
        disabled={readOnlyMode || savingReview}
        onActionChange={(action) => setTopicAction(topic.id, action)}
        onAddedChange={(added) => setTopicAdded(topic.id, added)}
        onReasonChange={(reasonKey, checked) => setTopicReason(topic.id, reasonKey, checked)}
        onNoteChange={(note) => setTopicNote(topic.id, note)}
        sectorComparison={isBase ? <SectorComparison {...sectorComparison} topicId={topic.id} locale={locale} /> : null}
      />
    )
  }
}

function CategoryIcon({ categoryKey }: { categoryKey: string }) {
  const palette: Record<string, string> = {
    E: "bg-emerald-100 text-emerald-800",
    S: "bg-sky-100 text-sky-800",
    G: "bg-violet-100 text-violet-800",
    O: "bg-muted text-muted-foreground",
  }

  return (
    <span aria-hidden="true" className={cn("flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold", palette[categoryKey] ?? palette.O)}>
      {categoryKey === "O" ? "•" : categoryKey}
    </span>
  )
}

function RecommendedBadge({ locale }: { locale: "es" | "en" }) {
  const tr = (message: string) => ui(locale, message)

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge className="gap-1 border-primary/30 bg-primary/10 text-primary hover:bg-primary/15" variant="outline">
          <Sparkles className="h-3 w-3" aria-hidden="true" />
          {PRODUCT_NAME}
        </Badge>
      </TooltipTrigger>
      <TooltipContent>
        <p>{tr("Tema recomendado por la aplicación")}</p>
      </TooltipContent>
    </Tooltip>
  )
}

function AddedBadge({ locale }: { locale: "es" | "en" }) {
  const tr = (message: string) => ui(locale, message)

  return (
    <Badge variant="secondary" className="gap-1">
      <UserPlus className="h-3 w-3" aria-hidden="true" />
      {tr("Añadido por ti")}
    </Badge>
  )
}

function StatePanel({
  icon,
  title,
  description,
  progress,
  action,
}: {
  icon: React.ReactNode
  title: string
  description: string
  progress?: React.ReactNode
  action: React.ReactNode
}) {
  return (
    <div role="status" aria-live="polite" className="rounded-lg border border-border p-6">
      <div className="flex items-start gap-3">
        {icon}
        <div className="min-w-0 flex-1 space-y-4">
          <div>
            <h2 className="text-base font-semibold text-foreground">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
          </div>
          {progress}
          {action}
        </div>
      </div>
    </div>
  )
}

function PredictionProgressIndicator() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  return (
    <div className="space-y-2" aria-label={tr("Seguimiento de la generación de la propuesta de temas")}>
      <div className="h-2 overflow-hidden rounded-full bg-primary/15" role="progressbar" aria-label={tr("Generando propuesta de temas materiales")}>
        <div className="h-full w-1/2 rounded-full bg-primary motion-safe:animate-pulse" />
      </div>
      <p className="text-xs text-muted-foreground">
        {" "}{tr("La página se actualiza automáticamente; no hace falta repetir la acción mientras la barra siga activa.")}{" "}</p>
    </div>
  )
}

type TopicDocumentEvidenceItem = {
  kind: string
  standard: string | null
  documentId: number | null
  page: number | null
  confidence: number | null
  snippet: string
  documentDeleted: boolean
}

function TopicReviewCard({
  locale = "es",
  topic,
  isBase,
  isRecommended,
  isAdded,
  expanded,
  onExpandedChange,
  documentEvidence,
  action,
  reasons,
  note,
  disabled,
  onActionChange,
  onAddedChange,
  onReasonChange,
  onNoteChange,
  sectorComparison,
}: {
  locale?: "es" | "en"
  topic: CatalogTopic
  isBase: boolean
  isRecommended: boolean
  isAdded: boolean
  expanded: boolean
  onExpandedChange: (open: boolean) => void
  documentEvidence: LaravelDocumentEvidence | null
  action?: LaravelTopicAction
  reasons: string[]
  note: string
  disabled: boolean
  onActionChange: (action: LaravelTopicAction) => void
  onAddedChange: (added: boolean) => void
  onReasonChange: (reasonKey: string, checked: boolean) => void
  onNoteChange: (note: string) => void
  sectorComparison?: React.ReactNode
}) {
  const tr = (message: string) => ui(locale, message)
  const title = topicTitle(topic, locale)
  const examples = topicExamples(topic, locale)
  const selected = isBase ? action === "accepted" : isAdded
  const canExpand = isBase || isAdded

  // Document evidence is reviewer context only: it never drives, suggests, or
  // disables the accept/reject/unsure controls below (ADD-only invariant).
  const provenance = isBase && documentEvidence ? topicDocumentProvenance(topic.esrs_code, documentEvidence) : null
  const evidenceItems: TopicDocumentEvidenceItem[] = provenance?.document
    ? flattenTopicEvidence(provenance.entries, documentEvidence)
    : []
  const needsDocumentReview =
    provenance?.document === true &&
    evidenceNeedsDocumentReview(provenance.entries, DOCUMENT_EVIDENCE_LOW_CONFIDENCE_THRESHOLD)
  const hasNegativeEvidence = provenance != null && topicHasNegativeDocumentEvidence(provenance.entries)

  const detailsTrigger = (
    <CollapsibleTrigger className="group flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground">
      <ChevronDown className="h-4 w-4 transition-transform group-data-[state=open]:rotate-180" aria-hidden="true" />
      <span className="sr-only">{expanded ? tr("Colapsar") : tr("Expandir")} {" "}{tr("detalles del tema")}</span>
    </CollapsibleTrigger>
  )

  const handleChecked = (checked: boolean) => {
    if (isBase) {
      onActionChange(checked ? "accepted" : "rejected")
    } else {
      onAddedChange(checked)
    }
  }

  return (
    <Collapsible
      open={expanded && canExpand}
      onOpenChange={onExpandedChange}
      className={cn(
        "rounded-md border bg-card transition-colors",
        selected ? "border-primary/50 bg-primary/5" : "border-border",
        isRecommended && !selected ? "border-dashed border-primary/40" : null,
      )}
    >
      <div className="flex min-w-0 flex-col gap-2 p-3 @3xl:flex-row @3xl:items-center @3xl:justify-between">
        <div className="flex min-w-0 flex-1 items-start gap-3">
          <Checkbox
            className="mt-0.5"
            aria-label={formatUi(locale, "Seleccionar {0}", [title])}
            checked={selected}
            disabled={disabled}
            onCheckedChange={(checked) => handleChecked(checked === true)}
          />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className={cn("text-sm font-medium [overflow-wrap:anywhere]", selected ? "text-primary" : "text-foreground")}>{title}</span>
              {isRecommended ? <RecommendedBadge locale={locale} /> : null}
              {isBase && !isRecommended ? <Badge variant="outline">{tr("Propuesto")}</Badge> : null}
              {isAdded ? <AddedBadge locale={locale} /> : null}
              {isBase && action === "unsure" ? <Badge variant="outline" className="border-amber-400 text-amber-900">{tr("Duda")}</Badge> : null}
              {isBase && action === "rejected" ? <Badge variant="outline" className="text-muted-foreground">{tr("Rechazado")}</Badge> : null}
              {examples.length > 0 ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button type="button" aria-label={formatUi(locale, "Ver ejemplos de {0}", [title])} className="text-muted-foreground hover:text-foreground">
                      <Info className="h-4 w-4" aria-hidden="true" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent className="max-w-sm">
                    <p className="font-medium">{tr("Ejemplos:")}</p>
                    <ul className="mt-1 list-disc space-y-0.5 pl-4">
                      {examples.map((example) => (
                        <li key={example}>{example}</li>
                      ))}
                    </ul>
                  </TooltipContent>
                </Tooltip>
              ) : null}
              {!isBase && canExpand ? detailsTrigger : null}
            </div>
          </div>
        </div>
        {isBase ? (
        <div className="flex shrink-0 items-center gap-1 self-start pl-7 @3xl:pl-0">
          {isBase ? (
            <div role="group" aria-label={formatUi(locale, "Revisar {0}", [title])} className="flex shrink-0 flex-nowrap items-center gap-1">
              {(["accepted", "unsure", "rejected"] as LaravelTopicAction[]).map((candidateAction) => (
                <Tooltip key={candidateAction}>
                  <TooltipTrigger asChild>
                    <Button
                      type="button"
                      size="sm"
                      variant={action === candidateAction ? "default" : "outline"}
                      className="h-8 shrink-0 whitespace-nowrap px-2 sm:px-3"
                      aria-pressed={action === candidateAction}
                      disabled={disabled}
                      onClick={() => onActionChange(candidateAction)}
                    >
                      {candidateAction === "accepted" ? (
                        <CheckCircle2 className="hidden h-4 w-4 sm:block" />
                      ) : candidateAction === "unsure" ? (
                        <HelpCircle className="hidden h-4 w-4 sm:block" />
                      ) : (
                        <XCircle className="hidden h-4 w-4 sm:block" />
                      )}
                      {tr(actionLabels[candidateAction])}
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p>{tr(actionDescriptions[candidateAction])}</p>
                  </TooltipContent>
                </Tooltip>
              ))}
            </div>
          ) : null}
          {detailsTrigger}
        </div>
        ) : null}
      </div>

      <CollapsibleContent className="border-t border-border/60 px-4 pb-4 pt-3">
        {isBase ? (
          <>
            <div className="mb-3 flex flex-wrap gap-2">
              {!action ? <Badge variant="secondary">{tr("Sin revisar")}</Badge> : null}
              {provenance ? <Badge variant="secondary">{tr("Perfil de empresa")}</Badge> : null}
              {provenance?.document ? <Badge variant="secondary">{tr("Documento subido")}</Badge> : null}
              {needsDocumentReview ? <Badge variant="outline" className="border-amber-400 text-amber-900">{tr("Revisar en el documento")}</Badge> : null}
            </div>
            {hasNegativeEvidence ? (
              <p className="text-sm text-muted-foreground">
                {" "}{tr("El documento indica que podría no ser material. Tenlo en cuenta al revisar; la decisión sigue siendo tuya.")}{" "}</p>
            ) : null}
            {evidenceItems.length > 0 ? (
              <details className="rounded-md border border-border px-3 py-2">
                <summary className="cursor-pointer text-sm font-medium text-foreground">
                  {" "}{tr("Evidencias del documento (")}{evidenceItems.length})
                </summary>
                <ul className="mt-2 space-y-2">
                  {evidenceItems.map((item, index) => (
                    <li key={index} className="text-sm text-muted-foreground">
                      {item.documentDeleted ? (
                        <span className="text-amber-700">{tr("Documento eliminado — revisar")}</span>
                      ) : (
                        <>
                          {" "}{tr("«")}{item.snippet}{tr("»")}{" "}{item.page != null ? formatUi(locale, " (página {0})", [item.page]) : ""}
                          {item.confidence != null ? formatUi(locale, " · confianza {0} %", [Math.round(item.confidence * 100)]) : ""}
                        </>
                      )}
                    </li>
                  ))}
                </ul>
              </details>
            ) : null}
            <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {REVIEW_REASON_KEYS.map((reasonKey: string) => (
                <label
                  key={reasonKey}
                  className="flex min-h-9 items-center gap-2 rounded-md border border-border px-3 py-2 text-sm"
                >
                  <Checkbox
                    checked={reasons.includes(reasonKey)}
                    disabled={disabled}
                    onCheckedChange={(checked) => onReasonChange(reasonKey, checked === true)}
                  />
                  <span>{tr(reasonLabels[reasonKey] ?? "Otro")}</span>
                </label>
              ))}
            </div>
          </>
        ) : null}

        <div className={cn(isBase ? "mt-4" : null)}>
          <Textarea
            placeholder={isBase ? tr("Nota de revisión opcional") : tr("Nota opcional sobre este tema")}
            value={note}
            disabled={disabled}
            onChange={(event) => onNoteChange(event.target.value)}
          />
        </div>

        {sectorComparison ? <div className="mt-4">{sectorComparison}</div> : null}
      </CollapsibleContent>
    </Collapsible>
  )
}
