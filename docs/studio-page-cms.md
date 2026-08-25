# Studio page CMS

Studio owners control their own public page: a banner, announcements, a
spotlight, guides, hours and contact details, arranged by one of three layouts.
Everything is edited in place on a page that mirrors the live one, held client
side, and published in a single transaction.

Branch `feature/studio-page-cms` in both `ink-api` and `inked-in-www`.

Related: [studio-registration-management.md](flows/studio-registration-management.md)
carries the endpoint tables and the per-area detail.

## Decisions, and why

These were settled deliberately. Reopen them with evidence, not by accident.

**Studios only, not artists.** Studios are businesses with a brand; artists are
people in a marketplace where comparability helps them get found. Artists may
follow later, but nothing here assumes it.

**Three named layouts, not a block builder.** A marketplace's value partly comes
from listings being comparable, and a studio owner should not have to design a
page. `StudioTemplate` is Portfolio, Team and Storefront. Everything below the
tabs is identical across all three; they differ only in what the page leads
with.

**One table, not two.** Announcements and guides share a publishing envelope -
title, slug, body, status, published_at, SEO - and differ in three columns.
`studio_posts` holds both, so there is one editor, one renderer and one sitemap
query. `StudioPostType` splits the families.

**Ephemeral notices get no URL.** A permanently indexed "walk-ins available
today" page is a liability. `StudioPostType::hasPublicPage()` decides, the same
way jacks' `SectionType` carries `hasItems()` and `isAuto()`.

**Dates control placement, not existence.** When an announcement's `ends_at`
passes it leaves the studio page, but its own page stays up as an archive, so a
link shared at the time still resolves.

**Client-held editing, not server drafts.** Edits live in the page until
Publish. No draft columns, no half-published pages. Revisit if steps beyond
this one need scheduling, since `status` already supports draft and scheduled.

**Ownership, not `is_claimed`, decides indexing.** That column defaults to true,
so legacy rows carry it without an owner. Only studios someone actually owns
reach the sitemap: auto-imported listings are thin, and thin pages are worth
less than no pages.

**Guides are aftercare and preparation, not blogging.** Most artists will not
write blog posts. They will write aftercare once if it saves them retyping it
into every chat, which is why the guide and the message are the same text.

## What a studio controls

Whether each section exists at all, and what goes in it: banner, announcements
with a kind and an optional date window, spotlight, guides, hours, contact,
location, and which of the three layouts the page uses. One aftercare guide can
be marked as the one sent to clients.

They cannot reorder sections freely or invent new ones. That is the layout
decision above.

**Every section renders only when the studio has filled it in.** A studio that
touches none of this sees the page it always had. That constraint is enforced
in the components, not by convention: each returns nothing when empty rather
than showing a placeholder.

## Where things live

| Area | Path |
|---|---|
| Public studio page | `inked-in-www/nextjs/pages/studios/[slug].tsx` |
| Editor | `inked-in-www/nextjs/pages/studios/[slug]/edit.tsx` |
| News page | `inked-in-www/nextjs/pages/studios/[slug]/news/[postSlug].tsx` |
| Guide page | `inked-in-www/nextjs/pages/studios/[slug]/guides/[guideSlug].tsx` |
| Page sections | `inked-in-www/nextjs/components/studio/` |
| Editors and the wrapper | `inked-in-www/nextjs/components/studio/edit/` |
| Dashboard studio surface | `inked-in-www/nextjs/components/dashboard/StudioSideColumn.tsx` |
| Studio dashboard state | `inked-in-www/nextjs/hooks/useStudioDashboard.ts` |
| Posts model and types | `ink-api/app/Models/StudioPost.php`, `app/Enums/StudioPost*.php` |
| Authorization | `ink-api/app/Policies/StudioPolicy.php` |

The editor renders the public section components inside `EditableSection`,
which adds the edit affordance around markup it never modifies. That is why the
live page cannot regress through the editor, and it is worth preserving: put
new page content in `components/studio/` and give it an editor beside it,
rather than building a second rendering path.

