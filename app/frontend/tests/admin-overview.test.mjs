import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { dirname, join } from "node:path"
import { fileURLToPath } from "node:url"
import test from "node:test"

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const read = (relativePath) => readFileSync(join(root, relativePath), "utf8")

test("the administration page is gated by the platform admin flag of the Laravel session", () => {
  const page = read("app/(dashboard)/admin/page.tsx")

  assert.match(page, /getLaravelServerSession/)
  assert.match(page, /session\?\.admin !== true\) notFound\(\)/)
  assert.match(page, /AdminOverview/)
  assert.doesNotMatch(page, /learning_admin/, "platform administration is not the learning administration")
})

test("the session type and header expose the platform admin flag and link", () => {
  assert.match(read("lib/laravel-api.ts"), /admin\?: boolean/)
  assert.match(read("app/(dashboard)/layout.tsx"), /admin=\{session\.admin === true\}/)
  const header = read("components/dashboard/dashboard-header.tsx")
  assert.match(header, /\{admin && <DropdownMenuItem asChild><Link href="\/admin">/)
})

test("the overview reads admin endpoints and opens other accounts' reports through as_user", () => {
  const source = read("components/admin/admin-overview.tsx")

  assert.match(source, /getLaravelAdminUsers\(\)/)
  assert.match(source, /getLaravelAdminAiUsefulness\(\)/)
  assert.match(source, /\/admin\/ai-usefulness\?format=pdf/, "the PDF comes from the platform")
  assert.match(source, /\/report\/xhtml-ixbrl-candidate\?inline=1&\$\{asUser\}/, "the report opens as the designed XBRL document")
  assert.match(source, /\/guided-report\/docx\?\$\{asUser\}/)
  assert.match(source, /\/characterization\/summary\?format=pdf&\$\{asUser\}/)
  assert.match(source, /error\.status === 403/, "a non-admin gets a clear message")
  assert.match(source, /role="alert"/)
  assert.doesNotMatch(source, /fetch\(["']\/api\//, "no direct fetches outside the Laravel client")
})

test("the overview no longer links the walkthrough video (removed at the user's request on 2026-11-20)", () => {
  const source = read("components/admin/admin-overview.tsx")

  assert.doesNotMatch(source, /demo-video|demo_video|Vídeo del recorrido/)
})
