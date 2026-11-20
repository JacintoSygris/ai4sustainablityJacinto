"use client"

import { ui } from "@/lib/i18n/messages.mjs"
import { useLocale } from "@/components/locale-provider"

import { useState } from "react"
import { Search, ExternalLink } from "lucide-react"
import { Input } from "@/components/ui/input"
import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"

// Sygris articles backing each card (featured images stored under /public/academy);
// the English edition is used when Sygris publishes one.
const articles = [
  {
    id: 1,
    title: "¿Qué es la doble importancia relativa?",
    description:
      "Aprende sobre el concepto de doble importancia relativa y cómo evaluar los impactos ambientales y financieros de tu empresa según NEIS.",
    image: "/academy/doble-materialidad.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/que-es-doble-materialidad/",
      en: "https://sygris.com/en/resources/articles/what-is-double-materiality/",
    },
  },
  {
    id: 2,
    title: "¿Sabes qué es un análisis de materialidad?",
    description:
      "Descubre cómo identificar y priorizar los temas ESG relevantes para tu empresa y sus grupos de interés, base del informe de sostenibilidad.",
    image: "/academy/analisis-materialidad.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/eventos/que-es-un-analisis-de-materialidad/",
      en: "https://sygris.com/recursos/eventos/que-es-un-analisis-de-materialidad/",
    },
  },
  {
    id: 3,
    title: "CSRD: todo lo que necesitas saber sobre la nueva directiva europea",
    description:
      "La directiva que pide información de sostenibilidad útil, comparable y accesible: a quién afecta, qué exige y en qué plazos.",
    image: "/academy/csrd-directiva.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/csrd-todo-lo-que-necesitas-saber-sobre-la-nueva-directiva-europea/",
      en: "https://sygris.com/recursos/articulos/csrd-todo-lo-que-necesitas-saber-sobre-la-nueva-directiva-europea/",
    },
  },
  {
    id: 4,
    title: "Novedades en los indicadores ESRS",
    description:
      "La lista revisada de indicadores publicada por EFRAG, traducida al español: qué cambia en los datos que piden las normas NEIS.",
    image: "/academy/indicadores-esrs.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/novedades-en-los-indicadores-esrs/",
      en: "https://sygris.com/recursos/articulos/novedades-en-los-indicadores-esrs/",
    },
  },
  {
    id: 5,
    title: "Si quieres cumplir con la CSRD, tienes que desglosar los datos",
    description:
      "Cómo convertir los datos normativos de las NEIS en información procesable y recogerla de forma eficiente dentro de la empresa.",
    image: "/academy/data-points.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/si-quieres-cumplir-con-la-csrd-tienes-que-desglosar-los-data-points/",
      en: "https://sygris.com/recursos/articulos/si-quieres-cumplir-con-la-csrd-tienes-que-desglosar-los-data-points/",
    },
  },
  {
    id: 6,
    title: "Claves del desarrollo de la taxonomía XBRL para los ESRS",
    description:
      "Qué es la taxonomía XBRL de las NEIS, cómo avanza su desarrollo en EFRAG y qué supone para el formato digital del informe.",
    image: "/academy/taxonomia-xbrl.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/claves-del-desarrollo-de-la-taxonomia-xbrl-para-los-esrs/",
      en: "https://sygris.com/recursos/articulos/claves-del-desarrollo-de-la-taxonomia-xbrl-para-los-esrs/",
    },
  },
  {
    id: 7,
    title: "PYMES y sostenibilidad: nuevos retos de información ESG",
    description:
      "Por qué las pymes deben preparar su información de sostenibilidad aunque no estén obligadas: clientes e inversores la piden en la cadena de valor.",
    image: "/academy/pymes-esg.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/pymes-y-sostenibilidad-nuevos-retos-de-informacion-esg/",
      en: "https://sygris.com/recursos/articulos/pymes-y-sostenibilidad-nuevos-retos-de-informacion-esg/",
    },
  },
  {
    id: 8,
    title: "El paquete Ómnibus",
    description:
      "Qué cambia con la propuesta Ómnibus de la Comisión Europea en los umbrales, plazos y contenidos del informe de sostenibilidad.",
    image: "/academy/omnibus.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/el-paquete-omnibus/",
      en: "https://sygris.com/recursos/articulos/el-paquete-omnibus/",
    },
  },
  {
    id: 9,
    title: "Errores clave en el reporting ESG que no puedes ignorar",
    description:
      "Los fallos más habituales al preparar el informe de sostenibilidad y cómo evitarlos antes de la revisión y la verificación.",
    image: "/academy/errores-reporting.jpg",
    isExternal: true,
    href: {
      es: "https://sygris.com/recursos/articulos/errores-clave-en-el-reporting-esg-que-no-puedes-ignorar/",
      en: "https://sygris.com/recursos/articulos/errores-clave-en-el-reporting-esg-que-no-puedes-ignorar/",
    },
  },
]

export function AcademySection() {
  const { locale } = useLocale()
  const tr = (message: string) => ui(locale, message)

  const [search, setSearch] = useState("")

  const filteredArticles = articles.filter((article) => tr(article.title).toLowerCase().includes(search.toLowerCase()))

  return (
    <section className="mt-12">
      <h2 className="text-2xl font-bold text-foreground">{tr("Academia Airis")}</h2>
      <p className="mt-2 text-muted-foreground">
        {" "}{tr("Consulta guías de apoyo para revisar materialidad, grupos de interés e información de sostenibilidad:")}{" "}</p>

      <div className="relative mt-6 max-w-md">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          placeholder={tr("Buscar...")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="pl-10"
        />
      </div>

      <div className="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {filteredArticles.map((article) => (
          <a
            key={article.id}
            href={article.href[locale === "en" ? "en" : "es"]}
            target="_blank"
            rel="noopener noreferrer"
            className="block rounded-xl focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
          >
          <Card className="h-full overflow-hidden transition-shadow hover:shadow-lg">
            <CardHeader className="relative p-0">
              <img src={article.image || "/placeholder.svg"} alt={tr(article.title)} className="h-48 w-full object-cover" />
              {article.isExternal && (
                <Badge variant="secondary" className="absolute left-3 top-3 gap-1">
                  <ExternalLink className="h-3 w-3" />
                  {" "}{tr("Enlace externo")}{" "}</Badge>
              )}
            </CardHeader>
            <CardContent className="p-4">
              <h3 className="font-semibold text-primary hover:underline">{tr(article.title)}</h3>
              <p className="mt-2 text-sm text-muted-foreground line-clamp-3">{tr(article.description)}</p>
            </CardContent>
          </Card>
          </a>
        ))}
      </div>
    </section>
  )
}