## Decomposition

Both large files were split so the above was possible at all.

- `studios/[slug].tsx`: 2,019 to 1,060 lines, twelve section components
- `dashboard.tsx`: 2,666 to 1,598 lines, state into `useStudioDashboard`

Both extractions moved markup **verbatim**, with props keeping the page's own
identifier names, so no moved line changed. Boundaries were computed by walking
indentation and checking brace balance, not guessed from section comments - the
first attempt guessed and cut through parent closing tags.

## Bugs found on the way

Most of the value here was not the feature.

- **No authorization on studio writes.** Every studio write endpoint and both
  dashboard reads were reachable by any signed-in user. `StudioPolicy` now
  gates them on ownership.
- **`studios.slug` had no unique index** and three write paths could collide,
  which nested studio URLs would have made a hijack vector.
- **Two hours tables.** The dashboard wrote `studio_availability`; the page read
  `business_hours`. Hours saved in the dashboard never appeared publicly, on web
  or mobile. Consolidated, with the legacy write path removed.
- **POST never invalidated the client cache** while PUT and DELETE did, so every
  POST write looked lost for the full five-minute TTL. Present in both the web
  client and the shared client mobile uses.
- **A 60s public cache on owner-editable reads**, so an owner who saved and
  reloaded saw their old page.
- **`getById` never eager-loaded announcements**, so that section was dead UI.
- **The dashboard read a `studio` key the API does not return**, so the edit
  modal opened blank and the contact form reset on every load.
- **`EditStudioModal` had no trigger anywhere** and was unreachable. Replaced by
  the editor route and deleted.
- **Guide URLs pointed at `/news/`**, so a guide resolved under the wrong route
  with the wrong furniture.
- **`MessageService::sendAftercare` was never called** and duplicated
  `sendTypedMessage`. Removed rather than revived.

## Testing locally

Frontend `http://dev.inkedin.test:4000`, not localhost: `SESSION_DOMAIN`
depends on the hostname and login will not hold otherwise.

Sign in as `demoshop@getinked.in` / `Demoshop1!`. The dashboard opens on the
studio view, and **Edit Studio Page** goes to the editor.

**Demo Shop's content is seeded, not structural.** Banner, three announcements,
three spotlights, an aftercare guide, four verified artists and one pending
were all added for testing. Any other studio - `tattoo-colosseum`,
`reid-studios` - shows the original bare page, which is the comparison worth
making.

Demo work is hidden from non-demo viewers, so the portfolio grid and the
Spotlight picker's Tattoos tab look empty until you are signed in as the demo
shop.

## Gotchas

**Three caching layers**, all of which have caused a false bug report:

1. Server `cache.headers` on public studio routes - removed
2. Client in-memory cache, 5 minutes, opt-out - POST now invalidates, and owner
   dashboard reads bypass it
3. Next SSR `s-maxage=30`, with an owner bypass cookie set on publish

**A reused browser tab will lie to you.** After a run of Fast Refresh cycles the
page component stops re-executing, so client fetches never fire and nothing
client-rendered updates. This produced three phantom bugs in one session,
including a convincing "no studio page shows its artists". Open a fresh tab
first, before building any theory.

**React Native has 40 pre-existing typecheck errors** across files unrelated to
this work. The web project sits at 122, also pre-existing. Judge changes by
whether the count moves, not by whether it is zero.

## Still open

- **Preview omits the portfolio grid and the tabs**, because the tab shell is
  still inside `[slug].tsx`. Extracting it would remove the last place preview
  and reality can drift.
- **React Native has no guides, news pages or template awareness.** Parity is
  marked critical in `inked-in-www/CLAUDE.md`.
- **The artist page still carries `stale-while-revalidate=300`**, the same
  staleness removed from studio pages.
- **Publish is not atomic across image uploads.** The relational work is one
  transaction; images upload first as separate requests.
- **`ElasticService::post()` cannot reach production Elasticsearch.** Tracked
  separately, no overlap with this work.
