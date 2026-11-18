import { notFound } from "next/navigation"
import { getLaravelServerSession } from "@/lib/laravel-server"
import { AdminOverview } from "@/components/admin/admin-overview"

// Platform administration: only verified accounts of the administrator email
// domains (services.admin.email_domains on the platform) get this page; everyone else
// sees a 404 so the route does not reveal itself.
export default async function AdminPage() {
  const session = await getLaravelServerSession()
  if (session?.admin !== true) notFound()
  return <AdminOverview />
}
