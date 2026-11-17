import type React from "react"
import { AuthHeader } from "@/components/auth/auth-header"
import { DashboardHeader } from "@/components/dashboard/dashboard-header"
import { Footer } from "@/components/ui/footer"
import { getLaravelServerSession } from "@/lib/laravel-server"

// /help is reachable signed in (dashboard header) and signed out (auth header):
// it shows the same header the visitor came from, plus the shared footer.
export default async function HelpLayout({
  children,
}: {
  children: React.ReactNode
}) {
  const session = await getLaravelServerSession()

  return (
    <div className="flex min-h-screen flex-col">
      {session ? <DashboardHeader user={session.user} learningAdmin={session.learning_admin === true} /> : <AuthHeader />}
      <main className="flex-1">
        <div className="container mx-auto max-w-3xl px-4 py-12">{children}</div>
      </main>
      <Footer />
    </div>
  )
}
